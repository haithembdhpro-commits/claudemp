<?php
/**
 * Module WeConvert.io Suite (chargé par weconvert-suite.php — ne pas activer seul) :
 *   WeConvert.io CAPI — Meta Conversions API
 * Description: Purchase côté serveur + browser. Déduplication automatique via eventID.
 *              Zéro credential hardcodé — tout depuis WeConvert.io Settings.
 * Version:     2.6.0
 * Author:      WeConvert.io
 *
 * Changelog:
 *   v2.6.0 — EMQ : vraie cause des ph/fn/ln/ct/st à ~50 % = le proxy fingerprint
 *             renvoyait Purchase en CAPI une 2e fois (même event_id, sans PII).
 *             Le proxy ignore désormais Purchase/ViewContent/AddToCart/InitiateCheckout.
 *             Normalisation Meta partagée : téléphone (+213/00213/0 en trop),
 *             fn/ln/ct/st sans espaces/accents/ponctuation, wilaya DZ-16 → « alger ».
 *             Advanced Matching navigateur sur la thank-you page (window._kb_am).
 *   v2.5.0 — FIX déduplication browser/CAPI : event_id suffixé pixel primaire
 *             sauvé dans _kb_capi_event_id_browser, lu par le JS footer.
 *             Supprime le double-comptage Meta qui causait ph/fn/ln à 50%.
 *             kb_capi_call_meta() retourne le suffixe du pixel primaire.
 *             Log CAPI enrichi : affiche les clés user_data envoyées.
 *   v2.4.0 — Plus aucun define() avec credentials.
 *             Lit pixel_id, access_token, api_version, test_code, rate_dzd_eur via kb_get().
 *             Fallback direct get_option() si weconvert-settings.php non actif.
 *             kb_capi_call_meta() exposé globalement → réutilisé par Confirmed + Delivered.
 *             event_id inclut le pixel_id lu dynamiquement (plus de constante).
 *             Guard double-envoi sur _kb_capi_purchase_sent.
 *             HPOS-compatible : wc_get_order() uniquement.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════════════════════
// CONFIG — lecture depuis wp_options via weconvert-settings
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Lit une option WeConvert.io.
 * Utilise kb_get() si weconvert-settings.php est actif, sinon get_option() direct.
 * Ne jamais appeler get_option('kb_...') directement dans ce plugin.
 */
