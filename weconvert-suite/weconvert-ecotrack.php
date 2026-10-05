<?php
/**
 * Module WeConvert.io Suite (chargé par weconvert-suite.php — ne pas activer seul) :
 *   WeConvert.io — EcoTrack (Packers) Auto Parcel
 * Description: Crée automatiquement un colis EcoTrack (Packers & toute société sur EcoTrack)
 *              quand une commande WooCommerce passe en "completed". Poll l'API EcoTrack
 *              toutes les 15 min pour détecter les colis livrés → OrderDelivered CAPI.
 * Version:     6.1.0
 * Author:      WeConvert.io
 *
 * API : https://documenter.getpostman.com/view/14517169/Tz5je15g
 *   - Auth : Authorization: Bearer {token}
 *   - Limites : 50 req/min · 1 500 req/h · 15 000 req/jour
 *   - Pas de webhooks côté EcoTrack → détection livraison par poll de /get/orders
 *     (global_status = "livre", livred_at = date réelle de livraison)
 *
 * Changelog v6.1.0 :
 *   - Même logique que ZR Express : completed → colis créé ET validé (transféré au livreur)
 *   - Verrou anti double création · nouveaux essais auto (2/5/15/30/60 min) si API indisponible
 *   - Préfixe kb_eco_ sur toutes les fonctions (zéro collision avec d'autres plugins EcoTrack)
 *   - Neutralise l'ancien module ZR Express s'il est encore actif
 *
 * Changelog v6.0.0 :
 *   - Remplace kitabook-zrexpress.php
 *   - Anti-doublon : si un colis avec la même référence (#1234) existe déjà sur EcoTrack
 *     (créé à la main ou par un autre plugin), il est lié à la commande au lieu d'être recréé
 *   - Détection stop desk via le titre de la méthode de livraison WooCommerce
 *   - do_action('kb_parcel_delivered', $parcel) — format normalisé livreur-agnostique
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════════════════════
// CONFIG
// ═══════════════════════════════════════════════════════════════════════════════

function kb_eco_cfg( string $key, $default = '' ) {
    if ( function_exists( 'kb_get' ) ) {
        $v = kb_get( $key, $default );
        return $v === '' ? $default : $v;
    }
    $v = get_option( 'kb_' . $key, $default );
    return $v === '' ? $default : $v;
}

function kb_eco_is_configured(): bool {
    return kb_eco_cfg( 'eco_api_token' ) !== '' && kb_eco_cfg( 'eco_api_base' ) !== '';
}

function kb_eco_reference( $order ): string {
    return kb_eco_cfg( 'eco_ref_prefix', '#' ) . $order->get_order_number();
}

// ═══════════════════════════════════════════════════════════════════════════════
// ANTI-CONFLIT — ancien module ZR Express encore présent sur le serveur
// ═══════════════════════════════════════════════════════════════════════════════

function kb_eco_neutralize_zrexpress(): void {
    if ( function_exists( 'zr_create_parcel_on_completed' ) ) {
        remove_action( 'woocommerce_order_status_completed', 'zr_create_parcel_on_completed', 10 );
    }
}
kb_eco_neutralize_zrexpress();
add_action( 'init', 'kb_eco_neutralize_zrexpress', 99 );

add_action( 'admin_notices', function() {
    if ( ! function_exists( 'zr_create_parcel_on_completed' ) || ! current_user_can( 'activate_plugins' ) ) return;
    echo '<div class="notice notice-warning"><p><strong>WeConvert.io :</strong> l\'ancien module ZR Express est encore actif. '
        . 'WeConvert.io l\'a neutralisé (aucun colis ZR ne sera créé) — désactive-le dans Extensions pour finir le nettoyage.</p></div>';
} );

// ═══════════════════════════════════════════════════════════════════════════════
// HOOK PRINCIPAL — commande completed → colis transféré à la société de livraison
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'woocommerce_order_status_completed', 'kb_eco_on_order_completed', 10, 1 );

function kb_eco_on_order_completed( int $order_id ) {
    if ( ! kb_eco_is_configured() ) return;
    if ( kb_eco_cfg( 'eco_auto_create', '1' ) !== '1' ) return;
    kb_eco_transfer_order( $order_id, 1 );
}

add_action( 'kb_eco_retry_transfer', 'kb_eco_transfer_order', 10, 2 );

/**
 * Transfert complet d'une commande : création du colis puis validation (expédition).
 * Idempotent : peut être appelé plusieurs fois sans créer de doublon.
 * Erreurs temporaires (réseau, 429, 5xx) → nouvel essai automatique planifié.
 */
function kb_eco_transfer_order( int $order_id, int $attempt = 1 ): void {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    // Verrou : deux requêtes simultanées (double clic, action groupée) ne créent jamais 2 colis
    if ( ! kb_eco_lock( $order_id ) ) {
        kb_eco_log( $order_id, 'Transfert déjà en cours — skip.' );
        return;
    }

    try {
        $tracking = $order->get_meta( '_kb_eco_tracking' );

        if ( ! $tracking ) {
            $result = kb_eco_create_step( $order );
            if ( $result['status'] === 'retry' ) {
                kb_eco_schedule_retry( $order, $attempt, $result['message'] );
                return;
            }
            if ( $result['status'] !== 'ok' ) return;
            $tracking = $result['tracking'];
            $order    = wc_get_order( $order_id );
        }

        $must_ship = kb_eco_cfg( 'eco_auto_ship', '1' ) === '1'
            && ! $order->get_meta( '_kb_eco_shipped' )
            && ! $order->get_meta( '_kb_eco_linked' ); // colis créé ailleurs : on n'y touche pas

        if ( $must_ship ) {
            $ship = kb_eco_ship_parcel( $order, $tracking );
            if ( $ship['status'] === 'retry' ) kb_eco_schedule_retry( $order, $attempt, $ship['message'] );
        }
    } finally {
        kb_eco_unlock( $order_id );
    }
}

