<?php
/**
 * Module WeConvert.io Suite (chargé par weconvert-suite.php — ne pas activer seul) :
 *   WeConvert.io — Fingerprint S-TIER
 * Description: Tracking first-party complet, résistant iOS14/ITP, AdBlockers et cookieless.
 *              Cross-session stitching via table DB — relie les visites anonymes aux commandes.
 *              Proxy pixel script Meta depuis ton domaine — invisible aux adblockers.
 *              EMQ 9+/10 sans infrastructure cloud tierce.
 * Version:     2.1.0
 * Author:      WeConvert.io
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'KB_FP_VERSION',    '2.1.0' );
define( 'KB_FP_DB_VERSION', '1' );

function kb_fp_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'kb_fingerprints';
}

// ═══════════════════════════════════════════════════════════════════════════════
// INSTALL — table cross-session stitching
// ═══════════════════════════════════════════════════════════════════════════════

// Note: kb_fp_install() est appelé via register_activation_hook dans weconvert-suite.php
add_action( 'plugins_loaded', 'kb_fp_maybe_upgrade' );

function kb_fp_install(): void {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table   = kb_fp_table();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        fp_hash      VARCHAR(64)  NOT NULL,
        external_id  VARCHAR(64)  NOT NULL,
        phone_hash   VARCHAR(64)  DEFAULT NULL,
        email_hash   VARCHAR(64)  DEFAULT NULL,
        fbp          VARCHAR(200) DEFAULT NULL,
        fbc          VARCHAR(200) DEFAULT NULL,
        ip_hash      VARCHAR(64)  DEFAULT NULL,
        ua_hash      VARCHAR(64)  DEFAULT NULL,
        canvas_hash  VARCHAR(32)  DEFAULT NULL,
        webgl_hash   VARCHAR(32)  DEFAULT NULL,
        order_count  INT UNSIGNED NOT NULL DEFAULT 0,
        last_seen    DATETIME     NOT NULL,
        created_at   DATETIME     NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY   uq_fp_hash   (fp_hash),
        KEY          idx_ext_id   (external_id),
        KEY          idx_phone    (phone_hash),
        KEY          idx_email    (email_hash),
        KEY          idx_seen     (last_seen)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    update_option( 'kb_fp_db_version', KB_FP_DB_VERSION );
}

function kb_fp_maybe_upgrade(): void {
    if ( get_option( 'kb_fp_db_version' ) !== KB_FP_DB_VERSION ) {
        kb_fp_install();
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// CONFIG
// ═══════════════════════════════════════════════════════════════════════════════

function kb_fp_cfg( string $key, $default = '' ) {
    if ( function_exists( 'kb_get' ) ) return kb_get( $key, $default );
    return get_option( 'kb_' . $key, $default );
}

// ═══════════════════════════════════════════════════════════════════════════════
// COOKIE HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Lit un cookie depuis les headers HTTP bruts.
 * Évite $_COOKIE / sanitize_text_field WP qui peut modifier les valeurs.
 */
function kb_fp_read_raw_cookie( string $name ): string {
    $raw = $_SERVER['HTTP_COOKIE'] ?? '';
    foreach ( explode( ';', $raw ) as $pair ) {
        $parts = explode( '=', trim( $pair ), 2 );
        if ( count( $parts ) === 2 && trim( $parts[0] ) === $name ) {
            return trim( $parts[1] );
        }
    }
    return '';
}

/**
 * Pose un cookie first-party via Set-Cookie header PHP.
 * Cette approche survit à iOS ITP — les cookies first-party ne sont pas effacés.
 */