function kb_capi_cfg( string $key, $default = '' ) {
    if ( function_exists( 'kb_get' ) ) return kb_get( $key, $default );
    return get_option( 'kb_' . $key, $default );
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS — conversion, hashing, IP
// Les fonctions kb_convert_* sont déjà dans weconvert-settings si actif.
// Guard function_exists pour éviter les conflits de redéfinition.
// ═══════════════════════════════════════════════════════════════════════════════

if ( ! function_exists( 'kb_capi_to_eur' ) ) {
    function kb_capi_to_eur( float $amount, string $currency ): float {
        if ( strtoupper( $currency ) !== 'DZD' ) return $amount;
        $rate = (float) kb_capi_cfg( 'rate_dzd_eur', 285 );
        return $rate > 0 ? round( $amount / $rate, 2 ) : round( $amount / 285, 2 );
    }
}

if ( ! function_exists( 'kb_capi_send_currency' ) ) {
    function kb_capi_send_currency( string $currency ): string {
        return strtoupper( $currency ) === 'DZD' ? 'EUR' : strtoupper( $currency );
    }
}

if ( ! function_exists( 'kb_capi_hash' ) ) {
    function kb_capi_hash( $value ): ?string {
        $value = trim( strtolower( (string) $value ) );
        return $value !== '' ? hash( 'sha256', $value ) : null;
    }
}

if ( ! function_exists( 'kb_capi_hash_phone' ) ) {
    /**
     * Normalise un numéro algérien et le hash SHA256.
     * 07XXXXXXXX → 2137XXXXXXXX
     * 0XXXXXXXXX → 213XXXXXXXXX
     */
    function kb_capi_hash_phone( $phone ): ?string {
        $phone = kb_capi_norm_phone( $phone );
        return $phone !== '' ? hash( 'sha256', $phone ) : null;
    }
}

if ( ! function_exists( 'kb_capi_norm_phone' ) ) {
    /**
     * Numéro algérien → format E.164 sans « + » attendu par Meta (213XXXXXXXXX).
     * Gère 0555…, 555…, +213 555…, 00213 555…, +213 0555… (0 en trop après l'indicatif).
     */
    function kb_capi_norm_phone( $phone ): string {
        $p = preg_replace( '/\D/', '', (string) $phone );
        if ( str_starts_with( $p, '00' ) )  $p = substr( $p, 2 );
        if ( str_starts_with( $p, '213' ) ) $p = substr( $p, 3 );
        $p = ltrim( $p, '0' );
        return strlen( $p ) === 9 ? '213' . $p : $p;
    }
}

if ( ! function_exists( 'kb_capi_norm_text' ) ) {
    /**
     * Normalisation Meta pour fn / ln / ct / st : minuscules, sans accents,
     * sans espaces ni ponctuation. Les lettres arabes sont conservées (UTF-8).
     */
    function kb_capi_norm_text( $value ): string {
        $v = mb_strtolower( trim( (string) $value ), 'UTF-8' );
        if ( function_exists( 'remove_accents' ) ) $v = remove_accents( $v );
        return preg_replace( '/[^\p{L}\p{N}]+/u', '', $v );
    }
}

if ( ! function_exists( 'kb_capi_hash_text' ) ) {
    function kb_capi_hash_text( $value ): ?string {
        $v = kb_capi_norm_text( $value );
        return $v !== '' ? hash( 'sha256', $v ) : null;
    }
}

if ( ! function_exists( 'kb_capi_state_name' ) ) {
    /**
     * Wilaya : WooCommerce stocke un code (DZ-16) → on envoie le nom (« Alger »),
     * sans le numéro éventuel (« 16 - Alger »).
     */
    function kb_capi_state_name( string $country, string $state ): string {
        if ( $state !== '' && function_exists( 'WC' ) && WC()->countries ) {
            $states = WC()->countries->get_states( $country ?: 'DZ' );
            if ( is_array( $states ) && isset( $states[ $state ] ) ) $state = $states[ $state ];
        }
        return trim( preg_replace( '/^\s*\d+\s*[-–:.]?\s*/u', '', html_entity_decode( $state ) ) );
    }
}

if ( ! function_exists( 'kb_capi_name_hashes' ) ) {
    /**
     * fn / ln hashés. Cas DZ fréquent : nom complet saisi dans un seul champ
     * (« Mohamed Amine Benali ») → fn = premier mot, ln = dernier mot.
     */
    function kb_capi_name_hashes( string $first, string $last ): array {
        $first = trim( $first );
        $last  = trim( $last );
        if ( $last === '' ) {
            $parts = preg_split( '/\s+/u', $first, -1, PREG_SPLIT_NO_EMPTY ) ?: [ '' ];
            $first = $parts[0];
            $last  = count( $parts ) > 1 ? end( $parts ) : '';
        }
        return [ 'fn' => kb_capi_hash_text( $first ), 'ln' => kb_capi_hash_text( $last ) ];
    }
}

if ( ! function_exists( 'kb_capi_get_ip' ) ) {
    /**
     * IP réelle du visiteur — Cloudflare + proxy aware.
     */
    function kb_capi_get_ip(): ?string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ] as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $h ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            }
        }
        return null;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// APPEL API META — fonction partagée avec Confirmed + Delivered
// ═══════════════════════════════════════════════════════════════════════════════