/**
 * Crée le colis (ou lie un colis existant de même référence).
 * @return array  status = ok|retry|fail (+ tracking | message)
 */
function kb_eco_create_step( $order ): array {
    $reference = kb_eco_reference( $order );

    // ── Anti-doublon : colis déjà présent sur EcoTrack avec cette référence ? ─
    $existing = kb_eco_find_tracking_by_reference( $reference );
    if ( $existing ) {
        $order->update_meta_data( '_kb_eco_tracking', $existing );
        $order->update_meta_data( '_kb_eco_linked', '1' );
        $order->delete_meta_data( '_kb_eco_error' );
        $order->save();
        $order->add_order_note( "🔗 EcoTrack : colis existant lié ({$reference}) — tracking {$existing}" );
        return [ 'status' => 'ok', 'tracking' => $existing ];
    }

    $payload = kb_eco_build_order_payload( $order );
    if ( is_wp_error( $payload ) ) {
        $msg = $payload->get_error_message();
        if ( in_array( $payload->get_error_code(), [ 'eco_wilayas', 'eco_communes', 'eco_rate_limit', 'http_request_failed' ], true ) ) {
            return [ 'status' => 'retry', 'message' => $msg ];
        }
        kb_eco_mark_error( $order, $msg . ' — corriger la commande puis « Créer le colis » (metabox EcoTrack).' );
        return [ 'status' => 'fail' ];
    }

    $response = kb_eco_api( 'POST', '/create/order', $payload );
    $failure  = kb_eco_classify_failure( $response );
    if ( $failure ) {
        if ( $failure['status'] === 'fail' ) kb_eco_mark_error( $order, 'création colis — ' . $failure['message'] );
        return $failure;
    }

    $body     = json_decode( wp_remote_retrieve_body( $response ), true );
    $tracking = sanitize_text_field( $body['tracking'] ?? '' );
    if ( $tracking === '' ) {
        kb_eco_mark_error( $order, 'réponse EcoTrack sans tracking : ' . substr( wp_remote_retrieve_body( $response ), 0, 200 ) );
        return [ 'status' => 'fail' ];
    }
    $order->update_meta_data( '_kb_eco_tracking', $tracking );
    $order->delete_meta_data( '_kb_eco_error' );
    $order->save();
    $order->add_order_note( "✅ EcoTrack : colis créé — tracking {$tracking}" );
    kb_eco_index_add( $reference, $tracking );
    return [ 'status' => 'ok', 'tracking' => $tracking ];
}

/**
 * Analyse une réponse EcoTrack. null = succès, sinon ['status' => retry|fail, 'message', 'error_code'].
 */
function kb_eco_classify_failure( $response ): ?array {
    if ( is_wp_error( $response ) ) {
        $fatal = $response->get_error_code() === 'eco_auth';
        return [ 'status' => $fatal ? 'fail' : 'retry', 'message' => $response->get_error_message() ];
    }
    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code >= 500 || $code === 0 ) {
        return [ 'status' => 'retry', 'message' => "EcoTrack indisponible (HTTP {$code})" ];
    }
    if ( $code === 200 && ! empty( $body['success'] ) ) return null;

    $detail = $body['message'] ?? substr( wp_remote_retrieve_body( $response ), 0, 300 );
    if ( ! empty( $body['errors'] ) && is_array( $body['errors'] ) ) {
        $parts = [];
        foreach ( $body['errors'] as $field => $msgs ) $parts[] = $field . ' : ' . implode( ', ', (array) $msgs );
        $detail = implode( ' | ', $parts );
    }
    return [ 'status' => 'fail', 'message' => "HTTP {$code} — {$detail}", 'error_code' => $body['error'] ?? null ];
}

function kb_eco_mark_error( $order, string $message ): void {
    $order->update_meta_data( '_kb_eco_error', $message );
    $order->save();
    $order->add_order_note( '❌ EcoTrack : ' . $message );
    kb_eco_log( $order->get_id(), $message );
}

/**
 * Nouvel essai : 2 min, 5 min, 15 min, 30 min, 1 h. Au-delà → erreur visible sur la commande.
 */
function kb_eco_schedule_retry( $order, int $attempt, string $message ): void {
    $delays = [ 1 => 120, 2 => 300, 3 => 900, 4 => 1800, 5 => 3600 ];
    if ( ! isset( $delays[ $attempt ] ) ) {
        kb_eco_mark_error( $order, "échec après {$attempt} essais ({$message}) — utiliser la metabox EcoTrack de la commande." );
        return;
    }
    $args = [ $order->get_id(), $attempt + 1 ];
    if ( function_exists( 'as_schedule_single_action' ) ) {
        as_schedule_single_action( time() + $delays[ $attempt ], 'kb_eco_retry_transfer', $args, 'weconvert' );
    } else {
        wp_schedule_single_event( time() + $delays[ $attempt ], 'kb_eco_retry_transfer', $args );
    }
    $order->add_order_note( "⏳ EcoTrack : {$message} — nouvel essai automatique dans " . ( $delays[ $attempt ] / 60 ) . ' min.' );
}

function kb_eco_lock( int $order_id ): bool {
    $key = 'kb_eco_lock_' . $order_id;
    if ( add_option( $key, time(), '', 'no' ) ) return true;   // atomique (clé unique en DB)
    if ( time() - (int) get_option( $key ) > 120 ) {             // verrou orphelin (crash)
        update_option( $key, time(), false );
        return true;
    }
    return false;
}

