<?php
/**
 * Plugin Name: XLeon Translate AI
 * Plugin URI: https://github.com/Xavileaks/XLeon-Translate-AI
 * Description: Traduce páginas públicas con tu propia API de OpenAI o Claude y conserva las traducciones en WordPress.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: XLeon
 * License: GPL-2.0-or-later
 * Text Domain: xleon-translate-ai
 */
defined( 'ABSPATH' ) || exit;
define( 'XLT_VERSION', '1.0.0' );
define( 'XLT_FILE', __FILE__ );
foreach ( array( 'settings', 'store', 'provider', 'html', 'frontend', 'admin' ) as $xlt_class ) {
    require_once __DIR__ . '/includes/class-' . $xlt_class . '.php';
}
register_activation_hook( __FILE__, array( 'XLT_Store', 'install' ) );
add_action( 'plugins_loaded', static function () {
    if ( get_option( 'xlt_db_version' ) !== XLT_VERSION ) {
        XLT_Store::install();
    }
    XLT_Frontend::boot();
    XLT_Admin::boot();
} );
