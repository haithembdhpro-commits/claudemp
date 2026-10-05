<?php
/**
 * Module WeConvert.io Suite (chargé par weconvert-suite.php — ne pas activer seul) :
 *   WeConvert.io — Pixel Top-of-Funnel
 * Description: Fire PageView et ViewContent côté navigateur ET côté serveur (CAPI).
 *              Déduplication automatique browser/server via eventID partagé.
 *              Utilise le fingerprint KB si disponible pour améliorer le match rate EMQ.
 * Version:     1.0.0
 * Author:      WeConvert.io
 *
 * Dépendances :
 *   - weconvert-settings.php   → kb_get() pour la config
 *   - weconvert-fingerprint.php (optionnel) → kb_fp_get_user_data() pour EMQ renforcé
 *
 * Events produits :
 *   PageView    — toutes les pages (browser uniquement, pas de CAPI — volume trop élevé)
 *   ViewContent — fiches produit WooCommerce (browser + CAPI server-side)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── CONFIG ──────────────────────────────────────────────────────────────────

function kb_px_cfg( string $key, $default = '' ) {
    if ( function_exists( 'kb_get' ) ) return kb_get( $key, $default );
    return get_option( 'kb_' . $key, $default );
}

// ─── HELPERS (partagés avec capi.php — guard pour éviter redéfinition) ────────

if ( ! function_exists( 'kb_px_hash' ) ) {
    function kb_px_hash( $value ): ?string {
        $value = trim( strtolower( (string) $value ) );
        return $value !== '' ? hash( 'sha256', $value ) : null;
    }
}

if ( ! function_exists( 'kb_px_hash_phone' ) ) {
    function kb_px_hash_phone( $phone ): ?string {
        $phone = preg_replace( '/[^0-9]/', '', (string) $phone );
        if ( strlen( $phone ) === 10 && $phone[0] === '0' ) {
            $phone = '213' . substr( $phone, 1 );
        }
        return $phone !== '' ? hash( 'sha256', $phone ) : null;
    }
}

if ( ! function_exists( 'kb_px_get_ip' ) ) {
    function kb_px_get_ip(): ?string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ] as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $h ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            }
        }
        return null;
    }
}

// ─── LIT fbp/fbc depuis les cookies HTTP bruts ───────────────────────────────

function kb_px_read_cookies(): array {
    $fbp = '';
    $fbc = '';
    $raw = $_SERVER['HTTP_COOKIE'] ?? '';
    if ( $raw !== '' ) {
        foreach ( explode( ';', $raw ) as $pair ) {
            $parts = explode( '=', trim( $pair ), 2 );
            if ( count( $parts ) === 2 ) {
                if ( trim( $parts[0] ) === '_fbp' ) $fbp = trim( $parts[1] );
                if ( trim( $parts[0] ) === '_fbc' ) $fbc = trim( $parts[1] );
            }
        }
    }
    return [ 'fbp' => $fbp, 'fbc' => $fbc ];
}

// ─── CONSTRUIT user_data pour CAPI ───────────────────────────────────────────
// Priorise kb_fp_get_user_data() si weconvert-fingerprint est actif,
// sinon utilise IP + UA + fbp/fbc disponibles.

function kb_px_build_user_data( string $event_id ): array {
    // Si fingerprint plugin actif → user_data enrichi
    if ( function_exists( 'kb_fp_get_user_data' ) ) {
        return kb_fp_get_user_data( $event_id );
    }

    // Fallback basique
    $cookies = kb_px_read_cookies();
    return array_filter( [
        'client_ip_address' => kb_px_get_ip(),
        'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'fbc'               => $cookies['fbc'] ?: null,
        'fbp'               => $cookies['fbp'] ?: null,
    ] );
}

// ─── APPEL API META CAPI ─────────────────────────────────────────────────────

function kb_px_call_meta( array $payload ) {
    $pixel_id = kb_px_cfg( 'meta_pixel_id' );
    $token    = kb_px_cfg( 'meta_access_token' );
    $api_ver  = kb_px_cfg( 'meta_api_version', 'v19.0' );

    if ( ! $pixel_id || ! $token ) return;

    $url = sprintf(
        'https://graph.facebook.com/%s/%s/events?access_token=%s',
        $api_ver, $pixel_id, $token
    );

    $test_code = kb_px_cfg( 'meta_test_code' );
    if ( $test_code !== '' ) $payload['test_event_code'] = $test_code;

    // Fire-and-forget asynchrone via wp_remote_post non-bloquant
    // blocking=false → n'attend pas la réponse Meta, pas d'impact perf page
    wp_remote_post( $url, [
        'timeout'  => 0.01,   // non-bloquant
        'blocking' => false,
        'headers'  => [ 'Content-Type' => 'application/json' ],
        'body'     => wp_json_encode( $payload ),
    ] );
}

// ═══════════════════════════════════════════════════════════════════════════════
// PAGEVIEW
// Browser seulement — volume trop élevé pour CAPI (coûts + quota Meta)
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_footer', 'kb_px_inject_pageview_js', 5 );

function kb_px_inject_pageview_js() {
    // Ne pas re-fire sur la thank-you page — capi.php gère déjà Purchase là-bas
    if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) return;

    $pixel_id = kb_px_cfg( 'meta_pixel_id' );
    if ( ! $pixel_id ) return;

    // event_id unique par page + session (pour déduplication éventuelle future)
    $event_id = 'kb_pv_' . substr( md5( $pixel_id . get_the_ID() . ( $_SERVER['REQUEST_URI'] ?? '' ) ), 0, 16 );
    ?>
    <script>
    (function() {
        // Initialisation pixel Meta (si pas déjà fait par un autre plugin)
        if ( typeof window.fbq === 'undefined' ) {
            !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
            n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
            document,'script','https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', '<?php echo esc_js( $pixel_id ); ?>');
        }
        fbq('track', 'PageView', {}, { eventID: '<?php echo esc_js( $event_id ); ?>' });
    })();
    </script>
    <noscript>
        <img height="1" width="1" style="display:none"
             src="https://www.facebook.com/tr?id=<?php echo esc_attr( $pixel_id ); ?>&ev=PageView&noscript=1" />
    </noscript>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// VIEWCONTENT
// Browser + CAPI server-side sur les fiches produit WooCommerce
// ═══════════════════════════════════════════════════════════════════════════════

// ── CAPI server-side ViewContent ──────────────────────────────────────────────
// Déclenché via REST endpoint appelé en JS (non-bloquant pour le visiteur)

add_action( 'rest_api_init', 'kb_px_register_viewcontent_endpoint' );

function kb_px_register_viewcontent_endpoint() {
    register_rest_route( 'weconvert/v1', '/viewcontent', [
        'methods'             => 'POST',
        'callback'            => 'kb_px_viewcontent_capi_handler',
        'permission_callback' => '__return_true',
    ] );
}

function kb_px_viewcontent_capi_handler( WP_REST_Request $request ) {
    $body       = $request->get_json_params();
    $product_id = isset( $body['product_id'] ) ? intval( $body['product_id'] ) : 0;
    $event_id   = isset( $body['event_id'] )   ? sanitize_text_field( $body['event_id'] ) : '';
    $fbp        = isset( $body['fbp'] )         ? sanitize_text_field( $body['fbp'] ) : '';
    $fbc        = isset( $body['fbc'] )         ? sanitize_text_field( $body['fbc'] ) : '';
    $fp_data    = isset( $body['fp'] )          ? (array) $body['fp'] : [];

    if ( ! $product_id || ! $event_id ) {
        return new WP_REST_Response( [ 'error' => 'Missing product_id or event_id' ], 400 );
    }

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return new WP_REST_Response( [ 'error' => 'Product not found' ], 404 );
    }

    // Prix en EUR
    $raw_currency  = get_woocommerce_currency();
    $price_raw     = (float) $product->get_price();
    $price_eur     = strtoupper( $raw_currency ) === 'DZD'
        ? round( $price_raw / (float) kb_px_cfg( 'rate_dzd_eur', 285 ), 2 )
        : $price_raw;
    $send_currency = strtoupper( $raw_currency ) === 'DZD' ? 'EUR' : strtoupper( $raw_currency );

    // User data — combine fingerprint JS + headers serveur
    $user_data = array_filter( [
        'client_ip_address' => kb_px_get_ip(),
        'client_user_agent' => $request->get_header( 'user_agent' ) ?: ( $_SERVER['HTTP_USER_AGENT'] ?? null ),
        'fbc'               => $fbc ?: null,
        'fbp'               => $fbp ?: null,
        // Données fingerprint enrichies envoyées depuis le JS
        'external_id'       => ! empty( $fp_data['external_id'] ) ? hash( 'sha256', sanitize_text_field( $fp_data['external_id'] ) ) : null,
    ] );

    $payload = [
        'data' => [ [
            'event_name'       => 'ViewContent',
            'event_time'       => time(),
            'event_id'         => $event_id,
            'action_source'    => 'website',
            'event_source_url' => get_permalink( $product_id ),
            'user_data'        => $user_data,
            'custom_data'      => [
                'value'        => $price_eur,
                'currency'     => $send_currency,
                'content_ids'  => [ (string) $product_id ],
                'content_name' => $product->get_name(),
                'content_type' => 'product',
                'contents'     => [ [
                    'id'         => (string) $product_id,
                    'quantity'   => 1,
                    'item_price' => $price_eur,
                ] ],
            ],
        ] ],
    ];

    $test_code = kb_px_cfg( 'meta_test_code' );
    if ( $test_code !== '' ) $payload['test_event_code'] = $test_code;

    $pixel_id = kb_px_cfg( 'meta_pixel_id' );
    $token    = kb_px_cfg( 'meta_access_token' );
    $api_ver  = kb_px_cfg( 'meta_api_version', 'v19.0' );

    if ( ! $pixel_id || ! $token ) {
        return new WP_REST_Response( [ 'error' => 'Pixel not configured' ], 500 );
    }

    $url = sprintf(
        'https://graph.facebook.com/%s/%s/events?access_token=%s',
        $api_ver, $pixel_id, $token
    );

    $response = wp_remote_post( $url, [
        'timeout' => 10,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( $payload ),
    ] );

    if ( is_wp_error( $response ) ) {
        return new WP_REST_Response( [ 'error' => $response->get_error_message() ], 500 );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $resp = json_decode( wp_remote_retrieve_body( $response ), true );

    return new WP_REST_Response( [
        'status'          => $code === 200 ? 'ok' : 'error',
        'events_received' => $resp['events_received'] ?? 0,
        'event_id'        => $event_id,
    ], $code === 200 ? 200 : 502 );
}

// ── JS ViewContent — injecté sur les fiches produit ──────────────────────────

add_action( 'wp_footer', 'kb_px_inject_viewcontent_js', 10 );

function kb_px_inject_viewcontent_js() {
    // Uniquement sur les fiches produit WooCommerce
    if ( ! function_exists( 'is_product' ) || ! is_product() ) return;

    global $post;
    $product = wc_get_product( $post->ID );
    if ( ! $product ) return;

    $pixel_id   = kb_px_cfg( 'meta_pixel_id' );
    if ( ! $pixel_id ) return;

    $product_id    = $product->get_id();
    $product_name  = $product->get_name();
    $raw_currency  = get_woocommerce_currency();
    $price_raw     = (float) $product->get_price();
    $price_eur     = strtoupper( $raw_currency ) === 'DZD'
        ? round( $price_raw / (float) kb_px_cfg( 'rate_dzd_eur', 285 ), 2 )
        : $price_raw;
    $send_currency = strtoupper( $raw_currency ) === 'DZD' ? 'EUR' : strtoupper( $raw_currency );

    // event_id déterministe — même valeur envoyée au browser ET au CAPI endpoint
    // Inclut le timestamp minute pour éviter les faux doublons sur revisites
    $event_id = 'kb_vc_' . $product_id . '_' . substr( md5( $product_id . $pixel_id . floor( time() / 60 ) ), 0, 12 );

    $endpoint_url = rest_url( 'weconvert/v1/viewcontent' );

    ?>
    <script>
    (function() {
        var PIXEL_ID   = '<?php echo esc_js( $pixel_id ); ?>';
        var PRODUCT_ID = <?php echo (int) $product_id; ?>;
        var EVENT_ID   = '<?php echo esc_js( $event_id ); ?>';
        var ENDPOINT   = '<?php echo esc_js( $endpoint_url ); ?>';
        var PRICE      = <?php echo (float) $price_eur; ?>;
        var CURRENCY   = '<?php echo esc_js( $send_currency ); ?>';
        var NAME       = '<?php echo esc_js( $product_name ); ?>';

        // ── 1. Lire fbp / fbc depuis les cookies ─────────────────────────────
        function getCookie( name ) {
            var match = document.cookie.match( new RegExp( '(?:^|;\\s*)' + name + '=([^;]*)' ) );
            return match ? match[1] : '';
        }
        var fbp = getCookie( '_fbp' );
        var fbc = getCookie( '_fbc' );

        // ── 2. Récupérer l'external_id depuis le fingerprint KB si dispo ─────
        var fp_external_id = '';
        try {
            fp_external_id = window._kb_fp_id || localStorage.getItem( 'kb_fp_id' ) || '';
        } catch(e) {}

        // ── 3. Fire event browser fbq ─────────────────────────────────────────
        function fireViewContent() {
            if ( typeof window.fbq !== 'function' ) {
                setTimeout( fireViewContent, 150 );
                return;
            }
            fbq( 'track', 'ViewContent', {
                value        : PRICE,
                currency     : CURRENCY,
                content_ids  : [ String( PRODUCT_ID ) ],
                content_name : NAME,
                content_type : 'product',
                contents     : [ { id: String( PRODUCT_ID ), quantity: 1, item_price: PRICE } ],
            }, { eventID: EVENT_ID } );
        }
        fireViewContent();

        // ── 4. Appel CAPI server-side via REST endpoint (non-bloquant) ────────
        // Utilise fetch avec keepalive pour garantir l'envoi même si le visiteur
        // quitte la page avant la réponse
        var payload = JSON.stringify( {
            product_id : PRODUCT_ID,
            event_id   : EVENT_ID,
            fbp        : fbp,
            fbc        : fbc,
            fp         : { external_id: fp_external_id },
        } );

        if ( typeof fetch !== 'undefined' ) {
            fetch( ENDPOINT, {
                method   : 'POST',
                headers  : { 'Content-Type': 'application/json' },
                body     : payload,
                keepalive: true,
            } ).catch( function(){} ); // Silent fail — browser event suffit
        } else {
            // Fallback XHR pour vieux navigateurs
            var xhr = new XMLHttpRequest();
            xhr.open( 'POST', ENDPOINT, true );
            xhr.setRequestHeader( 'Content-Type', 'application/json' );
            xhr.send( payload );
        }
    })();
    </script>
    <?php
}

// ─── SECTION SETTINGS — ajout dans weconvert-settings si présent ──────────────
// Filtre pour que settings.php puisse afficher l'état de ce plugin

add_filter( 'kb_active_modules', function( $modules ) {
    $modules['pixel'] = [
        'label'  => '📡 Pixel Top-of-Funnel',
        'events' => [ 'PageView (browser)', 'ViewContent (browser + CAPI)' ],
    ];
    return $modules;
} );
