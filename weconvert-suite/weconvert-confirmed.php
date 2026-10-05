<?php
/**
 * Module WeConvert.io Suite (chargé par weconvert-suite.php — ne pas activer seul) :
 *   WeConvert.io — OrderConfirmed CAPI
 * Description: Fire l'event custom OrderConfirmed sur Meta CAPI quand une commande COD
 *              est confirmée (status → completed). Déduplication automatique.
 *              Zéro credential hardcodé — tout depuis WeConvert.io Settings.
 * Version:     2.1.0
 * Author:      WeConvert.io
 *
 * Changelog:
 *   v2.1.0 — Réutilise kb_capi_build_user_data_from_order() et kb_capi_call_meta()
 *             de weconvert-capi.php (fallback autonome si capi.php absent).
 *             Plus de duplication de la logique hashing/conversion.
 *             Lit taux via kb_conf_cfg() → kb_get() → get_option().
 *             event_id inclut pixel_id dynamique.
 *
 * Dépendances (optionnelles) :
 *   - weconvert-settings.php → kb_get()
 *   - weconvert-capi.php     → kb_capi_build_user_data_from_order(), kb_capi_call_meta()
 *                             kb_capi_to_eur(), kb_capi_send_currency()
 *
 * Si weconvert-capi.php est absent, ce plugin est autonome (helpers inline).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════════════════════
// CONFIG
// ═══════════════════════════════════════════════════════════════════════════════

function kb_conf_cfg( string $key, $default = '' ) {
    if ( function_exists( 'kb_get' ) ) return kb_get( $key, $default );
    return get_option( 'kb_' . $key, $default );
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS INLINE — actifs uniquement si weconvert-capi.php est absent
// ═══════════════════════════════════════════════════════════════════════════════

if ( ! function_exists( 'kb_capi_hash' ) ) {
    function kb_capi_hash( $value ): ?string {
        $value = trim( strtolower( (string) $value ) );
        return $value !== '' ? hash( 'sha256', $value ) : null;
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
            if ( ! empty( $_SERVER[ $h ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $h ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            }
        }
        return null;
    }
}

if ( ! function_exists( 'kb_capi_to_eur' ) ) {
    function kb_capi_to_eur( float $amount, string $currency ): float {
        if ( strtoupper( $currency ) !== 'DZD' ) return $amount;
        $rate = (float) kb_conf_cfg( 'rate_dzd_eur', 285 );
        return $rate > 0 ? round( $amount / $rate, 2 ) : round( $amount / 285, 2 );
    }
}

if ( ! function_exists( 'kb_capi_send_currency' ) ) {
    function kb_capi_send_currency( string $currency ): string {
        return strtoupper( $currency ) === 'DZD' ? 'EUR' : strtoupper( $currency );
    }
}

if ( ! function_exists( 'kb_capi_build_user_data_from_order' ) ) {
    function kb_capi_build_user_data_from_order( $order ): array {
        $fbp = $order->get_meta( '_kb_fbp' ) ?: null;
        $fbc = $order->get_meta( '_kb_fbc' ) ?: null;
        $ip  = $order->get_meta( '_kb_client_ip' ) ?: $order->get_customer_ip_address() ?: kb_capi_get_ip();
        $ua  = $order->get_meta( '_kb_client_ua' ) ?: $order->get_customer_user_agent() ?: ( $_SERVER['HTTP_USER_AGENT'] ?? null );

        $external_id = $order->get_meta( '_kb_external_id' )
            ?: hash( 'sha256', (string) $order->get_id() );

        $full  = trim( $order->get_billing_first_name() );
        $ln_r  = trim( $order->get_billing_last_name() );
        $parts = preg_split( '/\s+/u', $full );
        $fn    = $ln_r !== '' ? kb_capi_hash( $full ) : kb_capi_hash( strtolower( $parts[0] ) );
        $last  = count( $parts ) > 1 ? $parts[ count( $parts ) - 1 ] : $parts[0];
        $ln    = $ln_r !== '' ? kb_capi_hash( $ln_r ) : kb_capi_hash( strtolower( $last ) );

        return array_filter( [
            'ph'                => kb_capi_hash_phone( $order->get_billing_phone() ),
            'fn'                => $fn,
            'ln'                => $ln,
            'em'                => kb_capi_hash( $order->get_billing_email() ),
            'ct'                => kb_capi_hash( $order->get_billing_city() ),
            'st'                => kb_capi_hash( $order->get_billing_state() ),
            'country'           => kb_capi_hash( $order->get_billing_country() ),
            'external_id'       => $external_id,
            'fbc'               => $fbc,
            'fbp'               => $fbp,
            'client_ip_address' => $ip ?: null,
            'client_user_agent' => $ua ?: null,
        ] );
    }
}

if ( ! function_exists( 'kb_capi_call_meta' ) ) {
    function kb_capi_call_meta( array $payload, bool $blocking = true ) {
        $pixel_id = kb_conf_cfg( 'meta_pixel_id' );
        $token    = kb_conf_cfg( 'meta_access_token' );
        $api_ver  = kb_conf_cfg( 'meta_api_version', 'v19.0' );
        if ( ! $pixel_id || ! $token ) {
            return new WP_Error( 'kb_conf_config', 'WeConvert.io : Pixel ID ou Access Token manquant.' );
        }
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/events?access_token=%s',
            $api_ver, $pixel_id, $token
        );
        return wp_remote_post( $url, [
            'timeout' => $blocking ? 15 : 0.01,
            'blocking'=> $blocking,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
        ] );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// HOOK PRINCIPAL
// Priority 20 → après EcoTrack (10) et kb_capi_send_purchase (10)
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'woocommerce_order_status_completed', 'kb_conf_fire_on_confirmed', 20, 1 );

function kb_conf_fire_on_confirmed( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    // Guard déduplication
    if ( $order->get_meta( '_kb_capi_confirmed_sent' ) ) return;

    // Payload
    $raw_currency  = $order->get_currency();
    $send_currency = kb_capi_send_currency( $raw_currency );
    $raw_total     = (float) $order->get_total();
    $send_value    = kb_capi_to_eur( $raw_total, $raw_currency );

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

    $pixel_id = kb_conf_cfg( 'meta_pixel_id' );
    $event_id = 'kb_conf_' . $order_id . '_' . substr( md5( $order_id . $pixel_id ), 0, 12 );

    $payload = [
        'data' => [ [
            'event_name'       => 'OrderConfirmed',
            'event_time'       => time(),
            'event_id'         => $event_id,
            'action_source'    => 'website',
            'event_source_url' => home_url( '/' ),
            'user_data'        => kb_capi_build_user_data_from_order( $order ),
            'custom_data'      => [
                'value'        => $send_value,
                'currency'     => $send_currency,
                'content_ids'  => $content_ids,
                'contents'     => $contents,
                'content_type' => 'product',
                'num_items'    => count( $contents ),
                'order_id'     => (string) $order_id,
            ],
        ] ],
    ];

    $test_code = kb_conf_cfg( 'meta_test_code' );
    if ( $test_code !== '' ) $payload['test_event_code'] = $test_code;

    $response = kb_capi_call_meta( $payload );

    if ( is_wp_error( $response ) ) {
        $order->add_order_note( '[KB CONF] ❌ ' . $response->get_error_message() );
        return;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    $code = wp_remote_retrieve_response_code( $response );

    if ( $code === 200 && ! empty( $body['events_received'] ) ) {
        $order->update_meta_data( '_kb_capi_confirmed_sent', current_time( 'mysql' ) );
        $order->update_meta_data( '_kb_capi_confirmed_event_id', $event_id );
        $order->save();
        $order->add_order_note(
            '[KB CONF] ✅ OrderConfirmed — event_id: ' . $event_id .
            ' | ' . $raw_total . ' ' . $raw_currency . ' → ' . $send_value . ' ' . $send_currency .
            ' (taux: ' . kb_conf_cfg( 'rate_dzd_eur', 285 ) . ')'
        );
    } else {
        $error = $body['error']['message'] ?? wp_json_encode( $body );
        $order->add_order_note( '[KB CONF] ❌ HTTP ' . $code . ' : ' . $error );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// DÉCLARATION MODULE
// ═══════════════════════════════════════════════════════════════════════════════

add_filter( 'kb_active_modules', function( $modules ) {
    $modules['confirmed'] = [
        'label'  => '✅ OrderConfirmed CAPI',
        'events' => [ 'OrderConfirmed (server-side — COD natif)' ],
    ];
    return $modules;
} );