function kb_eco_unlock( int $order_id ): void {
    delete_option( 'kb_eco_lock_' . $order_id );
}

/**
 * Construit le payload /create/order depuis une commande WC.
 * @return array|WP_Error
 */
function kb_eco_build_order_payload( $order ) {
    $order_id = $order->get_id();

    // ── Wilaya ────────────────────────────────────────────────────────────────
    $wilaya_raw  = $order->get_shipping_state() ?: $order->get_billing_state();
    $commune_raw = $order->get_shipping_city()  ?: $order->get_billing_city();

    $wilaya_id = kb_eco_match_wilaya( (string) $wilaya_raw );
    if ( ! $wilaya_id ) {
        return new WP_Error( 'eco_wilaya', "wilaya introuvable « {$wilaya_raw} »" );
    }

    // ── Livraison domicile / stop desk ────────────────────────────────────────
    $stop_desk = kb_eco_is_stop_desk( $order ) ? 1 : 0;

    // ── Commune ───────────────────────────────────────────────────────────────
    $communes = kb_eco_get_communes_cached();
    if ( is_wp_error( $communes ) ) return $communes;

    $commune = kb_eco_match_commune( $communes, $wilaya_id, kb_eco_clean_dz_city( (string) $commune_raw ) );

    if ( ! $commune ) {
        $commune = kb_eco_fallback_commune( $communes, $wilaya_id, $stop_desk );
        if ( ! $commune ) {
            return new WP_Error( 'eco_commune', "aucune commune active pour la wilaya {$wilaya_id}" );
        }
        $order->add_order_note( "⚠️ EcoTrack : commune « {$commune_raw} » non reconnue — « {$commune['nom']} » utilisée. Vérifier le colis." );
    } elseif ( $stop_desk && empty( $commune['has_stop_desk'] ) ) {
        $desk = kb_eco_fallback_commune( $communes, $wilaya_id, 1 );
        if ( $desk ) {
            $order->add_order_note( "⚠️ EcoTrack : pas de stop desk à « {$commune['nom']} » — bureau de « {$desk['nom']} » utilisé." );
            $commune = $desk;
        } else {
            $order->add_order_note( "⚠️ EcoTrack : aucun stop desk dans la wilaya {$wilaya_id} — livraison à domicile." );
            $stop_desk = 0;
        }
    }

    // ── Client ────────────────────────────────────────────────────────────────
    $phone = kb_eco_normalize_phone( $order->get_billing_phone() );
    if ( ! $phone ) {
        return new WP_Error( 'eco_phone', 'téléphone client invalide « ' . $order->get_billing_phone() . ' »' );
    }

    $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
    if ( mb_strlen( $customer_name ) < 2 ) $customer_name = 'Client';

    $street = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
    if ( ! $street ) $street = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
    if ( ! $street ) $street = $commune['nom'];

    // ── Produits ──────────────────────────────────────────────────────────────
    $product_parts = [];
    $total_qty     = 0;
    foreach ( $order->get_items() as $item ) {
        $qty        = max( 1, (int) $item->get_quantity() );
        $total_qty += $qty;
        $product_parts[] = $item->get_name() . ( $qty > 1 ? ' x' . $qty : '' );
    }
    $produit = implode( ', ', $product_parts );
    if ( mb_strlen( $produit ) > 250 ) $produit = mb_substr( $produit, 0, 247 ) . '...';

    $remarque = trim( (string) $order->get_customer_note() );
    if ( mb_strlen( $remarque ) > 250 ) $remarque = mb_substr( $remarque, 0, 247 ) . '...';

    $payload = [
        'reference'   => kb_eco_reference( $order ),
        'nom_client'  => $customer_name,
        'telephone'   => $phone,
        'adresse'     => $street,
        'commune'     => $commune['nom'],
        'code_wilaya' => (int) $wilaya_id,
        'montant'     => round( (float) $order->get_total(), 2 ),
        'remarque'    => $remarque,
        'produit'     => $produit,
        'quantite'    => max( 1, $total_qty ),
        'stock'       => 0,
        'type'        => 1,             // 1 = Livraison
        'stop_desk'   => $stop_desk,
    ];

    $phone_2 = kb_eco_normalize_phone( (string) $order->get_shipping_phone() );
    if ( $phone_2 && $phone_2 !== $phone ) $payload['telephone_2'] = $phone_2;

    /**
     * Filtre : kb_eco_order_payload — personnaliser le payload avant envoi
     * (ex : poids, fragile, boutique).
     */
    return apply_filters( 'kb_eco_order_payload', $payload, $order );
}

/**
 * Valide et expédie un colis → il part chez la société de livraison
 * (après expédition il n'est plus modifiable).
 * @return array  status = ok|retry|fail (+ message)
 */
function kb_eco_ship_parcel( $order, string $tracking ): array {
    $data = [ 'tracking' => $tracking ];
    if ( kb_eco_cfg( 'eco_ask_collection', '0' ) === '1' ) $data['ask_collection'] = 1;

    $response = kb_eco_api( 'POST', '/valid/order', $data );
    $failure  = kb_eco_classify_failure( $response );

    // 10001 « Commande non modifiable » = déjà expédiée
    if ( ! $failure || ( $failure['error_code'] ?? null ) === 10001 ) {
        $order->update_meta_data( '_kb_eco_shipped', current_time( 'mysql' ) );
        $order->delete_meta_data( '_kb_eco_error' );
        $order->save();
        $order->add_order_note( "🚀 EcoTrack : colis {$tracking} validé — transféré à la société de livraison." );
        return [ 'status' => 'ok' ];
    }
    if ( $failure['status'] === 'fail' ) kb_eco_mark_error( $order, 'validation expédition — ' . $failure['message'] );
    return $failure;
}

