<?php
/**
 * Plugin Name: Billie Rechnungskauf
 * Plugin URI: https://www.billie.io/
 * Description: Jetzt durchstarten mit der beliebtesten Zahlungsart für B2B-Shops: Billie Rechnungskauf
 * Version: 2.2.0
 * Author: pooliestudios
 * Author URI: https://pooliestudios.com
 * License: MIT
 * Text Domain: billie
 * Domain Path: lang
 * WC requires at least: 3.0.0
 * WC tested up to: 8.7.0
 * Woo: 18734003528292:f3a1ed458a399d218d26e5b63777482f
 * 
 * @package Billie Rechnungskauf
 */

use Billie\Plugin;
use Billie\Billie\BillieBlocksSupport;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Blocks\Package;

defined( 'ABSPATH' ) || die( 'Direct access not allowed' );

define( 'BILLIE_PLUGIN_VERSION', '2.2.0' );
define( 'BILLIE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BILLIE_PLUGIN_PATH', __DIR__ );
define( 'BILLIE_VIEW_PATH', BILLIE_PLUGIN_PATH . '/views' );
define( 'BILLIE_RESSOURCES_PATH', BILLIE_PLUGIN_PATH . '/ressources' );

define( 'BILLIE_SANDBOX_URL', 'https://paella-sandbox.billie.io/api/v2' );
define( 'BILLIE_PRODUCTION_URL', 'https://paella.billie.io/api/v2' );

require_once 'src/autoload.php';

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
	$billie_plugin = new Plugin();
	add_action( 'plugins_loaded', array( $billie_plugin, 'init' ) );
}

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( FeaturesUtil::class ) ) {
		FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'woocommerce_blocks_loaded', function () {
	if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( PaymentMethodRegistry $payment_method_registry ) {
				$container = Package::container();
				// registers as shared instance.
				$container->register(
					BillieBlocksSupport::class,
					function () {
						return new BillieBlocksSupport();
					}
				);
				$payment_method_registry->register(
					$container->get( BillieBlocksSupport::class )
				);
			},
			5
		);
	}
} );
