<?php

/**
 * Plugin Name:         ifthenpay | Payments for MemberPress
 * Plugin URI:          https://github.com/ifthenpay/ifthenpay-payments-for-memberpress
 * Description:         MemberPress integration for payments with the ifthenpay gateway: accept cards, digital & mobile wallets, local bank transfers and alternative methods—secure one-time payments and recurring (period-based) subscriptions.
 * Version:             1.0.0
 * Requires at least:   6.5
 * Requires PHP:        7.4
 * Author:              ifthenpay
 * Author URI:          https://ifthenpay.com/
 * License:             GPL v3
 * License URI:         https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:         ifthenpay-payments-for-memberpress
 * Domain Path:         /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You shall not pass!' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';

/** Single source of truth */
define( 'IFTP_MP_VERSION', '1.0.0' );
define( 'IFTP_MP_PLUGIN_NAME', 'ifthenpay-payments-for-memberpress' ); // folder == text-domain
define( 'IFTP_MP_PATH', WP_PLUGIN_DIR . '/' . IFTP_MP_PLUGIN_NAME );
define( 'IFTP_MP_URL', plugins_url( '/' . IFTP_MP_PLUGIN_NAME ) );
define( 'IFTP_MP_ASSETS_URL', IFTP_MP_URL . '/assets' );
define( 'IFTP_MP_CSS_URL', IFTP_MP_ASSETS_URL . '/css' );
define( 'IFTP_MP_JS_URL', IFTP_MP_ASSETS_URL . '/js' );
define( 'IFTP_MP_IMAGES_URL', IFTP_MP_ASSETS_URL . '/images' );

/** PSR-4 autoload (Composer) */
$autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

/** Only run gateway wiring when MemberPress is active */
if ( is_plugin_active( 'memberpress/memberpress.php' ) ) {
	// Load MP base classes
	require_once IFTP_MP_PATH . '/../memberpress/app/lib/MeprBaseGateway.php';
	require_once IFTP_MP_PATH . '/../memberpress/app/lib/MeprBaseRealGateway.php';

	// Our PSR-4 Plugin bootstrap
	( new Ifthenpay\MemberPress\Plugin() )->boot();
} else {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'ifthenpay | Payments for MemberPress requires MemberPress to be active.', 'ifthenpay-payments-for-memberpress' ) .
				'</p></div>';
		}
	);
}
