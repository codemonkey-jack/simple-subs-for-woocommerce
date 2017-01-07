<?php
namespace WP_Satchel\Includes\Gateways;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PayPal\Service\AdaptivePaymentsService;
use PayPal\Types\AP\PaymentDetailsRequest;
use PayPal\Types\AP\PayRequest;
use PayPal\Types\AP\PreapprovalRequest;
use PayPal\Types\AP\Receiver;
use PayPal\Types\AP\ReceiverList;
use PayPal\Types\AP\RefundRequest;
use PayPal\Types\Common\RequestEnvelope;
use WP_Satchel\Includes\Utils;

class Paypal extends \WC_Payment_Gateway {

	/** @var bool Whether or not logging is enabled */
	public static $log_enabled = false;

	/** @var WC_Logger Logger instance */
	public static $log = false;

	public function __construct() {
		$this->id                 = 'wpsatchel-paypal-adaptive';
		$this->has_fields         = true;
		$this->order_button_text  = __( 'Proceed to PayPal', 'woocommerce' );
		$this->method_title       = __( 'WP Satchel PayPal Subscription', 'woocommerce' );
		$this->method_description = sprintf( __( 'PayPal standard sends customers to PayPal to enter their payment information. PayPal IPN requires fsockopen/cURL support to update order statuses after payment. Check the %ssystem status%s page for more details.', 'woocommerce' ), '<a href="' . admin_url( 'admin.php?page=wc-status' ) . '">', '</a>' );
		$this->supports           = array(
			'products',
			'refunds'
		);

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->title          = $this->get_option( 'title' );
		$this->description    = $this->get_option( 'description' );
		$this->testmode       = 'yes' === $this->get_option( 'testmode', 'no' );
		$this->debug          = 'yes' === $this->get_option( 'debug', 'no' );
		$this->email          = $this->get_option( 'email' );
		$this->receiver_email = $this->get_option( 'receiver_email', $this->email );
		$this->identity_token = $this->get_option( 'identity_token' );

		self::$log_enabled = $this->debug;

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options'
		) );

		if ( ! $this->is_valid_for_use() ) {
			$this->enabled = 'no';
		} else {

		}
	}

	public function payment_fields() {
		echo $this->description;
		if ( Utils::is_availabe_for_subscription() && is_user_logged_in() ) {
			echo '<br><input type="checkbox" name="satchel_paypal_preapproval">' . __( 'Preapprove all future payments for subscriptions in this order.', 'stppst' );
		}
	}

	private function log( $text ) {
		$log = new Logger( 'debug' );
		$log->pushHandler( new StreamHandler( \WP_Satchel\paypal_stripe_subscription()->plugin_path . 'log.log', Logger::WARNING ) );
		$log->warning( $text );
	}

	public function process_payment( $order_id ) {
		$order = get_post( $order_id );
		if ( ! is_object( $order ) ) {
			wc_add_notice( '', __( "Order doesn't exist.", "stppst" ) );

			return;
		}

		$order = wc_get_order( $order );

		include_once \WP_Satchel\paypal_stripe_subscription()->plugin_path . 'vendor/autoload.php';


		if ( isset( $_POST['satchel_paypal_preapproval'] ) ) {
			return $this->_pre_apporve_request( $order );
		} else {
			return $this->_pay_request( $order );
		}
	}

	private function _pre_apporve_request( \WC_Order $order ) {
		$pre_request                     = new PreapprovalRequest();
		$pre_request->cancelUrl          = $order->get_cancel_order_url();
		$pre_request->returnUrl          = $this->get_return_url( $order );
		$pre_request->startingDate       = date( 'c' );
		$pre_request->currencyCode       = $order->get_order_currency();
		$pre_request->requestEnvelope    = new RequestEnvelope( 'en_US' );
		$pre_request->ipnNotificationUrl = add_query_arg( 'wc-api', 'wp_satchel_pp', home_url( '/' ) );
		//$pre_request->memo               = $order->id;
		$service = new AdaptivePaymentsService( array(
			"mode"            => $this->get_option( 'testmode' ) == 'yes' ? "sandbox" : "live",
			"acct1.UserName"  => $this->get_option( 'api_username' ),
			"acct1.Password"  => $this->get_option( 'api_password' ),
			"acct1.Signature" => $this->get_option( 'api_signature' ),
			"acct1.AppId"     => $this->get_option( 'app_id' )
		) );
		try {
			/* wrap API method calls on the service object with a try catch */
			$response = $service->Preapproval( $pre_request );
			if ( strtolower( $response->responseEnvelope->ack ) == 'success' ) {
				$url = $this->get_request_url( array(
					'cmd'            => '_ap-preapproval',
					'preapprovalkey' => $response->preapprovalKey
				) );
				update_post_meta( $order->id, '_ws_paypal_prekey', $response->preapprovalKey );


				return array(
					'result'   => 'success',
					'redirect' => $url,
				);
			} else {
				wc_add_notice( '', $response->error[0]->message );
			}
		} catch ( \Exception $ex ) {
			wc_add_notice( __( 'Payment error:', 'woothemes' ) . $ex->getMessage(), 'error' );

			return;
		}

	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order                            = wc_get_order( $order_id );
		$refundRequest                    = new RefundRequest( new RequestEnvelope( "en_US" ) );
		$refundRequest->transactionId     = $order->get_transaction_id();
		$paymentDetailsReq                = new PaymentDetailsRequest( new RequestEnvelope( "en_US" ) );
		$paymentDetailsReq->transactionId = $order->get_transaction_id();

		$service = new AdaptivePaymentsService( array(
			"mode"            => $this->get_option( 'testmode' ) == 'yes' ? "sandbox" : "live",
			"acct1.UserName"  => $this->get_option( 'api_username' ),
			"acct1.Password"  => $this->get_option( 'api_password' ),
			"acct1.Signature" => $this->get_option( 'api_signature' ),
			"acct1.AppId"     => $this->get_option( 'app_id' )
		) );

		$response = $service->PaymentDetails( $paymentDetailsReq );
		if ( strtolower( $response->responseEnvelope->ack ) == 'success' ) {
			$email                       = $response->paymentInfoList->paymentInfo[0]->receiver->email;
			$receiver                    = new Receiver();
			$receiver->email             = $email;
			$receiver->amount            = $amount;
			$refundRequest->receiverList = new ReceiverList( array( $receiver ) );
			$refundRequest->currencyCode = $order->get_order_currency();

			$response = $service->Refund( $refundRequest );
			if ( strtolower( $response->responseEnvelope->ack ) == 'success' ) {
				$trans_id      = $response->refundInfoList->refundInfo[0]->encryptedRefundTransactionId;
				$refund_amount = $response->refundInfoList->refundInfo[0]->refundGrossAmount;
				$order->add_order_note( sprintf( __( 'Refunded %s - Refund ID: %s', 'woocommerce' ), $refund_amount, $trans_id ) );

				return true;
			} else {
				return new \WP_Error( 'refund_fail', $response->error->message );
			}
		}
	}

	private function _pay_request( \WC_Order $order ) {
		//case pay request
		$pay_request                     = new PayRequest();
		$pay_request->actionType         = 'PAY';
		$receiver                        = new Receiver();
		$receiver->email                 = $this->get_option( 'receiver_email' );
		$receiver->amount                = $order->get_total();
		$pay_request->receiverList       = new ReceiverList( array( $receiver ) );
		$pay_request->currencyCode       = $order->get_order_currency();
		$pay_request->cancelUrl          = $order->get_cancel_order_url();
		$pay_request->returnUrl          = $this->get_return_url( $order );
		$ipn_url                         = add_query_arg( 'wc-api', 'wp_satchel_pp', home_url( '/' ) );
		$pay_request->ipnNotificationUrl = $ipn_url;
		$pay_request->requestEnvelope    = new RequestEnvelope( 'en_US' );
		$service                         = new AdaptivePaymentsService( array(
			"mode"            => $this->get_option( 'testmode' ) == 'yes' ? "sandbox" : "live",
			"acct1.UserName"  => $this->get_option( 'api_username' ),
			"acct1.Password"  => $this->get_option( 'api_password' ),
			"acct1.Signature" => $this->get_option( 'api_signature' ),
			"acct1.AppId"     => $this->get_option( 'app_id' )
		) );
		try {
			/* wrap API method calls on the service object with a try catch */
			$response = $service->Pay( $pay_request );
			if ( strtolower( $response->responseEnvelope->ack ) == 'success' ) {
				$url = $this->get_request_url( array(
					'cmd'    => '_ap-payment',
					'paykey' => $response->payKey
				) );
				update_post_meta( $order->id, '_ws_paypal_paykey', $response->payKey );
				$order->update_status( 'pending' );

				return array(
					'result'   => 'success',
					'redirect' => $url,
				);
			} else {
				wc_add_notice( '', $response->error );
			}
		} catch ( \Exception $ex ) {
			wc_add_notice( __( 'Payment error:', 'woothemes' ) . $ex->getMessage(), 'error' );

			return;
		}
	}

	/**
	 * @param $args
	 *
	 * @return bool|string
	 */
	public function get_request_url( $args ) {
		if ( ! $args ) {
			return false;
		}

		if ( $this->get_option( 'testmode' ) == 'yes' ) {
			$base_url = 'https://www.sandbox.paypal.com/cgi-bin/webscr?';
		} else {
			$base_url = 'https://www.paypal.com/cgi-bin/webscr?';
		}

		return $base_url . http_build_query( $args, '', '&' );
	}

	/**
	 * Check if this gateway is enabled and available in the user's country.
	 * @return bool
	 */
	public function is_valid_for_use() {
		return in_array( get_woocommerce_currency(), apply_filters( 'woocommerce_paypal_supported_currencies', array(
			'AUD',
			'BRL',
			'CAD',
			'MXN',
			'NZD',
			'HKD',
			'SGD',
			'USD',
			'EUR',
			'JPY',
			'TRY',
			'NOK',
			'CZK',
			'DKK',
			'HUF',
			'ILS',
			'MYR',
			'PHP',
			'PLN',
			'SEK',
			'CHF',
			'TWD',
			'THB',
			'GBP',
			'RMB',
			'RUB'
		) ) );
	}

	/**
	 * Initialize form fields
	 *
	 * @access public
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'       => array(
				'title'   => __( 'Enable/Disable', 'woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable PayPal Adaptive', 'woocommerce' ),
				'default' => 'yes'
			),
			'title'         => array(
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				'default'     => __( 'PayPal', 'woocommerce' ),
				'desc_tip'    => true,
			),
			'description'   => array(
				'title'       => __( 'Description', 'woocommerce' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
				'default'     => __( 'Pay via PayPal; you can pay with your credit card if you don\'t have a PayPal account.', 'woocommerce' )
			),
			'email'         => array(
				'title'       => __( 'PayPal Email', 'woocommerce' ),
				'type'        => 'email',
				'description' => __( 'Please enter your PayPal email address; this is needed in order to take payment.', 'woocommerce' ),
				'default'     => get_option( 'admin_email' ),
				'desc_tip'    => true,
				'placeholder' => 'you@youremail.com'
			),
			'testmode'      => array(
				'title'       => __( 'PayPal Sandbox', 'woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable PayPal sandbox', 'woocommerce' ),
				'default'     => 'no',
				'description' => sprintf( __( 'PayPal sandbox can be used to test payments. Sign up for a developer account <a href="%s">here</a>.', 'woocommerce' ), 'https://developer.paypal.com/' ),
			),
			'api_details'   => array(
				'title'       => __( 'API Credentials', 'woocommerce' ),
				'type'        => 'title',
				'description' => sprintf( __( 'Enter your PayPal API credentials to process refunds via PayPal. Learn how to access your PayPal API Credentials %shere%s.', 'woocommerce' ), '<a href="https://developer.paypal.com/webapps/developer/docs/classic/api/apiCredentials/#creating-an-api-signature">', '</a>' ),
			),
			'api_username'  => array(
				'title'       => __( 'API Username', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Get your API credentials from PayPal.', 'woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
				//'placeholder' => __( 'Optional', 'woocommerce' )
			),
			'api_password'  => array(
				'title'       => __( 'API Password', 'woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Get your API credentials from PayPal.', 'woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
				//'placeholder' => __( 'Optional', 'woocommerce' )
			),
			'api_signature' => array(
				'title'       => __( 'API Signature', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Get your API credentials from PayPal.', 'woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
				//'placeholder' => __( 'Optional', 'woocommerce' )
			),
			'app_id'        => array(
				'title'       => __( 'PayPal App ID', 'woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'desc_tip'    => true,
				//'placeholder' => __( 'Optional', 'woocommerce' )
			)
		);
	}
}