// ═══════════════════════════════════════════════════════════════════════════════
// POLL — détection des colis livrés (cron 15 min, planifié par weconvert-delivered.php)
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'kb_delivery_poll', 'kb_eco_run_poll' );

/**
 * Parcourt /get/orders (40 colis/page) sur la fenêtre lookback.
 * - met à jour l'index référence → tracking (anti-doublon)
 * - fire kb_parcel_delivered pour chaque colis livré
 * - note les retours
 *
 * @return array|WP_Error stats
 */
function kb_eco_run_poll() {
    if ( ! kb_eco_is_configured() ) return new WP_Error( 'eco_config', 'Token EcoTrack manquant.' );

    $parcels = kb_eco_fetch_parcels();
    if ( is_wp_error( $parcels ) ) {
        error_log( '[KB EcoTrack] Poll error: ' . $parcels->get_error_message() );
        return $parcels;
    }

    $stats = [ 'parcels' => count( $parcels ), 'delivered' => 0, 'returned' => 0, 'unmatched' => 0 ];

    foreach ( $parcels as $p ) {
        $global = strtolower( (string) ( $p['global_status'] ?? '' ) );

        if ( $global === 'livre' || ! empty( $p['livred_at'] ) ) {
            $normalized = kb_eco_normalize_parcel( $p );
            if ( ! $normalized['order_id'] ) { $stats['unmatched']++; continue; }
            $stats['delivered']++;

            // Lier le tracking avant tout changement de statut (évite une re-création du colis)
            $order = wc_get_order( $normalized['order_id'] );
            if ( $order && ! $order->get_meta( '_kb_eco_shipped' ) ) {
                if ( ! $order->get_meta( '_kb_eco_tracking' ) && $normalized['tracking'] ) {
                    $order->update_meta_data( '_kb_eco_tracking', $normalized['tracking'] );
                }
                $order->update_meta_data( '_kb_eco_shipped', $order->get_meta( '_kb_eco_shipped' ) ?: current_time( 'mysql' ) );
                $order->save();
            }
            /**
             * Action : kb_parcel_delivered — format normalisé livreur-agnostique.
             * Écouté par weconvert-delivered.php → kb_del_process_parcel()
             */
            do_action( 'kb_parcel_delivered', $normalized );
            continue;
        }

        if ( $global === 'retour' ) {
            $order_id = kb_eco_resolve_order_id( $p );
            if ( ! $order_id ) continue;
            $order = wc_get_order( $order_id );
            if ( ! $order || $order->get_meta( '_kb_eco_returned' ) ) continue;
            $order->update_meta_data( '_kb_eco_returned', current_time( 'mysql' ) );
            if ( ! $order->get_meta( '_kb_eco_shipped' ) ) $order->update_meta_data( '_kb_eco_shipped', current_time( 'mysql' ) );
            if ( ! $order->get_meta( '_kb_eco_tracking' ) ) {
                $order->update_meta_data( '_kb_eco_tracking', $p['tracking'] ?? '' );
            }
            $order->save();
            $order->add_order_note( '↩️ EcoTrack : colis ' . ( $p['tracking'] ?? '' ) . ' en retour (' . ( $p['status'] ?? '' ) . ').' );
            $stats['returned']++;
            do_action( 'kb_parcel_returned', kb_eco_normalize_parcel( $p ) );
        }
    }

    update_option( 'kb_eco_last_poll', [ 'at' => current_time( 'mysql' ) ] + $stats, false );
    return $stats;
}

/**
 * Récupère tous les colis de la fenêtre lookback (pagination) et rafraîchit l'index.
 * @return array|WP_Error
 */
function kb_eco_fetch_parcels() {
    $lookback = max( 1, min( 90, (int) kb_eco_cfg( 'eco_lookback_days', 30 ) ) );
    $args     = [
        'start_date' => wp_date( 'Y-m-d', strtotime( "-{$lookback} days" ) ),
        'end_date'   => wp_date( 'Y-m-d', strtotime( '+1 day' ) ),
    ];

    $all   = [];
    $page  = 1;
    $last  = 1;
    $max   = 40; // 40 pages × 40 = 1 600 colis max par poll (rate limit : 50 req/min)
    do {
        $response = kb_eco_api( 'GET', '/get/orders', $args + [ 'page' => $page ] );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || ! is_array( $body ) ) {
            return new WP_Error( 'eco_http', "HTTP {$code} — " . substr( wp_remote_retrieve_body( $response ), 0, 200 ) );
        }
        $all  = array_merge( $all, $body['data'] ?? [] );
        $last = (int) ( $body['last_page'] ?? 1 );
        $page++;
    } while ( $page <= $last && $page <= $max );

    // Index référence → tracking pour l'anti-doublon
    $index = [];
    foreach ( $all as $p ) {
        if ( ! empty( $p['reference'] ) && ! empty( $p['tracking'] ) ) {
            $index[ kb_eco_ref_key( $p['reference'] ) ] = $p['tracking'];
        }
    }
    set_transient( 'kb_eco_ref_index', [ 'at' => time(), 'map' => $index ], 20 * MINUTE_IN_SECONDS );

    return $all;
}

/**
 * Format normalisé pour kb_parcel_delivered (indépendant du livreur).
 */
