<?php

namespace WP_Satchel\Includes\Admin;

use Monolog\Handler\Curl\Util;
use WP_Satchel\Includes\Gateways\Paypal;
use WP_Satchel\Includes\Gateways\Paypal_IPN;
use WP_Satchel\Includes\Model\Subscription;
use WP_Satchel\Includes\Utils;

class Product_Admin {
	private $ipn;
	public $query_vars = array(
		'st-view-subscription',
		'st-subscription-address',
		'st-pause-subscription',
		'stresume-subscription',
		'stcancel-subscription',
	);

	public function __construct() {
		add_action( 'product_type_options', array( &$this, 'product_type_options' ) );
		add_action( 'woocommerce_process_product_meta_simple', array(
			&$this,
			'woocommerce_process_product_meta_simple'
		) );
		add_action( 'woocommerce_product_options_general_product_data', array( &$this, 'append_product_options' ) );
		add_action( 'woocommerce_process_product_meta_variable', array( &$this, 'process_variant_variable' ) );
		add_action( 'woocommerce_ajax_save_product_variations', array( &$this, 'process_variant_variable' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( &$this, 'variable_attributes' ), 10, 3 );
		add_action( 'woocommerce_variation_options', array( &$this, 'append_variant_type' ), 10, 3 );
		add_action( 'woocommerce_checkout_update_order_meta', array( &$this, 'create_subscription' ) );
		$this->ipn = new Paypal_IPN();
		add_action( 'add_meta_boxes', array( &$this, 'subscription_meta_boxes' ), 10, 2 );

		//price
		add_filter( 'woocommerce_price_html', array( &$this, 'product_price_html' ), 10, 2 );
		add_filter( 'woocommerce_get_variation_price_html', array( $this, 'variant_price_html' ), 10, 2 );
		add_filter( 'woocommerce_sale_price_html', array( &$this, 'product_sale_price_html' ), 10, 2 );
		add_filter( 'woocommerce_free_price_html', array( &$this, 'free_price' ), 10, 2 );
		add_action( 'woocommerce_get_price', array( &$this, 'product_raw_price' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_price', array( &$this, 'cart_item_price' ), 10, 3 );
		add_filter( 'woocommerce_cart_product_subtotal', array( &$this, 'product_subtotal' ), 10, 4 );
		//order checkout
		add_action( 'woocommerce_order_details_after_order_table', array(
			&$this,
			'display_subscription_order_checkout'
		) );
		//myaccount
		add_action( 'woocommerce_account_dashboard', array( &$this, 'display_my_subscriptions' ) );
		add_shortcode( 'ws_show_user_subscription', array( &$this, 'ws_show_user_subscription' ) );
		add_action( 'wp_loaded', array( &$this, 'check_if_sub_expired' ) );
	}

	public function variant_price_html( $price, \WC_Product_Variation $variant ) {
		if ( is_admin() ) {
			return $price;
		}
		if ( Utils::is_product_subscription( $variant->get_variation_id() ) ) {
			$data = Utils::get_sub_data( $variant->get_variation_id() );

			return $data['string'];
		}

		return $price;
	}

	public function variable_attributes( $loop, $variation_data, $variation ) {
		$product_id           = $variation->ID;
		$period               = get_post_meta( $product_id, '_wpsatchel_period', true );
		$price_time_unit      = get_post_meta( $product_id, '_wpsatchel_price_time_unit', true );
		$free_trial           = get_post_meta( $product_id, '_wpsatchel_free_trial', true );
		$trial_time_unit      = get_post_meta( $product_id, '_wpsatchel_trial_time_unit', true );
		$max_length           = get_post_meta( $product_id, '_wpsatchel_max_length', true );
		$max_length_time_unit = get_post_meta( $product_id, '_wpsatchel_max_length_time_unit', true );
		$signup_fee           = get_post_meta( $product_id, '_wpsatchel_singup_fee', true );

		$is_enabled = get_post_meta( $product_id, '_wps_subscription', true );
		?>
		<div class="ws">
			<div class="wpsatchel-product <?php echo $is_enabled == 'yes' ? null : 'satchel-hide' ?>">
				<div class="group">
					<div class="col span_2_of_12">
						<label><?php _e( 'Price is per', 'stppst' ); ?></label>
					</div>
					<div class="col span_4_of_12">
						<input type="text" class="input-text"
						       id="_wpsatchel_period" name="_wpsatchel_period[<?php echo $loop ?>]"
						       placeholder="<?php _e( 'e . g . 7', 'stppst' ); ?>"
						       value="<?php echo $period ?>">
					</div>
					<div class="col span_4_of_12">
						<select id="_wpsatchel_price_time_unit" name="_wpsatchel_price_time_unit[<?php echo $loop ?>]"
						        class="select" style="margin-left: 3px">
							<?php foreach ( Utils::get_time_units() as $key => $val ): ?>
								<option <?php selected( $price_time_unit, $key ) ?>
									value="<?php echo $key ?>"><?php echo $val ?></option>
							<?php endforeach;; ?>
						</select>
					</div>
				</div>
				<div class="group">
					<div class="col span_2_of_12">
						<label><?php _e( 'Free trial', 'stppst' ); ?></label>
					</div>
					<div class="col span_4_of_12">
						<input type="text" class="input-text"
						       id="_wpsatchel_free_trial" name="_wpsatchel_free_trial[<?php echo $loop ?>]"
						       placeholder="<?php _e( 'e . g . 7', 'stppst' ); ?>"
						       value="<?php echo $free_trial ?>">
					</div>
					<div class="col span_4_of_12">
						<select id="_wpsatchel_trial_time_unit" name="_wpsatchel_trial_time_unit[<?php echo $loop ?>]"
						        class="select" style="margin-left: 3px">
							<?php foreach ( Utils::get_time_units() as $key => $val ): ?>
								<option <?php selected( $trial_time_unit, $key ) ?>
									value="<?php echo $key ?>"><?php echo $val ?></option>
							<?php endforeach;; ?>
						</select>
					</div>
				</div>
				<div class="group">
					<div class="col span_2_of_12">
						<label><?php _e( 'Signup Fee', 'stppst' ); ?> (<?php echo get_woocommerce_currency_symbol() ?>
							)</label>
					</div>
					<div class="col span_4_of_12">
						<input type="text" class="input-text"
						       id="_wpsatchel_singup_fee" name="_wpsatchel_singup_fee[<?php echo $loop ?>]"
						       placeholder="<?php _e( 'e . g . 10', 'stppst' ); ?>"
						       value="<?php echo $signup_fee ?>">
					</div>
				</div>
				<div class="group">
					<div class="col span_2_of_12">
						<label><?php _e( 'Max Length', 'stppst' ); ?></label>
					</div>
					<div class="col span_4_of_12">
						<input type="text" class="input-text"
						       id="_wpsatchel_max_length" name="_wpsatchel_max_length[<?php echo $loop ?>]"
						       placeholder="<?php _e( 'e . g . 7', 'stppst' ); ?>"
						       value="<?php echo $max_length ?>">
					</div>
					<div class="col span_4_of_12">
						<select id="_wpsatchel_max_length_time_unit"
						        name="_wpsatchel_max_length_time_unit[<?php echo $loop ?>]"
						        class="select" style="margin-left: 3px">
							<?php foreach ( Utils::get_time_units() as $key => $val ): ?>
								<option <?php selected( $max_length_time_unit, $key ) ?>
									value="<?php echo $key ?>"><?php echo $val ?></option>
							<?php endforeach;; ?>
						</select>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function process_variant_variable( $product_id ) {
		// Check if post id is set
		if ( ! isset( $_POST['variable_post_id'] ) ) {
			return;
		}

		$all_ids = $_POST['variable_post_id'];

		foreach ( $all_ids as $k => $id ) {
			$product_id = $id;
			if ( ! isset( $_POST['_wps_subscription'][ $k ] ) ) {
				update_post_meta( $product_id, '_wps_subscription', 'no' );
			} else {
				update_post_meta( $product_id, '_wps_subscription', 'yes' );
				$price_period    = isset( $_POST['_wpsatchel_period'][ $k ] ) ? $_POST['_wpsatchel_period'][ $k ] : null;
				$price_time_unit = isset( $_POST['_wpsatchel_price_time_unit'][ $k ] ) ? $_POST['_wpsatchel_price_time_unit'][ $k ] : null;
				$free_trial      = isset( $_POST['_wpsatchel_free_trial'][ $k ] ) ? $_POST['_wpsatchel_free_trial'][ $k ] : null;
				$trial_time_unit = isset( $_POST['_wpsatchel_trial_time_unit'][ $k ] ) ? $_POST['_wpsatchel_trial_time_unit'][ $k ] : null;
				$max_length      = isset( $_POST['_wpsatchel_max_length'][ $k ] ) ? $_POST['_wpsatchel_max_length'][ $k ] : null;
				$max_length_unit = isset( $_POST['_wpsatchel_trial_time_unit'] [ $k ] ) ? $_POST['_wpsatchel_max_length_time_unit'][ $k ] : null;
				$signup_fee      = isset( $_POST['_wpsatchel_singup_fee'][ $k ] ) ? $_POST['_wpsatchel_singup_fee'][ $k ] : 0;
				update_post_meta( $product_id, '_wpsatchel_period', $price_period );
				update_post_meta( $product_id, '_wpsatchel_price_time_unit', $price_time_unit );
				update_post_meta( $product_id, '_wpsatchel_free_trial', $free_trial );
				update_post_meta( $product_id, '_wpsatchel_trial_time_unit', $trial_time_unit );
				update_post_meta( $product_id, '_wpsatchel_max_length', $max_length );
				update_post_meta( $product_id, '_wpsatchel_max_length_time_unit', $max_length_unit );
				update_post_meta( $product_id, '_wpsatchel_singup_fee', $signup_fee );
			}
		}
	}

	public function append_variant_type( $loop, $variation_data, $variation ) {
		echo '<label><input type="checkbox" id="_wps_subscription" class="checkbox" name="_wps_subscription[' . $loop . ']" ' . checked( Utils::is_product_subscription( $variation->ID ), true, false ) . ' /> ' . __( 'Subscription', 'stppst' ) . ' <a class="tips" data-tip="' . __( 'Sell this variable product as a subscription product with recurring billing.', 'stppst' ) . '" href="#">[?]</a></label>';
	}

	public function check_if_sub_expired() {
		if ( is_admin() ) {
			return;
		}
		$models = Subscription::find_all();
		foreach ( $models as $model ) {
			if ( $model->status != Subscription::STATUS_ACTIVE ) {
				continue;
			}

			$due_date = $model->get_due_date( false );
			if ( strtotime( '-1 day', $due_date ) <= time() && $model->order_due_date_id == null ) {
				$old_order = $model->get_last_order();
				if ( ! is_object( $old_order ) ) {
					continue;
				}

				//we create an order for invoice
				$order                    = wc_create_order( array(
					'customer_id' => $old_order->get_user_id()
				) );
				$model->order_due_date_id = $order->id;
				//update order data
				$product = wc_get_product( $model->product_id );
				$cart    = new \WC_Cart();
				$cart->add_to_cart( $product->id, 1 );
				foreach ( $cart->get_cart() as $cart_item_key => $values ) {
					$order->add_product(
						$values['data'],
						$values['quantity'],
						array(
							'variation' => $values['variation'],
							'totals'    => array(
								'subtotal'     => $values['line_subtotal'],
								'subtotal_tax' => $values['line_subtotal_tax'],
								'total'        => $values['line_total'],
								'tax'          => $values['line_tax'],
								'tax_data'     => $values['line_tax_data'] // Since 2.2
							)
						) );
				}
				$order->set_address( $old_order->get_address( 'billing' ), 'billing' );
				$order->set_address( $old_order->get_address( 'shipping' ), 'shipping' );
				$order->set_payment_method( $old_order->payment_method );
				$order->set_total( $cart->shipping_total, 'shipping' );
				$order->set_total( $cart->tax_total, 'tax' );
				$order->set_total( $cart->shipping_tax_total, 'shipping_tax' );
				$order->set_total( $cart->total );
				update_option( $order->id, 'ws_renewal', $model->id );
				$model->save();
			} elseif ( $due_date < time() ) {
				//we having an order, need to check time if it passed sp long, clsoe the sub & cancel order
				//we will fire a prepay
				if ( get_post_meta( $model->order_due_date_id, '_ws_paypal_prekey', true ) != false ) {
					$gateway = new Paypal();
					$order   = wc_get_order( $model->order_due_date_id );
					$this->ipn->create_preapporve_pay_request( $order, $gateway );
				} else {
					//todo send email, if this due date pass x day, just close order & cancel subscription

				}
			}
		}
	}

	public function ws_show_user_subscription() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		ob_start();
		$id    = Utils::http_get( 'subscription_id' );
		$model = new Subscription( $id );
		if ( $model->user_id != get_current_user_id() ) {
			return '';
		}

		if ( wp_verify_nonce( Utils::http_post( '_wpnonce' ), 'ws_cancel_subscription' ) ) {
			$model->status = Subscription::STATUS_CANCEL;
			$model->add_log( __( "Subscription cancel by user", 'stppst' ), 'cancel', time() );
			$model->save();
		}

		$order = $model->get_last_order();
		if ( is_wp_error( $model ) ) {
			return __( "Subscription doesn't exists", "stppst" );
		}
		?>
		<div class="woocommerce ws-my-sub">
			<?php woocommerce_account_navigation() ?>
			<div class="woocommerce-MyAccount-content">
				<p><?php
					printf(
						__( 'Subscription #%1$s was started on %2$s and is currently %3$s.', 'woocommerce' ),
						'<mark class="order-number">' . $model->id . '</mark>',
						'<mark class="order-date">' . date_i18n( get_option( 'date_format' ), $model->start_date ) . '</mark>',
						'<mark class="order-status">' . $model->get_status() . '</mark>'
					);
					?></p>
				<h2><?php _e( "Subscription Detail", "" ) ?></h2>
				<dl>
					<dt><?php _e( "Recurring Amount:", "stppst" ) ?></dt>
					<dd> <?php echo $model->get_recurring() ?>
					</dd>

					<dt><?php _e( "Payment Method:", "stppst" ) ?></dt>
					<dd>
						<?php
						if ( $model->status == Subscription::STATUS_TRIAL ) {
							echo __( "Trial", "stppst" );
						} else {
							echo $order->payment_method_title;
						}
						?>
					</dd>
					<dt><?php _e( "Payment Due:", "stppst" ) ?></dt>
					<dd><?php echo $model->get_due_date() ?></dd>
					<?php if ( $model->get_status() == 'active' ): ?>
						<dt><?php _e( "Actions:", "stppst" ) ?></dt>
						<dd>
							<!--<a href="http://sugarcoder.org/my-account/pause-subscription/356"
							   class="button"><?php /*_e( "Pause Subscription", "stppst" ) */ ?></a>-->
							<form method="post">
								<?php wp_nonce_field( 'ws_cancel_subscription' ) ?>
								<input type="hidden" name="id" value="<?php echo $model->id ?>"/>
								<button class="button"
								        type="submit"><?php _e( "Cancel Subscription", "stppst" ) ?></button>
							</form>
						</dd>
					<?php endif; ?>
				</dl>
				<h2><?php _e( "Related Orders", "stppst" ) ?></h2>
				<table class="shop_table order_details">
					<thead>
					<tr>
						<th><?php _e( "Order", "stppst" ) ?></th>
						<th><?php _e( "Date", "stppst" ) ?></th>
						<th><?php _e( "Status", "stppst" ) ?></th>
						<th><?php _e( "Total", "stppst" ) ?></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $model->order_ids as $oid ): ?>
						<?php $order = wc_get_order( $oid ) ?>
						<?php if ( is_object( $order ) ): ?>
							<tr>
								<td>
									<a href="<?php wc_get_page_permalink( 'myaccount' ) ?>view-order/<?php echo $oid ?>">#<?php echo $oid ?></a>
								</td>
								<td><?php echo date_i18n( Utils::get_datetime_format(), strtotime( $order->order_date ) ) ?></td>
								<td><?php echo ucfirst( $order->get_status() ) ?></td>
								<td><?php echo $order->get_formatted_order_total() ?></td>
							</tr>
						<?php endif; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function display_my_subscriptions() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$subs = Subscription::get_by_user( get_current_user_id() );
		if ( count( $subs ) ) {
			?>
			<h2><?php _e( "My Subscriptions", "stppst" ) ?></h2>
			<table class="shop_table order_details">
				<thead>
				<tr>
					<th><?php _e( "ID", "stppst" ) ?></th>
					<th><?php _e( "Status", "stppst" ) ?></th>
					<th><?php _e( "Product(s)", "stppst" ) ?></th>
					<th><?php _e( "Recurring", "stppst" ) ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $subs as $sub ): ?>
					<tr>
						<td>
							<a href="<?php echo add_query_arg( array(
								'subscription_id' => $sub->id
							), get_permalink( Utils::get_view_subscription_page() ) ) ?>">#<?php echo $sub->id ?></a>
						</td>
						<td><?php echo $sub->get_status() ?></td>
						<td><?php echo $sub->get_products() ?></td>
						<td><?php echo $sub->get_recurring() ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}
	}

	public function display_subscription_order_checkout( \WC_Order $order ) {
		$subs = Subscription::find_subscription_by_order( $order->id );
		if ( count( $subs ) ) {
			?>
			<h2><?php _e( "Related Subscriptions", "stppst" ) ?></h2>
			<table class="shop_table order_details">
				<thead>
				<tr>
					<th><?php _e( "ID", "stppst" ) ?></th>
					<th><?php _e( "Status", "stppst" ) ?></th>
					<th><?php _e( "Product(s)", "stppst" ) ?></th>
					<th><?php _e( "Recurring", "stppst" ) ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $subs as $sub ): ?>
					<tr>
						<td>
							<a href="<?php echo add_query_arg( array(
								'subscription_id' => $sub->id
							), get_permalink( Utils::get_view_subscription_page() ) ) ?>">#<?php echo $sub->id ?></a>
						</td>
						<td><?php echo $sub->get_status() ?></td>
						<td><?php echo $sub->get_products() ?></td>
						<td><?php echo $sub->get_recurring() ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}
	}

	public function product_price_html( $price, $product ) {
		if ( is_admin() ) {
			return $price;
		}

		if ( Utils::is_product_subscription( $product->id ) ) {
			$data = Utils::get_sub_data( $product->id );

			return $data['string'];
		}

		return $price;
	}

	public function product_sale_price_html( $price, \WC_Product $product ) {
		if ( is_admin() ) {
			return $price;
		}

		if ( Utils::is_product_subscription( $product->id ) ) {
			$data                  = Utils::get_sub_data( $product->id );
			$display_regular_price = $product->get_display_price( $product->get_regular_price() );

			return $product->get_price_html_from_to( $display_regular_price, $data['string'] );
		}

		return $price;
	}

	public function free_price( $price, $product ) {
		if ( is_admin() ) {
			return $price;
		}

		if ( Utils::is_product_subscription( $product->id ) ) {
			$data = Utils::get_sub_data( $product->id );

			return $data['string'];
		}

		return $price;
	}

	public function product_subtotal( $product_subtotal, $_product, $quantity, $cart ) {
		if ( Utils::is_product_subscription( $_product->id ) ) {
			$data = Utils::get_sub_data( $_product->id );
			if ( $data['free_trial'] > 0 && $data['signup_fee'] > 0 ) {
				return sprintf( __( "%s now, then %s / %s", "stppst" ), wc_price( $_product->get_price() ), wc_price( $_product->price ), $data['period'] . ' ' . Utils::get_unit( $_product->id ) );
			} elseif ( $data['free_trial'] > 0 ) {
				return sprintf( __( "%s now, then %s / %s", "stppst" ), wc_price( 0 ), wc_price( $_product->price ), $data['period'] . ' ' . Utils::get_unit( $_product->id ) );
			} elseif ( $data['signup_fee'] > 0 ) {
				return sprintf( __( "%s now, then %s / %s", "stppst" ), wc_price( $_product->get_price() ), wc_price( $_product->price ), $data['period'] . ' ' . Utils::get_unit( $_product->id ) );
			} else {
				return sprintf( __( "%s / %s", "stppst" ), wc_price( $_product->price ), Utils::get_period_string( $_product->id ) );
			}
		}

		return $product_subtotal;
	}

	public function product_raw_price( $price, \WC_Product $product ) {
		if ( is_admin() ) {
			return $price;
		}

		if ( Utils::is_product_subscription( $product->id ) ) {
			$data     = Utils::get_sub_data( $product->id );
			$addition = 0;
			if ( $data['signup_fee'] > 0 ) {
				$addition += $data['signup_fee'];
			}

			if ( $data['free_trial'] > 0 ) {
				return 0 + $addition;
			} else {
				$price += $addition;
			}
		}

		return $price;
	}

	public function subscription_meta_boxes( $post_type, $post ) {
		if ( $post_type != 'satchel_subscription' ) {
			return;
		}
		add_meta_box(
			'subscription - info',
			__( 'Subscription Info' ),
			function () use ( $post ) {
				$model = new Subscription( $post->ID );
				?>
				<div class="ws">
					<h3><?php printf( __( "Subscription #%s", "stppst" ), $post->ID ) ?></h3>
					<span><?php echo wc_price( $model->price ) ?>
						/ <?php echo $model->period . ' ' . $model->period_time_unit ?></span>
					<div class="group">
						<div class="col span_4_of_12">
							<h3><?php _e( "General Details", "stppst" ) ?></h3>
							<div>
								<strong><?php _e( "Started:", "stppst" ) ?></strong>
								<?php echo date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $post->post_date ) ) ?>
							</div>
							<div>
								<strong><?php _e( "Status", "stppst" ) ?></strong>
								<?php echo $model->get_status() ?>
							</div>
							<div>
								<strong><?php _e( "Payment Method:", "stppst" ) ?></strong>
								<?php
								$first_order = $model->order_ids[0];
								$order       = wc_get_order( $first_order );
								echo $order->payment_method_title;
								?>
							</div>
							<div>
								<strong><?php _e( "Shipping Method:", "stppst" ) ?></strong>
								<?php
								echo $order->get_shipping_method();
								?>
							</div>
							<div>
								<strong><?php _e( "Related Orders:", "stppst" ) ?></strong>
								<?php
								$ids = array();
								foreach ( $model->order_ids as $oid ) {
									$ids[] = sprintf( ' <a href = "%s" >%s </a> ', admin_url( "post.php?post=" . $oid . "&action=edit" ), "#" . $oid );
								}
								echo implode( ', ', $ids );
								?>
							</div>
						</div>
						<div class="col span_4_of_12">
							<h3><?php _e( "Customer Detail", "stppst" ) ?></h3>
							<div>
								<strong><?php _e( "Name:", "stppst" ) ?></strong>
								<?php echo $order->get_formatted_billing_full_name() ?>
							</div>
							<div>
								<strong><?php _e( "Email:", "stppst" ) ?></strong>
								<?php
								echo $order->billing_email;
								?>
							</div>
							<div>
								<strong><?php _e( "Phone:", "stppst" ) ?></strong>
								<?php
								echo $order->billing_phone;
								?>
							</div>
						</div>
						<div class="col span_4_of_12">
							<h3><?php _e( "Scheduled Events", "stppst" ) ?></h3>
							<div><strong><?php _e( "Payment Due:", "stppst" ) ?></strong>
								<?php
								echo $model->get_due_date( true );
								?></div>
							<div>
								<strong><?php _e( "Expiration::", "stppst" ) ?></strong>
								<?php
								echo $model->get_expired_date( true );
								?>
							</div>
						</div>
					</div>
				</div>
				<?php
			},
			'satchel_subscription',
			'normal',
			'default'
		);

		add_meta_box(
			'subscription - item',
			__( 'Subscription Info' ),
			function () use ( $post ) {
				$model       = new Subscription( $post->ID );
				$first_order = $model->order_ids[0];
				$order       = wc_get_order( $first_order );
				?>
				<table class="widefat">
					<thead>
					<tr>
						<th><?php _e( "Item", "stppst" ) ?></th>
						<th><?php _e( "QTY", "stppst" ) ?></th>
						<th><?php _e( "Total", "stppst" ) ?></th>
						<th><?php _e( "Tax", "stppst" ) ?></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $order->get_items() as $item ): ?>
						<?php
						$product = $order->get_product_from_item( $item );
						?>
						<tr>
							<td>
								<a href="<?php echo get_edit_post_link( $product->id ) ?>"><?php echo $product->get_title() ?></a>
							</td>
							<td>
								<?php
								echo $item['qty'];
								?>
							</td>
							<td>
								<?php echo $order->get_item_subtotal( $item ) ?>
							</td>
							<td>
								<?php echo $order->get_item_tax( $item ) ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php
			},
			'satchel_subscription',
			'normal',
			'default'
		);
	}

	/**
	 * @param $price
	 * @param $cart_item
	 * @param $cart_item_key
	 *
	 * @return mixed
	 */
	public function cart_item_price( $price, $cart_item, $cart_item_key ) {
		if ( isset( $cart_item['variation_id'] ) && ! empty( $cart_item['variation_id'] ) && Utils::is_product_subscription( $cart_item['variation_id'] ) ) {
			$sub = Utils::get_sub_data( $cart_item['variation_id'] );

			return $sub['string'];
		} elseif ( Utils::is_product_subscription( $cart_item['product_id'] ) ) {
			$sub = Utils::get_sub_data( $cart_item['product_id'] );

			return $sub['string'];
		}

		return $price;
	}

	public function create_subscription( $order_id ) {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( get_post_meta( $order_id, 'ws_renewal', false ) != false ) {
			//case renewal
		} else {
			$order = wc_get_order( $order_id );
			$model = Subscription::find_subscription_by_order( $order_id );
			if ( ! empty( $model ) ) {
				//alread created
				return;
			}
			$items = $order->get_items( 'line_item' );
			foreach ( $items as $item ) {
				$product = $order->get_product_from_item( $item );
				$id      = $product->id;
				if ( $product instanceof \WC_Product_Variation ) {
					$id = $product->variation_id;
				}
				if ( Utils::is_product_subscription( $id ) ) {
					$data = Utils::get_sub_data( $id );
					//$product                     = wc_get_product( $id );
					$model                       = new Subscription();
					$model->order_id             = $order_id;
					$model->free_trial           = $data['free_trial'];
					$model->free_trial_time_unit = $data['free_trial_time_unit'];
					$model->max_length           = $data['max_length'];
					$model->max_length_time_unit = $data['max_length_time_unit'];
					$model->period               = $data['period'];
					$model->period_time_unit     = $data['period_time_unit'];
					$model->signup_fee           = $data['signup_fee'];
					$model->status               = $data['free_trial'] > 0 ? Subscription::STATUS_TRIAL : Subscription::STATUS_PENDING;
					$model->price                = $product->price;
					$model->product_id           = $product->id;
					$model->start_date           = time();
					$model->user_id              = $order->get_user_id();
					$model->save();
				}
			}
		}
	}

	public function append_product_options() {
		$product_id = get_the_ID();

		$period               = get_post_meta( $product_id, '_wpsatchel_period', true );
		$price_time_unit      = get_post_meta( $product_id, '_wpsatchel_price_time_unit', true );
		$free_trial           = get_post_meta( $product_id, '_wpsatchel_free_trial', true );
		$trial_time_unit      = get_post_meta( $product_id, '_wpsatchel_trial_time_unit', true );
		$max_length           = get_post_meta( $product_id, '_wpsatchel_max_length', true );
		$max_length_time_unit = get_post_meta( $product_id, '_wpsatchel_max_length_time_unit', true );
		$signup_fee           = get_post_meta( $product_id, '_wpsatchel_singup_fee', true );

		$is_enabled = get_post_meta( $product_id, '_wps_subscription', true );
		?>
		<div class="wpsatchel-product <?php echo $is_enabled == 'yes' ? null : 'satchel-hide' ?>">
			<p class="form-field">
				<label><?php _e( 'Price is per', 'stppst' ); ?></label>
				<input type="text" class="input-text"
				       id="_wpsatchel_period" name="_wpsatchel_period"
				       placeholder="<?php _e( 'e . g . 7', 'stppst' ); ?>"
				       value="<?php echo $period ?>">
				<select id="_wpsatchel_price_time_unit" name="_wpsatchel_price_time_unit"
				        class="select" style="margin-left: 3px">
					<?php foreach ( Utils::get_time_units() as $key => $val ): ?>
						<option <?php selected( $price_time_unit, $key ) ?>
							value="<?php echo $key ?>"><?php echo $val ?></option>
					<?php endforeach;; ?>
				</select>
			</p>
			<p class="form-field">
				<label><?php _e( 'Free trial', 'stppst' ); ?></label>
				<input type="text" class="input-text"
				       id="_wpsatchel_free_trial" name="_wpsatchel_free_trial"
				       placeholder="<?php _e( 'e . g . 7', 'stppst' ); ?>"
				       value="<?php echo $free_trial ?>">
				<select id="_wpsatchel_trial_time_unit" name="_wpsatchel_trial_time_unit"
				        class="select" style="margin-left: 3px">
					<?php foreach ( Utils::get_time_units() as $key => $val ): ?>
						<option <?php selected( $trial_time_unit, $key ) ?>
							value="<?php echo $key ?>"><?php echo $val ?></option>
					<?php endforeach;; ?>
				</select>
			</p>
			<p class="form-field">
				<label><?php _e( 'Signup Fee', 'stppst' ); ?> (<?php echo get_woocommerce_currency_symbol() ?>)</label>
				<input type="text" class="input-text"
				       id="_wpsatchel_singup_fee" name="_wpsatchel_singup_fee"
				       placeholder="<?php _e( 'e . g . 10', 'stppst' ); ?>"
				       value="<?php echo $signup_fee ?>">
			</p>
			<p class="form-field">
				<label><?php _e( 'Max Length', 'stppst' ); ?></label>
				<input type="text" class="input-text"
				       id="_wpsatchel_max_length" name="_wpsatchel_max_length"
				       placeholder="<?php _e( 'e . g . 7', 'stppst' ); ?>"
				       value="<?php echo $max_length ?>">
				<select id="_wpsatchel_max_length_time_unit" name="_wpsatchel_max_length_time_unit"
				        class="select" style="margin-left: 3px">
					<?php foreach ( Utils::get_time_units() as $key => $val ): ?>
						<option <?php selected( $max_length_time_unit, $key ) ?>
							value="<?php echo $key ?>"><?php echo $val ?></option>
					<?php endforeach;; ?>
				</select>
			</p>
		</div>
		<?php
	}

	public function product_type_options( $args ) {
		$args['wps_subscription'] = array(
			'id'            => '_wps_subscription',
			'wrapper_class' => 'show_if_simple',
			'label'         => __( 'Subscription', 'stppst' ),
			'description'   => __( 'Subscription description', 'domain' ),
			'default'       => 'no'
		);

		return $args;
	}

	public function woocommerce_process_product_meta_simple( $product_id ) {
		if ( ! isset( $_POST['_wps_subscription'] ) ) {
			update_post_meta( $product_id, '_wps_subscription', 'no' );
		} else {
			update_post_meta( $product_id, '_wps_subscription', 'yes' );
			$price_period    = isset( $_POST['_wpsatchel_period'] ) ? $_POST['_wpsatchel_period'] : null;
			$price_time_unit = isset( $_POST['_wpsatchel_price_time_unit'] ) ? $_POST['_wpsatchel_price_time_unit'] : null;
			$free_trial      = isset( $_POST['_wpsatchel_free_trial'] ) ? $_POST['_wpsatchel_free_trial'] : null;
			$trial_time_unit = isset( $_POST['_wpsatchel_trial_time_unit'] ) ? $_POST['_wpsatchel_trial_time_unit'] : null;
			$max_length      = isset( $_POST['_wpsatchel_max_length'] ) ? $_POST['_wpsatchel_max_length'] : null;
			$max_length_unit = isset( $_POST['_wpsatchel_trial_time_unit'] ) ? $_POST['_wpsatchel_max_length_time_unit'] : null;
			$signup_fee      = isset( $_POST['_wpsatchel_singup_fee'] ) ? $_POST['_wpsatchel_singup_fee'] : 0;
			update_post_meta( $product_id, '_wpsatchel_period', $price_period );
			update_post_meta( $product_id, '_wpsatchel_price_time_unit', $price_time_unit );
			update_post_meta( $product_id, '_wpsatchel_free_trial', $free_trial );
			update_post_meta( $product_id, '_wpsatchel_trial_time_unit', $trial_time_unit );
			update_post_meta( $product_id, '_wpsatchel_max_length', $max_length );
			update_post_meta( $product_id, '_wpsatchel_max_length_time_unit', $max_length_unit );
			update_post_meta( $product_id, '_wpsatchel_singup_fee', $signup_fee );
		}
	}
}