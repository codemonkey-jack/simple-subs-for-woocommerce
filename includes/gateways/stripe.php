<?php
/**
 * Author: Jack Kitterhing
 */

namespace WP_Satchel\Includes\Gateways;

use Stripe\Customer;
use Stripe\Plan;
use WP_Satchel\Includes\Model\Subscription;
use WP_Satchel\Includes\Utils;

class Stripe extends \WC_Payment_Gateway {
	public function __construct() {
		$this->id         = 'wpsatchel-stripe';
		$this->has_fields = true;
		//$this->order_button_text  = __( 'Proceed to PayPal', 'woocommerce' );
		$this->method_title = __( 'Stripe by WP Satchel', 'stppst' );;
		$this->endpoint_url = 'https://api.stripe.com/';
		//$this->method_description = sprintf( __( 'PayPal standard sends customers to PayPal to enter their payment information. PayPal IPN requires fsockopen/cURL support to update order statuses after payment. Check the %ssystem status%s page for more details.', 'woocommerce' ), '<a href="' . admin_url( 'admin.php?page=wc-status' ) . '">', '</a>' );
		$this->supports = array(
			'products',
			'refunds'
		);

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define properties
		$this->enabled         = $this->get_option( 'enabled' );
		$this->debug           = $this->get_option( 'debug' );
		$this->capture         = $this->get_option( 'capture' ) === 'yes' ? true : false;
		$this->title           = $this->get_option( 'title' );
		$this->description     = $this->get_option( 'description' );
		$this->secret_key      = $this->debug == 'yes' ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'live_secret_key' );
		$this->publishable_key = $this->debug == 'yes' ? $this->get_option( 'test_publishable_key' ) : $this->get_option( 'live_publishable_key' );
		$this->checkout_style  = $this->get_option( 'checkout_style' );
		$this->checkout_image  = $this->get_option( 'checkout_image' );