function kb_fp_set_cookie( string $name, string $value, int $days = 90, bool $http_only = false ): void {
    if ( headers_sent() ) return;
    $host    = parse_url( home_url(), PHP_URL_HOST ) ?: '';
    $domain  = preg_match( '/\./', $host ) ? '.' . $host : $host;
    $expires = gmdate( 'D, d M Y H:i:s T', time() + $days * DAY_IN_SECONDS );
    $secure  = is_ssl() ? '; Secure' : '';
    $httponly= $http_only ? '; HttpOnly' : '';
    header(
        "Set-Cookie: {$name}={$value}; Path=/; Expires={$expires}; Domain={$domain}; SameSite=Lax{$secure}{$httponly}",
        false
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// 1. FIRST-PARTY _fbp
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Génère ou récupère le _fbp first-party.
 * Format identique au _fbp Meta officiel : fb.1.{ms}.{random10}
 * Posé via Set-Cookie PHP → first-party → 90j sur Safari iOS (ITP ne touche pas).
 */
function kb_fp_ensure_fbp(): string {
    $existing = kb_fp_read_raw_cookie( '_fbp' );
    if ( $existing && preg_match( '/^fb\.\d+\.\d+\.\d+$/', $existing ) ) {
        return $existing;
    }
    $fbp = 'fb.1.' . round( microtime( true ) * 1000 ) . '.' . mt_rand( 1000000000, 9999999999 );
    kb_fp_set_cookie( '_fbp', $fbp, 90, false ); // non-httpOnly : doit être lisible par fbq JS
    return $fbp;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 2. SESSION TOKEN (kb_st)
// ═══════════════════════════════════════════════════════════════════════════════

function kb_fp_get_session_token(): string {
    $existing = kb_fp_read_raw_cookie( 'kb_st' );
    if ( $existing && preg_match( '/^[0-9a-f]{64}$/', $existing ) ) {
        return $existing;
    }
    $token = bin2hex( random_bytes( 32 ) );
    kb_fp_set_cookie( 'kb_st', $token, 90, true ); // httpOnly
    return $token;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 3. IP HELPER
// ═══════════════════════════════════════════════════════════════════════════════

function kb_fp_get_ip(): ?string {
    foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ] as $h ) {
        if ( ! empty( $_SERVER[$h] ) ) {
            $ip = trim( explode( ',', $_SERVER[$h] )[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
        }
    }
    return null;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 4. CROSS-SESSION STITCHING — DB
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Résout ou crée un external_id stable pour un fp_hash donné.
 * Le même device retrouve toujours le même external_id même sans cookie.
 */
function kb_fp_resolve_external_id( string $fp_hash, array $ctx = [] ): string {
    global $wpdb;
    $table = kb_fp_table();
    $now   = current_time( 'mysql' );

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, external_id, fbp, fbc FROM {$table} WHERE fp_hash = %s LIMIT 1",
        $fp_hash
    ) );

    if ( $row ) {
        $upd = [ 'last_seen' => $now ];
        if ( ! empty( $ctx['fbp'] ) && $ctx['fbp'] !== $row->fbp ) $upd['fbp'] = substr( $ctx['fbp'], 0, 200 );
        if ( ! empty( $ctx['fbc'] ) && $ctx['fbc'] !== $row->fbc ) $upd['fbc'] = substr( $ctx['fbc'], 0, 200 );
        $wpdb->update( $table, $upd, [ 'id' => $row->id ] );
        return $row->external_id;
    }

    $external_id = hash( 'sha256', $fp_hash . '_' . $now . '_' . wp_rand() );
    $wpdb->insert( $table, [
        'fp_hash'     => $fp_hash,
        'external_id' => $external_id,
        'fbp'         => substr( $ctx['fbp']         ?? '', 0, 200 ),
        'fbc'         => substr( $ctx['fbc']         ?? '', 0, 200 ),
        'ip_hash'     => $ctx['ip_hash']              ?? null,
        'ua_hash'     => $ctx['ua_hash']              ?? null,
        'canvas_hash' => substr( $ctx['canvas_hash']  ?? '', 0, 32 ),
        'webgl_hash'  => substr( $ctx['webgl_hash']   ?? '', 0, 32 ),
        'last_seen'   => $now,
        'created_at'  => $now,
    ] );
    return $external_id;
}

/**
 * Lie un téléphone + email à un fingerprint (appelé à la commande).
 */
function kb_fp_link_identity( string $fp_hash, ?string $phone_raw, ?string $email_raw ): void {
    global $wpdb;
    $table = kb_fp_table();

    $phone_hash = $phone_raw ? kb_capi_hash_phone( $phone_raw ) : null;
    $email_hash = $email_raw ? hash( 'sha256', strtolower( trim( $email_raw ) ) ) : null;

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, order_count FROM {$table} WHERE fp_hash = %s LIMIT 1", $fp_hash
    ) );
    if ( ! $row ) return;

    $wpdb->update( $table, array_filter( [
        'phone_hash'  => $phone_hash,
        'email_hash'  => $email_hash,
        'order_count' => (int) $row->order_count + 1,
        'last_seen'   => current_time( 'mysql' ),
    ] ), [ 'id' => $row->id ] );
}

/**
 * Retrouve un external_id par téléphone (pour les orders sans fp_hash en meta).
 */
function kb_fp_find_by_phone( string $phone_raw ): ?string {
    global $wpdb;
    $phone_hash = kb_capi_hash_phone( $phone_raw );
    if ( ! $phone_hash ) return null;
    return $wpdb->get_var( $wpdb->prepare(
        "SELECT external_id FROM " . kb_fp_table() . " WHERE phone_hash = %s ORDER BY last_seen DESC LIMIT 1",
        $phone_hash
    ) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// 5. ENRICHISSEMENT CAPI — fonction partagée avec tous les plugins WeConvert.io
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Construit un user_data complet et enrichi pour Meta CAPI à partir d'un order WC.
 * Override de kb_capi_build_user_data_from_order() défini dans weconvert-capi.php.
 * WeConvert.io-fingerprint.php s'active en priority 1 dans wp_head → ce fichier
 * est chargé avant capi.php → function_exists guard ci-dessous prend effet.
 */
function kb_fp_get_enriched_user_data( $order ): array {
    // ── Données WC ───────────────────────────────────────────────────────────
    $phone   = $order->get_billing_phone();
    $email   = $order->get_billing_email();
    $fn      = trim( $order->get_billing_first_name() );
    $ln      = trim( $order->get_billing_last_name() );
    $city    = $order->get_billing_city();
    $state   = $order->get_billing_state();
    $country = $order->get_billing_country();

    // ── external_id — cascade ────────────────────────────────────────────────
    $external_id = $order->get_meta( '_kb_external_id' );
    if ( ! $external_id && $phone ) {
        $external_id = kb_fp_find_by_phone( $phone );
    }
    if ( ! $external_id ) {
        $external_id = hash( 'sha256', 'order_' . $order->get_id() );
    }

    // ── fbp / fbc ────────────────────────────────────────────────────────────
    $fbp = $order->get_meta( '_kb_fbp' ) ?: kb_fp_read_raw_cookie( '_fbp' ) ?: null;
    $fbc = $order->get_meta( '_kb_fbc' ) ?: kb_fp_read_raw_cookie( '_fbc' ) ?: null;

    // ── IP / UA ──────────────────────────────────────────────────────────────
    $ip = $order->get_meta( '_kb_client_ip' ) ?: $order->get_customer_ip_address() ?: kb_fp_get_ip();
    $ua = $order->get_meta( '_kb_client_ua' ) ?: $order->get_customer_user_agent() ?: ( $_SERVER['HTTP_USER_AGENT'] ?? null );

    // ── Hash (normalisation Meta : helpers partagés de weconvert-capi.php) ───
    $names   = kb_capi_name_hashes( $fn, $ln );
    $country = $country ?: 'DZ';

    return array_filter( [
        'ph'                => kb_capi_hash_phone( $phone ),
        'fn'                => $names['fn'],
        'ln'                => $names['ln'],
        'em'                => $email  ? hash( 'sha256', strtolower( trim( $email ) ) )   : null,
        'ct'                => kb_capi_hash_text( $city ),
        'st'                => kb_capi_hash_text( kb_capi_state_name( $country, (string) $state ) ),
        'country'           => hash( 'sha256', strtolower( $country ) ),
        'external_id'       => $external_id,
        'fbc'               => $fbc,
        'fbp'               => $fbp,
        'client_ip_address' => $ip  ?: null,
        'client_user_agent' => $ua  ?: null,
    ] );
}

// Override global — weconvert-capi.php utilise kb_capi_build_user_data_from_order()
// Ce fichier est chargé en premier (order d'activation) → on définit ici la version enrichie
if ( ! function_exists( 'kb_capi_build_user_data_from_order' ) ) {
    function kb_capi_build_user_data_from_order( $order ): array {
        return kb_fp_get_enriched_user_data( $order );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// 6. HOOK CHECKOUT — sauvegarde meta order + liaison identity
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'woocommerce_checkout_order_processed', 'kb_fp_enrich_on_checkout', 3, 1 );
add_action( 'woocommerce_checkout_order_created',   'kb_fp_enrich_on_checkout', 3, 1 );

function kb_fp_enrich_on_checkout( $order_or_id ): void {
    $order = is_object( $order_or_id ) ? $order_or_id : wc_get_order( $order_or_id );
    if ( ! $order || $order->get_meta( '_kb_fp_enriched' ) ) return;

    $session_token = kb_fp_get_session_token();
    $ip            = kb_fp_get_ip();
    $ua            = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Récupérer le transient de session AVANT d'appeler kb_fp_ensure_fbp(),
    // car le transient contient déjà le fbp posé lors de fp-collect (wp_head).
    $transient_key = 'kb_fp_' . substr( $session_token, 0, 16 );
    $cached        = get_transient( $transient_key ) ?: [];
    $fp_hash       = $cached['fp_hash'] ?? null;

    // ── fbp : cascade de 3 sources ───────────────────────────────────────────
    // 1. Cookie HTTP brut (cas standard, browser a le cookie depuis wp_head)
    // 2. Transient de session (posé par /fp-collect, survit si headers_sent() au checkout)
    // 3. kb_fp_ensure_fbp() : génère et tente de poser le cookie via Set-Cookie.
    //    Si headers_sent() = true (checkout AJAX / Gutenberg), le cookie ne part pas
    //    mais la valeur retournée est utilisée directement → fbp toujours présent.
    $fbp = kb_fp_read_raw_cookie( '_fbp' );
    if ( ! $fbp || ! preg_match( '/^fb\.\d+\.\d+\.\d+$/', $fbp ) ) {
        $fbp = $cached['fbp'] ?? '';
    }
    if ( ! $fbp || ! preg_match( '/^fb\.\d+\.\d+\.\d+$/', $fbp ) ) {
        // kb_fp_ensure_fbp() génère la valeur même si Set-Cookie échoue.
        // On récupère la valeur retournée (pas forcément dans le cookie).
        $fbp = kb_fp_ensure_fbp();
    }

    $fbc = kb_fp_read_raw_cookie( '_fbc' );
    $external_id = null;

    if ( $fp_hash ) {
        $external_id = kb_fp_resolve_external_id( $fp_hash, [
            'fbp'         => $fbp,
            'fbc'         => $fbc,
            'ip_hash'     => hash( 'sha256', $ip ?: '' ),
            'ua_hash'     => hash( 'sha256', $ua ),
            'canvas_hash' => $cached['canvas_hash'] ?? '',
            'webgl_hash'  => $cached['webgl_hash']  ?? '',
        ] );
        kb_fp_link_identity( $fp_hash, $order->get_billing_phone(), $order->get_billing_email() );
    } else {
        // Pas de fingerprint JS → fallback session token
        $external_id = hash( 'sha256', $session_token );
    }

    $order->update_meta_data( '_kb_fbp',          $fbp );
    $order->update_meta_data( '_kb_fbc',           $fbc );
    $order->update_meta_data( '_kb_client_ip',     $ip ?: '' );
    $order->update_meta_data( '_kb_client_ua',     $ua );
    $order->update_meta_data( '_kb_external_id',   $external_id );
    $order->update_meta_data( '_kb_session_token', $session_token );
    if ( $fp_hash ) $order->update_meta_data( '_kb_fp_hash', $fp_hash );
    $order->update_meta_data( '_kb_fp_enriched',   '1' );
    $order->save();
}

// ═══════════════════════════════════════════════════════════════════════════════
// 7. REST ENDPOINTS
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'rest_api_init', 'kb_fp_register_endpoints' );

function kb_fp_register_endpoints(): void {
    // Collecte signaux JS → fp_hash → external_id
    register_rest_route( 'weconvert/v1', '/fp-collect', [
        'methods'             => 'POST',
        'callback'            => 'kb_fp_collect_handler',
        'permission_callback' => '__return_true',
    ] );
    // Proxy fbevents.js depuis ton domaine (adblock bypass)
    register_rest_route( 'weconvert/v1', '/fbevents.js', [
        'methods'             => 'GET',
        'callback'            => 'kb_fp_proxy_script_handler',
        'permission_callback' => '__return_true',
    ] );
    // Proxy events fbq (adblock bypass)
    register_rest_route( 'weconvert/v1', '/pixel-proxy', [
        'methods'             => 'POST',
        'callback'            => 'kb_fp_proxy_event_handler',
        'permission_callback' => '__return_true',
    ] );
}

// ── fp-collect ────────────────────────────────────────────────────────────────

function kb_fp_collect_handler( WP_REST_Request $req ): WP_REST_Response {
    $body = $req->get_json_params();
    if ( empty( $body ) ) return new WP_REST_Response( [ 'ok' => false ], 400 );

    $session_token = kb_fp_get_session_token();
    $fbp           = kb_fp_ensure_fbp();
    $fbc           = kb_fp_read_raw_cookie( '_fbc' );
    $ip            = kb_fp_get_ip();
    $ua            = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $allowed = [
        'canvas_hash', 'webgl_hash', 'audio_hash', 'fonts_hash',
        'screen_w', 'screen_h', 'pixel_ratio', 'color_depth',
        'tz_offset', 'lang', 'platform', 'cpu_cores', 'touch', 'plugins_count',
    ];
    $signals = [];
    foreach ( $allowed as $k ) {
        if ( isset( $body[$k] ) ) $signals[$k] = sanitize_text_field( (string) $body[$k] );
    }
    $signals['ip_hash'] = hash( 'sha256', $ip ?: '' );
    $signals['ua_hash'] = hash( 'sha256', $ua );

    // fp_hash composite — stable par device
    $fp_hash = hash( 'sha256', implode( '|', [
        $signals['canvas_hash']  ?? '',
        $signals['webgl_hash']   ?? '',
        $signals['screen_w']     ?? '',
        $signals['screen_h']     ?? '',
        $signals['pixel_ratio']  ?? '',
        $signals['tz_offset']    ?? '',
        $signals['platform']     ?? '',
        $signals['cpu_cores']    ?? '',
        $signals['ua_hash'],
    ] ) );

    $external_id = kb_fp_resolve_external_id( $fp_hash, array_merge( $signals, [
        'fbp' => $fbp, 'fbc' => $fbc,
    ] ) );

    // Cache transient 30min pour le checkout
    $transient_key = 'kb_fp_' . substr( $session_token, 0, 16 );
    set_transient( $transient_key, array_merge( $signals, [
        'fp_hash'     => $fp_hash,
        'external_id' => $external_id,
        'fbp'         => $fbp,
        'fbc'         => $fbc,
    ] ), 30 * MINUTE_IN_SECONDS );

    return new WP_REST_Response( [
        'ok'          => true,
        'fp_id'       => substr( $fp_hash, 0, 16 ),
        'external_id' => $external_id,
        'fbp'         => $fbp,
    ], 200 );
}

// ── fbevents.js proxy ────────────────────────────────────────────────────────

function kb_fp_proxy_script_handler(): void {
    $cached = get_transient( 'kb_fbevents_js' );

    if ( ! $cached ) {
        $resp = wp_remote_get( 'https://connect.facebook.net/en_US/fbevents.js', [
            'timeout'    => 10,
            'user-agent' => 'Mozilla/5.0 (compatible; WeConvertProxy/2.0)',
        ] );
        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            // Fallback redirect
            header( 'Location: https://connect.facebook.net/en_US/fbevents.js', true, 302 );
            exit;
        }
        $cached = wp_remote_retrieve_body( $resp );
        set_transient( 'kb_fbevents_js', $cached, HOUR_IN_SECONDS );
    }

    header( 'Content-Type: application/javascript; charset=utf-8' );
    header( 'Cache-Control: public, max-age=3600' );
    header( 'X-Robots-Tag: noindex' );
    echo $cached; // phpcs:ignore
    exit;
}

// ── pixel-proxy : events fbq → CAPI server-side ───────────────────────────────

/**
 * Events que le proxy ne doit PAS renvoyer en CAPI, parce qu'un module dédié
 * les envoie déjà côté serveur avec le même event_id.
 */
function kb_fp_proxy_skip_events(): array {
    $skip = [];
    if ( function_exists( 'kb_capi_send_purchase' ) )               $skip[] = 'Purchase';
    if ( function_exists( 'kb_px_viewcontent_capi_handler' ) )      $skip[] = 'ViewContent';
    if ( function_exists( 'kb_ev_addtocart_handler' ) )             $skip[] = 'AddToCart';
    if ( function_exists( 'kb_ev_initiatecheckout_handler' ) )      $skip[] = 'InitiateCheckout';
    return $skip;
}

function kb_fp_proxy_event_handler( WP_REST_Request $req ): WP_REST_Response {
    $body = $req->get_json_params();
    if ( empty( $body['event_name'] ) || empty( $body['event_id'] ) ) {
        return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
    }

    $event_name = sanitize_text_field( $body['event_name'] );
    $event_id   = sanitize_text_field( $body['event_id'] );

    $allowed_events = [ 'PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase' ];
    if ( ! in_array( $event_name, $allowed_events, true ) ) {
        return new WP_REST_Response( [ 'error' => 'Event not allowed' ], 403 );
    }

    // Ces events ont déjà leur propre envoi CAPI (même event_id) avec un user_data
    // plus riche : Purchase (capi.php, ph/fn/ln/ct/st), ViewContent (pixel.php),
    // AddToCart + InitiateCheckout (events.php). Les renvoyer ici créait un 2e event
    // serveur SANS ph/fn/ln → couverture ph/fn/ln/ct/st bloquée à ~50 % dans l'EMQ.
    if ( in_array( $event_name, kb_fp_proxy_skip_events(), true ) ) {
        return new WP_REST_Response( [ 'ok' => true, 'skipped' => 'server_side_module', 'event_id' => $event_id ], 200 );
    }

    $session_token = kb_fp_get_session_token();
    $transient_key = 'kb_fp_' . substr( $session_token, 0, 16 );
    $cached        = get_transient( $transient_key ) ?: [];

    $fbp = sanitize_text_field( $body['fbp'] ?? '' )
        ?: ( $cached['fbp'] ?? '' )
        ?: kb_fp_ensure_fbp();
    $fbc = sanitize_text_field( $body['fbc'] ?? '' ) ?: ( $cached['fbc'] ?? '' );
    $external_id = $cached['external_id'] ?? hash( 'sha256', $session_token );
    $ip  = kb_fp_get_ip();
    $ua  = $req->get_header( 'user_agent' ) ?: ( $_SERVER['HTTP_USER_AGENT'] ?? null );

    $user_data = array_filter( [
        'client_ip_address' => $ip,
        'client_user_agent' => $ua,
        'fbc'               => $fbc ?: null,
        'fbp'               => $fbp ?: null,
        'external_id'       => $external_id,
    ] );

    $cd = $body['custom_data'] ?? [];
    $custom_data = array_filter( [
        'value'        => isset( $cd['value'] )        ? (float) $cd['value']                                    : null,
        'currency'     => isset( $cd['currency'] )     ? sanitize_text_field( $cd['currency'] )                  : null,
        'content_ids'  => isset( $cd['content_ids'] )  ? array_map( 'sanitize_text_field', (array) $cd['content_ids'] ) : null,
        'content_type' => isset( $cd['content_type'] ) ? sanitize_text_field( $cd['content_type'] )              : null,
        'content_name' => isset( $cd['content_name'] ) ? sanitize_text_field( $cd['content_name'] )              : null,
        'contents'     => isset( $cd['contents'] )     ? (array) $cd['contents']                                 : null,
        'num_items'    => isset( $cd['num_items'] )    ? (int) $cd['num_items']                                   : null,
    ] );

    $pixel_id = kb_fp_cfg( 'meta_pixel_id' );
    $token    = kb_fp_cfg( 'meta_access_token' );
    $api_ver  = kb_fp_cfg( 'meta_api_version', 'v19.0' );
    if ( ! $pixel_id || ! $token ) {
        return new WP_REST_Response( [ 'error' => 'Not configured' ], 500 );
    }

    $payload = [
        'data' => [ [
            'event_name'       => $event_name,
            'event_time'       => time(),
            'event_id'         => $event_id,
            'action_source'    => 'website',
            'event_source_url' => sanitize_url( $body['source_url'] ?? home_url( '/' ) ),
            'user_data'        => $user_data,
            'custom_data'      => $custom_data ?: new stdClass(),
        ] ],
    ];

    $test_code = kb_fp_cfg( 'meta_test_code' );
    if ( $test_code ) $payload['test_event_code'] = $test_code;

    $resp = wp_remote_post(
        sprintf( 'https://graph.facebook.com/%s/%s/events?access_token=%s', $api_ver, $pixel_id, $token ),
        [ 'timeout' => 8, 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( $payload ) ]
    );

    $code = is_wp_error( $resp ) ? 500 : wp_remote_retrieve_response_code( $resp );
    $data = is_wp_error( $resp ) ? [] : json_decode( wp_remote_retrieve_body( $resp ), true );

    return new WP_REST_Response( [
        'ok'              => $code === 200,
        'events_received' => $data['events_received'] ?? 0,
        'event_id'        => $event_id,
    ], 200 ); // Toujours 200 → pas de console errors qui alertent les adblockers
}

// ═══════════════════════════════════════════════════════════════════════════════
// 8. JS — injecté en priority 1 dans wp_head (avant tout autre script)
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_head', 'kb_fp_inject_js', 1 );

function kb_fp_inject_js(): void {
    $pixel_id = kb_fp_cfg( 'meta_pixel_id' );
    if ( ! $pixel_id ) return;

    $session_token = kb_fp_get_session_token();
    $fbp           = kb_fp_ensure_fbp();
    $fp_id_init    = substr( hash( 'sha256', $session_token ), 0, 16 );

    $collect_url  = rest_url( 'weconvert/v1/fp-collect' );
    $proxy_url    = rest_url( 'weconvert/v1/pixel-proxy' );
    $fbevents_url = rest_url( 'weconvert/v1/fbevents.js' );
    ?>
<script id="kb-fp-stier">
(function(){
'use strict';

// ── Globals exposés ────────────────────────────────────────────────────────
window._kb_fp_id       = '<?php echo esc_js( $fp_id_init ); ?>';
window._kb_fbp         = '<?php echo esc_js( $fbp ); ?>';
window._kb_proxy_url   = '<?php echo esc_js( $proxy_url ); ?>';
window._kb_pixel_id    = '<?php echo esc_js( $pixel_id ); ?>';
window._kb_fbevents_url= '<?php echo esc_js( $fbevents_url ); ?>';
window._kb_proxy_skip  = <?php echo wp_json_encode( kb_fp_proxy_skip_events() ); ?>;

try {
    var s = localStorage.getItem('kb_fp_id');
    if (s) window._kb_fp_id = s;
} catch(e) {}

// ── Cookie helper ──────────────────────────────────────────────────────────
function gc(n) {
    var m = document.cookie.match(new RegExp('(?:^|;\\s*)'+n+'=([^;]*)'));
    return m ? m[1] : '';
}

// ── Fingerprint signals ────────────────────────────────────────────────────

function canvasHash() {
    try {
        var c=document.createElement('canvas'); c.width=300; c.height=80;
        var x=c.getContext('2d');
        x.textBaseline='alphabetic'; x.fillStyle='#f69d3c'; x.fillRect(0,0,300,80);
        x.font='16px Arial'; x.fillStyle='#069';
        x.fillText('KitabookDZ \uD83C\uDDE9\uD83C\uDDFF Tracking',2,30);
        x.font='14px Times New Roman'; x.fillStyle='rgba(102,200,0,.7)';
        x.fillText('ABCDEFabcdef0123456789',10,60);
        var d=c.toDataURL(),h=0;
        for(var i=0;i<Math.min(d.length,2000);i++){h=((h<<5)-h)+d.charCodeAt(i);h|=0;}
        return Math.abs(h).toString(36);
    } catch(e){ return 'x'; }
}

function webglHash() {
    try {
        var c=document.createElement('canvas');
        var gl=c.getContext('webgl')||c.getContext('experimental-webgl');
        if(!gl) return 'nowgl';
        var dbg=gl.getExtension('WEBGL_debug_renderer_info');
        var r=dbg?gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL):gl.getParameter(gl.RENDERER);
        var v=dbg?gl.getParameter(dbg.UNMASKED_VENDOR_WEBGL):gl.getParameter(gl.VENDOR);
        var s=(r||'')+'|'+(v||''),h=0;
        for(var i=0;i<s.length;i++){h=((h<<5)-h)+s.charCodeAt(i);h|=0;}
        return Math.abs(h).toString(36);
    } catch(e){ return 'x'; }
}

function audioFingerprint() {
    // Async — résultat dans window._kb_audio_hash après ~300ms
    try {
        var ctx=new(window.OfflineAudioContext||window.webkitOfflineAudioContext)(1,44100,44100);
        var osc=ctx.createOscillator();
        var cmp=ctx.createDynamicsCompressor();
        [['threshold',-50],['knee',40],['ratio',12],['attack',0],['release',.25]].forEach(function(p){
            if(cmp[p[0]]&&cmp[p[0]].setValueAtTime) cmp[p[0]].setValueAtTime(p[1],ctx.currentTime);
        });
        osc.type='triangle'; osc.connect(cmp); cmp.connect(ctx.destination); osc.start(0);
        ctx.startRendering();
        ctx.oncomplete=function(e){
            var buf=e.renderedBuffer.getChannelData(0),sum=0;
            for(var i=0;i<Math.min(buf.length,5000);i++) sum+=Math.abs(buf[i]);
            window._kb_audio_hash=(Math.round(sum*10000)).toString(36);
        };
    } catch(e){ window._kb_audio_hash='x'; }
}

function fontsHash() {
    var fonts=['Arial','Arial Black','Comic Sans MS','Courier New','Georgia',
               'Impact','Tahoma','Times New Roman','Trebuchet MS','Verdana',
               'Calibri','Cambria','Consolas','Segoe UI','Lucida Console',
               'Century Gothic','Palatino Linotype','Franklin Gothic Medium',
               'Gill Sans MT','Book Antiqua','Garamond','Rockwell',
               'Copperplate','Optima','Futura','Baskerville','Didot',
               'Monaco','Courier','Helvetica'];
    var t=document.createElement('span');
    t.style.cssText='position:absolute;left:-9999px;font-size:72px;font-family:monospace';
    t.innerHTML='mmmmmmmmmmlli';
    document.body.appendChild(t);
    var bw=t.offsetWidth, bh=t.offsetHeight, bits='';
    fonts.forEach(function(f){
        t.style.fontFamily='"'+f+'",monospace';
        bits+=(t.offsetWidth!==bw||t.offsetHeight!==bh)?'1':'0';
    });
    document.body.removeChild(t);
    var h=0;
    for(var i=0;i<bits.length;i++){h=((h<<5)-h)+bits.charCodeAt(i);h|=0;}
    return Math.abs(h).toString(36);
}

function collectAndSend() {
    audioFingerprint(); // lance async

    var cv=canvasHash(), wg=webglHash();
    var signals={
        canvas_hash : cv,
        webgl_hash  : wg,
        audio_hash  : 'pending',
        screen_w    : screen.width||0,
        screen_h    : screen.height||0,
        pixel_ratio : (window.devicePixelRatio||1).toFixed(2),
        color_depth : screen.colorDepth||0,
        tz_offset   : new Date().getTimezoneOffset(),
        lang        : navigator.language||'',
        platform    : navigator.platform||'',
        cpu_cores   : navigator.hardwareConcurrency||0,
        touch       : ('ontouchstart' in window||navigator.maxTouchPoints>0)?1:0,
        plugins_count:(navigator.plugins||[]).length,
    };
    try { signals.fonts_hash=fontsHash(); } catch(e){ signals.fonts_hash='x'; }

    function doSend() {
        if(window._kb_audio_hash && window._kb_audio_hash!=='x') {
            signals.audio_hash=window._kb_audio_hash;
        }
        fetch('<?php echo esc_js( $collect_url ); ?>',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify(signals),
            keepalive:true
        }).then(function(r){return r.json();})
        .then(function(d){
            if(d&&d.fp_id){
                window._kb_fp_id=d.fp_id;
                if(d.fbp) window._kb_fbp=d.fbp;
                try{localStorage.setItem('kb_fp_id',d.fp_id);}catch(e){}
            }
        }).catch(function(){});
    }
    // Attendre audio 350ms max
    setTimeout(doSend, 350);
}

// ── Proxy fbq — Dual mode A/B ──────────────────────────────────────────────
// Mode A : fbq se charge → on intercepte + duplique vers proxy (enrichissement)
// Mode B : fbq bloqué par adblock → fbq fantôme first-party

function sendToProxy(name,data,opts){
    var eid=opts&&(opts.eventID||opts.event_id);
    if(!eid) return;
    // Déjà envoyé en CAPI par son module dédié (même event_id) → pas de doublon serveur
    if((window._kb_proxy_skip||[]).indexOf(name)!==-1) return;
    fetch(window._kb_proxy_url,{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
            event_name:name, event_id:eid,
            fbp:gc('_fbp')||window._kb_fbp,
            fbc:gc('_fbc'),
            source_url:location.href,
            custom_data:data||{}
        }),
        keepalive:true
    }).catch(function(){});
}

function patchFbq(orig){
    if(window._kbFbqPatched) return;
    window._kbFbqPatched=true;
    var p=function(){
        var a=Array.prototype.slice.call(arguments);
        try{orig.apply(this,a);}catch(e){}
        if(a[0]==='track'&&a[1]!=='PageView') sendToProxy(a[1],a[2]||{},a[3]||{});
    };
    ['callMethod','queue','loaded','push','version'].forEach(function(k){
        if(orig[k]!==undefined) p[k]=orig[k];
    });
    for(var k in orig){ if(orig.hasOwnProperty(k)) p[k]=orig[k]; }
    window.fbq=p;
}

// Watcher fbq — check toutes les 100ms, timeout 3s
var checks=0,watcher=setInterval(function(){
    checks++;
    if(typeof window.fbq==='function'){
        clearInterval(watcher); patchFbq(window.fbq); return;
    }
    if(checks>=30){ // 3s → adblock détecté
        clearInterval(watcher);
        if(!window._kbFbqPatched){
            window._kbFbqPatched=true;
            var q=[];
            window.fbq=function(){
                var a=Array.prototype.slice.call(arguments);
                if(a[0]==='track') sendToProxy(a[1],a[2]||{},a[3]||{});
                q.push(a);
            };
            window.fbq.queue=q; window.fbq.loaded=false; window.fbq.push=window.fbq;
            window._kbFbqFallback=true;
        }
    }
},100);

// ── Charger fbevents.js depuis proxy first-party ───────────────────────────
// Exécuté après DOMContentLoaded pour ne pas bloquer le rendu initial
document.addEventListener('DOMContentLoaded',function(){

    if(!window.fbq){
        // Initialiser fbq stub avant le chargement du script
        var n=window.fbq=function(){
            n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments);
        };
        if(!window._fbq) window._fbq=n;
        n.push=n; n.loaded=!0; n.version='2.0'; n.queue=[];

        var s=document.createElement('script');
        s.async=true;
        // ← Chargé depuis TON domaine (first-party) — adblockers ne bloquent pas
        s.src=window._kb_fbevents_url;
        // init mis en file TOUT DE SUITE (avant le chargement du script) : sinon un
        // track déjà en file (ex. Purchase sur la thank-you page) passe avant l'init
        // et fbevents.js l'ignore. _kb_am = Advanced Matching hashé (thank-you page).
        n('init',window._kb_pixel_id,window._kb_am||{});
        s.onload=function(){
            fbq('track','PageView');
            patchFbq(window.fbq);
        };
        document.head.appendChild(s);
    }

    scheduleCollect();
});

