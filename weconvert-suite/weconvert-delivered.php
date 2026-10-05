<?php
/**
 * Module WeConvert.io Suite (chargé par weconvert-suite.php — ne pas activer seul) :
 *   WeConvert.io — OrderDelivered CAPI
 * Description: Fire l'event custom OrderDelivered sur Meta CAPI quand un colis COD est
 *              livré. Livreur-agnostique : écoute l'action kb_parcel_delivered
 *              (EcoTrack / Packers via poll 15 min). Déduplication automatique.
 *              Zéro credential hardcodé — tout depuis WeConvert.io Settings.
 * Version:     3.0.0
 * Author:      WeConvert.io
 *
 * Changelog:
 *   v3.0.0 — Suppression ZR Express. Action générique kb_parcel_delivered (format normalisé).
 *             Cron kb_del_poll_delivery → do_action('kb_delivery_poll') pour chaque driver.
 *             Garde 7 jours (limite Meta CAPI) + auto-completed si livré.
 *   v2.2.0 — Réutilise kb_capi_build_user_data_from_order() et kb_capi_call_meta().
 *             Webhook ZR Express déclenche aussi l'event Delivered (plus rapide que le poll).
 *             Fallback HPOS-compatible pour la recherche par téléphone.
 *             Zéro define() hardcodé.
 *
 * Dépendances (optionnelles) :
 *   - weconvert-settings.php  → kb_get()
 *   - weconvert-capi.php      → helpers partagés
 *   - weconvert-ecotrack.php  → driver EcoTrack (Packers) : création colis + poll livraisons
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════════════════════
// CONFIG
// ═══════════════════════════════════════════════════════════════════════════════

function kb_del_cfg( string $key, $default = '' ) {
    if ( function_exists( 'kb_get' ) ) return kb_get( $key, $default );
    return get_option( 'kb_' . $key, $default );
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS INLINE — actifs si weconvert-capi.php est absent
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
        $rate = (float) kb_del_cfg( 'rate_dzd_eur', 285 );
        return $rate > 0 ? round( $amount / $rate, 2 ) : round( $amount / 285, 2 );
    }
}
if ( ! function_exists( 'kb_capi_build_user_data_from_order' ) ) {
    function kb_capi_build_user_data_from_order( $order ): array {
        $fbp = $order->get_meta( '_kb_fbp' ) ?: null;
        $fbc = $order->get_meta( '_kb_fbc' ) ?: null;
        $ip  = $order->get_meta( '_kb_client_ip' ) ?: $order->get_customer_ip_address() ?: kb_capi_get_ip();
        $ua  = $order->get_meta( '_kb_client_ua' ) ?: $order->get_customer_user_agent() ?: ( $_SERVER['HTTP_USER_AGENT'] ?? null );
        $eid = $order->get_meta( '_kb_external_id' ) ?: hash( 'sha256', (string) $order->get_id() );
        $full  = trim( $order->get_billing_first_name() );
        $ln_r  = trim( $order->get_billing_last_name() );
        $parts = preg_split( '/\s+/u', $full );
        $fn    = $ln_r !== '' ? kb_capi_hash( $full ) : kb_capi_hash( strtolower( $parts[0] ) );
        $last  = count( $parts ) > 1 ? $parts[ count( $parts ) - 1 ] : $parts[0];
        $ln    = $ln_r !== '' ? kb_capi_hash( $ln_r ) : kb_capi_hash( strtolower( $last ) );
        return array_filter( [
            'ph'                => kb_capi_hash_phone( $order->get_billing_phone() ),
            'fn'                => $fn, 'ln' => $ln,
            'em'                => kb_capi_hash( $order->get_billing_email() ),
            'ct'                => kb_capi_hash( $order->get_billing_city() ),
            'st'                => kb_capi_hash( $order->get_billing_state() ),
            'country'           => kb_capi_hash( $order->get_billing_country() ),
            'external_id'       => $eid,
            'fbc'               => $fbc, 'fbp' => $fbp,
            'client_ip_address' => $ip ?: null,
            'client_user_agent' => $ua ?: null,
        ] );
    }
}
if ( ! function_exists( 'kb_capi_call_meta' ) ) {
    function kb_capi_call_meta( array $payload, bool $blocking = true ) {
        $pixel_id = kb_del_cfg( 'meta_pixel_id' );
        $token    = kb_del_cfg( 'meta_access_token' );
        $api_ver  = kb_del_cfg( 'meta_api_version', 'v19.0' );
        if ( ! $pixel_id || ! $token ) return new WP_Error( 'kb_del_config', 'Pixel ID ou token manquant.' );
        $url = sprintf( 'https://graph.facebook.com/%s/%s/events?access_token=%s', $api_ver, $pixel_id, $token );
        return wp_remote_post( $url, [
            'timeout' => $blocking ? 15 : 0.01, 'blocking' => $blocking,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
        ] );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// CRON 15 MINUTES — poll livreur(s)
// ═══════════════════════════════════════════════════════════════════════════════

add_filter( 'cron_schedules', function( $s ) {
    if ( ! isset( $s['kb_15min'] ) ) {
        $s['kb_15min'] = [ 'interval' => 900, 'display' => 'WeConvert.io — 15 minutes' ];
    }
    return $s;
} );

// Auto-réparation : planifie le cron même si le plugin n'a pas été réactivé après mise à jour.
add_action( 'init', function() {
    if ( wp_next_scheduled( 'kb_del_poll_zrexpress' ) ) {
        wp_clear_scheduled_hook( 'kb_del_poll_zrexpress' ); // ancien hook ZR Express (v5)
    }
    if ( ! wp_next_scheduled( 'kb_del_poll_delivery' ) ) {
        wp_schedule_event( time() + 60, 'kb_15min', 'kb_del_poll_delivery' );
    }
} );

/**
 * Le cron déclenche kb_delivery_poll — chaque driver livreur s'y accroche
 * (weconvert-ecotrack.php → eco_run_poll).
 */
