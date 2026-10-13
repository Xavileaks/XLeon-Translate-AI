<?php
defined( 'ABSPATH' ) || exit;

final class XLT_Admin {
    public static function boot(): void {
        add_action( 'admin_menu', static function () {
            add_menu_page( 'XLeon Translate AI', 'XLeon Translate', 'manage_options', 'xleon-translate-ai', array( self::class, 'page' ), 'dashicons-translation', 81 );
        } );
        add_action( 'admin_enqueue_scripts', static function ( $hook ) {
            if ( 'toplevel_page_xleon-translate-ai' === $hook ) {
                wp_enqueue_script( 'xlt-admin', plugins_url( 'assets/admin.js', XLT_FILE ), array(), XLT_VERSION, true );
                wp_add_inline_script( 'xlt-admin', 'window.XLTAdmin=' . wp_json_encode( array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'xlt_admin' ) ) ) . ';', 'before' );
            }
        } );
        add_action( 'admin_post_xlt_settings', array( self::class, 'save_settings' ) );
        add_action( 'admin_post_xlt_edit', array( self::class, 'edit' ) );
        foreach ( array( 'models', 'prepare', 'batch', 'capture', 'scan_link', 'row' ) as $action ) {
            add_action( 'wp_ajax_xlt_' . $action, array( self::class, $action ) );
        }
    }

    private static function permission( string $nonce = 'xlt_admin' ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No tienes permisos.', '', array( 'response' => 403 ) );
        }
        if ( wp_doing_ajax() ) {
            check_ajax_referer( $nonce, 'nonce' );
        } else {
            check_admin_referer( $nonce );
        }
    }

    private static function result( $result ): void {
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }
        wp_send_json_success( $result );
    }

    private static function target(): string {
        $target = sanitize_key( wp_unslash( $_POST['target'] ?? '' ) );
        if ( ! in_array( $target, XLT_Settings::get()['targets'], true ) ) {
            self::result( new WP_Error( 'target', 'Guarda primero el idioma de destino en los ajustes.' ) );
        }
        return $target;
    }

    private static function url() {
        $url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
        $url = remove_query_arg( array( 'xlt_lang', 'xlt_scan', '_xlt_nonce' ), $url );
        $parts = wp_parse_url( $url );
        parse_str( $parts['query'] ?? '', $query );
        if ( ! XLT_Frontend::local_url( $url ) || array_diff( array_keys( $query ), array( 'p', 'page_id' ) ) ) {
            return new WP_Error( 'url', 'Introduce una URL pública de este sitio, sin parámetros de acciones, búsqueda o sesiones.' );
        }
        foreach ( array( 'p', 'page_id' ) as $id_key ) {
            if ( isset( $query[ $id_key ] ) ) {
                $post = get_post( absint( $query[ $id_key ] ) );
                if ( ! $post || 'publish' !== $post->post_status || $post->post_password ) {
                    return new WP_Error( 'private', 'Solo se pueden capturar páginas públicas sin contraseña.' );
                }
            }
        }
        foreach ( array( 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id' ) as $option ) {
            $id = get_option( $option );
            if ( $id ) {
                $sensitive = wp_parse_url( get_permalink( $id ) );
                $path = rtrim( $parts['path'] ?? '/', '/' );
                $private_path = rtrim( $sensitive['path'] ?? '', '/' );
                if ( $url === get_permalink( $id ) || ( $private_path && ( $path === $private_path || str_starts_with( $path, $private_path . '/' ) ) ) ) {
                    return new WP_Error( 'private', 'Carrito, checkout y cuenta se excluyen para evitar capturar datos personales.' );
                }
            }
        }
        return $url;
    }

    public static function models(): void {
        self::permission();
        $provider = 'claude' === ( $_POST['provider'] ?? '' ) ? 'claude' : 'openai';
        self::result( XLT_Provider::models( $provider ) );
    }

    public static function scan_link(): void {
        self::permission();
        $target = self::target();
        $url = self::url();
        if ( is_wp_error( $url ) ) {
            self::result( $url );
        }
        self::result( array( 'url' => add_query_arg( array( 'xlt_scan' => $target, '_xlt_nonce' => wp_create_nonce( 'xlt_scan' ) ), $url ) ) );
    }

    public static function capture(): void {
        self::permission();
        $target = self::target();
        $url = self::url();
        if ( is_wp_error( $url ) ) {
            self::result( $url );
        }
        $strings = json_decode( wp_unslash( $_POST['strings'] ?? '' ), true );
        if ( ! is_array( $strings ) || count( $strings ) > 200 || strlen( wp_json_encode( $strings ) ) > 100000 ) {
            self::result( new WP_Error( 'size', 'El lote de cadenas es demasiado grande.' ) );
        }
        $strings = array_values( array_filter( $strings, static fn( $s ) => is_string( $s ) && XLT_HTML::translatable( trim( $s ) ) ) );
        try {
            $count = XLT_Store::capture( array_map( 'trim', $strings ), XLT_Settings::get()['source'], $target, $url );
            self::result( array( 'added' => $count ) );
        } catch ( Throwable $e ) {
            self::result( new WP_Error( 'db', 'No se pudieron guardar las cadenas. Comprueba la base de datos.' ) );
        }
    }

    public static function prepare(): void {
        self::permission();
        $target = self::target();
        $url = self::url();
        if ( is_wp_error( $url ) ) {
            self::result( $url );
        }
        if ( ! class_exists( 'DOMDocument' ) ) {
            self::result( new WP_Error( 'dom', 'Activa la extensión PHP DOM en tu servidor.' ) );
        }
        // No cookies or authorization forwarded. No redirects to another origin.
        $response = wp_safe_remote_get( $url, array( 'timeout' => 25, 'redirection' => 0, 'limit_response_size' => 2097153 ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            self::result( new WP_Error( 'fetch', 'No se pudo leer la página pública. Usa su URL final sin redirecciones o abre la captura en navegador.' ) );
        }
        $html = wp_remote_retrieve_body( $response );
        if ( strlen( $html ) > 2097152 || stripos( $html, '<html' ) === false ) {
            self::result( new WP_Error( 'html', 'La página no es HTML o supera los 2 MB.' ) );
        }
        $strings = ( new XLT_HTML( $html ) )->strings();
        if ( count( $strings ) > 2000 ) {
            self::result( new WP_Error( 'size', 'La página tiene demasiadas cadenas. Usa la captura en navegador.' ) );
        }
        $settings = XLT_Settings::get();
        try {
            $added = XLT_Store::capture( $strings, $settings['source'], $target, $url );
        } catch ( Throwable $e ) {
            self::result( new WP_Error( 'db', 'No se pudieron guardar las cadenas.' ) );
        }
        $known = XLT_Store::find( $strings, $settings['source'], $target );
        $pending = array_values( array_filter( $strings, static fn( $s ) => ! array_key_exists( $s, $known ) ) );
        $job = wp_generate_uuid4();
        set_transient( 'xlt_job_' . $job, array( 'user' => get_current_user_id(), 'source' => $settings['source'], 'target' => $target, 'url' => $url, 'strings' => $pending, 'offset' => 0 ), HOUR_IN_SECONDS );
        self::result( array( 'job' => $job, 'total' => count( $strings ), 'pending' => count( $pending ), 'added' => $added, 'preview' => add_query_arg( 'xlt_lang', $target, $url ) ) );
    }

    public static function batch(): void {
        self::permission();
        $id = sanitize_text_field( wp_unslash( $_POST['job'] ?? '' ) );
        if ( ! preg_match( '/^[a-f0-9-]{36}$/', $id ) ) {
            self::result( new WP_Error( 'job', 'La sesión de traducción no es válida.' ) );
        }
        $job = get_transient( 'xlt_job_' . $id );
        if ( ! $job || (int) $job['user'] !== get_current_user_id() || $job['source'] !== XLT_Settings::get()['source'] || ! in_array( $job['target'], XLT_Settings::get()['targets'], true ) ) {
            self::result( new WP_Error( 'job', 'La sesión caducó o los idiomas cambiaron. Vuelve a leer la página; las traducciones guardadas se conservan.' ) );
        }
        $lock = 'xlt_lock_' . $id;
        if ( ! add_option( $lock, time(), '', false ) ) {
            self::result( new WP_Error( 'busy', 'Este lote ya está en curso. Espera antes de reintentar.' ) );
        }
        $result = null;
        try {
            $strings = array();
            foreach ( array_slice( $job['strings'], $job['offset'], 10 ) as $string ) {
                $candidate = array_merge( $strings, array( $string ) );
                if ( strlen( wp_json_encode( $candidate ) ) > 12000 ) {
                    break;
                }
                $strings[] = $string;
            }
            if ( $strings ) {
                $translated = XLT_Provider::translate( $strings, $job['source'], $job['target'] );
                if ( is_wp_error( $translated ) ) {
                    $result = $translated;
                } else {
                    foreach ( $strings as $i => $original ) {
                        // Preserve manual edits or translations made since the scan.
                        if ( ! XLT_Store::fill( $original, $translated[ $i ], $job['source'], $job['target'] ) ) {
                            throw new RuntimeException( 'No se pudo guardar la traducción.' );
                        }
                    }
                    $job['offset'] += count( $strings );
                }
            }
            if ( ! $result ) {
                set_transient( 'xlt_job_' . $id, $job, HOUR_IN_SECONDS );
                $result = array( 'done' => $job['offset'], 'total' => count( $job['strings'] ), 'complete' => $job['offset'] >= count( $job['strings'] ) );
            }
        } catch ( Throwable $e ) {
            $result = new WP_Error( 'save', 'No se pudo guardar el lote. Vuelve a leer la página para recuperar el progreso.' );
        } finally {
            delete_option( $lock );
        }
        self::result( $result );
    }

    public static function row(): void {
        self::permission();
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . XLT_Store::table() . ' WHERE id=%d', absint( $_POST['id'] ?? 0 ) ) );
        if ( ! $row || $row->source_lang !== XLT_Settings::get()['source'] || ! in_array( $row->target_lang, XLT_Settings::get()['targets'], true ) ) {
            self::result( new WP_Error( 'row', 'La cadena ya no está disponible para los idiomas activos.' ) );
        }
        $translated = XLT_Provider::translate( array( $row->original ), $row->source_lang, $row->target_lang );
        if ( is_wp_error( $translated ) ) {
            self::result( $translated );
        }
        // The user reviews the suggestion in the textarea before saving it.
        self::result( array( 'text' => $translated[0] ) );
    }

    public static function save_settings(): void {
        self::permission( 'xlt_settings' );
        if ( ( ! empty( $_POST['openai_key'] ) || ! empty( $_POST['claude_key'] ) ) && ! function_exists( 'openssl_encrypt' ) ) {
            wp_die( 'Activa la extensión PHP OpenSSL para proteger tus claves API.' );
        }
        try {
            $result = XLT_Settings::save( wp_unslash( $_POST ) );
        } catch ( Throwable $e ) {
            $result = new WP_Error( 'encryption', 'No se pudo proteger la clave API.' );
        }
        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ) );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=xleon-translate-ai&saved=1' ) );
        exit;
    }

    public static function edit(): void {
        self::permission( 'xlt_edit' );
        global $wpdb;
        $id = absint( $_POST['id'] ?? 0 );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . XLT_Store::table() . ' WHERE id=%d', $id ) );
        $text = trim( wp_unslash( $_POST['translated'] ?? '' ) );
        if ( ! $row || ( '' !== $text && ! XLT_Provider::valid( $row->original, $text ) ) ) {
            wp_die( 'El texto no es válido. Conserva los marcadores y no introduzcas HTML.' );
        }
        if ( ! XLT_Store::put( $row->original, $text, $row->source_lang, $row->target_lang, $row->source_url ) ) {
            wp_die( 'No se pudo guardar la traducción.' );
        }
        $return = wp_get_referer();
        wp_safe_redirect( $return ?: admin_url( 'admin.php?page=xleon-translate-ai' ) );
        exit;
    }

    public static function page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        global $wpdb;
        $settings = XLT_Settings::get();
        $languages = XLT_Settings::languages();
        $target = sanitize_key( wp_unslash( $_GET['target'] ?? ( $settings['targets'][0] ?? '' ) ) );
        if ( ! in_array( $target, $settings['targets'], true ) ) {
            $target = $settings['targets'][0] ?? '';
        }
        $search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        $pending = ! empty( $_GET['pending'] );
        $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $where = $wpdb->prepare( 'source_lang=%s AND target_lang=%s', $settings['source'], $target );
        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare( ' AND (original LIKE %s OR translated LIKE %s)', $like, $like );
        }
        if ( $pending ) {
            $where .= " AND translated=''";
        }
        $total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . XLT_Store::table() . ' WHERE ' . $where );
        $rows = $wpdb->get_results( 'SELECT * FROM ' . XLT_Store::table() . ' WHERE ' . $where . $wpdb->prepare( ' ORDER BY id DESC LIMIT 30 OFFSET %d', ( $page - 1 ) * 30 ) );
        ?>
        <style>#xlt-stop[hidden]{display:none!important}</style>
        <div class="wrap">
            <h1>XLeon Translate AI <small>1.0.0</small></h1>
            <p>Captura cadenas, busca un texto y cámbialo una sola vez: la misma cadena se sustituye en todas sus apariciones para ese idioma. La IA es opcional.</p>
            <?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>Ajustes guardados.</p></div><?php endif; ?>
            <details <?php echo $target ? '' : 'open'; ?>><summary><h2 style="display:inline">Idiomas y conexión API</h2></summary>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                    <input type="hidden" name="action" value="xlt_settings"><?php wp_nonce_field( 'xlt_settings' ); ?>
                    <table class="form-table" role="presentation">
                        <tr><th><label for="xlt-source">Idioma original</label></th><td><select id="xlt-source" name="source"><?php foreach ( $languages as $code => $name ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['source'], $code ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select></td></tr>
                        <tr><th>Idiomas de destino</th><td><?php foreach ( $languages as $code => $name ) : ?><label style="display:inline-block;margin:0 18px 12px 0"><input type="checkbox" name="targets[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $settings['targets'], true ) ); ?>> <?php echo esc_html( $name ); ?></label><?php endforeach; ?><p class="description">El idioma original se excluye de los destinos al guardar.</p></td></tr>
                        <tr><th><label for="xlt-provider">Proveedor de IA</label></th><td><select name="provider" id="xlt-provider"><option value="openai" <?php selected( $settings['provider'], 'openai' ); ?>>OpenAI (ChatGPT)</option><option value="claude" <?php selected( $settings['provider'], 'claude' ); ?>>Anthropic (Claude)</option></select></td></tr>
                        <?php foreach ( array( 'openai' => 'OpenAI', 'claude' => 'Claude' ) as $provider => $label ) : ?>
                        <tr><th><label for="xlt-<?php echo esc_attr( $provider ); ?>-key">Clave API de <?php echo esc_html( $label ); ?></label></th><td>
                            <input type="password" id="xlt-<?php echo esc_attr( $provider ); ?>-key" name="<?php echo esc_attr( $provider ); ?>_key" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo XLT_Settings::key( $provider ) ? 'Clave guardada' : 'Sin configurar'; ?>">
                            <label><input type="checkbox" name="forget_<?php echo esc_attr( $provider ); ?>" value="1"> Borrar clave guardada</label>
                            <p class="description">Déjalo vacío para conservarla. La clave no se muestra en el navegador y se guarda cifrada en la base de datos.</p>
                        </td></tr>
                        <tr><th><label for="xlt-<?php echo esc_attr( $provider ); ?>-model">Modelo de <?php echo esc_html( $label ); ?></label></th><td>
                            <input id="xlt-<?php echo esc_attr( $provider ); ?>-model" name="<?php echo esc_attr( $provider ); ?>_model" value="<?php echo esc_attr( $settings[ $provider . '_model' ] ); ?>" list="xlt-<?php echo esc_attr( $provider ); ?>-models" class="regular-text" placeholder="ID de un modelo de texto">
                            <datalist id="xlt-<?php echo esc_attr( $provider ); ?>-models"></datalist>
                            <button type="button" class="button xlt-models" data-provider="<?php echo esc_attr( $provider ); ?>">Consultar modelos</button>
                            <p class="description">Guarda la clave primero. Elige un modelo que genere texto; la lista de OpenAI incluye también modelos para otras tareas.</p>
                        </td></tr>
                        <?php endforeach; ?>
                        <tr><th>Selector de idiomas</th><td><label><input type="checkbox" name="footer" value="1" <?php checked( $settings['footer'] ); ?>> Mostrar al pie de las páginas</label><p class="description">También puedes insertar el shortcode <code>[xleon_language_switcher]</code> en una página, bloque o widget de Elementor.</p></td></tr>
                        <tr><th>Captura avanzada</th><td><label><input type="checkbox" name="capture_gettext" value="1" <?php checked( $settings['capture_gettext'] ); ?>> Incluir también cadenas internas de WordPress y plugins</label><p class="description">Recoge mensajes localizados durante la captura, incluso si no aparecen en la página. Puede añadir muchas cadenas internas; úsalo si falta un mensaje o texto con marcadores.</p></td></tr>
                    </table>
                    <p>Las peticiones de IA envían las cadenas al proveedor elegido y pueden generar costes en tu cuenta API. Puedes capturar y editar textos sin conectar ninguna API.</p>
                    <?php submit_button( 'Guardar ajustes' ); ?>
                </form>
            </details>
            <h2>Capturar cadenas de una página</h2>
            <p>«Leer HTML» recoge el contenido servido por WordPress. «Captura en navegador» añade textos de JavaScript y AJAX: abre la página, interactúa con botones o desplegables y regresa al diccionario.</p>
            <p><label for="xlt-url">URL pública</label> <input type="url" id="xlt-url" value="<?php echo esc_url( home_url( '/' ) ); ?>" class="large-text" style="max-width:620px"></p>
            <p><label for="xlt-target">Idioma de destino</label> <select id="xlt-target"><?php foreach ( $settings['targets'] as $code ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $target, $code ); ?>><?php echo esc_html( $languages[ $code ] ); ?></option><?php endforeach; ?></select>
                <button type="button" class="button" id="xlt-read">Leer HTML</button>
                <button type="button" class="button" id="xlt-scan">Captura en navegador</button>
                <button type="button" class="button" id="xlt-ai" disabled>Traducir pendientes con IA</button>
                <button type="button" class="button" id="xlt-stop" hidden>Detener tras este lote</button>
            </p>
            <p id="xlt-status" role="status" aria-live="polite"></p>
            <p>Se omiten scripts, código, campos con datos de usuario y páginas de carrito, checkout o cuenta. Marca cualquier zona que quieras excluir con <code>translate="no"</code> o <code>data-xlt-ignore</code>.</p>
            <h2>Diccionario compartido</h2>
            <form method="get">
                <input type="hidden" name="page" value="xleon-translate-ai">
                <label for="xlt-filter-target">Idioma</label> <select name="target" id="xlt-filter-target"><?php foreach ( $settings['targets'] as $code ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $target, $code ); ?>><?php echo esc_html( $languages[ $code ] ); ?></option><?php endforeach; ?></select>
                <label for="xlt-search" class="screen-reader-text">Buscar cadena</label><input type="search" name="s" id="xlt-search" value="<?php echo esc_attr( $search ); ?>" placeholder="Buscar original o traducción">
                <label><input type="checkbox" name="pending" value="1" <?php checked( $pending ); ?>> Solo pendientes</label>
                <button class="button">Filtrar / actualizar</button>
            </form>
            <p><?php echo esc_html( $total ); ?> cadenas · Una cadena repetida ocupa una sola fila por idioma. Se compara el texto exacto, incluidas mayúsculas y espacios interiores.</p>
            <table class="widefat striped"><thead><tr><th scope="col">Texto original</th><th scope="col">Traducción / sustitución global</th></tr></thead><tbody>
                <?php foreach ( $rows as $row ) : ?>
                <tr><td style="width:42%;overflow-wrap:anywhere"><div><?php echo esc_html( $row->original ); ?></div><p><small>Detectada en <a href="<?php echo esc_url( $row->source_url ); ?>" target="_blank" rel="noopener noreferrer">esta página</a></small></p></td>
                    <td><form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                        <input type="hidden" name="action" value="xlt_edit"><input type="hidden" name="id" value="<?php echo esc_attr( $row->id ); ?>"><?php wp_nonce_field( 'xlt_edit' ); ?>
                        <label for="xlt-string-<?php echo esc_attr( $row->id ); ?>" class="screen-reader-text">Traducción de <?php echo esc_html( $row->original ); ?></label>
                        <textarea id="xlt-string-<?php echo esc_attr( $row->id ); ?>" class="large-text" name="translated" rows="2"><?php echo esc_textarea( $row->translated ); ?></textarea>
                        <p><button class="button button-primary">Guardar para todas las apariciones</button> <button type="button" class="button xlt-row" data-id="<?php echo esc_attr( $row->id ); ?>">Sugerir con IA</button></p>
                    </form></td>
                </tr>
                <?php endforeach; ?>
                <?php if ( ! $rows ) : ?><tr><td colspan="2">No hay cadenas con estos filtros. Guarda un idioma de destino y captura una página para comenzar.</td></tr><?php endif; ?>
            </tbody></table>
            <?php if ( $total > 30 ) : ?><p><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => (int) ceil( $total / 30 ) ) ) ); ?></p><?php endif; ?>
            <p>Vacía una traducción y guarda para volver al original. Los textos nuevos o aún pendientes muestran el original. Las versiones por idioma usan <code>?xlt_lang=en</code>; esta versión inicial no incluye URLs SEO por carpetas.</p>
        </div>
        <?php
    }
}