// Empreinte = canvas + WebGL + audio + 30 mesures de polices (reflows forcés) :
// lancée APRÈS le chargement complet, quand le navigateur est inactif, pour ne pas
// retarder l'affichage (LCP mobile). Une fois par session suffit : le résultat
// serveur est gardé 30 min (transient) → on ne recollecte qu'après 25 min.
function scheduleCollect(){
    try{
        var last=+sessionStorage.getItem('kb_fp_sent')||0;
        if(Date.now()-last<25*60*1000) return;
    }catch(e){}
    var run=function(){
        try{sessionStorage.setItem('kb_fp_sent',String(Date.now()));}catch(e){}
        collectAndSend();
    };
    var idle=function(){
        if(window.requestIdleCallback) requestIdleCallback(run,{timeout:3000});
        else setTimeout(run,1500);
    };
    if(document.readyState==='complete') idle();
    else window.addEventListener('load',idle,{once:true});
}

})();
</script>
<?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// 9. CRON — nettoyage quotidien fingerprints inactifs
// ═══════════════════════════════════════════════════════════════════════════════

// Note: scheduling kb_fp_cleanup géré dans weconvert-suite.php

add_action( 'kb_fp_cleanup', function() {
    global $wpdb;
    // Anonymes inactifs depuis 90j → purge
    $wpdb->query(
        "DELETE FROM " . kb_fp_table() .
        " WHERE order_count = 0 AND last_seen < DATE_SUB(NOW(), INTERVAL 90 DAY)"
    );
    // Invalider le cache fbevents.js pour forcer refetch
    delete_transient( 'kb_fbevents_js' );
} );