function kb_eco_normalize_parcel( array $p ): array {
    return [
        'source'       => 'ecotrack',
        'order_id'     => kb_eco_resolve_order_id( $p ),
        'tracking'     => (string) ( $p['tracking'] ?? '' ),
        'reference'    => (string) ( $p['reference'] ?? '' ),
        'phone'        => (string) ( $p['phone'] ?? '' ),
        'status'       => (string) ( $p['status'] ?? '' ),
        'amount'       => isset( $p['montant'] ) ? (float) $p['montant'] : null,
        'delivered_at' => kb_eco_to_timestamp( $p['livred_at'] ?? '' ),
        'wilaya_id'    => (string) ( $p['wilaya_id'] ?? '' ),
        'commune'      => (string) ( $p['commune'] ?? '' ), // /get/orders ne renvoie que commune_id (non mappable)
    ];
}

/**
 * Résout la commande WC d'un colis EcoTrack : tracking meta → référence → téléphone.
 */
function kb_eco_resolve_order_id( array $p ): int {
    $tracking = (string) ( $p['tracking'] ?? '' );

    if ( $tracking ) {
        $ids = wc_get_orders( [
            'limit'      => 1,
            'return'     => 'ids',
            'meta_key'   => '_kb_eco_tracking',
            'meta_value' => $tracking,
        ] );
        if ( ! empty( $ids ) ) return (int) $ids[0];
    }

    $ref = (string) ( $p['reference'] ?? '' );
    if ( $ref !== '' ) {
        $number = preg_replace( '/\D/', '', $ref );
        if ( $number !== '' ) {
            $order = wc_get_order( (int) $number );
            if ( $order && kb_eco_ref_key( kb_eco_reference( $order ) ) === kb_eco_ref_key( $ref ) ) {
                return $order->get_id();
            }
            // Numérotation séquentielle (plugin tiers) : order_number ≠ ID
            $ids = wc_get_orders( [
                'limit'      => 1,
                'return'     => 'ids',
                'meta_key'   => '_order_number',
                'meta_value' => $number,
            ] );
            if ( ! empty( $ids ) ) return (int) $ids[0];
        }
    }

    // Téléphone : uniquement pour un colis sans référence (évite de lier le colis d'une autre boutique)
    $phone = (string) ( $p['phone'] ?? '' );
    if ( $ref === '' && $phone && function_exists( 'kb_del_find_order_by_phone' ) ) {
        return kb_del_find_order_by_phone( $phone );
    }
    return 0;
}

// ═══════════════════════════════════════════════════════════════════════════════
// INDEX RÉFÉRENCES (anti-doublon)
// ═══════════════════════════════════════════════════════════════════════════════

function kb_eco_ref_key( string $ref ): string {
    return strtolower( preg_replace( '/[^a-z0-9]/i', '', $ref ) );
}

/**
 * Index rafraîchi s'il a plus de 2 min (un colis créé à la main juste avant doit être vu).
 * Plusieurs commandes confirmées d'affilée partagent le même rafraîchissement.
 */
function kb_eco_find_tracking_by_reference( string $reference ): ?string {
    $index = get_transient( 'kb_eco_ref_index' );
    if ( ! is_array( $index ) || ( time() - (int) ( $index['at'] ?? 0 ) ) > 2 * MINUTE_IN_SECONDS ) {
        $fetched = kb_eco_fetch_parcels();
        if ( is_wp_error( $fetched ) ) {
            error_log( '[KB EcoTrack] Anti-doublon indisponible : ' . $fetched->get_error_message() );
            return null;
        }
        $index = get_transient( 'kb_eco_ref_index' );
    }
    return $index['map'][ kb_eco_ref_key( $reference ) ] ?? null;
}

function kb_eco_index_add( string $reference, string $tracking ): void {
    $index = get_transient( 'kb_eco_ref_index' );
    if ( ! is_array( $index ) ) return;
    $index['map'][ kb_eco_ref_key( $reference ) ] = $tracking;
    set_transient( 'kb_eco_ref_index', $index, 20 * MINUTE_IN_SECONDS );
}

// ═══════════════════════════════════════════════════════════════════════════════
// TERRITOIRES — wilayas + communes, cache 24h
// ═══════════════════════════════════════════════════════════════════════════════

function kb_eco_get_wilayas_cached() {
    $cached = get_transient( 'kb_eco_wilayas' );
    if ( $cached !== false ) return $cached;

    $response = kb_eco_api( 'GET', '/get/wilayas' );
    if ( is_wp_error( $response ) ) return $response;
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( wp_remote_retrieve_response_code( $response ) !== 200 || empty( $body ) || ! is_array( $body ) ) {
        return new WP_Error( 'eco_wilayas', 'HTTP ' . wp_remote_retrieve_response_code( $response ) );
    }
    set_transient( 'kb_eco_wilayas', $body, DAY_IN_SECONDS );
    return $body;
}

function kb_eco_get_communes_cached() {
    $cached = get_transient( 'kb_eco_communes' );
    if ( $cached !== false ) return $cached;

    $response = kb_eco_api( 'GET', '/get/communes' );
    if ( is_wp_error( $response ) ) return $response;
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( wp_remote_retrieve_response_code( $response ) !== 200 || empty( $body ) || ! is_array( $body ) ) {
        return new WP_Error( 'eco_communes', 'HTTP ' . wp_remote_retrieve_response_code( $response ) );
    }
    $communes = array_values( $body ); // l'API renvoie un objet indexé
    set_transient( 'kb_eco_communes', $communes, DAY_IN_SECONDS );
    return $communes;
}

/**
 * "DZ-16", "16", "16 - Alger", "Alger", "Alger - الجزائر" → 16
 */
