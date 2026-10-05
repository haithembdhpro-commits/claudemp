<?php
/**
 * Module WeConvert.io Suite (chargé par weconvert-suite.php — ne pas activer seul) :
 *   WeConvert.io — Events Mid-Funnel
 * Description: AddToCart et InitiateCheckout côté serveur (CAPI) + browser.
 *              Ferme le gap avec WeTracked sur la couverture des events mid-funnel.
 *              Zéro credential hardcodé — tout depuis WeConvert.io Settings.
 * Version:     1.0.0
 * Author:      WeConvert.io
 *
 * Events produits :
 *   AddToCart        — browser (fbq) + CAPI server-side via REST endpoint
 *   InitiateCheckout — browser (fbq) + CAPI server-side au chargement de /checkout
 *
 * Dépendances (optionnelles) :
 *   - weconvert-settings.php  → kb_get()
 *   - weconvert-capi.php      → kb_capi_call_meta(), kb_capi_to_eur(),
 *                              kb_capi_send_currency(), kb_capi_hash(),
 *                              kb_capi_hash_phone(), kb_capi_get_ip()
 *
 * Déduplication :
 *   AddToCart        — event_id = kb_atc_{product_id}_{session_token}_{minute}
 *   InitiateCheckout — event_id = kb_ic_{cart_hash}_{pixel_id}
 *   La fenêtre minute empêche le double-fire sur les refreshs rapides.
 *   Le cart_hash change dès que le panier change → pas de faux doublons.
 *
 * Ordre des plugins WP (priority dans wp_footer) :
 *   5  → weconvert-pixel.php (PageView)
 *   10 → weconvert-pixel.php (ViewContent)
 *   15 → weconvert-events.php (AddToCart inline sur product page)
 *   20 → weconvert-capi.php (Purchase — thank-you page seulement)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════════════════════
// CONFIG
// ═══════════════════════════════════════════════════════════════════════════════

function kb_ev_cfg( string $key, $default = '' ) {
    if ( function_exists( 'kb_get' ) ) return kb_get( $key, $default );
    return get_option( 'kb_' . $key, $default );
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS INLINE — actifs si weconvert-capi.php absent
// ═══════════════════════════════════════════════════════════════════════════════

if ( ! function_exists( 'kb_capi_hash' ) ) {
    function kb_capi_hash( $v ): ?string {
        $v = trim( strtolower( (string) $v ) );
        return $v !== '' ? hash( 'sha256', $v ) : null;
    }
}
if ( ! function_exists( 'kb_capi_hash_phone' ) ) {
    function kb_capi_hash_phone( $phone ): ?string {
        $phone = preg_replace( '/[^0-9]/', '', (string) $phone );
        if ( strlen( $phone ) === 10 && $phone[0] === '0' ) $phone = '213' . substr( $phone, 1 );
        elseif ( strlen( $phone ) === 9 && $phone[0] !== '0' ) $phone = '213' . $phone;
        return $phone !== '' ? hash( 'sha256', $phone ) : null;
    }
}
if ( ! function_exists( 'kb_capi_get_ip' ) ) {
    function kb_capi_get_ip(): ?string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ] as $h ) {
            if ( ! empty( $_SERVER[$h] ) ) {
                $ip = trim( explode( ',', $_SERVER[$h] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            }
        }
        return null;
    }
}
if ( ! function_exists( 'kb_capi_to_eur' ) ) {
    function kb_capi_to_eur( float $amount, string $currency ): float {
        if ( strtoupper( $currency ) !== 'DZD' ) return $amount;
        $rate = (float) kb_ev_cfg( 'rate_dzd_eur', 285 );
        return $rate > 0 ? round( $amount / $rate, 2 ) : round( $amount / 285, 2 );
    }
}
if ( ! function_exists( 'kb_capi_send_currency' ) ) {
    function kb_capi_send_currency( string $currency ): string {
        return strtoupper( $currency ) === 'DZD' ? 'EUR' : strtoupper( $currency );
    }
}
if ( ! function_exists( 'kb_capi_call_meta' ) ) {
    function kb_capi_call_meta( array $payload, bool $blocking = true ) {
        $pixel_id = kb_ev_cfg( 'meta_pixel_id' );
        $token    = kb_ev_cfg( 'meta_access_token' );
        $api_ver  = kb_ev_cfg( 'meta_api_version', 'v19.0' );
        if ( ! $pixel_id || ! $token ) return new WP_Error( 'kb_ev_config', 'Pixel ID ou token manquant.' );
        $url = sprintf( 'https://graph.facebook.com/%s/%s/events?access_token=%s', $api_ver, $pixel_id, $token );
        return wp_remote_post( $url, [
            'timeout'  => $blocking ? 15 : 0.01,
            'blocking' => $blocking,
            'headers'  => [ 'Content-Type' => 'application/json' ],
            'body'     => wp_json_encode( $payload ),
        ] );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS LOCAUX
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Lit fbp/fbc depuis les cookies HTTP bruts (sans sanitize WP qui lowercase).
 */