// ═══════════════════════════════════════════════════════════════════════════════
// 10. ADMIN — stats page
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', function() {
    add_submenu_page(
        'weconvert-settings',
        'Fingerprint Stats',
        '🔒 Fingerprint',
        'manage_woocommerce',
        'kb-fingerprint-stats',
        'kb_fp_admin_stats'
    );
} );

function kb_fp_admin_stats(): void {
    global $wpdb;
    $table     = kb_fp_table();
    $total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    $linked    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE phone_hash IS NOT NULL" );
    $with_ord  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE order_count > 0" );
    $recent7   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 7 DAY)" );
    $link_rate = $total > 0 ? round( $linked / $total * 100, 1 ) : 0;
    $top       = $wpdb->get_results( "SELECT * FROM {$table} WHERE order_count > 0 ORDER BY order_count DESC, last_seen DESC LIMIT 10" );
    ?>
    <div class="wrap">
        <h1>🔒 WeConvert.io — Fingerprint S-TIER Stats</h1>
        <p style="color:#666">Table : <code><?= esc_html( $table ) ?></code> — Version : <?= KB_FP_VERSION ?></p>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:18px 0">
            <?php foreach ( [
                ['Devices uniques',  $total,    '#0c44ac'],
                ['Liés à un tél.',   $linked,   '#1a7a4a'],
                ['Avec commande',    $with_ord,  '#7a4a00'],
                ['Actifs 7 jours',   $recent7,  '#333'],
            ] as [$lbl,$val,$col] ) : ?>
            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px">
                <div style="font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px"><?= esc_html($lbl) ?></div>
                <div style="font-size:26px;font-weight:600;color:<?= esc_attr($col) ?>"><?= number_format($val) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <p>
            <strong>Taux liaison téléphone :</strong> <?= $link_rate ?>%
            <?php if ($link_rate>=50) echo '<span style="color:green">✅ Bon</span>';
            elseif ($link_rate>=20) echo '<span style="color:orange">⚠️ Moyen</span>';
            else echo '<span style="color:#c00">❌ Faible (normal au début)</span>'; ?>
        </p>
        <?php if ($top) : ?>
        <h2 style="margin-top:20px">Top devices par commandes</h2>
        <table class="wp-list-table widefat striped" style="margin-top:10px">
            <thead><tr>
                <th>External ID</th><th>Canvas</th><th>WebGL</th>
                <th>Orders</th><th>Tél. lié</th><th>Dernière visite</th>
            </tr></thead>
            <tbody>
            <?php foreach ($top as $r) : ?>
            <tr>
                <td><code><?= esc_html(substr($r->external_id,0,16)) ?>…</code></td>
                <td><code><?= esc_html($r->canvas_hash ?? '—') ?></code></td>
                <td><code><?= esc_html($r->webgl_hash  ?? '—') ?></code></td>
                <td><?= (int)$r->order_count ?></td>
                <td><?= $r->phone_hash ? '✅' : '—' ?></td>
                <td><?= esc_html($r->last_seen) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <h2 style="margin-top:22px">Architecture S-TIER</h2>
        <ol style="max-width:580px;line-height:2;color:#444;font-size:13px">
            <li><strong>First-party _fbp</strong> posé par PHP — survit 90j sur Safari iOS (ITP bypass)</li>
            <li><strong>fbevents.js proxy</strong> chargé depuis ton domaine — invisible à uBlock + Brave</li>
            <li><strong>Pixel event proxy</strong> — events fbq routés via ton serveur → Meta CAPI</li>
            <li><strong>Canvas + WebGL + Audio + Fonts</strong> — fingerprint stable sans cookie</li>
            <li><strong>Cross-session stitching</strong> — même device retrouve son external_id en DB</li>
            <li><strong>Liaison téléphone</strong> — commande → phone_hash → toutes visites futures reconnues</li>
        </ol>
    </div>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// DÉCLARATION MODULE