function kb_eco_match_wilaya( string $raw ): ?int {
    $raw = trim( preg_replace( '/^DZ-/i', '', trim( $raw ) ) );
    if ( $raw === '' ) return null;

    if ( preg_match( '/^(\d{1,2})\b/', $raw, $m ) ) {
        $code = (int) $m[1];
        if ( $code >= 1 && $code <= 58 ) return $code;
    }

    $name = kb_eco_clean_dz_state( $raw );
    $wilayas = kb_eco_get_wilayas_cached();
    if ( is_wp_error( $wilayas ) || ! $name ) return null;

    $sn = kb_eco_normalize( $name );
    foreach ( [ 'exact', 'prefix', 'contains' ] as $mode ) {
        foreach ( $wilayas as $w ) {
            $wn = kb_eco_normalize( $w['wilaya_name'] ?? '' );
            if ( kb_eco_name_matches( $wn, $sn, $mode ) ) return (int) $w['wilaya_id'];
        }
    }
    return null;
}

function kb_eco_match_commune( array $communes, int $wilaya_id, string $search ): ?array {
    if ( $search === '' ) return null;
    $sn   = kb_eco_normalize( $search );
    $pool = array_values( array_filter( $communes, fn( $c ) => (int) ( $c['wilaya_id'] ?? 0 ) === $wilaya_id ) );

    foreach ( [ 'exact', 'prefix', 'contains' ] as $mode ) {
        foreach ( $pool as $c ) {
            if ( kb_eco_name_matches( kb_eco_normalize( $c['nom'] ?? '' ), $sn, $mode ) ) return $c;
        }
    }
    // Faute de frappe légère (Bab Ezzouar / Bab Ezouar)
    $best = null; $best_d = 3;
    foreach ( $pool as $c ) {
        $d = levenshtein( kb_eco_normalize( $c['nom'] ?? '' ), $sn );
        if ( $d < $best_d ) { $best_d = $d; $best = $c; }
    }
    return $best;
}

/**
 * Commune de repli : chef-lieu (même nom que la wilaya) sinon première commune
 * (avec stop desk si demandé).
 */
function kb_eco_fallback_commune( array $communes, int $wilaya_id, int $need_desk ): ?array {
    $pool = array_values( array_filter( $communes, function( $c ) use ( $wilaya_id, $need_desk ) {
        if ( (int) ( $c['wilaya_id'] ?? 0 ) !== $wilaya_id ) return false;
        return ! $need_desk || ! empty( $c['has_stop_desk'] );
    } ) );
    if ( empty( $pool ) ) return null;

    $wilayas = kb_eco_get_wilayas_cached();
    if ( ! is_wp_error( $wilayas ) ) {
        foreach ( $wilayas as $w ) {
            if ( (int) $w['wilaya_id'] !== $wilaya_id ) continue;
            $wn = kb_eco_normalize( $w['wilaya_name'] );
            foreach ( $pool as $c ) {
                if ( kb_eco_normalize( $c['nom'] ) === $wn ) return $c;
            }
        }
    }
    return $pool[0];
}

function kb_eco_name_matches( string $candidate, string $search, string $mode ): bool {
    if ( $candidate === '' || $search === '' ) return false;
    if ( $mode === 'exact' )  return $candidate === $search;
    if ( $mode === 'prefix' ) return str_starts_with( $candidate, $search );
    return str_contains( $candidate, $search ) || str_contains( $search, $candidate );
}

function kb_eco_is_stop_desk( $order ): bool {
    $keywords = array_filter( array_map( 'trim', explode( ',', strtolower(
        kb_eco_cfg( 'eco_desk_keywords', 'stop desk,stopdesk,bureau,agence,point relais,مكتب' )
    ) ) ) );
    foreach ( $order->get_shipping_methods() as $method ) {
        $haystack = mb_strtolower( $method->get_name() . ' ' . $method->get_method_id() );
        foreach ( $keywords as $kw ) {
            if ( $kw !== '' && str_contains( $haystack, $kw ) ) return true;
        }
    }
    return (bool) apply_filters( 'kb_eco_is_stop_desk', false, $order );
}

function kb_eco_clean_dz_state( string $raw ): string {
    $raw = preg_replace( '/\s*[-–—]\s*[\x{0600}-\x{06FF}].*/u', '', trim( $raw ) );
    if ( preg_match( '/^\d+\s*[-–—]?\s*(.+)$/u', trim( $raw ), $m ) ) return trim( $m[1] );
    return trim( $raw );
}

function kb_eco_clean_dz_city( string $raw ): string {
    $raw = preg_replace( '/\s*[-–—]\s*[\x{0600}-\x{06FF}].*/u', '', trim( $raw ) );
    if ( preg_match( '/^[\x{0600}-\x{06FF}\s]+$/u', $raw ) ) return '';
    return trim( $raw );
}

function kb_eco_normalize( string $s ): string {
    $s = mb_strtolower( trim( $s ) );
    $t = function_exists( 'transliterator_transliterate' )
        ? transliterator_transliterate( 'Any-Latin; Latin-ASCII', $s )
        : @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $s );
    if ( $t !== false && $t !== null ) $s = $t;
    $s = preg_replace( "/['’`\"]/", '', $s );
    $s = preg_replace( '/[-_\s]+/', ' ', $s );
    return trim( $s );
}

/**
 * Téléphone → format local EcoTrack 0XXXXXXXXX
 */