		// Save gateway settings
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options'
		) );

		add_action( 'wp_enqueue_scripts', array( &$this, 'enqueue_scripts' ) );


		// Get admin notices and disable payment gateway if any
		$this->notices = $this->get_notices();

		// Disable payments and show admin notices if something is wrong with settings
		if ( $this->enabled == 'yes' && ! empty( $this->notices ) ) {

			// Show admin notices
			/*if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset( $_POST['subscriptio_stripe_test_secret_key'] ) ) {
				add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
			}*/

			// Disable payments
			if ( count( $this->notices ) > 1 || ! isset( $this->notices['ssl'] ) ) {
				$this->enabled = 'no';
			}
		}
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'satchel-stripe', \WP_Satchel\paypal_stripe_subscription()->plugin_url . 'assets/main.js' );
		wp_enqueue_style( 'satchel-stripe', \WP_Satchel\paypal_stripe_subscription()->plugin_url . 'assets/style.css' );
	}

	public function process_payment( $order_id ) {
		$order = get_post( $order_id );
		if ( ! is_object( $order ) ) {
			wc_add_notice( '', __( "Order doesn't exist.", "stppst" ) );

			return;
		}

		$order = wc_get_order( $order );
		\Stripe\Stripe::setApiKey( $this->secret_key );
		$token = $_POST['stripeToken'];
		if ( is_user_logged_in() ) {
			$cus_id = get_user_meta( get_current_user_id(), 'satchel_stripe_userid', true );
			try {
				$customer = Customer::retrieve( $cus_id );
				//this should be new card, or resuse card
				if ( ! isset( $_POST['satchel_stripe_card_list'] ) || empty( $_POST['satchel_stripe_card_list'] ) ) {
					//nothing check, return
					wc_add_notice( '', __( "You should use a already card, or add one.", "stppst" ) );

					return;
				}
			} catch ( \Exception $e ) {
				$customer = \Stripe\Customer::create( array(
						"source"      => $token,
						"description" => "Create customer"
					)
				);
				update_user_meta( get_current_user_id(), 'satchel_stripe_userid', $customer->id );
			}
			try {
				if ( $_POST['satchel_stripe_card_list'] == 'create_new_card' ) {
					$card_id = $customer->sources->create( array(
						'source' => $token
					) );
					$card_id = $card_id->id;
				} else {
					$card_id = $_POST['satchel_stripe_card_list'];
				}

				$opts = array(
					"amount"   => $order->get_total() * 100, // Amount in cents
					"currency" => $order->get_order_currency(),
					"customer" => $customer->id,
				);
				if ( isset( $card_id ) ) {
					$opts['source'] = $card_id;
				}
				$charge = \Stripe\Charge::create( $opts );
			} catch ( \Stripe\Error\Card $ex ) {
				wc_add_notice( __( 'Payment error:', 'woothemes' ) . $ex->getMessage(), 'error' );

				return;
			}
		} else {
			try {
				$charge = \Stripe\Charge::create( array(
					"amount"   => $order->get_total() * 100, // Amount in cents
					"currency" => $order->get_order_currency(),
					"source"   => $token,
				) );
			} catch ( \Stripe\Error\Card $ex ) {
				wc_add_notice( __( 'Payment error:', 'woothemes' ) . $ex->getMessage(), 'error' );

				return;
			}
		}

		update_post_meta( $order_id, 'stripe_charge_id', $charge->id );
		if ( $charge->captured ) {
			$order->add_order_note( sprintf( __( 'Stripe charge %s captured.', 'stppst' ), $charge->id ) );
			$order->payment_complete();
			if ( is_user_logged_in() ) {
				$models = Subscription::find_subscription_by_order( $order->id );
				foreach ( $models as $model ) {
					$model->status        = Subscription::STATUS_ACTIVE;
					$model->last_pay_date = time();
					$model->add_log( __( "Payment Received by Stripe", "stppst" ),
						"payment_received",
						time() );
					$model->save();
					//create a plan in stripe
					$product = wc_get_product( $model->product_id );
					$plan_id = md5( $model->id . '_' . site_url() );
					try {
						$plan = Plan::retrieve( $plan_id );
					} catch ( \Exception $e ) {
						$plan = Plan::create( array(
							'id'             => md5( $model->id . '_' . site_url() ),
							'amount'         => $model->price * 100,
							'currency'       => $order->get_order_currency(),
							'interval'       => $model->period_time_unit,
							'interval_count' => $model->period,
							'name'           => $product->get_title()
						) );
					}
					//now sub this customer to taht plan
					\Stripe\Subscription::create( array(
						'customer' => $customer->id,
						'plan'     => $plan->id
					) );
				}
			}
		} else {
			$order->add_order_note( sprintf( __( 'Stripe charge %s authorized and will be charged as soon as you start processing this order. Authorization will expire in 7 days.', 'stppst-stripe' ), $charge->id ) );
			$order->update_status( 'on-hold' );
			$order->reduce_order_stock();
		}
		// Empty cart
		WC()->cart->empty_cart();

		// Redirect user
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}


	public function payment_fields() {
		?>
		<script type="text/javascript" src="https://js.stripe.com/v2/"></script>
		<script type="text/javascript">
			Stripe.setPublishableKey('<?php echo $this->publishable_key ?>');
		</script>
		<?php
		if ( is_user_logged_in() ) {
			$cus_id = get_user_meta( get_current_user_id(), 'satchel_stripe_userid', true );
			if ( $cus_id ) {
				\Stripe\Stripe::setApiKey( $this->secret_key );
				try {
					$customer = Customer::retrieve(
						array(
							"id"     => $cus_id,
							"expand" => array( "default_source" )
						) );
					$cards    = $customer->sources->data;
					?>
					<div class="stripe_cards">
						<?php foreach ( $cards as $card ): ?>
							<div>
								<input type="radio" name="satchel_stripe_card_list"
								       value="<?php echo $card->id ?>">
								<label><?php printf( __( "%s ending with %s (expires %s/%s)", "stppst" ), $card->brand, $card->last4, $card->exp_month, $card->exp_year ) ?></label>
							</div>
						<?php endforeach; ?>
						<div>
							<input type="radio" name="satchel_stripe_card_list" class="create_new_card"
							       value="create_new_card">
							<label><?php _e( "Create new card", "stppst" ) ?></label>
						</div>
					</div>
					<div class="card_form satchel-hide">
						<div class="form-row">
							<label>
								<span>Card Number</span>
								<input type="text" size="20" data-stripe="number">
							</label>
						</div>

						<div class="form-row">
							<label>
								<span>Expiration (MM/YY)</span>
								<input type="text" size="2" data-stripe="exp_month">
							</label>
							<span> / </span>
							<input type="text" size="2" data-stripe="exp_year">
						</div>

						<div class="form-row">
							<label>
								<span>CVC</span>
								<input type="text" size="4" data-stripe="cvc">
							</label>
						</div>
					</div>
					<?php
				} catch ( \Exception $e ) {

				}
			}
		} else {
			?>
			<div class="card_form">
				<div class="form-row">
					<label>
						<span>Card Number</span>
						<input type="text" size="20" data-stripe="number">
					</label>
				</div>

				<div class="form-row">
					<label>
						<span>Expiration (MM/YY)</span>
						<input type="text" size="2" data-stripe="exp_month">
					</label>
					<span> / </span>
					<input type="text" size="2" data-stripe="exp_year">
				</div>

				<div class="form-row">
					<label>
						<span>CVC</span>
						<input type="text" size="4" data-stripe="cvc">
					</label>
				</div>
			</div>
			<?php
		}
	}

	public function get_notices() {
		$notices = array();


		// Check for SSL support
		if ( get_option( 'woocommerce_force_ssl_checkout' ) != 'yes' && ! class_exists( 'WordPressHTTPS' ) && ! is_ssl() ) {
			$notices['ssl'] = __( 'Subscriptio Stripe payment gateway requires full SSL support and enforcement during Checkout. Only test mode will work until this is solved.', 'stppst' );
		}

		// Check secret key
		if ( empty( $this->secret_key ) ) {
			$notices['secret'] = __( 'Subscriptio Stripe payment gateway requires Stripe Secret Key to be set.', 'stppst' );
		}

		// Check publishable key
		if ( empty( $this->publishable_key ) ) {
			$notices['publishable'] = __( 'Subscriptio Stripe payment gateway requires Stripe Publishable Key to be set.', 'stppst' );
		}

		return $notices;
	}


	/**
	 * Initialize form fields
	 *
	 * @access public
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'              => array(
				'title'   => __( 'Enable/Disable', 'stppst' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Stripe payment gateway', 'stppst' ),
				'default' => 'no',
			),
			'debug'                => array(
				'title'   => __( 'Test Mode', 'stppst' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable test & debug mode', 'stppst' ),
				'default' => 'no',
			),
			'capture'              => array(
				'title'       => __( 'Capture Immediately', 'stppst' ),
				'type'        => 'checkbox',
				'label'       => __( 'Capture the charge immediately', 'stppst' ),
				'description' => __( 'If unchecked, an authorization is issued at the time of purchase but the charge itself is captured when you start processed the order. Uncaptured charges expire in 7 days.', 'stppst' ),
				'default'     => 'yes',
			),
			'title'                => array(
				'title'       => __( 'Title', 'stppst' ),
				'type'        => 'text',
				'description' => __( 'The title which the user sees during checkout.', 'stppst' ),
				'default'     => __( 'Credit Card - Stripe', 'stppst' ),
			),
			'description'          => array(
				'title'       => __( 'Description', 'stppst' ),
				'type'        => 'textarea',
				'description' => __( 'The description which the user sees during checkout.', 'stppst' ),
				'default'     => __( 'Pay Securely via Stripe', 'stppst' ),
			),
			'checkout_style'       => array(
				'title'       => __( 'Checkout Style', 'stppst' ),
				'type'        => 'select',
				'description' => __( 'Control how credit card details fields appear on your page.', 'stppst' ),
				'default'     => 'inline',
				'options'     => array(
					'inline' => __( 'Inline', 'stppst' ),
					'modal'  => __( 'Modal', 'stppst' ),
				),
			),
			'checkout_image'       => array(
				'title'       => __( 'Checkout Image', 'stppst' ),
				'type'        => 'text',
				'description' => __( 'Stripe Checkout modal allows custom seller image to be displayed. Enter your custom 128x128 px image URL here (should be hosted on a secure location).', 'stppst' ),
				'default'     => '',
			),
			'test_secret_key'      => array(
				'title'       => __( 'Test Secret Key', 'stppst' ),
				'type'        => 'text',
				'description' => __( 'Test Secret Key from your Stripe Account (under Account Settings > API Keys).', 'stppst' ),
				'default'     => '',
			),
			'test_publishable_key' => array(
				'title'       => __( 'Test Publishable Key', 'stppst' ),
				'type'        => 'text',
				'description' => __( 'Test Publishable Key from your Stripe Account.', 'stppst' ),
				'default'     => '',
			),
			'live_secret_key'      => array(
				'title'       => __( 'Live Secret Key', 'stppst' ),
				'type'        => 'text',
				'description' => __( 'Live Secret Key from your Stripe Account.', 'stppst' ),
				'default'     => '',
			),
			'live_publishable_key' => array(
				'title'       => __( 'Live Publishable Key', 'stppst' ),
				'type'        => 'text',
				'description' => __( 'Live Publishable Key from your Stripe Account.', 'stppst' ),
				'default'     => '',
			),
		);
	}

	/**
	 * Init settings for gateways.
	 */
	public function init_settings() {
		parent::init_settings();
		$this->enabled = ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] ? 'yes' : 'no';
	}
}