// ═══════════════════════════════════════════════════════════════════════════════

add_filter( 'kb_active_modules', function( $modules ) {
    $modules['fingerprint'] = [
        'label'    => '🔒 Fingerprint S-TIER v2.0',
        'features' => [
            'First-party _fbp PHP (iOS ITP-proof, 90j)',
            'fbevents.js proxy first-party (uBlock bypass)',
            'Pixel event proxy REST (adblock bypass total)',
            'Canvas + WebGL + AudioContext + Fonts hash',
            'Cross-session stitching DB (wp_kb_fingerprints)',
            'Enrichissement CAPI automatique — tous plugins',
            'Liaison téléphone → device à la commande',
            'Nettoyage cron quotidien',
        ],
    ];
    return $modules;
} );

// ═══════════════════════════════════════════════════════════════════════════════
// ENRICHISSEMENT MID-FUNNEL — fonctions exposées pour weconvert-events.php
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Retrouve phone_hash + email_hash liés à un fp_hash ou external_id.
 * Utilisé par les events mid-funnel (AddToCart, InitiateCheckout) pour
 * enrichir les user_data anonymes avec des signaux d'identité connus.
 *
 * @param string $fp_id  fp_hash (16 chars abrégé) ou external_id complet
 * @return array ['ph' => sha256|null, 'em' => sha256|null]
 */
