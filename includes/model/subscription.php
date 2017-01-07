<?php

namespace WP_Satchel\Includes\Model;

use WP_Satchel\Includes\Utils;

class Subscription {
	const STATUS_PENDING = 'pending', STATUS_ACTIVE = 'active', STATUS_CANCEL = 'cancel', STATUS_TRIAL = 'trial';

	public $id;
	public $period;
	public $period_time_unit;
	public $free_trial;
	public $free_trial_time_unit;
	public $max_length;
	public $max_length_time_unit;
	public $signup_fee;
	public $order_ids;
	public $order_id;
	public $price;
	public $status;
	public $product_id;
	public $user_id;
	public $last_pay_date;
	public $start_date;
	public $logs;
	public $order_due_date_id;

	public function __construct( $post_id = null ) {
		if ( ! is_null( $post_id ) ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				return new \WP_Error( "invalid_product_id", "Invalid Product ID" );
			}
			$this->id                   = $post_id;
			$this->period               = get_post_meta( $post_id, '_wpsatchel_period', true );
			$this->period_time_unit     = get_post_meta( $post_id, '_wpsatchel_price_time_unit', true );
			$this->free_trial           = get_post_meta( $post_id, '_wpsatchel_free_trial', true );
			$this->free_trial_time_unit = get_post_meta( $post_id, '_wpsatchel_trial_time_unit', true );
			$this->max_length           = get_post_meta( $post_id, '_wpsatchel_max_length', true );
			$this->max_length_time_unit = get_post_meta( $post_id, '_wpsatchel_max_length_time_unit', true );
			$this->signup_fee           = get_post_meta( $post_id, '_wpsatchel_singup_fee', true );
			$this->order_ids            = get_post_meta( $post_id, '_wpsatchel_order_id' );
			$this->price                = get_post_meta( $post_id, '_wpsatchel_price', true );
			$this->product_id           = get_post_meta( $post_id, '_wpsatchel_product_id', true );
			$this->last_pay_date        = get_post_meta( $post_id, '_wpsatchel_last_pay_date', true );
			$this->start_date           = get_post_meta( $post_id, '_wpsatchel_start_date', true );
			$this->status               = get_post_meta( $post_id, '_wpsatchel_status', true );
			$this->order_due_date_id    = get_post_meta( $post_id, '_wpsatchel_order_due_date_id', true );
			$this->user_id              = $post->post_author;
		}
	}

	/**
	 * @return \WC_Order|\WC_Refund
	 */
	public function get_last_order() {
		if ( ! is_array( $this->order_ids ) ) {
			$this->order_ids = array();
		}
		$this->order_ids = array_filter( $this->order_ids );
		$last_id         = @array_pop( array_values( $this->order_ids ) );
		$order           = wc_get_order( $last_id );

		return $order;
	}

	public function get_status() {
		return $this->status;
		//always get latest id
	}

	/**
	 *
	 */
	public function save() {
		if ( ! $this->id ) {
			//create new
			$id       = wp_insert_post( array(
				'post_type'   => 'satchel_subscription',
				'post_title'  => 'Subscription for Order #' . $this->order_id,
				'post_author' => $this->user_id,
				'post_status' => 'publish'
			) );
			$this->id = $id;
		}

		update_post_meta( $this->id, '_wpsatchel_period', $this->period );
		update_post_meta( $this->id, '_wpsatchel_price_time_unit', $this->period_time_unit );
		update_post_meta( $this->id, '_wpsatchel_free_trial', $this->free_trial );
		update_post_meta( $this->id, '_wpsatchel_trial_time_unit', $this->free_trial_time_unit );
		update_post_meta( $this->id, '_wpsatchel_max_length', $this->max_length );
		update_post_meta( $this->id, '_wpsatchel_max_length_time_unit', $this->max_length_time_unit );
		update_post_meta( $this->id, '_wpsatchel_singup_fee', $this->signup_fee );
		update_post_meta( $this->id, '_wpsatchel_price', $this->price );
		update_post_meta( $this->id, '_wpsatchel_status', $this->status );
		update_post_meta( $this->id, '_wpsatchel_product_id', $this->product_id );
		update_post_meta( $this->id, '_wpsatchel_last_pay_date', $this->last_pay_date );
		update_post_meta( $this->id, '_wpsatchel_start_date', $this->start_date );
		update_post_meta( $this->id, '_wpsatchel_order_due_date_id', $this->order_due_date_id );

		if ( ! in_array( $this->order_id, $this->order_ids ) ) {
			add_post_meta( $this->id, '_wpsatchel_order_id', $this->order_id );
		}
	}

	/**
	 * @param $order_id
	 *
	 * @return \WP_Satchel\Includes\Model\Subscription[]
	 */
	public static function find_subscription_by_order( $order_id ) {
		$query = new \WP_Query( array(
			'post_type'   => 'satchel_subscription',
			'post_status' => 'publish',
			'meta_key'    => '_wpsatchel_order_id',
			'meta_value'  => $order_id
		) );

		$posts = $query->get_posts();
		if ( count( $posts ) == 0 ) {
			return array();
		}
		$subscriptions = array();
		foreach ( $posts as $post ) {
			$subscriptions[] = new Subscription( $post->ID );
		}

		return $subscriptions;
	}

	public function get_recurring() {
		$unit = $this->period_time_unit;
		if ( $this->period > 0 ) {
			$unit .= 's';
		}

		return wc_price( $this->price ) . ' / ' . $this->period . ' ' . $unit;
	}

	public function get_products() {
		$product = wc_get_product( $this->product_id );
		if ( is_object( $product ) ) {
			return sprintf( '<a href="%s">%s</a>', get_permalink( $product->id ), $product->get_title() );
		}
	}

	public function add_log( $text, $type, $timestamp ) {
		$data = array(
			'text'      => $text,
			'type'      => $type,
			'timestamp' => $timestamp
		);

		$logs = get_post_meta( $this->id, '_wpsatchel_logs' );
		foreach ( $logs as $log ) {
			if ( count( array_diff( $data, $log ) ) == 0 ) {
				return;
			}
		}

		add_post_meta( $this->id, '_wpsatchel_logs', $data );
	}

	public function get_expired_date( $is_format = true ) {
		$max_date = $this->max_length;
		if ( empty( $max_date ) ) {
			$max_date = '360 days';
		} else {
			$max_date = $this->max_length . ' ' . $this->max_length_time_unit;
		}

		$timestamp = strtotime( $max_date, $this->start_date );
		if ( $timestamp > strtotime( '+360 days', $this->start_date ) ) {
			$timestamp = strtotime( '+360 days', $this->start_date );
		}

		if ( $is_format ) {
			return date_i18n( Utils::get_date_format(), $timestamp );
		}

		return $timestamp;
	}

	/**
	 * @param bool $is_format
	 *
	 * @return bool|false|int|mixed|string
	 */
	public function get_due_date( $is_format = true ) {
		$due_date = false;
		switch ( $this->status ) {
			case 'pending':
				$due_date = $this->start_date;
				break;
			case 'trial':
				$due_date = strtotime( '+ ' . $this->free_trial . ' ' . $this->free_trial_time_unit, $this->start_date );
				break;
			case 'active':
				$last_pay_date = $this->last_pay_date;
				if ( is_null( $last_pay_date ) ) {
					$last_pay_date = $this->start_date;
				}

				$due_date = strtotime( '+ ' . $this->period . ' ' . $this->period_time_unit, $last_pay_date );
				break;
			case 'cancel':
				return "N/A";
				break;
		}
		if ( $is_format ) {
			return date_i18n( Utils::get_date_format(), $due_date );
		} else {
			return $due_date;
		}
	}

	/**
	 * @return \WP_Satchel\Includes\Model\Subscription[]
	 */
	public static function find_all() {
		$query = new \WP_Query( array(
			'post_type'   => 'satchel_subscription',
			'post_status' => 'publish',
		) );
		$data  = array();
		foreach ( $query->posts as $post ) {
			$model = new Subscription( $post->ID );
			if ( ! is_wp_error( $model ) ) {
				$data[] = $model;
			}
		}

		return $data;
	}

	public static function get_by_user( $user_id ) {
		$query = new \WP_Query( array(
			'post_type'   => 'satchel_subscription',
			'post_author' => $user_id,
			'post_status' => 'publish',
		) );
		$data  = array();
		foreach ( $query->get_posts() as $post ) {
			$model = new Subscription( $post->ID );
			if ( ! is_wp_error( $model ) ) {
				$data[] = $model;
			}
		}

		return $data;
	}
}