function kb_eco_normalize_phone( string $raw ): string {
    $p = preg_replace( '/\D/', '', $raw );
    if ( str_starts_with( $p, '00213' ) ) $p = substr( $p, 5 );
    elseif ( str_starts_with( $p, '213' ) && strlen( $p ) >= 11 ) $p = substr( $p, 3 );
    if ( strlen( $p ) === 9 && $p[0] !== '0' ) $p = '0' . $p;
    return ( strlen( $p ) === 10 && $p[0] === '0' ) ? $p : '';
}

/**
 * "2026-09-23 16:34:48" (heure d'Alger) → timestamp UTC
 */
function kb_eco_to_timestamp( string $datetime ): int {
    if ( $datetime === '' ) return 0;
    try {
        $dt = new DateTime( $datetime, new DateTimeZone( 'Africa/Algiers' ) );
        return $dt->getTimestamp();
    } catch ( Exception $e ) {
        return 0;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPER HTTP EcoTrack
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * @param string $method GET|POST|DELETE
 * @param string $endpoint ex '/create/order'
 * @param array  $data query (GET) ou body JSON (POST)
 */
function kb_eco_api( string $method, string $endpoint, array $data = [], ?string $token = null, ?string $base = null ) {
    $token = $token ?? kb_eco_cfg( 'eco_api_token' );
    $base  = rtrim( $base ?? kb_eco_cfg( 'eco_api_base', 'https://packers.ecotrack.dz' ), '/' );
    $base  = preg_replace( '#/api/v1$#', '', $base );
    $url   = $base . '/api/v1' . $endpoint;

    $args = [
        'method'  => $method,
        'timeout' => 25,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
    ];

    if ( $method === 'GET' || $method === 'DELETE' ) {
        if ( $data ) $url = add_query_arg( array_map( 'rawurlencode', $data ), $url );
    } else {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode( $data );
    }

    $response = wp_remote_request( $url, $args );

    if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 429 ) {
        return new WP_Error( 'eco_rate_limit', 'Limite API EcoTrack atteinte (429) — réessai au prochain cron.' );
    }
    if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 401 ) {
        return new WP_Error( 'eco_auth', 'Token EcoTrack invalide (401).' );
    }
    return $response;
}

function kb_eco_log( int $order_id, string $message ): void {
    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( "[EcoTrack] Order #{$order_id} — {$message}" );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ADMIN — metabox commande (tracking + actions)
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'add_meta_boxes', function() {
    $screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
    add_meta_box( 'kb-ecotrack', '🚚 EcoTrack', 'kb_eco_order_metabox', $screen, 'side', 'high' );
} );

function kb_eco_order_metabox( $post_or_order ) {
    $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
    if ( ! $order ) return;
    $tracking = $order->get_meta( '_kb_eco_tracking' );
    $base     = rtrim( kb_eco_cfg( 'eco_api_base', 'https://packers.ecotrack.dz' ), '/' );
    $nonce    = wp_create_nonce( 'kb_eco_order_action' );
    $url      = fn( $act ) => admin_url( 'admin-post.php?action=kb_eco_order&do=' . $act . '&order_id=' . $order->get_id() . '&_wpnonce=' . $nonce );

    $error = $order->get_meta( '_kb_eco_error' );
    if ( $error ) echo '<p style="color:#b32d2e"><strong>⚠️ Erreur :</strong> ' . esc_html( $error ) . '</p>';

    if ( $tracking ) {
        echo '<p><strong>Tracking :</strong><br><code>' . esc_html( $tracking ) . '</code></p>';
        echo '<p><a class="button" target="_blank" href="' . esc_url( $url( 'label' ) ) . '">🏷️ Étiquette PDF</a> ';
        if ( ! $order->get_meta( '_kb_eco_shipped' ) ) {
            echo '<a class="button" href="' . esc_url( $url( 'ship' ) ) . '">🚀 Expédier</a>';
        }
        echo '</p>';
        if ( $order->get_meta( '_kb_eco_shipped' ) )         echo '<p>🚀 Transféré à la société de livraison</p>';
        elseif ( $order->get_meta( '_kb_eco_linked' ) )      echo '<p>🔗 Colis créé hors WeConvert.io (lié par référence)</p>';
        else                                                 echo '<p>🕓 Créé — en attente de validation</p>';
        if ( $order->get_meta( '_kb_capi_delivered_sent' ) ) echo '<p>✅ Livré — OrderDelivered envoyé</p>';
        elseif ( $order->get_meta( '_kb_eco_returned' ) )    echo '<p>↩️ En retour</p>';
    } else {
        echo '<p>Aucun colis EcoTrack.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url( $url( 'create' ) ) . '">➕ Créer le colis</a></p>';
    }
}

add_action( 'admin_post_kb_eco_order', function() {
    if ( ! current_user_can( 'edit_shop_orders' ) ) wp_die( 'Unauthorized', 403 );
    check_admin_referer( 'kb_eco_order_action' );
    $order = wc_get_order( absint( $_GET['order_id'] ?? 0 ) );
    if ( ! $order ) wp_die( 'Commande introuvable' );
    $do       = sanitize_key( $_GET['do'] ?? '' );
    $tracking = $order->get_meta( '_kb_eco_tracking' );

    if ( $do === 'label' && $tracking ) {
        $response = kb_eco_api( 'GET', '/get/order/label', [ 'tracking' => $tracking ] );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            wp_die( 'Étiquette indisponible : ' . esc_html( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response ) ) );
        }
        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $tracking ) . '.pdf"' );
        echo wp_remote_retrieve_body( $response ); // phpcs:ignore
        exit;
    }
    if ( $do === 'ship' && $tracking ) {
        kb_eco_ship_parcel( $order, $tracking );
    }
    if ( $do === 'create' && ! $tracking && kb_eco_is_configured() ) {
        kb_eco_transfer_order( $order->get_id(), 1 );
    }
    wp_safe_redirect( wp_get_referer() ?: $order->get_edit_order_url() );
    exit;
} );