function kb_fp_get_identity_by_fp( string $fp_id ): array {
    global $wpdb;
    if ( ! $fp_id ) return [ 'ph' => null, 'em' => null ];

    $table = kb_fp_table();

    // Essai 1 : external_id exact (64 chars)
    if ( strlen( $fp_id ) === 64 ) {
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT phone_hash, email_hash FROM {$table}
             WHERE external_id = %s AND (phone_hash IS NOT NULL OR email_hash IS NOT NULL)
             LIMIT 1",
            $fp_id
        ) );
        if ( $row ) return [ 'ph' => $row->phone_hash, 'em' => $row->email_hash ];
    }

    // Essai 2 : fp_hash commence par fp_id (16 chars abrégé envoyés par le JS)
    if ( strlen( $fp_id ) === 16 ) {
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT phone_hash, email_hash FROM {$table}
             WHERE fp_hash LIKE %s AND (phone_hash IS NOT NULL OR email_hash IS NOT NULL)
             ORDER BY last_seen DESC LIMIT 1",
            $wpdb->esc_like( $fp_id ) . '%'
        ) );
        if ( $row ) return [ 'ph' => $row->phone_hash, 'em' => $row->email_hash ];
    }

    return [ 'ph' => null, 'em' => null ];
}

/**
 * Retrouve external_id par email (complément de kb_fp_find_by_phone).
 */
