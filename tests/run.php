<?php
// Standalone regression tests: no WordPress installation, network or credentials.
define( 'ABSPATH', __DIR__ );
$options = array();
$http_response = array();
$http_request = array();
class WP_Error {
    public function __construct( public string $code, public string $message, public array $data = array() ) {}
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; }
function get_locale() { return 'es_ES'; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function wp_salt( $scheme ) { return 'isolated-test-salt-no-real-secret'; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function home_url( $path = '' ) { return 'https://example.org' . $path; }
function wp_unslash( $value ) { return $value; }
function add_query_arg( $key, $value, $url ) {
    $fragment = ''; $parts = explode( '#', $url, 2 ); $url = $parts[0];
    if ( isset( $parts[1] ) ) { $fragment = '#' . $parts[1]; }
    $parts = explode( '?', $url, 2 ); parse_str( $parts[1] ?? '', $query ); $query[ $key ] = $value;
    return $parts[0] . '?' . http_build_query( $query ) . $fragment;
}
function wp_remote_get( $url, $args ) { $GLOBALS['http_request'] = array( $url, $args ); return $GLOBALS['http_response']; }
function wp_remote_post( $url, $args ) { return wp_remote_get( $url, $args ); }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
class WP_Http {
    public static function make_absolute_url( $url, $base ) {
        if ( preg_match( '~^https?://~', $url ) ) { return $url; }
        if ( str_starts_with( $url, '//' ) ) { return 'https:' . $url; }
        if ( str_starts_with( $url, '/' ) ) { return 'https://example.org' . $url; }
        return 'https://example.org/' . $url;
    }
}
foreach ( array( 'settings', 'provider', 'html', 'frontend' ) as $file ) {
    require dirname( __DIR__ ) . '/includes/class-' . $file . '.php';
}
$checks = 0;
set_error_handler( static function ( $severity, $message, $file, $line ) {
    throw new RuntimeException( "$message at $file:$line" );
} );
function check( bool $pass, string $message ): void {
    $GLOBALS['checks']++;
    if ( ! $pass ) { throw new RuntimeException( $message ); }
}
function response( array $body, int $code = 200 ): void {
    $GLOBALS['http_response'] = array( 'code' => $code, 'body' => json_encode( $body ) );
}

check( true === XLT_Settings::save( array( 'source' => 'es', 'targets' => array( 'es', 'en', 'fr', 'bad' ), 'openai_key' => 'test-key-not-a-credential', 'openai_model' => 'test-text-model' ) ), 'settings save' );
check( array( 'en', 'fr' ) === XLT_Settings::get()['targets'], 'validated destinations exclude source' );
check( 'test-key-not-a-credential' === XLT_Settings::key( 'openai' ), 'encrypted key round trip' );
check( ! str_contains( $options['xlt_settings']['openai_key'], 'test-key' ), 'key stored encrypted' );
XLT_Settings::save( array( 'source' => 'es', 'targets' => array( 'en' ), 'openai_model' => 'test-text-model' ) );
check( 'test-key-not-a-credential' === XLT_Settings::key( 'openai' ), 'blank field preserves key' );
check( is_wp_error( XLT_Settings::save( array( 'source' => 'bad' ) ) ), 'invalid source rejected' );
check( is_wp_error( XLT_Settings::save( array( 'source' => 'es', 'openai_key' => "bad\r\nkey" ) ) ), 'header injection rejected' );
check( XLT_Provider::valid( 'Hola %1$s {{name}} [button id="1"]', 'Hello %1$s {{name}} [button id="1"]' ), 'markers preserved' );
check( ! XLT_Provider::valid( 'Hola %s', 'Hello' ), 'lost marker rejected' );
check( ! XLT_Provider::valid( 'Hola', '<script>alert(1)</script>' ), 'HTML rejected' );
check( ! XLT_Provider::valid( 'Hola', '' ), 'empty output rejected' );
response( array( 'status' => 'completed', 'output' => array( array( 'content' => array( array( 'type' => 'output_text', 'text' => '["Buy","Hello %s"]' ) ) ) ) ) );
check( array( 'Buy', 'Hello %s' ) === XLT_Provider::translate( array( 'Comprar', 'Hola %s' ), 'es', 'en' ), 'OpenAI output parsed' );
check( 'https://api.openai.com/v1/responses' === $http_request[0], 'fixed OpenAI endpoint' );
$payload = json_decode( $http_request[1]['body'], true );
check( false === $payload['store'], 'OpenAI storage disabled' );
check( ! isset( $payload['temperature'] ), 'reasoning-model compatibility' );
response( array( 'status' => 'incomplete' ) );
check( is_wp_error( XLT_Provider::translate( array( 'Comprar' ), 'es', 'en' ) ), 'truncated response rejected' );
response( array( 'status' => 'completed', 'output' => array( array( 'content' => array( array( 'type' => 'output_text', 'text' => '["Buy"]' ) ) ) ) ) );
check( is_wp_error( XLT_Provider::translate( array( 'Comprar', 'Hola' ), 'es', 'en' ) ), 'partial array rejected' );
response( array( 'error' => array( 'message' => 'test-key-not-a-credential' ) ), 401 );
$error = XLT_Provider::translate( array( 'Hola' ), 'es', 'en' );
check( is_wp_error( $error ) && ! str_contains( $error->message, 'test-key' ), 'API error cannot echo secrets' );
XLT_Settings::save( array( 'source' => 'es', 'targets' => array( 'en' ), 'provider' => 'claude', 'claude_key' => 'test-claude-not-a-credential', 'claude_model' => 'test-claude-model' ) );
response( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => '["Buy"]' ) ) ) );
check( array( 'Buy' ) === XLT_Provider::translate( array( 'Comprar' ), 'es', 'en' ), 'Claude output parsed' );
check( 'https://api.anthropic.com/v1/messages' === $http_request[0] && '2023-06-01' === $http_request[1]['headers']['anthropic-version'], 'Claude endpoint and version' );
response( array( 'data' => array( array( 'id' => 'model-b' ), array( 'id' => 'model-a' ) ) ) );
check( array( 'model-a', 'model-b' ) === XLT_Provider::models( 'claude' ), 'account model list parsed' );