function kb_ev_read_fb_cookies(): array {
    $fbp = '';
    $fbc = '';
    foreach ( explode( ';', $_SERVER['HTTP_COOKIE'] ?? '' ) as $pair ) {
        $parts = explode( '=', trim( $pair ), 2 );
        if ( count( $parts ) === 2 ) {
            $name = trim( $parts[0] );
            $val  = trim( $parts[1] );
            if ( $name === '_fbp' ) $fbp = $val;
            if ( $name === '_fbc' ) $fbc = $val;
        }
    }
    return [ 'fbp' => $fbp, 'fbc' => $fbc ];
}

/**
 * Construit un user_data minimal pour les events mid-funnel (visiteur anonyme).
 * Le visiteur n'a pas encore rempli son adresse, donc seuls IP/UA/fbp/fbc
 * + external_id fingerprint sont disponibles.
 */
function kb_ev_build_anonymous_user_data( string $fbp = '', string $fbc = '', string $external_id = '' ): array {
    $cookies = kb_ev_read_fb_cookies();
    return array_filter( [
        'client_ip_address' => kb_capi_get_ip(),
        'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'fbc'               => $fbc ?: ( $cookies['fbc'] ?: null ),
        'fbp'               => $fbp ?: ( $cookies['fbp'] ?: null ),
        'external_id'       => $external_id ?: null,
    ] );
}

/**
 * Construit un user_data depuis WC_Customer (checkout page — utilisateur connecté
 * ou données pré-remplies par un précédent achat).
 */