function kb_fp_find_by_email( string $email_raw ): ?string {
    global $wpdb;
    $email_hash = hash( 'sha256', strtolower( trim( $email_raw ) ) );
    return $wpdb->get_var( $wpdb->prepare(
        "SELECT external_id FROM " . kb_fp_table() .
        " WHERE email_hash = %s ORDER BY last_seen DESC LIMIT 1",
        $email_hash
    ) );
}

/**
 * Construit le user_data enrichi pour un event mid-funnel anonyme.
 * Cascade : fingerprint DB → WC Customer connecté → anonyme pur.
 *
 * @param string $fp_id      fp_id du JS (_kb_fp_id)
 * @param string $fbp        _fbp first-party
 * @param string $fbc        _fbc
 * @param string $ip         IP client
 * @param string $ua         User-Agent client
 * @return array user_data prêt pour Meta CAPI
 */
function kb_fp_build_midfunnel_user_data(
    string $fp_id,
    string $fbp,
    string $fbc,
    string $ip,
    string $ua
): array {
    // ── 1. Résolution external_id depuis DB ──────────────────────────────────
    $external_id = null;
    $identity    = [ 'ph' => null, 'em' => null ];

    if ( $fp_id ) {
        // fp_id peut être le full external_id (64) ou l'abrégé JS (16)
        $identity    = kb_fp_get_identity_by_fp( $fp_id );
        $external_id = $fp_id;
        // Si fp_id est l'abrégé JS, chercher l'external_id complet
        if ( strlen( $fp_id ) === 16 ) {
            global $wpdb;
            $full = $wpdb->get_var( $wpdb->prepare(
                "SELECT external_id FROM " . kb_fp_table() .
                " WHERE fp_hash LIKE %s ORDER BY last_seen DESC LIMIT 1",
                $wpdb->esc_like( $fp_id ) . '%'
            ) );
            if ( $full ) $external_id = $full;
        }
    }

    // ── 2. WC Customer connecté — enrichissement si disponible ───────────────
    $wc_ph = null;
    $wc_em = null;
    if ( is_user_logged_in() && function_exists( 'WC' ) && WC()->customer ) {
        $customer = WC()->customer;
        $phone    = $customer->get_billing_phone();
        $email    = $customer->get_billing_email();
        if ( $phone ) $wc_ph = kb_capi_hash_phone( $phone );
        if ( $email ) $wc_em = hash( 'sha256', strtolower( trim( $email ) ) );
        // Si pas d'external_id DB, utiliser le customer_id WC
        if ( ! $external_id ) {
            $external_id = hash( 'sha256', 'wc_user_' . get_current_user_id() );
        }
    }

    // ── 3. Merge : DB > WC Customer > null ───────────────────────────────────
    $ph = $identity['ph'] ?: $wc_ph;
    $em = $identity['em'] ?: $wc_em;

    return array_filter( [
        'ph'                => $ph,
        'em'                => $em,
        'external_id'       => $external_id ?: null,
        'fbc'               => $fbc ?: null,
        'fbp'               => $fbp ?: null,
        'client_ip_address' => $ip  ?: null,
        'client_user_agent' => $ua  ?: null,
    ] );
}