if ( ! function_exists( 'kb_capi_call_meta' ) ) {
    /**
     * Envoie un payload CAPI à Meta — broadcast vers tous les pixels actifs.
     *
     * Lit la liste des pixels depuis kb_get_active_pixels() (settings v3.0).
     * Le premier pixel actif est bloquant (sa réponse est retournée).
     * Tous les autres sont fire-and-forget (non-bloquants).
     *
     * event_id suffixé par pixel pour éviter les conflits de déduplication
     * cross-pixels sur le même event.
     *
     * @param array  $payload          Payload CAPI complet incluant 'data'.
     * @param bool   $blocking         Bloquant pour le premier pixel.
     * @param string &$primary_eid_out Retourne l'event_id suffixé du pixel primaire
     *                                 (pour que le JS browser utilise le même ID → déduplication exacte).
     * @return array|WP_Error Réponse du premier pixel actif.
     */
    function kb_capi_call_meta( array $payload, bool $blocking = true, string &$primary_eid_out = '' ) {
        $api_ver = kb_capi_cfg( 'meta_api_version', 'v19.0' );

        // ── Récupération pixels actifs ────────────────────────────────────────
        // Priorité : kb_get_active_pixels() (settings v3.0) → fallback legacy
        $pixels = [];
        if ( function_exists( 'kb_get_active_pixels' ) ) {
            $pixels = kb_get_active_pixels();
        }

        // Fallback legacy — si settings v3.0 pas encore actif
        if ( empty( $pixels ) ) {
            $pid = kb_capi_cfg( 'meta_pixel_id' );
            $tok = kb_capi_cfg( 'meta_access_token' );
            if ( $pid && $tok ) {
                $pixels = [ [ 'pixel_id' => $pid, 'access_token' => $tok, 'label' => 'Principal' ] ];
            }
        }

        if ( empty( $pixels ) ) {
            return new WP_Error(
                'kb_capi_config',
                'WeConvert.io : Aucun pixel configuré. Aller dans WeConvert.io → Settings → Meta CAPI.'
            );
        }

        $primary_response = null;
        $is_first         = true;

        foreach ( $pixels as $px ) {
            $pid = preg_replace( '/[^0-9]/', '', $px['pixel_id']    ?? '' );
            $tok = sanitize_text_field(         $px['access_token'] ?? '' );
            if ( ! $pid || ! $tok ) continue;

            // event_id unique par pixel — évite les conflits de déduplication
            // quand le même event est envoyé à plusieurs pixels différents.
            // Le suffix est suffixé à la base event_id, pas au-dessus d'un suffix existant.
            $px_payload = $payload;
            $suffix     = substr( md5( $pid ), 0, 6 );
            if ( ! empty( $px_payload['data'][0]['event_id'] ) ) {
                $px_payload['data'][0]['event_id'] .= '_' . $suffix;
            }

            // Exposer l'event_id suffixé du pixel primaire pour le JS browser.
            // Sans ça, le browser envoie l'event_id sans suffix → Meta ne déduplique
            // pas correctement → 2 events comptés → ph/fn/ln à 50%.
            if ( $is_first && ! empty( $px_payload['data'][0]['event_id'] ) ) {
                $primary_eid_out = $px_payload['data'][0]['event_id'];
            }

            $url  = sprintf(
                'https://graph.facebook.com/%s/%s/events?access_token=%s',
                $api_ver, $pid, $tok
            );
            $args = [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $px_payload ),
            ];

            if ( $is_first ) {
                // Premier pixel — bloquant, on attend la réponse
                $args['timeout']  = $blocking ? 15 : 0.01;
                $args['blocking'] = $blocking;
                $primary_response = wp_remote_post( $url, $args );
                $is_first         = false;
            } else {
                // Pixels suivants — toujours fire-and-forget
                $args['timeout']  = 0.01;
                $args['blocking'] = false;
                wp_remote_post( $url, $args );
            }
        }

        return $primary_response ?? new WP_Error( 'kb_capi_no_pixel', 'Aucun pixel valide trouvé.' );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// CONSTRUCTION USER DATA
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Lit fbp et fbc depuis les cookies HTTP bruts.
 * Évite le sanitize WP qui lowercase les valeurs (fbp commence par fb.1 — sensible).
 */
function kb_capi_read_fb_cookies(): array {
    $fbp = '';
    $fbc = '';
    $raw = $_SERVER['HTTP_COOKIE'] ?? '';
    if ( $raw !== '' ) {
        foreach ( explode( ';', $raw ) as $cpair ) {
            $parts = explode( '=', trim( $cpair ), 2 );
            if ( count( $parts ) === 2 ) {
                $name = trim( $parts[0] );
                $val  = trim( $parts[1] );
                if ( $name === '_fbp' ) $fbp = $val;
                if ( $name === '_fbc' ) $fbc = $val;
            }
        }
    }
    return [ 'fbp' => $fbp, 'fbc' => $fbc ];
}

/**
 * Construit l'array user_data hashed pour le CAPI à partir d'un order WC.
 * Privilégie les données sauvées au checkout (_kb_*) pour les appels différés
 * (Confirmed, Delivered) où le contexte HTTP original n'est plus disponible.
 */
if ( ! function_exists( 'kb_capi_build_user_data_from_order' ) ) {
    function kb_capi_build_user_data_from_order( $order ): array {
        // Version enrichie (fingerprint : external_id retrouvé via la base d'empreintes) si le module est chargé
        if ( function_exists( 'kb_fp_get_enriched_user_data' ) ) return kb_fp_get_enriched_user_data( $order );

        // fbp/fbc : d'abord les meta sauvées au checkout, sinon cookies courants
        $fbp = $order->get_meta( '_kb_fbp' ) ?: null;
        $fbc = $order->get_meta( '_kb_fbc' ) ?: null;

        // IP + UA : meta sauvées > méthodes WC natives > headers courants
        $ip = $order->get_meta( '_kb_client_ip' )
            ?: $order->get_customer_ip_address()
            ?: kb_capi_get_ip();
        $ua = $order->get_meta( '_kb_client_ua' )
            ?: $order->get_customer_user_agent()
            ?: ( $_SERVER['HTTP_USER_AGENT'] ?? null );

        // external_id depuis fingerprint si disponible
        $external_id = $order->get_meta( '_kb_external_id' )
            ?: hash( 'sha256', (string) $order->get_id() );

        // Nom → fn / ln (gère le cas DZ où tout est dans first_name)
        $names   = kb_capi_name_hashes( $order->get_billing_first_name(), $order->get_billing_last_name() );
        $country = $order->get_billing_country() ?: 'DZ';

        return array_filter( [
            'ph'                => kb_capi_hash_phone( $order->get_billing_phone() ),
            'fn'                => $names['fn'],
            'ln'                => $names['ln'],
            'em'                => kb_capi_hash( $order->get_billing_email() ),
            'ct'                => kb_capi_hash_text( $order->get_billing_city() ),
            'st'                => kb_capi_hash_text( kb_capi_state_name( $country, $order->get_billing_state() ) ),
            'country'           => kb_capi_hash( $country ),
            'external_id'       => $external_id,
            'fbc'               => $fbc,
            'fbp'               => $fbp,
            'client_ip_address' => $ip ?: null,
            'client_user_agent' => $ua ?: null,
        ] );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ENVOI PURCHASE CAPI
// ═══════════════════════════════════════════════════════════════════════════════

function kb_capi_send_purchase( $order_or_id ) {
    // Résolution order
    if ( is_object( $order_or_id ) && method_exists( $order_or_id, 'get_id' ) ) {
        $order    = $order_or_id;
        $order_id = $order->get_id();
    } else {
        $order_id = absint( $order_or_id );
        $order    = wc_get_order( $order_id );
    }
    if ( ! $order_id || ! $order ) return;

    // ── event_id déterministe ─────────────────────────────────────────────────
    // Inclut le pixel_id (lu dynamiquement) pour que 2 stores avec 2 pixels
    // différents n'aient pas les mêmes event_id.
    $pixel_id = kb_capi_cfg( 'meta_pixel_id' );
    $event_id = 'kb_' . $order_id . '_' . substr( md5( $order_id . $pixel_id ), 0, 12 );

    // ── Sauvegarde contexte HTTP au premier passage ───────────────────────────
    // Ce bloc s'exécute même si le guard stoppe l'envoi CAPI plus bas,
    // parce que Confirmed/Delivered ont besoin de ces meta même pour les
    // commandes dont le Purchase a déjà été envoyé.
    if ( ! $order->get_meta( '_kb_capi_event_id' ) ) {
        $cookies = kb_capi_read_fb_cookies();
        $order->update_meta_data( '_kb_fbp', $cookies['fbp'] );
        $order->update_meta_data( '_kb_fbc', $cookies['fbc'] );
        $order->update_meta_data( '_kb_client_ip', kb_capi_get_ip() ?: '' );
        $order->update_meta_data( '_kb_client_ua', $_SERVER['HTTP_USER_AGENT'] ?? '' );
        $order->update_meta_data( '_kb_capi_event_id', $event_id );
        // external_id fingerprint si disponible
        if ( function_exists( 'kb_fp_get_session_token' ) ) {
            $tok = kb_fp_get_session_token();
            $order->update_meta_data( '_kb_external_id', hash( 'sha256', $tok ) );
        }
        $order->save();
    }

    // ── Guard double-envoi ────────────────────────────────────────────────────
    if ( $order->get_meta( '_kb_capi_purchase_sent' ) ) return;

    // ── Payload ───────────────────────────────────────────────────────────────
    $raw_currency  = $order->get_currency();
    $send_currency = kb_capi_send_currency( $raw_currency );
    $raw_total     = (float) $order->get_total();
    $send_total    = kb_capi_to_eur( $raw_total, $raw_currency );

    $contents    = [];
    $content_ids = [];
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        $pid     = $product ? (string) $product->get_id() : (string) $item->get_product_id();
        $qty     = max( 1, (int) $item->get_quantity() );
        $item_unit = (float) $item->get_total() / $qty;
        $contents[]    = [
            'id'         => $pid,
            'quantity'   => $qty,
            'item_price' => kb_capi_to_eur( $item_unit, $raw_currency ),
        ];
        $content_ids[] = $pid;
    }

    $payload = [
        'data' => [ [
            'event_name'       => 'Purchase',
            'event_time'       => time(),
            'event_id'         => $event_id,
            'action_source'    => 'website',
            'event_source_url' => home_url( '/checkout/order-received/' . $order_id . '/' ),
            'user_data'        => kb_capi_build_user_data_from_order( $order ),
            'custom_data'      => [
                'value'        => $send_total,
                'currency'     => $send_currency,
                'content_ids'  => $content_ids,
                'contents'     => $contents,
                'content_type' => 'product',
                'num_items'    => count( $contents ),
                'order_id'     => (string) $order_id,
            ],
        ] ],
    ];

    $test_code = kb_capi_cfg( 'meta_test_code' );
    if ( $test_code !== '' ) $payload['test_event_code'] = $test_code;

    // ── Envoi ─────────────────────────────────────────────────────────────────
    // $primary_event_id_suffixed sera rempli par référence avec l'event_id
    // du pixel primaire (suffixé). Le JS browser doit utiliser exactement cet ID
    // pour que Meta déduplique correctement les deux events (CAPI + browser).
    $primary_event_id_suffixed = '';
    $response = kb_capi_call_meta( $payload, true, $primary_event_id_suffixed );

    if ( is_wp_error( $response ) ) {
        $order->add_order_note( '[KB CAPI] ❌ ' . $response->get_error_message() );
        return;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    $code = wp_remote_retrieve_response_code( $response );

    if ( $code === 200 && ! empty( $body['events_received'] ) ) {
        // Sauvegarder l'event_id suffixé pour le JS browser (thank-you page).
        // Sans cette meta, le browser génère l'event_id sans suffix → Meta compte
        // deux events distincts → coverage ph/fn/ln/em tombe à 50%.
        if ( $primary_event_id_suffixed !== '' ) {
            $order->update_meta_data( '_kb_capi_event_id_browser', $primary_event_id_suffixed );
        }
        $order->update_meta_data( '_kb_capi_purchase_sent', current_time( 'mysql' ) );
        $order->save();

        // Log enrichi : liste les clés user_data envoyées pour debug EMQ
        $ud_keys = array_keys( $payload['data'][0]['user_data'] ?? [] );
        $order->add_order_note(
            '[KB CAPI] ✅ Purchase — event_id: ' . $event_id .
            ' (browser: ' . ( $primary_event_id_suffixed ?: 'n/a' ) . ')' .
            ' | ' . $raw_total . ' ' . $raw_currency . ' → ' . $send_total . ' ' . $send_currency .
            ' (taux: ' . kb_capi_cfg( 'rate_dzd_eur', 285 ) . ')' .
            ' | ud: [' . implode( ', ', $ud_keys ) . ']'
        );
    } else {
        $error = $body['error']['message'] ?? wp_json_encode( $body );
        $order->add_order_note( '[KB CAPI] ❌ HTTP ' . $code . ' : ' . $error );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// HOOKS WOOCOMMERCE
// Multi-hooks pour couvrir JUDE / COD / paiment différé
// Le guard _kb_capi_purchase_sent protège contre les envois multiples.
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'woocommerce_checkout_order_processed', 'kb_capi_send_purchase', 10, 1 );
add_action( 'woocommerce_checkout_order_created',   'kb_capi_send_purchase', 10, 1 );
add_action( 'woocommerce_order_status_processing',  'kb_capi_send_purchase', 10, 1 );
add_action( 'woocommerce_order_status_on-hold',     'kb_capi_send_purchase', 10, 1 );
// Note : woocommerce_order_status_completed est volontairement exclu ici.
// Completed = OrderConfirmed (weconvert-confirmed.php). Si les deux s'exécutent,
// le guard _kb_capi_purchase_sent empêche le double-envoi de Purchase,
// mais l'intention métier est : Purchase au checkout, Confirmed à la validation.

// ═══════════════════════════════════════════════════════════════════════════════
// PIXEL BROWSER — thank-you page
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_footer', 'kb_capi_inject_purchase_js_footer' );

function kb_capi_inject_purchase_js_footer() {
    if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) return;

    global $wp;
    $order_id = isset( $wp->query_vars['order-received'] ) ? intval( $wp->query_vars['order-received'] ) : 0;
    if ( ! $order_id ) return;

    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $pixel_id    = kb_capi_cfg( 'meta_pixel_id' );
    if ( ! $pixel_id ) return;

    $session_key = 'kb_purchase_fired_' . $order_id;

    // event_id — priorité au suffixé sauvé après l'envoi CAPI (v2.5.0).
    // _kb_capi_event_id_browser contient l'event_id avec le suffix du pixel primaire
    // (ex: kb_123_abc123def456_ff00aa). Sans ce suffix, Meta ne déduplique pas
    // l'event CAPI et l'event browser → deux events comptés → couverture à 50%.
    // Fallback en cascade pour les commandes antérieures à v2.5.0.
    $event_id = $order->get_meta( '_kb_capi_event_id_browser' )
        ?: $order->get_meta( '_kb_capi_event_id' )
        ?: ( 'kb_' . $order_id . '_' . substr( md5( $order_id . $pixel_id ), 0, 12 ) );

    $raw_curr      = $order->get_currency() ?: 'DZD';
    $raw_total     = (float) $order->get_total();
    $send_value    = kb_capi_to_eur( $raw_total, $raw_curr );
    $send_currency = kb_capi_send_currency( $raw_curr );

    $items       = [];
    $content_ids = [];
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( ! $product ) continue;
        $pid           = (string) $product->get_id();
        $content_ids[] = $pid;
        $items[]       = [
            'id'         => $pid,
            'quantity'   => (int) $item->get_quantity(),
            'item_price' => kb_capi_to_eur( (float) $product->get_price(), $raw_curr ),
        ];
    }

    $data = [
        'value'        => $send_value,
        'currency'     => $send_currency,
        'content_ids'  => $content_ids,
        'content_type' => 'product',
        'contents'     => $items,
        'num_items'    => count( $items ),
    ];

    // Advanced Matching navigateur : mêmes clés hashées que le CAPI, pour que
    // l'event browser porte aussi ph/fn/ln/ct/st (lu par fbq('init') du loader fingerprint).
    $am = array_intersect_key(
        kb_capi_build_user_data_from_order( $order ),
        array_flip( [ 'ph', 'fn', 'ln', 'ct', 'st', 'country', 'external_id' ] )
    );
    ?>
    <script>
    window._kb_am = <?php echo wp_json_encode( (object) $am ); ?>;
    (function() {
        var sessionKey = '<?php echo esc_js( $session_key ); ?>';
        var eventID    = '<?php echo esc_js( $event_id ); ?>';
        var pixelID    = '<?php echo esc_js( $pixel_id ); ?>';
        var eventData  = <?php echo wp_json_encode( $data ); ?>;

        // Guard cookie — évite le double-fire sur F5
        if ( document.cookie.indexOf( sessionKey + '=1' ) !== -1 ) return;

        function firePurchase() {
            if ( typeof window.fbq !== 'function' ) {
                setTimeout( firePurchase, 100 );
                return;
            }

            // Patch fbq pour bloquer tout Purchase qui ne vient pas de WeConvert.io
            // (évite les doubles depuis WC natif ou Pixel Cat etc.)
            if ( ! window._kbFbqPatched ) {
                var orig = window.fbq;
                window.fbq = function() {
                    var a = Array.prototype.slice.call( arguments );
                    if ( a[0] === 'track' && a[1] === 'Purchase' ) {
                        var eid = a[3] && ( a[3].eventID || a[3].event_id );
                        // Bloquer si pas de notre event_id
                        if ( ! eid || eid.indexOf( 'kb_' ) !== 0 ) return;
                    }
                    return orig.apply( this, a );
                };
                for ( var k in orig ) { if ( orig.hasOwnProperty(k) ) window.fbq[k] = orig[k]; }
                window._kbFbqPatched = true;
            }

            window.fbq( 'track', 'Purchase', eventData, { eventID: eventID } );
            document.cookie = sessionKey + '=1;path=/;max-age=3600';
        }

        firePurchase();
    })();
    </script>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// DÉCLARATION MODULE (pour Settings)
// ═══════════════════════════════════════════════════════════════════════════════

add_filter( 'kb_active_modules', function( $modules ) {
    $modules['capi'] = [
        'label'  => '🎯 CAPI — Meta Conversions API',
        'events' => [
            'Purchase (server-side + browser, dédupliqué)',
        ],
    ];
    return $modules;
} );