// ═══════════════════════════════════════════════════════════════════════════════
// ADMIN — page outils
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', function() {
    add_management_page(
        'EcoTrack — Outils', 'EcoTrack', 'manage_woocommerce',
        'kb-ecotrack-tools', 'kb_eco_admin_tools_page'
    );
} );

function kb_eco_admin_tools_page() {
    if ( isset( $_POST['eco_clear_cache'] ) && check_admin_referer( 'eco_clear_cache' ) ) {
        delete_transient( 'kb_eco_wilayas' );
        delete_transient( 'kb_eco_communes' );
        delete_transient( 'kb_eco_ref_index' );
        echo '<div class="notice notice-success"><p>✅ Cache wilayas / communes / références vidé.</p></div>';
    }

    if ( isset( $_POST['eco_test_territories'] ) && check_admin_referer( 'eco_test_territories' ) ) {
        $w = kb_eco_get_wilayas_cached();
        $c = kb_eco_get_communes_cached();
        if ( is_wp_error( $w ) || is_wp_error( $c ) ) {
            $err = is_wp_error( $w ) ? $w : $c;
            echo '<div class="notice notice-error"><p>❌ ' . esc_html( $err->get_error_message() ) . '</p></div>';
        } else {
            $desks = count( array_filter( $c, fn( $x ) => ! empty( $x['has_stop_desk'] ) ) );
            echo '<div class="notice notice-success"><p>✅ ' . count( $w ) . ' wilayas · ' . count( $c ) . ' communes · ' . $desks . ' avec stop desk.</p></div>';
            echo '<pre style="max-height:300px;overflow:auto;background:#f1f1f1;padding:10px">';
            foreach ( $w as $x ) echo esc_html( sprintf( "[%02d] %s\n", $x['wilaya_id'], $x['wilaya_name'] ) );
            echo '</pre>';
        }
    }

    if ( isset( $_POST['kb_eco_run_poll'] ) && check_admin_referer( 'kb_eco_run_poll' ) ) {
        $stats = kb_eco_run_poll();
        if ( is_wp_error( $stats ) ) {
            echo '<div class="notice notice-error"><p>❌ ' . esc_html( $stats->get_error_message() ) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>✅ Poll : ' . (int) $stats['parcels'] . ' colis lus · '
                . (int) $stats['delivered'] . ' livrés · ' . (int) $stats['returned'] . ' nouveaux retours · '
                . (int) $stats['unmatched'] . ' livrés sans commande WC correspondante.</p></div>';
        }
    }

    $last = get_option( 'kb_eco_last_poll' );
    ?>
    <div class="wrap">
        <h1>🚚 EcoTrack — Outils</h1>
        <p><em>Credentials dans <a href="<?= esc_url( admin_url( 'admin.php?page=weconvert-settings' ) ) ?>">WeConvert.io → Settings</a> · Plateforme : <code><?= esc_html( kb_eco_cfg( 'eco_api_base', 'https://packers.ecotrack.dz' ) ) ?></code></em></p>

        <form method="post" style="display:inline-block;margin-right:10px">
            <?php wp_nonce_field( 'eco_test_territories' ); ?>
            <input type="hidden" name="eco_test_territories" value="1">
            <button class="button button-primary">🔍 Tester connexion + afficher wilayas</button>
        </form>
        <form method="post" style="display:inline-block;margin-right:10px">
            <?php wp_nonce_field( 'kb_eco_run_poll' ); ?>
            <input type="hidden" name="kb_eco_run_poll" value="1">
            <button class="button">🔄 Lancer le poll livraisons maintenant</button>
        </form>
        <form method="post" style="display:inline-block">
            <?php wp_nonce_field( 'eco_clear_cache' ); ?>
            <input type="hidden" name="eco_clear_cache" value="1">
            <button class="button">🗑️ Vider le cache</button>
        </form>

        <?php if ( is_array( $last ) ) : ?>
        <p style="margin-top:14px"><strong>Dernier poll :</strong> <?= esc_html( $last['at'] ?? '' ) ?> —
            <?= (int) ( $last['parcels'] ?? 0 ) ?> colis, <?= (int) ( $last['delivered'] ?? 0 ) ?> livrés.</p>
        <?php endif; ?>

        <hr>
        <h2>Fonctionnement</h2>
        <ul style="list-style:disc;padding-left:20px">
            <li>Commande → <strong>completed</strong> : colis créé sur EcoTrack (référence <code><?= esc_html( kb_eco_cfg( 'eco_ref_prefix', '#' ) ) ?>N° commande</code>). Si un colis avec la même référence existe déjà, il est simplement lié.</li>
            <li>EcoTrack n'a pas de webhooks : le statut est lu toutes les 15 min. Colis livré → event <strong>OrderDelivered</strong> envoyé à Meta avec l'heure réelle de livraison.</li>
            <li>Pour recréer un colis : supprimer le meta <code>_kb_eco_tracking</code> de la commande, puis « Créer le colis » dans la metabox EcoTrack.</li>
        </ul>
    </div>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// DÉCLARATION MODULE
// ═══════════════════════════════════════════════════════════════════════════════

add_filter( 'kb_active_modules', function( $modules ) {
    $modules['ecotrack'] = [
        'label'    => '🚚 EcoTrack (Packers)',
        'features' => [
            'Création colis auto (completed → EcoTrack)',
            'Anti-doublon par référence',
            'Poll livraisons 15 min → OrderDelivered',
        ],
    ];
    return $modules;
} );