add_action( 'kb_del_poll_delivery', function() {
    do_action( 'kb_delivery_poll' );
} );

// ═══════════════════════════════════════════════════════════════════════════════
// DÉCLENCHEMENT — action livreur-agnostique
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Action : kb_parcel_delivered
 * Format attendu (normalisé) :
 *   [ 'source' => 'ecotrack', 'order_id' => 123, 'tracking' => 'ECXXX', 'phone' => '05…',
 *     'delivered_at' => timestamp UTC, 'amount' => 2600, 'wilaya_id' => '16', 'commune' => '…' ]
 */
add_action( 'kb_parcel_delivered', 'kb_del_process_parcel' );

// ═══════════════════════════════════════════════════════════════════════════════
// TRAITEMENT D'UN COLIS
// ═══════════════════════════════════════════════════════════════════════════════

function kb_del_process_parcel( array $parcel ) {
    $wc_order_id = (int) ( $parcel['order_id'] ?? 0 );

    if ( ! $wc_order_id && ! empty( $parcel['phone'] ) ) {
        $wc_order_id = kb_del_find_order_by_phone( $parcel['phone'] );
    }

    if ( ! $wc_order_id ) {
        error_log( '[KB Delivered] Colis sans match order: ' . ( $parcel['tracking'] ?? 'N/A' ) );
        return;
    }

    $order = wc_get_order( $wc_order_id );
    if ( ! $order ) {
        error_log( '[KB Delivered] Order WC introuvable: ' . $wc_order_id );
        return;
    }

    // Déduplication
    if ( $order->get_meta( '_kb_capi_delivered_sent' ) || $order->get_meta( '_kb_capi_delivered_skipped' ) ) return;

    // Livré ⇒ forcément confirmé : passage en completed (fire OrderConfirmed si pas encore fait)
    if ( $order->get_status() !== 'completed' && kb_del_cfg( 'del_autocomplete', '1' ) === '1' ) {
        $order->update_status( 'completed', '[KB Delivered] Colis livré (' . ( $parcel['source'] ?? 'livreur' ) . ') — auto-completed.' );
        $order = wc_get_order( $wc_order_id );
    }

    kb_del_send_event( $order, $parcel );
}

