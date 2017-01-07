<?php
/**
 * Author: Jack Kitterhing
 */
namespace WP_Satchel\Includes\Gateways;

use PayPal\IPN\PPIPNMessage;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PayPal\Service\AdaptivePaymentsService;
use PayPal\Types\AP\PayRequest;
use PayPal\Types\AP\Receiver;
use PayPal\Types\AP\ReceiverList;
use PayPal\Types\Common\RequestEnvelope;
use WP_Satchel\Includes\Model\Subscription;
use WP_Satchel\Includes\Utils;

class Paypal_IPN {
	public function __construct() {
		add_action( 'woocommerce_api_wp_satchel_pp', array( $this, 'ipn_handler' ) );
	}

	private function log( $text ) {
		$log = new Logger( 'debug' );
		$log->pushHandler( new StreamHandler( \WP_Satchel\paypal_stripe_subscription()->plugin_path . 'log1.log', Logger::WARNING ) );
		$log->warning( $text );
	}

	public function ipn_handler() {
		include_once \WP_Satchel\paypal_stripe_subscription()->plugin_path . 'vendor/autoload.php';

		$gateway = new Paypal();

		if ( ! class_exists( '\PayPal\IPN\PPIPNMessage' ) ) {
			include_once \WP_Satchel\paypal_stripe_subscription()->plugin_path . 'vendor/paypal/sdk-core-php/lib/PayPal/IPN/PPIPNMessage.php';
		}
		$this->log( "========================================" . date( 'Y-m-d H:i:s' ) );

		$ipnMessage = new PPIPNMessage( null, array(
			"mode"            => $gateway->get_option( 'testmode' ) == 'yes' ? "sandbox" : "live",
			"acct1.UserName"  => $gateway->get_option( 'api_username' ),
			"acct1.Password"  => $gateway->get_option( 'api_password' ),
			"acct1.Signature" => $gateway->get_option( 'api_signature' ),
			"acct1.AppId"     => $gateway->get_option( 'app_id' )
		) );

		if ( $ipnMessage->validate() ) {
			$raw_data = $ipnMessage->getRawData();
			if ( strtolower( $ipnMessage->getTransactionType() ) == 'adaptive payment pay' ) {
				$pay_key = $raw_data['pay_key'];
				$order   = $this->find_order( '_ws_paypal_paykey', $pay_key );
				if ( $order->has_status( 'completed' ) ) {
					return;
				}

				$data = array();
				foreach ( $raw_data as $k => $v ) {
					$data[ rawurldecode( $k ) ] = $v;
				}
				switch ( strtolower( $raw_data['status'] ) ) {
					case 'completed':
						//get the trans_id
						$i            = 0;
						$transactions = array();
						while ( true ) {
							if ( isset( $data["transaction[$i].id"] ) ) {
								$transactions[] = $data["transaction[$i].id"];
							} else {
								break;
							}
							$i ++;
						}

						$order->add_order_note( sprintf( __( 'PayPal payment completed via IPN (paykey: %s, transaction id(s): %s)', 'stppst' ), $pay_key, implode( ',', $transactions ) ) );
						$order->payment_complete( implode( ',', $transactions ) );
						//get subscription witht his order
						if ( ( $sub_id = get_post_meta( $order->id, 'ws_renewal', true ) ) == false ) {
							$models = Subscription::find_subscription_by_order( $order->id );
							foreach ( $models as $model ) {
								$model->status        = Subscription::STATUS_ACTIVE;
								$model->last_pay_date = time();
								$model->add_log( __( "Payment Received, trigger via IPN", "stppst" ),
									"payment_received",
									time() );
								$model->save();
							}
						} else {
							//renew
							$model = new Subscription( $sub_id );
							//this sub already active, we just update the last pay date & add log
							$model->last_pay_date = time();
							$model->add_log( __( "Payment Received for renew, trigger via IPN", "stppst" ),
								"payment_received",
								time() );
						}
						break;
					case 'pending':
						$order->update_status( 'on-hold', sprintf( __( 'Payment pending: %s', 'stppst' ), $data['pending_reason'] ) );
						break;
					case 'refunded':
						$order->update_status( 'refunded', sprintf( __( 'Payment %s via IPN.', 'stppst' ), wc_clean( $data['status'] ) ) );
						break;
					case 'reversed':
						$order->update_status( 'on-hold', sprintf( __( 'Payment %s via IPN.', 'stppst' ), wc_clean( $data['status'] ) ) );
						break;
					case 'failed':
					case 'denied':
					case 'expired':
					case 'voided':
						if ( ( $sub_id = get_post_meta( $order->id, 'ws_renewal', true ) ) != false ) {
							$model         = new Subscription( $sub_id );
							$model->status = Subscription::STATUS_CANCEL;
							$model->add_log( __( "Payment failed for renew, trigger via IPN", "stppst" ),
								"payment_failed",
								time() );
						} else {
							$models = Subscription::find_subscription_by_order( $order->id );
							foreach ( $models as $model ) {
								$model->status = Subscription::STATUS_CANCEL;
								$model->add_log( __( "Payment failed, trigger via IPN", "stppst" ),
									"payment_failed",
									time() );
								$model->save();
							}
						}
						$order->update_status( 'failed', sprintf( __( 'Payment %s via IPN.', 'stppst' ), wc_clean( $data['status'] ) ) );
						break;
				}
			} elseif ( strtolower( $ipnMessage->getTransactionType() ) == 'adaptive payment preapproval' ) {
				$pay_key = $raw_data['preapproval_key'];
				$order   = $this->find_order( '_ws_paypal_prekey', $pay_key );
				//create a request
				if ( $raw_data['approved'] == 'true' ) {
					//update meta
					update_post_meta( $order->id, '_ws_paypal_prekey', $pay_key );
					$this->create_preapporve_pay_request( $order, $gateway );
				} else {
					$order->update_status( 'failed', __( "Preapprove request fail", "stppst" ) );
				}
			}
		} else {

		}
		die;
	}