$_SERVER['REQUEST_URI'] = '/';
$html = '<!DOCTYPE html><html lang="es"><head><title>Comprar</title><meta name="description" content="A &amp; B"><script>const a="Comprar";</script><style>.Comprar{}</style></head><body><button> Comprar </button><a href="/shop/">Comprar</a><input type="submit" value="Comprar"><input type="email" value="private@example.org"><input placeholder="Tu nombre"><p>A &amp; B</p><p translate="no">No traducir</p><div class="notranslate">Excluir</div><div id="wpadminbar">Administrador</div><textarea>Privado</textarea><svg><text>Vector</text></svg><nav class="xlt-switcher"><a href="/">Español</a></nav><script type="application/json">{"name":"Comprar"}</script></body></html>';
$doc = new XLT_HTML( $html );
$strings = $doc->strings();
check( 1 === count( array_keys( $strings, 'Comprar', true ) ), 'duplicate button appears once' );
check( in_array( 'A & B', $strings, true ) && in_array( 'Tu nombre', $strings, true ), 'attributes and entities captured' );
foreach ( array( 'No traducir', 'Excluir', 'Administrador', 'Privado', 'Vector', 'Español', 'private@example.org' ) as $excluded ) {
    check( ! in_array( $excluded, $strings, true ), 'excluded text: ' . $excluded );
}
$rendered = $doc->render( array( 'Comprar' => 'Buy', 'A & B' => 'C & D', 'Tu nombre' => 'Your name' ), 'en' );
check( 4 === substr_count( $rendered, 'Buy' ), 'same translation applied to title and every button/link' );
check( str_contains( $rendered, 'const a="Comprar";' ), 'script content untouched' );
$unicode = '<!doctype html><html><head><style>.test::after{content:"Botón dinámico"}</style></head><body><script data-test="a>b">const name="Botón dinámico & café"; const json={"x":"á"};</script></body></html>';
$unicode_rendered = ( new XLT_HTML( $unicode ) )->render( array(), 'en' );
check( str_contains( $unicode_rendered, 'const name="Botón dinámico & café"; const json={"x":"á"};' ), 'Unicode and ampersands in executable script preserved byte-for-byte' );
check( str_contains( $unicode_rendered, 'content:"Botón dinámico"' ), 'Unicode CSS preserved byte-for-byte' );
check( str_contains( $rendered, 'lang="en"' ) && str_contains( $rendered, 'noindex,follow' ), 'language and indexing correct' );
check( str_contains( $rendered, 'C &amp; D' ), 'plain text ampersands serialized safely' );
check( str_contains( $rendered, 'content="C &amp; D"' ), 'ampersands in attributes preserved' );
check( str_contains( $rendered, 'href="https://example.org/shop/?xlt_lang=en"' ), 'internal navigation retains language' );
check( str_contains( $rendered, '<a href="/">Espa' ), 'switcher links preserved' );
foreach ( array( '/?add-to-cart=1', '/wp-admin/', 'https://other.org/page', '/file.pdf', '#fragment', 'mailto:hello@example.org', 'https://example.org:444/' ) as $url ) {
    check( $url === XLT_Frontend::link( $url, 'en' ), 'unsafe or external navigation untouched: ' . $url );
}
check( ! XLT_Frontend::local_url( 'https://user:pass@example.org/' ), 'URL credentials rejected' );
XLT_Settings::save( array( 'source' => 'es', 'targets' => array( 'en' ), 'forget_openai' => 1 ) );
check( '' === XLT_Settings::key( 'openai' ), 'forget saved key' );
echo 'PASS: ' . $checks . " regression checks, no external requests.\n";