// ═══════════════════════════════════════════════════════════════════════════════
// ENVOI CAPI OrderDelivered
// ═══════════════════════════════════════════════════════════════════════════════

function kb_del_send_event( $order, array $parcel ) {
    $order_id = $order->get_id();
    $tracking = (string) ( $parcel['tracking'] ?? '' );
    $source   = (string) ( $parcel['source'] ?? 'unknown' );

    // event_time = timestamp réel de la livraison si disponible
    $event_time = (int) ( $parcel['delivered_at'] ?? 0 );
    if ( $event_time <= 0 || $event_time > time() ) $event_time = time();

    // Meta rejette les events de plus de 7 jours → on marque sans envoyer
    if ( $event_time < time() - ( 7 * DAY_IN_SECONDS - HOUR_IN_SECONDS ) ) {
        $order->update_meta_data( '_kb_capi_delivered_skipped', current_time( 'mysql' ) );
        if ( $tracking ) $order->update_meta_data( '_kb_delivery_tracking', $tracking );
        $order->save();
        $order->add_order_note( '[KB Delivered] ⏭️ Livré le ' . wp_date( 'Y-m-d H:i', $event_time ) . ' — plus de 7 jours, non envoyé à Meta (limite CAPI).' );
        return;
    }

    $raw_currency = $order->get_currency();
    $raw_total    = (float) $order->get_total();
    $rate         = (float) kb_del_cfg( 'rate_dzd_eur', 285 );
    $send_value   = strtoupper( $raw_currency ) === 'DZD'
        ? round( $raw_total / ( $rate ?: 285 ), 2 )
        : $raw_total;

    $pixel_id   = kb_del_cfg( 'meta_pixel_id' );
    $event_id   = 'kb_del_' . $order_id . '_' . substr( md5( $order_id . 'delivered' . $pixel_id ), 0, 12 );

    $payload = [
        'data' => [ [
            'event_name'    => 'OrderDelivered',
            'event_time'    => $event_time,
            'event_id'      => $event_id,
            'action_source' => 'system_generated',
            'user_data'     => kb_capi_build_user_data_from_order( $order ),
            'custom_data'   => [
                'value'           => $send_value,
                'currency'        => 'EUR',
                'order_id'        => (string) $order_id,
                'tracking_number' => $tracking,
                'delivery_city'   => (string) ( $parcel['commune'] ?? '' ),
                'delivery_wilaya' => (string) ( $parcel['wilaya_id'] ?? '' ),
                'delivery_source' => $source,
            ],
        ] ],
    ];

    $test_code = kb_del_cfg( 'meta_test_code' );
    if ( $test_code !== '' ) $payload['test_event_code'] = $test_code;

    $response = kb_capi_call_meta( $payload );

    if ( is_wp_error( $response ) ) {
        error_log( '[KB Delivered] CAPI error order ' . $order_id . ': ' . $response->get_error_message() );
        $order->add_order_note( '[KB Delivered] ❌ ' . $response->get_error_message() );
        return;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code === 200 && ! empty( $body['events_received'] ) ) {
        $order->update_meta_data( '_kb_capi_delivered_sent', current_time( 'mysql' ) );
        $order->update_meta_data( '_kb_capi_delivered_event_id', $event_id );
        $order->update_meta_data( '_kb_delivery_tracking', $tracking );
        $order->update_meta_data( '_kb_delivery_source', $source );
        $order->save();
        $note = '[KB Delivered] ✅ OrderDelivered — event_id: ' . $event_id .
            ' | ' . $source . ' tracking: ' . ( $tracking ?: 'N/A' ) .
            ' | ' . $raw_total . ' DZD → ' . $send_value . ' EUR';
        $order->add_order_note( $note );
        error_log( '[KB Delivered] ✅ Order ' . $order_id . ' delivered event sent.' );
    } else {
        $error = $body['error']['message'] ?? wp_json_encode( $body );
        $order->add_order_note( '[KB Delivered] ❌ HTTP ' . $code . ' : ' . $error );
        error_log( '[KB Delivered] ❌ Order ' . $order_id . ' CAPI failed: ' . $error );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// FALLBACK — recherche order par téléphone (HPOS + postmeta)
// ═══════════════════════════════════════════════════════════════════════════════

function kb_del_find_order_by_phone( string $phone ): int {
    $phone = preg_replace( '/[^0-9]/', '', $phone );
    // Normaliser 213XXXXXXXX → 0XXXXXXXXX pour chercher dans WC
    if ( strlen( $phone ) === 12 && substr( $phone, 0, 3 ) === '213' ) {
        $phone = '0' . substr( $phone, 3 );
    }
    if ( empty( $phone ) ) return 0;

    global $wpdb;

    // HPOS (WC 7.1+)
    $hpos = $wpdb->prefix . 'wc_orders';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$hpos}'" ) === $hpos ) {
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$hpos}
             WHERE billing_phone = %s
               AND status IN ('wc-completed','wc-processing','wc-on-hold')
             ORDER BY id DESC LIMIT 1",
            $phone
        ) );
        if ( $id ) return (int) $id;
    }

    // postmeta (WC classique)
    $id = $wpdb->get_var( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta}
         WHERE meta_key = '_billing_phone' AND meta_value = %s
         ORDER BY post_id DESC LIMIT 1",
        $phone
    ) );
    return $id ? (int) $id : 0;
}

