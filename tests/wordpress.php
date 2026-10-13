<?php
// Run only on an isolated WordPress whose WP_ENVIRONMENT_TYPE is local.
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-load.php' ) ) {
    fwrite( STDERR, "Usage: php tests/wordpress.php /path/to/isolated/wordpress\n" );
    exit( 1 );
}
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8764';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) {
    throw new RuntimeException( 'Refusing to run outside a local WordPress.' );
}
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! is_blog_installed() ) {
    wp_install( 'XLT isolated validation', 'xlt-test', 'local@example.invalid', false, '', 'xlt-local-test-password' );
}
$activation = activate_plugin( 'xleon-translate-ai/xleon-translate-ai.php' );
if ( is_wp_error( $activation ) ) {
    throw new RuntimeException( $activation->get_error_message() );
}
require_once WP_PLUGIN_DIR . '/xleon-translate-ai/xleon-translate-ai.php';
XLT_Store::install();
$checks = 0;
function verify_xlt( bool $condition, string $name ): void {
    $GLOBALS['checks']++;
    if ( ! $condition ) { throw new RuntimeException( $name ); }
}
XLT_Settings::save( array( 'source' => 'es', 'targets' => array( 'en', 'fr' ), 'footer' => 1 ) );
XLT_Store::put( 'Comprar', '', 'es', 'en', home_url( '/' ) );
$first = XLT_Store::capture( array( 'Comprar', 'Comprar', 'Hola %s' ), 'es', 'en', home_url( '/' ) );
verify_xlt( empty( XLT_Store::find( array( 'Comprar' ), 'es', 'en' ) ), 'pending never renders as empty' );
verify_xlt( XLT_Store::put( 'Comprar', 'Buy', 'es', 'en', home_url( '/' ) ), 'database save' );
XLT_Store::capture( array( 'Comprar' ), 'es', 'en', home_url( '/second/' ) );
verify_xlt( array( 'Comprar' => 'Buy' ) === XLT_Store::find( array( 'Comprar' ), 'es', 'en' ), 'capture does not overwrite correction' );
XLT_Store::fill( 'Comprar', 'Overwrite attempted', 'es', 'en' );
verify_xlt( array( 'Comprar' => 'Buy' ) === XLT_Store::find( array( 'Comprar' ), 'es', 'en' ), 'AI fill cannot overwrite a manual edit' );
global $wpdb;
$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . XLT_Store::table() . ' WHERE source_lang=%s AND target_lang=%s AND original=%s', 'es', 'en', 'Comprar' ) );
verify_xlt( 1 === $count, 'one row across duplicates and pages' );
verify_xlt( empty( XLT_Store::find( array( 'Comprar' ), 'es', 'fr' ) ), 'languages isolated' );
$id_before = $wpdb->get_var( "SELECT id FROM " . XLT_Store::table() . " WHERE original='Comprar'" );
XLT_Store::put( 'Comprar', 'Shop now', 'es', 'en', home_url( '/' ) );
verify_xlt( $id_before === $wpdb->get_var( "SELECT id FROM " . XLT_Store::table() . " WHERE original='Comprar'" ), 'editing retains row ID' );
XLT_Store::put( 'Comprar', 'Buy', 'es', 'en', home_url( '/' ) );
XLT_Store::put( 'Botón dinámico', 'Dynamic button', 'es', 'en', home_url( '/' ) );
XLT_Store::put( 'Mensaje desplegable', 'Expanded message', 'es', 'en', home_url( '/' ) );
XLT_Store::put( 'Tu nombre', 'Your name', 'es', 'en', home_url( '/' ) );
$_GET['xlt_lang'] = 'en';
$html = '<!doctype html><html lang="es"><head><title>Comprar</title></head><body><button>Comprar</button><button>Comprar</button><p>Pendiente</p><script>window.example="Comprar";</script></body></html>';
$output = XLT_Frontend::translate( $html );
verify_xlt( 3 === substr_count( $output, 'Buy' ), 'one edit changes title and all buttons' );
verify_xlt( str_contains( $output, 'Pendiente' ), 'unknown original preserved' );
verify_xlt( str_contains( $output, 'window.example="Comprar"' ), 'scripts preserved' );
$_GET['xlt_lang'] = 'not-allowed';
verify_xlt( $html === XLT_Frontend::translate( $html ), 'unconfigured language does not translate' );
unset( $_GET['xlt_lang'] );
$administrator = get_user_by( 'login', 'xlt-test' );
wp_set_current_user( $administrator->ID );
$_GET['s'] = 'Comprar';
ob_start(); XLT_Admin::page(); $admin_html = ob_get_clean();
unset( $_GET['s'] );
verify_xlt( str_contains( $admin_html, 'Comprar' ) && str_contains( $admin_html, 'Guardar para todas las apariciones' ), 'native backend renders dictionary' );
verify_xlt( str_contains( $admin_html, 'value="" class="regular-text" autocomplete="new-password"' ), 'API fields are always empty' );
wp_set_current_user( 0 );
$_GET['xlt_scan'] = 'en'; $_GET['_xlt_nonce'] = wp_create_nonce( 'xlt_scan' );
verify_xlt( '' === XLT_Frontend::scan_target(), 'visitors cannot enable capture' );
unset( $_GET['xlt_scan'], $_GET['_xlt_nonce'] );
wp_set_current_user( $administrator->ID );
$content = '<h2>Prueba de cadenas</h2><button>Comprar</button> <button>Comprar</button><input type="submit" value="Comprar"><input placeholder="Tu nombre"><p translate="no">Texto excluido</p><div id="xlt-dynamic"></div><script>setTimeout(function(){var b=document.createElement("button");b.textContent="Botón dinámico";b.onclick=function(){var p=document.createElement("p");p.textContent="Mensaje desplegable";document.getElementById("xlt-dynamic").appendChild(p);};document.getElementById("xlt-dynamic").appendChild(b);},500);</script>[xleon_language_switcher]';
$fixture_id = get_option( 'xlt_fixture_id' );
$data = array( 'post_title' => 'Comprar', 'post_content' => $content, 'post_status' => 'publish', 'post_type' => 'page' );
if ( $fixture_id ) { $data['ID'] = $fixture_id; }
$fixture_id = wp_insert_post( $data );
update_option( 'xlt_fixture_id', $fixture_id );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $fixture_id );
echo 'PASS: ' . $checks . ' WordPress ' . $GLOBALS['wp_version'] . " integration checks.\n";
