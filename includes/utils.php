<?php

namespace WP_Satchel\Includes;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class Utils {
	private static $products_cache;

	public static function get_time_units() {
		return array(
			'day'    => __( "Days", "stppst" ),
			'week'  => __( "Weeks", "stppst" ),
			'month' => __( "Months", "stppst" ),
			'year'  => __( "Years", "stppst" ),
		);
	}

	public static function log( $text ) {
		$log = new Logger( 'debug' );
		$log->pushHandler( new StreamHandler( \WP_Satchel\paypal_stripe_subscription()->plugin_path . 'debug.log', Logger::WARNING ) );
		$log->warning( $text );
	}

	/**
	 * @return bool
	 */
	public static function is_availabe_for_subscription() {
		global $woocommerce;

		if ( ! empty( $woocommerce->cart->cart_contents ) ) {
			foreach ( $woocommerce->cart->cart_contents as $item ) {
				if ( isset( $item['variation_id'] ) && ! empty( $item['variation_id'] ) && get_post_meta( $item['variation_id'], '_wps_subscription', true ) == 'yes' ) {
					return true;
				} elseif ( get_post_meta( $item['product_id'], '_wps_subscription', true ) == 'yes' ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param $product_id
	 *
	 * @return bool
	 */
	public static function is_product_subscription( $product_id ) {
		if ( get_post_meta( $product_id, '_wps_subscription', true ) == 'yes' ) {
			return true;
		}

		return false;
	}

	/**
	 * @param $key
	 * @param bool $default
	 *
	 * @return bool
	 */
	public static function http_get( $key, $default = false ) {
		if ( isset( $_GET[ $key ] ) ) {
			return $_GET[ $key ];
		}

		return $default;
	}

	public static function http_post( $key, $default = false ) {
		if ( isset( $_POST[ $key ] ) ) {
			return $_POST[ $key ];
		}

		return $default;
	}

	/**
	 * @return string
	 */
	public static function get_datetime_format() {
		return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	/**
	 * @return mixed|void
	 */
	public static function get_date_format() {
		return get_option( 'date_format' );
	}

	public static function get_view_subscription_page() {
		if ( ( $page_id = get_option( 'ws_view_sub_page' ) ) == false ) {
			$args = array(
				'post_parent'  => wc_get_page_id( 'myaccount' ),
				'post_title'   => __( "My Subscription", "stppst" ),
				'post_status'  => 'publish',
				'post_content' => '[ws_show_user_subscription]',
				'post_type'    => 'page'
			);
			$id   = wp_insert_post( $args, true );
			update_option( 'ws_view_sub_page', $id );
		}

		return $page_id;
	}

	/**
	 * @param $product_id
	 *
	 * @return array
	 */
	public static function get_sub_data( $product_id, $with_string = true ) {
		if ( isset( self::$products_cache[ $product_id ] ) ) {
			return self::$products_cache[ $product_id ];
		}

		$period               = get_post_meta( $product_id, '_wpsatchel_period', true );
		$price_time_unit      = get_post_meta( $product_id, '_wpsatchel_price_time_unit', true );
		$free_trial           = get_post_meta( $product_id, '_wpsatchel_free_trial', true );
		$trial_time_unit      = get_post_meta( $product_id, '_wpsatchel_trial_time_unit', true );
		$max_length           = get_post_meta( $product_id, '_wpsatchel_max_length', true );
		$max_length_time_unit = get_post_meta( $product_id, '_wpsatchel_max_length_time_unit', true );
		$signup_fee           = get_post_meta( $product_id, '_wpsatchel_singup_fee', true );

		$product = wc_get_product( $product_id );

		$data = array(
			'period'               => $period,
			'period_time_unit'     => $price_time_unit,
			'free_trial'           => $free_trial,
			'free_trial_time_unit' => $trial_time_unit,
			'max_length'           => $max_length,
			'max_length_time_unit' => $max_length_time_unit,
			'signup_fee'           => $signup_fee,
			'price'                => $product->price,
		);

		if ( $with_string ) {
			$strings = array(
				'free_trial_signup_fee' => sprintf( __( "%s / %s with a free trial of %s and a signup fee for %s", "stppst" ),
					wc_price( $product->price ), self::get_period_string( $product->id ),
					self::get_period_string( $product->id, 'free_trial' ), wc_price( $signup_fee ) ),
				'free_trial'            => sprintf( __( "%s / %s with a free trial of %s ", "stppst" ),
					wc_price( $product->price ), self::get_period_string( $product_id ), self::get_period_string( $product_id, 'free_trial' ) ),
				'signup_fee'            => sprintf( "%s / %s with a signup fee for %s", wc_price( $product->price ), self::get_period_string( $product_id ), wc_price( $signup_fee ) ),
				'default'               => sprintf( "%s / %s", wc_price( $product->price ), self::get_period_string( $product_id ) )
			);

			if ( $free_trial > 0 && $signup_fee > 0 ) {
				$data['string'] = $strings['free_trial_signup_fee'];
			} elseif ( $free_trial > 0 ) {
				$data['string'] = $strings['free_trial'];
			} elseif ( $signup_fee > 0 ) {
				$data['string'] = $strings['signup_fee'];
			} else {
				$data['string'] = $strings['default'];
			}
		}

		self::$products_cache[ $product_id ] = $data;

		return $data;
	}

	/**
	 * @param $product_id
	 *
	 * @return string
	 */
	public static function get_period_string( $product_id, $type = 'period' ) {
		$data = self::get_sub_data( $product_id, false );
		switch ( $type ) {
			case 'period':
				$unit = $data['period_time_unit'];
				if ( $data['period'] > 1 ) {
					$unit = $unit . 's';
				}

				return $data['period'] . ' ' . $unit;
				break;
			case 'free_trial':
				$unit = $data['free_trial_time_unit'];
				if ( $data['period'] > 1 ) {
					$unit = $unit . 's';
				}

				return $data['free_trial'] . ' ' . $unit;
				break;
		}

	}

	public static function get_unit( $product_id ) {
		$data = self::get_sub_data( $product_id );
		$unit = $data['period_time_unit'];
		if ( $data['period'] > 1 ) {
			$unit = $unit . 's';
		}

		return $unit;
	}
}