	public function create_preapporve_pay_request( \WC_Order $order, $gateway ) {
		$pay_request                     = new PayRequest();
		$pay_request->actionType         = 'PAY';
		$receiver                        = new Receiver();
		$receiver->email                 = $gateway->get_option( 'receiver_email' );
		$receiver->amount                = $order->get_total();
		$pay_request->receiverList       = new ReceiverList( array( $receiver ) );
		$pay_request->currencyCode       = $order->get_order_currency();
		$pay_request->cancelUrl          = $order->get_cancel_order_url();
		$pay_request->returnUrl          = $gateway->get_return_url( $order );
		$ipn_url                         = add_query_arg( 'wc-api', 'wp_satchel_pp', home_url( '/' ) );
		$pay_request->ipnNotificationUrl = $ipn_url;
		$pay_request->requestEnvelope    = new RequestEnvelope( 'en_US' );
		$pay_request->preapprovalKey     = get_post_meta( $order->id, '_ws_paypal_prekey', true );
		$service                         = new AdaptivePaymentsService( array(
			"mode"            => $gateway->get_option( 'testmode' ) == 'yes' ? "sandbox" : "live",
			"acct1.UserName"  => $gateway->get_option( 'api_username' ),
			"acct1.Password"  => $gateway->get_option( 'api_password' ),
			"acct1.Signature" => $gateway->get_option( 'api_signature' ),
			"acct1.AppId"     => $gateway->get_option( 'app_id' )
		) );
		try {
			/* wrap API method calls on the service object with a try catch */
			$response = $service->Pay( $pay_request );
			if ( strtolower( $response->responseEnvelope->ack ) == 'success' ) {
				//$this->log( var_export( $response, true ) );
				$pay_key = $response->payKey;
				update_post_meta( $order->id, '_ws_paypal_paykey', $pay_key );
				//$order->add_order_note( sprintf( __( '(init) PayPal payment completed via IPN (paykey: %s, transaction id(s): %s)', 'stppst' ), $pay_key, $trans_id ) );
				//$order->payment_complete( implode( ',', $trans_id ) );
				return true;
			} else {
				$this->log( var_export( $response, true ) );
				$order->update_status( 'failed', $response->error );
				return false;
			}
		} catch ( \Exception $ex ) {
			$this->log( $ex->getMessage() );

			return;
		}
	}

	/**
	 * @param $key
	 * @param $value
	 *
	 * @return \WC_Order|\WC_Refund
	 */
	private function find_order( $key, $value ) {
		$posts = new \WP_Query( $args = array(
			'posts_per_page' => - 1,
			'post_type'      => 'shop_order',
			'post_status'    => 'any',
			'meta_query'     => array(
				array(
					'key'     => $key,
					'value'   => $value,
					'compare' => '=',
				),
			),
			'fields'         => 'ids',
		) );

		if ( $posts->post_count == 0 ) {
			die;
		}

		$post  = $posts->posts[0];
		$order = wc_get_order( $post );

		return $order;
	}
}