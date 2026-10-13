<?php
defined( 'ABSPATH' ) || exit;

final class XLT_Settings {
    public static function languages(): array {
        return array( 'es' => 'Español', 'en' => 'English', 'ca' => 'Català', 'fr' => 'Français', 'de' => 'Deutsch', 'it' => 'Italiano', 'pt' => 'Português', 'nl' => 'Nederlands', 'pl' => 'Polski', 'uk' => 'Українська', 'ar' => 'العربية', 'ja' => '日本語', 'zh' => '中文' );
    }

    public static function get(): array {
        $locale = substr( get_locale(), 0, 2 );
        return wp_parse_args( get_option( 'xlt_settings', array() ), array(
            'provider' => 'openai', 'openai_model' => '', 'claude_model' => '',
            'source' => isset( self::languages()[ $locale ] ) ? $locale : 'es',
            'targets' => array( 'en' ), 'footer' => false, 'capture_gettext' => false,
            'openai_key' => '', 'claude_key' => '',
        ) );
    }

    private static function secret(): string {
        return hash( 'sha256', wp_salt( 'auth' ) . 'xleon-translate-ai', true );
    }

    public static function encrypt( string $key ): string {
        $iv = random_bytes( 12 );
        $tag = '';
        $cipher = openssl_encrypt( $key, 'aes-256-gcm', self::secret(), OPENSSL_RAW_DATA, $iv, $tag );
        if ( false === $cipher ) {
            throw new RuntimeException( 'No se pudo proteger la clave API.' );
        }
        return base64_encode( $iv . $tag . $cipher );
    }

    public static function key( string $provider ): string {
        $constant = 'openai' === $provider ? 'XLT_OPENAI_API_KEY' : 'XLT_CLAUDE_API_KEY';
        if ( defined( $constant ) ) {
            return trim( (string) constant( $constant ) );
        }
        $stored = base64_decode( self::get()[ $provider . '_key' ] ?? '', true );
        if ( false === $stored || strlen( $stored ) < 29 ) {
            return '';
        }
        $key = openssl_decrypt( substr( $stored, 28 ), 'aes-256-gcm', self::secret(), OPENSSL_RAW_DATA, substr( $stored, 0, 12 ), substr( $stored, 12, 16 ) );
        return false === $key ? '' : $key;
    }

    public static function save( array $input ) {
        $settings = self::get();
        $languages = self::languages();
        $source = sanitize_key( $input['source'] ?? '' );
        if ( ! isset( $languages[ $source ] ) ) {
            return new WP_Error( 'source', 'Selecciona un idioma original válido.' );
        }
        $settings['source'] = $source;
        $settings['provider'] = 'claude' === ( $input['provider'] ?? '' ) ? 'claude' : 'openai';
        $settings['targets'] = array_values( array_diff( array_intersect( array_keys( $languages ), (array) ( $input['targets'] ?? array() ) ), array( $source ) ) );
        $settings['footer'] = ! empty( $input['footer'] );
        $settings['capture_gettext'] = ! empty( $input['capture_gettext'] );
        foreach ( array( 'openai', 'claude' ) as $provider ) {
            $settings[ $provider . '_model' ] = preg_replace( '/[^a-zA-Z0-9_.:\/-]/', '', substr( (string) ( $input[ $provider . '_model' ] ?? '' ), 0, 120 ) );
            if ( ! empty( $input[ 'forget_' . $provider ] ) ) {
                $settings[ $provider . '_key' ] = '';
            } elseif ( ! empty( $input[ $provider . '_key' ] ) ) {
                $key = trim( (string) $input[ $provider . '_key' ] );
                if ( strlen( $key ) > 512 || preg_match( '/[\x00-\x20\x7f]/', $key ) ) {
                    return new WP_Error( 'key', 'La clave API contiene caracteres no válidos.' );
                }
                $settings[ $provider . '_key' ] = self::encrypt( $key );
            }
        }
        update_option( 'xlt_settings', $settings, false );
        return true;
    }
}
