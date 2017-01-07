<?php

/**
 * Plugin Name: Simple Subs For WooCommerce
 * Plugin URI:
 * Description: WooCommerce Subscriptions - Stripe and PayPal
 * Version: 0.1 Alpha
 * Author: WPSatchel
 * Author URI:
 * Requires at least: 3.6
 * Tested up to: 4.4
 */
namespace WP_Satchel;

use WP_Satchel\Gateways\Paypal;
use WP_Satchel\Includes\Admin\Product_Admin;

class Subscription {
	/**
	 * URL to this plugin's directory.
	 *
	 * @type string
	 */
	public $plugin_url = '';

	/**
	 * Path to this plugin's directory.
	 *
	 * @type string
	 */
	public $plugin_path = '';

	/**
	 * @var string
	 * The google place api for using to queury info
	 */
	public $prefix = '';

	/**
	 * @var string
	 */
	public $domain = '';
	/**
	 * @var array
	 */
	public $global = array();

	public function __construct() {
		$this->init();
		//load domain
		add_action( 'plugins_loaded', array( &$this, 'localization' ) );
		//autoload
		spl_autoload_register( array( &$this, 'autoload' ) );
		//assets
		add_action( 'admin_enqueue_scripts', array( &$this, 'admin_script' ) );
		add_action( 'wp_enqueue_scripts', array( &$this, 'script' ) );
		add_action( 'init', array( &$this, 'load_includes' ) );
		add_action( 'init', array( &$this, 'register_post_type' ) );
		add_action( 'admin_init', array( &$this, 'add_caps' ) );
	}

	public function add_caps() {
		// gets the administrator role
		$admins = get_role( 'administrator' );

		$admins->add_cap( 'edit_subscription' );
		$admins->add_cap( 'edit_subscriptions' );
		$admins->add_cap( 'edit_others_subscriptions' );
		$admins->add_cap( 'publish_subscriptions' );
		$admins->add_cap( 'read_subscription' );
		$admins->add_cap( 'read_private_subscriptions' );
		$admins->add_cap( 'delete_ssubscription' );
	}

	public function register_post_type() {
		$labels = array(
			'name'               => _x( 'Subscriptions', 'WP Satchel', 'text_domain' ),
			'singular_name'      => _x( 'Subscription', 'WP Satchel', 'text_domain' ),
			'menu_name'          => __( 'Subscriptions', 'text_domain' ),
			'all_items'          => __( 'All Subscriptions', 'text_domain' ),
			'add_new_item'       => __( 'Add New Subscription', 'text_domain' ),
			'add_new'            => __( 'Add New', 'text_domain' ),
			'new_item'           => __( 'New Subscription', 'text_domain' ),
			'edit_item'          => __( 'Edit Subscription', 'text_domain' ),
			'update_item'        => __( 'Update Subscription', 'text_domain' ),
			'view_item'          => __( 'View Subscription', 'text_domain' ),
			'search_items'       => __( 'Search Subscription', 'text_domain' ),
			'not_found'          => __( 'Not found', 'text_domain' ),
			'not_found_in_trash' => __( 'Not found in Trash', 'text_domain' ),
		);
		$args   = array(
			'label'               => __( 'Subscriptions', 'text_domain' ),
			'labels'              => $labels,
			'supports'            => false,
			'taxonomies'          => array(),
			'hierarchical'        => false,
			'public'              => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_admin_bar'   => false,
			'show_in_nav_menus'   => true,
			'can_export'          => false,
			'has_archive'         => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'capabilities'        => array(
				'create_posts'       => false,
				'edit_post'          => 'edit_subscription',
				'edit_posts'         => 'edit_subscriptions',
				'edit_others_posts'  => 'edit_others_subscriptions',
				'publish_posts'      => 'publish_subscriptions',
				'read_post'          => 'read_subscription',
				'read_private_posts' => 'read_private_subscriptions',
				'delete_post'        => 'delete_ssubscription'
			),
			//'map_meta_cap'        => true,
			// as pointed out by iEmanuele, adding map_meta_cap will map the meta correctly
			'capability_type'     => array( 'ssubscription', 'ssubscriptions' )
		);
		register_post_type( 'satchel_subscription', $args );

	}

	public function script() {

	}

	public function admin_script() {
		wp_enqueue_script( 'satchel', $this->plugin_url . 'assets/main.js' );
		wp_enqueue_style( 'satchel', $this->plugin_url . 'assets/style.css' );
		wp_enqueue_style( 'satchel-admin', $this->plugin_url . 'assets/admin.css' );
	}

	public function load_includes() {
		new Product_Admin();

		add_filter( 'woocommerce_payment_gateways', array( &$this, 'add_gateways' ) );
	}

	public function add_gateways( $gateways ) {
		$gateways[] = 'WP_Satchel\Includes\Gateways\Paypal';
		$gateways[] = 'WP_Satchel\Includes\Gateways\Stripe';

		return $gateways;
	}

	//----------------------------------------------------------------------------------------------------------------------------//

	public function init() {
		//some defined
		$this->plugin_url  = plugins_url( '/', __FILE__ );
		$this->plugin_path = plugin_dir_path( __FILE__ );
		$this->prefix      = 'ppstsub';
		$this->domain      = 'wpsatchel_subscription';

		include_once $this->plugin_path . 'vendor/autoload.php';
	}

	public function localization() {

	}

	public function autoload( $classname ) {

		$parts = explode( '\\', $classname );
		if ( $parts[0] != 'WP_Satchel' ) {
			return;
		}

		unset( $parts[0] );
		$parts = strtolower( implode( DIRECTORY_SEPARATOR, $parts ) );
		$path  = $this->plugin_path . $parts . '.php';
		$path = str_replace(ABSPATH,'',$path);
		$path  = str_replace( '_', '-', $path );
$path = ABSPATH.$path;
		if ( is_file( $path ) ) {
			include_once $path;
		}
	}
}

global $paypal_script_subscription;
$paypal_script_subscription = null;
/**
 * @return Subscription
 */
function paypal_stripe_subscription() {
	global $paypal_script_subscription;
	if ( $paypal_script_subscription == null ) {
		$paypal_script_subscription = new Subscription();
	}

	return $paypal_script_subscription;
}

paypal_stripe_subscription();