function kb_ev_build_customer_user_data( string $fbp = '', string $fbc = '', string $ext_id = '' ): array {
    $customer = WC()->customer ?? null;

    if ( ! $customer ) {
        return kb_ev_build_anonymous_user_data( $fbp, $fbc, $ext_id );
    }

    $phone     = $customer->get_billing_phone();
    $email     = $customer->get_billing_email();
    $first     = $customer->get_billing_first_name();
    $last      = $customer->get_billing_last_name();
    $city      = $customer->get_billing_city();
    $state     = $customer->get_billing_state();
    $country   = $customer->get_billing_country();
    $cookies   = kb_ev_read_fb_cookies();

    $fb_p = $fbp ?: ( $cookies['fbp'] ?: null );
    $fb_c = $fbc ?: ( $cookies['fbc'] ?: null );

    return array_filter( [
        'ph'                => $phone  ? kb_capi_hash_phone( $phone )  : null,
        'em'                => $email  ? kb_capi_hash( $email )        : null,
        'fn'                => $first  ? kb_capi_hash( $first )        : null,
        'ln'                => $last   ? kb_capi_hash( $last )         : null,
        'ct'                => $city   ? kb_capi_hash( $city )         : null,
        'st'                => $state  ? kb_capi_hash( $state )        : null,
        'country'           => $country? kb_capi_hash( $country )      : null,
        'external_id'       => $ext_id ?: null,
        'fbc'               => $fb_c,
        'fbp'               => $fb_p,
        'client_ip_address' => kb_capi_get_ip(),
        'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ] );
}

/**
 * Envoie un payload CAPI avec test_code si configuré.
 * Fire-and-forget (blocking=false) pour ne pas ralentir le navigateur.
 */
function kb_ev_send_capi( array $payload_data, string $event_name, string $event_id, array $user_data, array $custom_data ): void {
    $pixel_id = kb_ev_cfg( 'meta_pixel_id' );
    if ( ! $pixel_id ) return;

    $payload = [
        'data' => [ [
            'event_name'       => $event_name,
            'event_time'       => time(),
            'event_id'         => $event_id,
            'action_source'    => 'website',
            'event_source_url' => $payload_data['source_url'] ?? home_url( '/' ),
            'user_data'        => $user_data,
            'custom_data'      => $custom_data,
        ] ],
    ];

    $test_code = kb_ev_cfg( 'meta_test_code' );
    if ( $test_code !== '' ) $payload['test_event_code'] = $test_code;

    // Non-bloquant — on ne veut pas ralentir le thread PHP principal
    kb_capi_call_meta( $payload, false );
}

// ═══════════════════════════════════════════════════════════════════════════════
// ADDTOCART — REST endpoint (appelé depuis le JS)
// ═══════════════════════════════════════════════════════════════════════════════
//
// Pourquoi un REST endpoint et pas un hook WC côté serveur ?
// woocommerce_add_to_cart s'exécute pendant une requête AJAX WC (add-to-cart).
// À ce moment, fbp/fbc ne sont pas dans les meta de la commande et le contexte
// navigateur (IP réelle, UA) est disponible via les headers de la requête AJAX.
// L'endpoint REST nous permet de recevoir aussi fbp, fbc, et external_id
// directement depuis le navigateur dans le même appel → meilleur EMQ.

add_action( 'rest_api_init', function() {
    register_rest_route( 'weconvert/v1', '/addtocart', [
        'methods'             => 'POST',
        'callback'            => 'kb_ev_addtocart_handler',
        'permission_callback' => '__return_true',
    ] );
} );

function kb_ev_addtocart_handler( WP_REST_Request $request ) {
    $body       = $request->get_json_params() ?? [];
    $product_id = isset( $body['product_id'] ) ? absint( $body['product_id'] ) : 0;
    $quantity   = isset( $body['quantity'] )   ? max( 1, (int) $body['quantity'] ) : 1;
    $event_id   = isset( $body['event_id'] )   ? sanitize_text_field( $body['event_id'] ) : '';
    $fbp        = isset( $body['fbp'] )        ? sanitize_text_field( $body['fbp'] ) : '';
    $fbc        = isset( $body['fbc'] )        ? sanitize_text_field( $body['fbc'] ) : '';
    $fp_id      = isset( $body['fp_id'] )      ? sanitize_text_field( $body['fp_id'] ) : '';
    // Compat ancien champ external_id envoyé par les JS existants
    if ( ! $fp_id && isset( $body['external_id'] ) ) {
        $fp_id = sanitize_text_field( $body['external_id'] );
    }

    if ( ! $product_id || ! $event_id ) {
        return new WP_REST_Response( [ 'error' => 'Missing product_id or event_id' ], 400 );
    }

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return new WP_REST_Response( [ 'error' => 'Product not found' ], 404 );
    }

    $pixel_id     = kb_ev_cfg( 'meta_pixel_id' );
    $raw_currency = get_woocommerce_currency();
    $raw_price    = (float) $product->get_price();
    $send_price   = kb_capi_to_eur( $raw_price, $raw_currency );
    $send_currency= kb_capi_send_currency( $raw_currency );

    $ip = kb_capi_get_ip() ?: '';
    $ua = $request->get_header( 'user_agent' ) ?: ( $_SERVER['HTTP_USER_AGENT'] ?? '' );

    // ── EMQ S-TIER : enrichissement via fingerprint DB ────────────────────────
    // kb_fp_build_midfunnel_user_data() cherche ph + em en DB depuis le fp_id.
    // Retourne aussi WC Customer data si utilisateur connecté.
    // Fallback vers user_data anonyme si fingerprint.php non actif.
    if ( function_exists( 'kb_fp_build_midfunnel_user_data' ) ) {
        $user_data = kb_fp_build_midfunnel_user_data( $fp_id, $fbp, $fbc, $ip, $ua );
    } else {
        $user_data = kb_ev_build_anonymous_user_data(
            $fbp, $fbc,
            $fp_id ? hash( 'sha256', $fp_id ) : ''
        );
    }

    $custom_data = [
        'value'        => $send_price * $quantity,
        'currency'     => $send_currency,
        'content_ids'  => [ (string) $product_id ],
        'content_name' => $product->get_name(),
        'content_type' => 'product',
        'contents'     => [ [
            'id'         => (string) $product_id,
            'quantity'   => $quantity,
            'item_price' => $send_price,
        ] ],
        'num_items'    => $quantity,
    ];

    $payload = [
        'data' => [ [
            'event_name'       => 'AddToCart',
            'event_time'       => time(),
            'event_id'         => $event_id,
            'action_source'    => 'website',
            'event_source_url' => get_permalink( $product_id ),
            'user_data'        => $user_data,
            'custom_data'      => $custom_data,
        ] ],
    ];

    $test_code = kb_ev_cfg( 'meta_test_code' );
    if ( $test_code !== '' ) $payload['test_event_code'] = $test_code;

    // Bloquant ici car on veut retourner la réponse au JS
    $response = kb_capi_call_meta( $payload, true );

    if ( is_wp_error( $response ) ) {
        return new WP_REST_Response( [ 'error' => $response->get_error_message() ], 500 );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    return new WP_REST_Response( [
        'status'          => $code === 200 ? 'ok' : 'error',
        'events_received' => $data['events_received'] ?? 0,
        'event_id'        => $event_id,
    ], $code === 200 ? 200 : 502 );
}

// ═══════════════════════════════════════════════════════════════════════════════
// ADDTOCART — JS injecté sur les pages produit + shop
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_footer', 'kb_ev_inject_addtocart_js', 15 );

function kb_ev_inject_addtocart_js() {
    if ( ! function_exists( 'is_woocommerce' ) ) return;
    // Sur les pages produit ET shop (AJAX add-to-cart depuis le shop)
    if ( ! is_product() && ! is_shop() && ! is_product_category() && ! is_product_tag() ) return;

    $pixel_id    = kb_ev_cfg( 'meta_pixel_id' );
    if ( ! $pixel_id ) return;

    $raw_currency = get_woocommerce_currency();
    $is_dzd       = strtoupper( $raw_currency ) === 'DZD';
    $rate         = (float) kb_ev_cfg( 'rate_dzd_eur', 285 );
    $endpoint_url = rest_url( 'weconvert/v1/addtocart' );
    ?>
    <script>
    (function() {
        var PIXEL_ID   = '<?php echo esc_js( $pixel_id ); ?>';
        var IS_DZD     = <?php echo $is_dzd ? 'true' : 'false'; ?>;
        var RATE       = <?php echo (float) $rate; ?>;
        var ENDPOINT   = '<?php echo esc_js( $endpoint_url ); ?>';
        var SEND_CURR  = IS_DZD ? 'EUR' : '<?php echo esc_js( $raw_currency ); ?>';
        var _fired     = {};  // Guard anti-double-fire dans la même session page

        function getCookie(n) {
            var m = document.cookie.match(new RegExp('(?:^|;\\s*)'+n+'=([^;]*)'));
            return m ? m[1] : '';
        }
        function toEur(price) { return IS_DZD ? Math.round((price / RATE) * 100) / 100 : price; }

        // Génère un event_id déterministe (même produit dans la même minute = même ID)
        function makeEventId(productId) {
            var minute = Math.floor(Date.now() / 60000);
            return 'kb_atc_' + productId + '_' + minute;
        }

        function fireAddToCart(productId, productName, price, qty) {
            if (!productId) return;
            var eid = makeEventId(productId);

            // Guard — un seul fire par produit par minute par pageload
            if (_fired[eid]) return;
            _fired[eid] = true;

            var priceEur = toEur(price);
            var fbp = getCookie('_fbp');
            var fbc = getCookie('_fbc');
            var fpId = '';
            try { fpId = window._kb_fp_id || localStorage.getItem('kb_fp_id') || ''; } catch(e){}

            // ── 1. Browser fbq ─────────────────────────────────────────────
            if (typeof window.fbq === 'function') {
                fbq('track', 'AddToCart', {
                    value       : priceEur * qty,
                    currency    : SEND_CURR,
                    content_ids : [String(productId)],
                    content_name: productName,
                    content_type: 'product',
                    contents    : [{id: String(productId), quantity: qty, item_price: priceEur}],
                    num_items   : qty,
                }, { eventID: eid });
            }

            // ── 2. CAPI server-side (non-bloquant, keepalive) ───────────────
            // fp_id = identifiant fingerprint court (16 chars) — le serveur
            // fait le lookup DB pour récupérer ph + em si le device est connu.
            var body = JSON.stringify({
                product_id : productId,
                quantity   : qty,
                event_id   : eid,
                fbp        : fbp,
                fbc        : fbc,
                fp_id      : fpId,
            });

            if (typeof fetch !== 'undefined') {
                fetch(ENDPOINT, {
                    method   : 'POST',
                    headers  : {'Content-Type': 'application/json'},
                    body     : body,
                    keepalive: true,
                }).catch(function(){});
            }
        }

        // ── Interception bouton "Ajouter au panier" standard WooCommerce ──────
        // WC déclenche l'event added_to_cart sur $(document) après un add AJAX réussi.
        // Pour les boutons non-AJAX (pages produit), on écoute le submit du form.

        // Event AJAX WC (shop page, catégorie) — fired after XHR success
        jQuery(document).on('added_to_cart', function(e, fragments, cart_hash, button) {
            var $btn = jQuery(button);
            var pid  = parseInt($btn.data('product_id') || $btn.val(), 10);
            var qty  = parseInt($btn.data('quantity') || 1, 10);
            var name = $btn.data('product_name') || $btn.closest('.product').find('.woocommerce-loop-product__title').text().trim() || '';
            var price= parseFloat($btn.data('price') || 0);
            fireAddToCart(pid, name, price, qty);
        });

        // Form submit sur la fiche produit (add non-AJAX + variantes)
        jQuery(document).on('submit', 'form.cart', function() {
            var $f   = jQuery(this);
            var pid  = parseInt($f.find('[name=add-to-cart]').val() || 0, 10);
            var qty  = parseInt($f.find('[name=quantity]').val() || 1, 10);
            var name = $f.find('.product_title').text().trim()
                    || jQuery('h1.product_title').text().trim() || '';
            var price= parseFloat($f.data('price')
                    || jQuery('.price .woocommerce-Price-amount').first().text().replace(/[^\d.]/g,'') || 0);
            fireAddToCart(pid, name, price, qty);
        });
    })();
    </script>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// INITIATECHECKOUT — server-side sur le chargement de /checkout
// ═══════════════════════════════════════════════════════════════════════════════
//
// Stratégie : wp_footer (priority 20) sur la page checkout uniquement.
// On combine browser fbq + appel REST endpoint (même pattern que ViewContent).
// event_id = kb_ic_{cart_hash}_{pixel_id} → stable tant que le panier ne change pas.

add_action( 'rest_api_init', function() {
    register_rest_route( 'weconvert/v1', '/initiatecheckout', [
        'methods'             => 'POST',
        'callback'            => 'kb_ev_initiatecheckout_handler',
        'permission_callback' => '__return_true',
    ] );
} );

function kb_ev_initiatecheckout_handler( WP_REST_Request $request ) {
    $body     = $request->get_json_params() ?? [];
    $event_id = isset( $body['event_id'] )    ? sanitize_text_field( $body['event_id'] ) : '';
    $fbp      = isset( $body['fbp'] )         ? sanitize_text_field( $body['fbp'] ) : '';
    $fbc      = isset( $body['fbc'] )         ? sanitize_text_field( $body['fbc'] ) : '';
    $fp_id    = isset( $body['fp_id'] )       ? sanitize_text_field( $body['fp_id'] ) : '';
    if ( ! $fp_id && isset( $body['external_id'] ) ) {
        $fp_id = sanitize_text_field( $body['external_id'] );
    }
    $value    = isset( $body['value'] )        ? (float) $body['value'] : 0.0;
    $currency = isset( $body['currency'] )     ? strtoupper( sanitize_text_field( $body['currency'] ) ) : 'EUR';
    $num_items= isset( $body['num_items'] )    ? (int) $body['num_items'] : 0;
    $cont_ids = isset( $body['content_ids'] ) && is_array( $body['content_ids'] )
        ? array_map( 'sanitize_text_field', $body['content_ids'] )
        : [];

    if ( ! $event_id ) {
        return new WP_REST_Response( [ 'error' => 'Missing event_id' ], 400 );
    }

    $ip = kb_capi_get_ip() ?: '';
    $ua = $request->get_header( 'user_agent' ) ?: ( $_SERVER['HTTP_USER_AGENT'] ?? '' );

    // ── EMQ S-TIER : cascade enrichissement ──────────────────────────────────
    // Priorité 1 : fingerprint DB → ph + em si device connu + lié à une commande
    // Priorité 2 : WC Customer si connecté (kb_fp_build_midfunnel_user_data le gère)
    // Priorité 3 : kb_ev_build_customer_user_data fallback (WC session / formulaire)
    if ( function_exists( 'kb_fp_build_midfunnel_user_data' ) ) {
        $user_data = kb_fp_build_midfunnel_user_data( $fp_id, $fbp, $fbc, $ip, $ua );
        // Si fingerprint DB n'a pas de ph/em mais WC Customer en a → merge
        if ( empty( $user_data['ph'] ) || empty( $user_data['em'] ) ) {
            $wc_data = kb_ev_build_customer_user_data( $fbp, $fbc, $fp_id );
            if ( empty( $user_data['ph'] ) && ! empty( $wc_data['ph'] ) ) $user_data['ph'] = $wc_data['ph'];
            if ( empty( $user_data['em'] ) && ! empty( $wc_data['em'] ) ) $user_data['em'] = $wc_data['em'];
            if ( empty( $user_data['fn'] ) && ! empty( $wc_data['fn'] ) ) $user_data['fn'] = $wc_data['fn'];
            if ( empty( $user_data['ln'] ) && ! empty( $wc_data['ln'] ) ) $user_data['ln'] = $wc_data['ln'];
        }
    } else {
        $user_data = kb_ev_build_customer_user_data(
            $fbp, $fbc,
            $fp_id ? hash( 'sha256', $fp_id ) : ''
        );
    }

    $custom_data = array_filter( [
        'value'       => $value,
        'currency'    => $currency,
        'content_ids' => $cont_ids,
        'content_type'=> 'product',
        'num_items'   => $num_items ?: null,
    ] );

    $payload = [
        'data' => [ [
            'event_name'       => 'InitiateCheckout',
            'event_time'       => time(),
            'event_id'         => $event_id,
            'action_source'    => 'website',
            'event_source_url' => wc_get_checkout_url(),
            'user_data'        => $user_data,
            'custom_data'      => $custom_data,
        ] ],
    ];

    $test_code = kb_ev_cfg( 'meta_test_code' );
    if ( $test_code !== '' ) $payload['test_event_code'] = $test_code;

    $response = kb_capi_call_meta( $payload, true );

    if ( is_wp_error( $response ) ) {
        return new WP_REST_Response( [ 'error' => $response->get_error_message() ], 500 );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    return new WP_REST_Response( [
        'status'          => $code === 200 ? 'ok' : 'error',
        'events_received' => $data['events_received'] ?? 0,
        'event_id'        => $event_id,
    ], $code === 200 ? 200 : 502 );
}

add_action( 'wp_footer', 'kb_ev_inject_initiatecheckout_js', 20 );

function kb_ev_inject_initiatecheckout_js() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) return;

    $pixel_id = kb_ev_cfg( 'meta_pixel_id' );
    if ( ! $pixel_id ) return;

    // Données panier
    $cart         = WC()->cart;
    $raw_currency = get_woocommerce_currency();
    $raw_total    = $cart ? (float) $cart->get_cart_contents_total() : 0.0;
    $send_total   = kb_capi_to_eur( $raw_total, $raw_currency );
    $send_currency= kb_capi_send_currency( $raw_currency );
    $num_items    = $cart ? (int) $cart->get_cart_contents_count() : 0;
    $cart_hash    = $cart ? $cart->get_cart_hash() : uniqid();

    $content_ids  = [];
    $contents     = [];
    if ( $cart ) {
        foreach ( $cart->get_cart() as $item ) {
            $pid = (string) ( $item['variation_id'] ?: $item['product_id'] );
            $qty = (int) $item['quantity'];
            $content_ids[] = $pid;
            $contents[]    = [
                'id'         => $pid,
                'quantity'   => $qty,
                'item_price' => kb_capi_to_eur( (float) $item['data']->get_price(), $raw_currency ),
            ];
        }
    }

    // event_id stable tant que le panier ne change pas
    $event_id    = 'kb_ic_' . substr( md5( $cart_hash . $pixel_id ), 0, 16 );
    $endpoint    = rest_url( 'weconvert/v1/initiatecheckout' );
    ?>
    <script>
    (function() {
        var PIXEL_ID  = '<?php echo esc_js( $pixel_id ); ?>';
        var EVENT_ID  = '<?php echo esc_js( $event_id ); ?>';
        var ENDPOINT  = '<?php echo esc_js( $endpoint ); ?>';
        var VALUE     = <?php echo (float) $send_total; ?>;
        var CURRENCY  = '<?php echo esc_js( $send_currency ); ?>';
        var NUM_ITEMS = <?php echo (int) $num_items; ?>;
        var CONT_IDS  = <?php echo wp_json_encode( $content_ids ); ?>;
        var CONTENTS  = <?php echo wp_json_encode( $contents ); ?>;

        // Guard sessionStorage — un seul fire par cart_hash par onglet
        var sessionKey = 'kb_ic_<?php echo esc_js( substr( $cart_hash, 0, 8 ) ); ?>';
        if ( sessionStorage.getItem(sessionKey) ) return;

        function getCookie(n) {
            var m = document.cookie.match(new RegExp('(?:^|;\\s*)'+n+'=([^;]*)'));
            return m ? m[1] : '';
        }
        var fbp  = getCookie('_fbp') || window._kb_fbp || '';
        var fbc  = getCookie('_fbc') || '';
        var fpId = '';
        try { fpId = window._kb_fp_id || localStorage.getItem('kb_fp_id') || ''; } catch(e){}

        // ── 1. Browser fbq ─────────────────────────────────────────────────
        function fireIC() {
            if (typeof window.fbq !== 'function') { setTimeout(fireIC, 150); return; }
            fbq('track', 'InitiateCheckout', {
                value       : VALUE,
                currency    : CURRENCY,
                content_ids : CONT_IDS,
                content_type: 'product',
                contents    : CONTENTS,
                num_items   : NUM_ITEMS,
            }, { eventID: EVENT_ID });
            sessionStorage.setItem(sessionKey, '1');
        }
        fireIC();

        // ── 2. CAPI server-side ─────────────────────────────────────────────
        // fp_id envoyé → serveur fait lookup DB → ph + em si device connu
        var payload = JSON.stringify({
            event_id   : EVENT_ID,
            fbp        : fbp,
            fbc        : fbc,
            fp_id      : fpId,
            value      : VALUE,
            currency   : CURRENCY,
            num_items  : NUM_ITEMS,
            content_ids: CONT_IDS,
        });

        if (typeof fetch !== 'undefined') {
            fetch(ENDPOINT, {
                method   : 'POST',
                headers  : {'Content-Type': 'application/json'},
                body     : payload,
                keepalive: true,
            }).catch(function(){});
        }
    })();
    </script>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// SAUVEGARDE CONTEXTE FB AU CHECKOUT
// Complète ce que weconvert-capi.php fait au submit — on le fait aussi
// au simple chargement du checkout pour les cas où WP checkout order hooks
// s'exécutent dans un contexte sans cookies (AJAX, page builder, etc.)
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'rest_api_init', function() {
    register_rest_route( 'weconvert/v1', '/save-fb-context', [
        'methods'             => 'POST',
        'callback'            => 'kb_ev_save_fb_context_handler',
        'permission_callback' => '__return_true',
    ] );
} );

function kb_ev_save_fb_context_handler( WP_REST_Request $request ) {
    $body    = $request->get_json_params() ?? [];
    $fbp     = sanitize_text_field( $body['fbp'] ?? '' );
    $fbc     = sanitize_text_field( $body['fbc'] ?? '' );
    $ext_id  = sanitize_text_field( $body['external_id'] ?? '' );

    if ( ! $fbp && ! $fbc && ! $ext_id ) {
        return new WP_REST_Response( [ 'status' => 'nothing_to_save' ], 200 );
    }

    // Sauvegarde en session WC — récupérable lors de la création de commande
    if ( function_exists( 'WC' ) && WC()->session ) {
        if ( $fbp ) WC()->session->set( 'kb_fbp', $fbp );
        if ( $fbc ) WC()->session->set( 'kb_fbc', $fbc );
        if ( $ext_id ) WC()->session->set( 'kb_external_id', $ext_id );
    }

    return new WP_REST_Response( [ 'status' => 'saved' ], 200 );
}

// Lire la session WC pour enrichir les meta de commande
// Hook priority 5 → avant weconvert-capi.php (priority 10)
add_action( 'woocommerce_checkout_order_processed', 'kb_ev_transfer_session_to_order', 5, 1 );

function kb_ev_transfer_session_to_order( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order || ! function_exists( 'WC' ) || ! WC()->session ) return;

    $fbp    = WC()->session->get( 'kb_fbp', '' );
    $fbc    = WC()->session->get( 'kb_fbc', '' );
    $ext_id = WC()->session->get( 'kb_external_id', '' );

    // Seulement si weconvert-capi.php n'a pas déjà sauvé (il a priority 10)
    if ( $fbp && ! $order->get_meta( '_kb_fbp' ) ) {
        $order->update_meta_data( '_kb_fbp', $fbp );
    }
    if ( $fbc && ! $order->get_meta( '_kb_fbc' ) ) {
        $order->update_meta_data( '_kb_fbc', $fbc );
    }
    if ( $ext_id && ! $order->get_meta( '_kb_external_id' ) ) {
        $order->update_meta_data( '_kb_external_id', hash( 'sha256', $ext_id ) );
    }
    $order->save();
}

// JS pour appeler save-fb-context au chargement du checkout
add_action( 'wp_footer', 'kb_ev_inject_save_context_js', 5 );

function kb_ev_inject_save_context_js() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) return;
    $endpoint = rest_url( 'weconvert/v1/save-fb-context' );
    ?>
    <script>
    (function() {
        // Appel silencieux au chargement checkout — sauvegarde fbp/fbc/ext_id en session WC
        // pour qu'ils soient disponibles même si le submit arrive par AJAX
        function getCookie(n) {
            var m = document.cookie.match(new RegExp('(?:^|;\\s*)'+n+'=([^;]*)'));
            return m ? m[1] : '';
        }
        var fpId = '';
        try { fpId = window._kb_fp_id || localStorage.getItem('kb_fp_id') || ''; } catch(e){}

        var fbp = getCookie('_fbp') || window._kb_fbp || '';
        var fbc = getCookie('_fbc') || '';
        if (!fbp && !fbc && !fpId) return;

        fetch('<?php echo esc_js( $endpoint ); ?>', {
            method   : 'POST',
            headers  : {'Content-Type': 'application/json'},
            body     : JSON.stringify({fbp: fbp, fbc: fbc, external_id: fpId, fp_id: fpId}),
            keepalive: true,
        }).catch(function(){});
    })();
    </script>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// DÉCLARATION MODULE
// ═══════════════════════════════════════════════════════════════════════════════

add_filter( 'kb_active_modules', function( $modules ) {
    $modules['events'] = [
        'label'  => '🛒 Events Mid-Funnel',
        'events' => [
            'AddToCart (browser + CAPI server-side)',
            'InitiateCheckout (browser + CAPI server-side)',
            'Sauvegarde contexte FB en session WC',
        ],
    ];
    return $modules;
} );