// ═══════════════════════════════════════════════════════════════════════════════
// ADMIN — page de test manuel
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', function() {
    add_management_page(
        'WeConvert Delivered — Test', 'WeConvert Delivered', 'manage_options',
        'kb-delivered-test', 'kb_del_admin_page'
    );
} );

function kb_del_admin_page() {
    $ran = false;
    if ( isset( $_POST['kb_run_poll'] ) && check_admin_referer( 'kb_del_run_poll' ) ) {
        do_action( 'kb_delivery_poll' );
        $ran = true;
    }
    $next = wp_next_scheduled( 'kb_del_poll_delivery' );
    ?>
    <div class="wrap">
        <h1>📦 WeConvert Delivered — Test manuel</h1>
        <?php if ( $ran ) : ?>
            <div class="notice notice-success"><p>✅ Poll exécuté. Vérifie les order notes et le log d'erreurs.</p></div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field( 'kb_del_run_poll' ); ?>
            <button type="submit" name="kb_run_poll" class="button button-primary">
                🚀 Lancer le poll livraisons maintenant
            </button>
        </form>
        <hr>
        <p>
            <strong>Prochain cron :</strong>
            <?php echo $next
                ? esc_html( date( 'Y-m-d H:i:s', $next ) ) . ' UTC'
                : '⚠️ Non planifié — désactive et réactive le plugin'; ?>
        </p>
        <p><em>Config → <a href="<?= admin_url( 'admin.php?page=weconvert-settings' ) ?>">WeConvert.io → Settings</a></em></p>
    </div>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// DÉCLARATION MODULE
// ═══════════════════════════════════════════════════════════════════════════════

add_filter( 'kb_active_modules', function( $modules ) {
    $modules['delivered'] = [
        'label'  => '📦 OrderDelivered CAPI',
        'events' => [
            'OrderDelivered (poll livreur 15 min)',
            'Cash encaissé réel COD',
        ],
    ];
    return $modules;
} );
