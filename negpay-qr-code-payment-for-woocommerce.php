<?php
/**
 * Plugin Name: Negpay QR Code Payment Gateway
 * Plugin URI: https://wordpress.org/plugins/negpay-qr-code-payment-for-woocommerce/
 * Description: QR кодоор төлбөр төлөх систем 
 * Version: 1.0.1
 * Author: Davkharbayar
 * Author URI: https://www.facebook.com/davkhardouble
 * License: GPLv2 or later
 * Text Domain: negpay-qr-code-payment-for-woocommerce
 * WC requires at least: 5.7
 * WC tested up to: 5.8.3

 * 
 * @category WooCommerce
 * @package  negpay QR Code Payment Gateway
 * @author   Davkharbayar <negpaymn@gmail.com>
 * @license  http://www.gnu.org/licenses/ GNU General Public License
 * @link     https://wordpress.org/plugins/negpay-qr-code-payment-for-woocommerce/
 *
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}



$consts = array(
    'NEGPAY_WOO_PLUGIN_VERSION'       => '1.0.1', // plugin version
    'NEGPAY_WOO_PLUGIN_BASENAME'      => plugin_basename( __FILE__ ),
    'NEGPAY_WOO_PLUGIN_DIR'           => plugin_dir_url( __FILE__ ),
    'NEGPAY_WOO_API'                  => 'https://api.negpay.mn' 	
);

foreach( $consts as $const => $value ) {
    if ( ! defined( $const ) ) {
        define( $const, $value );
    }
}



// register activation hook
register_activation_hook( __FILE__, 'negpaywc_plugin_activation' );

function negpaywc_plugin_activation() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    set_transient( 'negpaywc-admin-notice-on-activation', true, 5 );
}

// register deactivation hook
register_deactivation_hook( __FILE__, 'negpaywc_plugin_deactivation' );

function negpaywc_plugin_deactivation() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    delete_option( 'negpaywc_plugin_dismiss_rating_notice' );
    delete_option( 'negpaywc_plugin_no_thanks_rating_notice' );
    delete_option( 'negpay_plugin_installed_time' );
}

// plugin action links
add_filter( 'plugin_action_links_' . NEGPAY_WOO_PLUGIN_BASENAME, 'negpaywc_add_action_links', 10, 2 );

function negpaywc_add_action_links( $links ) {
    $upiwclinks = array(
        '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc-negpay' ) . '">' . __( 'Settings', 'negpay-qr-code-payment-for-woocommerce' ) . '</a>',
    );
    return array_merge( $upiwclinks, $links );
}

// add admin notices
add_action( 'admin_notices', 'negpaywc_new_plugin_install_notice' );

function negpaywc_new_plugin_install_notice() { 
    // Show a warning to sites running PHP < 5.6
    if( version_compare( PHP_VERSION, '5.6', '<' ) ) {
	    echo '<div class="error"><p>' . __( 'Таны PHP-ийн хувилбар negpay QR Code Payment Gateway плагин шаардлагатай PHP-ийн доод хувилбараас доогуур байна. Хосттойгоо холбогдож, хувилбараа 5.6 эсвэл түүнээс дээш хувилбар болгон шинэчилнэ үү', 'negpay-qr-code-payment-for-woocommerce' ) . '</p></div>';
    }
}

require_once plugin_dir_path( __FILE__ ) . 'includes/payment.php';