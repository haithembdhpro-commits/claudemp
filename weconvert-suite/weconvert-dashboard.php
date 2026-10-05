<?php
/**
 * Module WeConvert.io Suite (chargé par weconvert-suite.php — ne pas activer seul) :
 *   WeConvert.io — COD Dashboard
 * Description: Dashboard opérationnel COD Algérie — taux de confirmation, taux de livraison,
 *              cash réel encaissé, entonnoir Purchase→Confirmed→Delivered, graphe 30j.
 *              Données tirées directement de la DB WooCommerce + order meta WeConvert.io.
 * Version:     1.0.0
 * Author:      WeConvert.io
 *
 * Dépendances : WooCommerce (requis), weconvert-settings.php (optionnel pour le taux DZD).
 * Compatible HPOS (wc_get_orders) + postmeta classique (wpdb fallback).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════════════════════
// MENU
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', 'kb_dash_menu' );

function kb_dash_menu() {
    add_menu_page(
        'WeConvert.io Dashboard',
        'WeConvert Dashboard',
        'manage_woocommerce',
        'weconvert-dashboard',
        'kb_dash_page',
        'dashicons-chart-area',
        56
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// AJAX ENDPOINT — données JSON pour le JS
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_kb_dash_data', 'kb_dash_ajax_data' );

function kb_dash_ajax_data() {
    check_ajax_referer( 'kb_dash_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized', 403 );

    $period    = sanitize_text_field( $_POST['period']    ?? '30' );
    $date_from = sanitize_text_field( $_POST['date_from'] ?? '' );
    $date_to   = sanitize_text_field( $_POST['date_to']   ?? '' );

    if ( $period === 'custom' && $date_from && $date_to ) {
        wp_send_json_success( kb_dash_compute_range( $date_from . ' 00:00:00', $date_to . ' 23:59:59' ) );
        return;
    }

    $valid = [ '0', '1', '2', '7', '30', '90' ];
    $days  = in_array( $period, $valid, true ) ? (int) $period : 30;
    wp_send_json_success( kb_dash_compute( $days ) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// COMPUTE — toutes les métriques
// ═══════════════════════════════════════════════════════════════════════════════

function kb_dash_compute( int $days ): array {
    $since = $days === 0
        ? date( 'Y-m-d 00:00:00' )
        : date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
    $now = date( 'Y-m-d H:i:s' );
    return kb_dash_compute_range( $since, $now, $days );
}

function kb_dash_compute_range( string $since, string $now, int $days = -1 ): array {
    global $wpdb;

    if ( $days < 0 ) {
        $diff = ( strtotime( $now ) - strtotime( $since ) ) / 86400;
        $days = max( 1, (int) ceil( $diff ) );
    }

    $rate = function_exists( 'kb_get' ) ? (float) kb_get( 'rate_dzd_eur', 285 ) : 285.0;

    // ── Détection HPOS ───────────────────────────────────────────────────────
    $hpos_table = $wpdb->prefix . 'wc_orders';
    $use_hpos   = ( $wpdb->get_var( "SHOW TABLES LIKE '{$hpos_table}'" ) === $hpos_table );

    // ── Récupération des orders dans la période ──────────────────────────────
    if ( $use_hpos ) {
        $orders_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, total_amount, currency, date_created_gmt
             FROM {$hpos_table}
             WHERE type = 'shop_order'
               AND status IN ('wc-completed','wc-processing','wc-on-hold')
               AND date_created_gmt >= %s AND date_created_gmt <= %s",
            $since, $now
        ) );
        $order_ids    = wp_list_pluck( $orders_raw, 'id' );
        $order_totals = [];
        foreach ( $orders_raw as $row ) {
            $order_totals[ $row->id ] = [
                'total'    => (float) $row->total_amount,
                'currency' => strtoupper( $row->currency ?: 'DZD' ),
                'date'     => substr( $row->date_created_gmt, 0, 10 ),
            ];
        }
    } else {
        $order_ids    = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'shop_order'
               AND post_status IN ('wc-completed','wc-processing','wc-on-hold')
               AND post_date >= %s AND post_date <= %s",
            $since, $now
        ) );
        $order_totals = [];
    }

    if ( empty( $order_ids ) ) return kb_dash_empty( $days );

    $ids_in = implode( ',', array_map( 'intval', $order_ids ) );

    // ── Meta lookup ──────────────────────────────────────────────────────────
    $meta_table = $use_hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
    $id_col     = $use_hpos ? 'order_id' : 'post_id';
    $meta_keys  = $use_hpos
        ? [ '_kb_capi_purchase_sent', '_kb_capi_confirmed_sent', '_kb_capi_delivered_sent', '_billing_phone', '_kb_eco_tracking', '_kb_delivery_tracking', '_kb_zr_tracking' ]
        : [ '_kb_capi_purchase_sent', '_kb_capi_confirmed_sent', '_kb_capi_delivered_sent', '_order_total', '_order_currency', '_billing_phone', '_kb_eco_tracking', '_kb_delivery_tracking', '_kb_zr_tracking' ];

    $keys_in  = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
    $raw_meta = $wpdb->get_results( $wpdb->prepare(
        "SELECT {$id_col} as order_id, meta_key, meta_value FROM {$meta_table}
         WHERE {$id_col} IN ({$ids_in}) AND meta_key IN ({$keys_in})",
        ...$meta_keys
    ) );
    $meta_map = [];
    foreach ( $raw_meta as $row ) {
        $meta_map[ $row->order_id ][ $row->meta_key ] = $row->meta_value;
    }

    // ── Accumulateurs ────────────────────────────────────────────────────────
    $total_orders  = count( $order_ids );
    $n_purchase    = 0; $n_confirmed   = 0; $n_delivered   = 0;
    $cash_dzd      = 0.0; $cash_eur    = 0.0;
    $purchase_dzd  = 0.0; $confirmed_dzd = 0.0; $delivered_dzd = 0.0;

    // ── Timeline ─────────────────────────────────────────────────────────────
    $timeline = [];
    $tl_days  = max( 1, $days );
    for ( $d = $tl_days - 1; $d >= 0; $d-- ) {
        $timeline[ date( 'Y-m-d', strtotime( "-{$d} days" ) ) ] = [ 'purchase' => 0, 'confirmed' => 0, 'delivered' => 0 ];
    }

    // ── Date map ─────────────────────────────────────────────────────────────
    $date_map = [];
    if ( $use_hpos ) {
        foreach ( $order_totals as $oid => $info ) { $date_map[ $oid ] = $info['date']; }
    } else {
        $dates = $wpdb->get_results( "SELECT ID as id, DATE(post_date) as d FROM {$wpdb->posts} WHERE ID IN ({$ids_in})" );
        foreach ( $dates as $row ) { $date_map[ $row->id ] = $row->d; }
    }

    // ── Boucle orders ─────────────────────────────────────────────────────────
    $recent = [];
    foreach ( $order_ids as $oid ) {
        $m = $meta_map[ $oid ] ?? [];
        if ( $use_hpos && isset( $order_totals[ $oid ] ) ) {
            $cur = $order_totals[ $oid ]['currency'];
            $tot = $order_totals[ $oid ]['total'];
        } else {
            $cur = strtoupper( $m['_order_currency'] ?? 'DZD' );
            $tot = (float) ( $m['_order_total'] ?? 0 );
        }
        $to_dzd = fn( $v ) => $cur === 'DZD' ? $v : round( $v * $rate, 2 );

        $has_purchase  = ! empty( $m['_kb_capi_purchase_sent'] );
        $has_confirmed = ! empty( $m['_kb_capi_confirmed_sent'] );
        $has_delivered = ! empty( $m['_kb_capi_delivered_sent'] );

        if ( $has_purchase )  { $n_purchase++;  $purchase_dzd  += $to_dzd( $tot ); }
        if ( $has_confirmed ) { $n_confirmed++; $confirmed_dzd += $to_dzd( $tot ); }
        if ( $has_delivered ) {
            $n_delivered++; $delivered_dzd += $to_dzd( $tot );
            if ( $cur === 'DZD' ) { $cash_dzd += $tot; $cash_eur += round( $tot / $rate, 2 ); }
            else                  { $cash_eur += $tot; $cash_dzd += round( $tot * $rate, 2 ); }
        }

        $day = $date_map[ $oid ] ?? null;
        if ( $day && isset( $timeline[ $day ] ) ) {
            if ( $has_purchase )  $timeline[ $day ]['purchase']++;
            if ( $has_confirmed ) $timeline[ $day ]['confirmed']++;
            if ( $has_delivered ) $timeline[ $day ]['delivered']++;
        }

        $phone     = $m['_billing_phone'] ?? '';
        $status    = $has_delivered ? 'delivered' : ( $has_confirmed ? 'confirmed' : ( $has_purchase ? 'purchase' : 'pending' ) );
        $tracking  = $m['_kb_eco_tracking'] ?? ( $m['_kb_delivery_tracking'] ?? ( $m['_kb_zr_tracking'] ?? '' ) );
        $order_obj = wc_get_order( $oid );
        $items_list = [];
        if ( $order_obj ) {
            foreach ( $order_obj->get_items() as $item ) {
                $items_list[] = [ 'name' => $item->get_name(), 'qty' => $item->get_quantity(), 'line' => round( $item->get_total(), 2 ) ];
            }
        }
        $recent[] = [
            'id'            => $oid,
            'status'        => $status,
            'total'         => $tot,
            'currency'      => $cur,
            'phone'         => $phone,
            'phone_masked'  => substr( $phone, 0, 4 ) . '***' . substr( $phone, -2 ),
            'tracking'      => $tracking,
            'date'          => $date_map[ $oid ] ?? '',
            'has_purchase'  => $has_purchase,
            'has_confirmed' => $has_confirmed,
            'has_delivered' => $has_delivered,
            'items'         => $items_list,
            'billing_name'  => $order_obj ? trim( $order_obj->get_billing_first_name() . ' ' . $order_obj->get_billing_last_name() ) : '',
            'billing_city'  => $order_obj ? $order_obj->get_billing_city() : '',
            'billing_state' => $order_obj ? $order_obj->get_billing_state() : '',
            'shipping_total'=> $order_obj ? round( (float) $order_obj->get_shipping_total(), 2 ) : 0,
            'wc_status'     => $order_obj ? $order_obj->get_status() : '',
        ];
    }

    $rate_conf = $n_purchase  > 0 ? min( 100, round( $n_confirmed / $n_purchase  * 100, 1 ) ) : 0;
    $rate_del  = $n_confirmed > 0 ? min( 100, round( $n_delivered / $n_confirmed * 100, 1 ) ) : 0;
    $rate_glob = $n_purchase  > 0 ? min( 100, round( $n_delivered / $n_purchase  * 100, 1 ) ) : 0;
    $delta_pct = $purchase_dzd > 0 ? round( ( $purchase_dzd - $cash_dzd ) / $purchase_dzd * 100, 1 ) : 0;

    usort( $recent, fn( $a, $b ) => $b['id'] - $a['id'] );

    return [
        'days'          => $days,
        'total_orders'  => $total_orders,
        'n_purchase'    => $n_purchase,
        'n_confirmed'   => $n_confirmed,
        'n_delivered'   => $n_delivered,
        'rate_conf'     => $rate_conf,
        'rate_del'      => $rate_del,
        'rate_glob'     => $rate_glob,
        'cash_dzd'      => round( $cash_dzd ),
        'cash_eur'      => round( $cash_eur, 2 ),
        'purchase_dzd'  => round( $purchase_dzd ),
        'confirmed_dzd' => round( $confirmed_dzd ),
        'delivered_dzd' => round( $delivered_dzd ),
        'delta_pct'     => $delta_pct,
        'rate_dzd_eur'  => $rate,
        'timeline'      => [
            'labels'    => array_keys( $timeline ),
            'purchase'  => array_column( array_values( $timeline ), 'purchase' ),
            'confirmed' => array_column( array_values( $timeline ), 'confirmed' ),
            'delivered' => array_column( array_values( $timeline ), 'delivered' ),
        ],
        'recent' => $recent,
    ];
}

function kb_dash_empty( int $days ): array {
    return [
        'days' => $days, 'total_orders' => 0, 'n_purchase' => 0,
        'n_confirmed' => 0, 'n_delivered' => 0, 'rate_conf' => 0,
        'rate_del' => 0, 'rate_glob' => 0, 'cash_dzd' => 0,
        'cash_eur' => 0, 'purchase_dzd' => 0, 'delta_pct' => 0,
        'rate_dzd_eur' => 285, 'timeline' => [ 'labels' => [], 'purchase' => [], 'confirmed' => [], 'delivered' => [] ],
        'recent' => [],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════════
// PAGE HTML
// ═══════════════════════════════════════════════════════════════════════════════

function kb_dash_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    $nonce    = wp_create_nonce( 'kb_dash_nonce' );
    $ajax_url = admin_url( 'admin-ajax.php' );
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root {
    --bg:        #0D1117;
    --bg2:       #161B22;
    --bg3:       #1C2128;
    --border:    #30363D;
    --amber:     #F5A623;
    --amber-dim: #7A5312;
    --green:     #1D9E75;
    --green-dim: #0D4D38;
    --red:       #E24B4A;
    --red-dim:   #5C1F1E;
    --blue:      #378ADD;
    --blue-dim:  #153659;
    --text:      #E6EDF3;
    --muted:     #7D8590;
    --muted2:    #484F58;
    --mono:      'IBM Plex Mono', monospace;
    --sans:      'IBM Plex Sans', sans-serif;
    --radius:    8px;
}
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); color: var(--text); font-family: var(--sans); font-size: 14px; min-height: 100vh; }

/* ── LAYOUT ─────────────────────────────── */
.kb-wrap { max-width: 1280px; margin: 0 auto; padding: 28px 24px; }

/* ── HEADER ─────────────────────────────── */
.kb-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; flex-wrap: wrap; gap: 16px; }
.kb-logo { display: flex; align-items: center; gap: 12px; }
.kb-logo-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--amber); box-shadow: 0 0 12px var(--amber); animation: pulse 2s infinite; }
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: .5; } }
.kb-logo h1 { font-family: var(--mono); font-size: 16px; font-weight: 600; color: var(--text); letter-spacing: .04em; }
.kb-logo span { font-size: 11px; color: var(--muted); margin-left: 8px; text-transform: uppercase; letter-spacing: .08em; }
.kb-tabs { display: flex; gap: 4px; background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); padding: 4px; }
.kb-tab { font-family: var(--mono); font-size: 12px; font-weight: 500; padding: 6px 16px; border-radius: 6px; cursor: pointer; border: none; background: transparent; color: var(--muted); transition: all .15s; }
.kb-tab.active { background: var(--bg3); color: var(--amber); border: 1px solid var(--amber-dim); }
.kb-tab:hover:not(.active) { color: var(--text); }

/* ── FUNNEL ─────────────────────────────── */
.kb-funnel { display: grid; grid-template-columns: 1fr auto 1fr auto 1fr; gap: 0; margin-bottom: 24px; align-items: center; }
.kb-funnel-card { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px 24px; position: relative; overflow: hidden; }
.kb-funnel-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; border-radius: var(--radius) var(--radius) 0 0; }
.kb-funnel-card.purchase::before { background: var(--blue); }
.kb-funnel-card.confirmed::before { background: var(--amber); }
.kb-funnel-card.delivered::before { background: var(--green); }
.kb-funnel-label { font-family: var(--mono); font-size: 10px; font-weight: 500; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); margin-bottom: 8px; }
.kb-funnel-num { font-family: var(--mono); font-size: 36px; font-weight: 600; line-height: 1; margin-bottom: 6px; }
.kb-funnel-card.purchase .kb-funnel-num { color: var(--blue); }
.kb-funnel-card.confirmed .kb-funnel-num { color: var(--amber); }
.kb-funnel-card.delivered .kb-funnel-num { color: var(--green); }
.kb-funnel-sub { font-size: 12px; color: var(--muted); }
.kb-funnel-arrow { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 0 12px; gap: 4px; }
.kb-arrow-pct { font-family: var(--mono); font-size: 16px; font-weight: 600; }
.kb-arrow-pct.good { color: var(--green); }
.kb-arrow-pct.warn { color: var(--amber); }
.kb-arrow-pct.bad  { color: var(--red); }
.kb-arrow-line { width: 1px; height: 24px; background: var(--border); }
.kb-arrow-icon { color: var(--muted2); font-size: 16px; }

/* ── KPI GRID ───────────────────────────── */
.kb-kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
.kb-kpi { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; }
.kb-kpi-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 10px; }
.kb-kpi-value { font-family: var(--mono); font-size: 26px; font-weight: 600; color: var(--text); line-height: 1; margin-bottom: 4px; }
.kb-kpi-sub { font-size: 11px; color: var(--muted); }
.kb-kpi-bar { height: 3px; background: var(--border); border-radius: 2px; margin-top: 12px; overflow: hidden; }
.kb-kpi-bar-fill { height: 100%; border-radius: 2px; transition: width .6s cubic-bezier(.4,0,.2,1); }

/* ── CHART ──────────────────────────────── */
.kb-grid-2 { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-bottom: 24px; }
.kb-panel { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px 24px; }
.kb-panel-title { font-family: var(--mono); font-size: 12px; font-weight: 500; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; }
.kb-chart-wrap { position: relative; height: 220px; }
.kb-legend { display: flex; flex-direction: column; gap: 12px; }
.kb-legend-item { display: flex; align-items: center; gap: 10px; }
.kb-legend-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.kb-legend-label { font-size: 12px; color: var(--muted); flex: 1; }
.kb-legend-val { font-family: var(--mono); font-size: 14px; font-weight: 600; color: var(--text); }

/* ── TABLE ──────────────────────────────── */
.kb-table-wrap { overflow-x: auto; }
table.kb-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.kb-table thead tr { border-bottom: 1px solid var(--border); }
.kb-table th { font-family: var(--mono); font-size: 10px; font-weight: 500; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); padding: 10px 14px; text-align: left; }
.kb-table td { padding: 11px 14px; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
.kb-table tr:last-child td { border-bottom: none; }
.kb-table tbody tr:hover td { background: var(--bg3); }
.kb-table td:first-child { font-family: var(--mono); font-weight: 500; }
.kb-badge { display: inline-flex; align-items: center; gap: 5px; font-family: var(--mono); font-size: 11px; font-weight: 500; padding: 3px 9px; border-radius: 20px; }
.kb-badge.purchase  { background: var(--blue-dim);  color: #85B7EB; border: 1px solid var(--blue-dim); }
.kb-badge.confirmed { background: var(--amber-dim); color: #F5A623; border: 1px solid var(--amber-dim); }
.kb-badge.delivered { background: var(--green-dim); color: #5DCAA5; border: 1px solid var(--green-dim); }
.kb-badge.pending   { background: var(--bg3);       color: var(--muted); border: 1px solid var(--border); }
.kb-badge-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
.kb-mono { font-family: var(--mono); font-size: 12px; color: var(--muted); }
.kb-tracking { font-family: var(--mono); font-size: 11px; color: var(--blue); }

/* ── EMPTY ──────────────────────────────── */
.kb-empty { text-align: center; padding: 60px 0; color: var(--muted); }
.kb-empty-icon { font-size: 48px; margin-bottom: 12px; opacity: .3; }
.kb-empty p { font-size: 13px; }

/* ── LOADER ─────────────────────────────── */
.kb-loader { display: flex; align-items: center; justify-content: center; padding: 80px; }
.kb-spinner { width: 28px; height: 28px; border: 2px solid var(--border); border-top-color: var(--amber); border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── DELTA BOX ──────────────────────────── */
.kb-delta { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; }
.kb-delta-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--border); }
.kb-delta-row:last-child { border-bottom: none; }
.kb-delta-label { font-size: 12px; color: var(--muted); }
.kb-delta-val { font-family: var(--mono); font-size: 14px; font-weight: 600; }

/* ── MODAL ──────────────────────────────── */
.kb-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.7); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px; }
.kb-modal-overlay.hidden { display: none; }
.kb-modal { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); width: 100%; max-width: 640px; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 64px rgba(0,0,0,.6); }
.kb-modal-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid var(--border); }
.kb-modal-title { font-family: var(--mono); font-size: 14px; font-weight: 600; color: var(--text); }
.kb-modal-close { background: none; border: none; color: var(--muted); cursor: pointer; font-size: 20px; line-height: 1; padding: 4px; transition: color .15s; }
.kb-modal-close:hover { color: var(--text); }
.kb-modal-body { padding: 24px; }
.kb-modal-section { margin-bottom: 20px; }
.kb-modal-section-title { font-family: var(--mono); font-size: 10px; font-weight: 500; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); margin-bottom: 12px; }
.kb-modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.kb-modal-field { display: flex; flex-direction: column; gap: 4px; }
.kb-modal-field-label { font-size: 11px; color: var(--muted); }
.kb-modal-field-value { font-family: var(--mono); font-size: 13px; color: var(--text); font-weight: 500; }
.kb-modal-items { width: 100%; border-collapse: collapse; font-size: 12px; }
.kb-modal-items th { font-family: var(--mono); font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; padding: 6px 10px; text-align: left; border-bottom: 1px solid var(--border); }
.kb-modal-items td { padding: 8px 10px; border-bottom: 1px solid var(--border); color: var(--text); }
.kb-modal-items tr:last-child td { border-bottom: none; }
.kb-modal-stages { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.kb-stage { display: flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 20px; font-family: var(--mono); font-size: 11px; font-weight: 500; }
.kb-stage.done   { background: var(--green-dim); color: #5DCAA5; border: 1px solid var(--green-dim); }
.kb-stage.undone { background: var(--bg3); color: var(--muted); border: 1px solid var(--border); }
.kb-modal-link { font-family: var(--mono); font-size: 11px; color: var(--blue); text-decoration: none; }
.kb-modal-link:hover { text-decoration: underline; }

/* ── CHART FILTERS ──────────────────────── */
.kb-chart-filters { display: flex; gap: 6px; }
.kb-chart-filter { font-family: var(--mono); font-size: 11px; font-weight: 500; padding: 4px 12px; border-radius: 20px; border: 1px solid var(--border); background: transparent; color: var(--muted); cursor: pointer; transition: all .15s; }
.kb-chart-filter.active-purchase  { border-color: #378ADD; color: #378ADD; background: rgba(55,138,221,.1); }
.kb-chart-filter.active-confirmed { border-color: #F5A623; color: #F5A623; background: rgba(245,166,35,.1); }
.kb-chart-filter.active-delivered { border-color: #1D9E75; color: #1D9E75; background: rgba(29,158,117,.1); }
.kb-chart-filter:hover:not([class*='active']) { color: var(--text); border-color: var(--muted); }

/* ── TABLE ROW CLICKABLE ────────────────── */
.kb-table tbody tr { cursor: pointer; }

/* ── RESPONSIVE ─────────────────────────── */
@media (max-width: 900px) {
    .kb-funnel { grid-template-columns: 1fr; }
    .kb-funnel-arrow { flex-direction: row; padding: 4px 0; }
    .kb-kpis { grid-template-columns: 1fr 1fr; }
    .kb-grid-2 { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
    .kb-kpis { grid-template-columns: 1fr; }
}

/* Surcharge WP Admin */
#wpcontent { background: var(--bg); }
#wpbody-content { background: var(--bg); }
.wrap { background: var(--bg); }
</style>
</head>
<body>
<div class="kb-wrap">

    <!-- HEADER -->
    <div class="kb-header">
        <div class="kb-logo">
            <div class="kb-logo-dot"></div>
            <h1>WeConvert.io <span>COD Dashboard</span></h1>
        </div>
        <div class="kb-tabs" role="tablist">
            <button class="kb-tab" role="tab" data-days="1">Aujourd'hui</button>
            <button class="kb-tab" role="tab" data-days="7">7 jours</button>
            <button class="kb-tab active" role="tab" data-days="30">30 jours</button>
        </div>
    </div>

    <!-- CONTENT -->
    <div id="kb-content">
        <div class="kb-loader"><div class="kb-spinner"></div></div>
    </div>

    <!-- MODAL COMMANDE -->
    <div class="kb-modal-overlay hidden" id="kb-modal-overlay" onclick="kbModalClose(event)">
        <div class="kb-modal" id="kb-modal">
            <div class="kb-modal-header">
                <span class="kb-modal-title" id="kb-modal-title">Commande #—</span>
                <button class="kb-modal-close" onclick="kbCloseModal()">✕</button>
            </div>
            <div class="kb-modal-body" id="kb-modal-body"></div>
        </div>
    </div>

</div>

<script>
(function() {
    var AJAX_URL    = '<?php echo esc_js( $ajax_url ); ?>';
    var NONCE       = '<?php echo esc_js( $nonce ); ?>';
    var chart       = null;
    var activeDays  = 30;
    var activeCustom = null;
    var recentData  = [];
    var activeFilters = { 0: true, 1: true, 2: true };
    var filterClasses = ['active-purchase', 'active-confirmed', 'active-delivered'];

    // ── Modal ─────────────────────────────────────────────────────────────
    window.kbOpenModal = function(orderId) {
        var row = recentData.find(function(r) { return r.id == orderId; });
        if (!row) return;
        document.getElementById('kb-modal-title').textContent = 'Commande #' + row.id;
        var adminUrl = '<?php echo esc_js( admin_url("admin.php?page=wc-orders&action=edit&id=") ); ?>';
        var body = '';
        body += '<div class="kb-modal-section"><div class="kb-modal-section-title">Étapes CAPI</div><div class="kb-modal-stages">';
        body += stage('Purchase',  row.has_purchase);
        body += stage('Confirmed', row.has_confirmed);
        body += stage('Delivered', row.has_delivered);
        body += '</div></div>';
        body += '<div class="kb-modal-section"><div class="kb-modal-section-title">Informations client</div><div class="kb-modal-grid">';
        body += field('Nom', row.billing_name || '—');
        body += field('Téléphone', row.phone || '—');
        body += field('Ville', row.billing_city || '—');
        body += field('Wilaya', row.billing_state || '—');
        body += '</div></div>';
        body += '<div class="kb-modal-section"><div class="kb-modal-section-title">Détails commande</div><div class="kb-modal-grid">';
        body += field('Statut WC', row.wc_status || '—');
        body += field('Statut KB', row.status);
        body += field('Total', fmtNum(row.total) + ' ' + row.currency);
        body += field('Livraison', fmtNum(row.shipping_total) + ' ' + row.currency);
        body += field('Tracking', row.tracking || '—');
        body += field('Date', row.date || '—');
        body += '</div></div>';
        if (row.items && row.items.length > 0) {
            body += '<div class="kb-modal-section"><div class="kb-modal-section-title">Produits</div>';
            body += '<table class="kb-modal-items"><thead><tr><th>Produit</th><th>Qté</th><th>Total</th></tr></thead><tbody>';
            row.items.forEach(function(item) {
                body += '<tr><td>' + escHtml(item.name) + '</td><td>' + item.qty + '</td><td>' + fmtNum(item.line) + ' ' + row.currency + '</td></tr>';
            });
            body += '</tbody></table></div>';
        }
        body += '<div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">';
        body += '<a href="' + adminUrl + row.id + '" target="_blank" class="kb-modal-link">→ Ouvrir dans WooCommerce</a>';
        body += '</div>';
        document.getElementById('kb-modal-body').innerHTML = body;
        document.getElementById('kb-modal-overlay').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    };
    window.kbCloseModal = function() {
        document.getElementById('kb-modal-overlay').classList.add('hidden');
        document.body.style.overflow = '';
    };
    window.kbModalClose = function(e) {
        if (e.target === document.getElementById('kb-modal-overlay')) kbCloseModal();
    };
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') kbCloseModal(); });
    function stage(label, done) {
        return '<div class="kb-stage ' + (done ? 'done' : 'undone') + '">' + (done ? '✓' : '○') + ' ' + label + '</div>';
    }
    function field(label, value) {
        return '<div class="kb-modal-field"><span class="kb-modal-field-label">' + label + '</span><span class="kb-modal-field-value">' + escHtml(String(value)) + '</span></div>';
    }

    // ── Chart filters ─────────────────────────────────────────────────────
    window.kbToggleDataset = function(idx, btn) {
        if (!chart) return;
        activeFilters[idx] = !activeFilters[idx];
        chart.data.datasets[idx].hidden = !activeFilters[idx];
        chart.update();
        if (activeFilters[idx]) { btn.classList.add(filterClasses[idx]); }
        else { btn.classList.remove(filterClasses[idx]); }
    };

    // ── Onglets période (Aujourd'hui / 7 / 30 jours) ─────────────────────
    // L'ancien sélecteur de dates n'existe plus dans le HTML : l'appeler plantait tout le script
    // avant load() → spinner infini.
    document.querySelectorAll('.kb-tab[data-days]').forEach(function(tab) {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.kb-tab[data-days]').forEach(function(t) {
                t.classList.remove('active');
                t.setAttribute('aria-selected', 'false');
            });
            tab.classList.add('active');
            tab.setAttribute('aria-selected', 'true');
            activeDays = tab.getAttribute('data-days');
            load(activeDays);
        });
    });

    // ── Load data ─────────────────────────────────────────────────────────
    var loadSeq = 0;
    function showError(msg) {
        document.getElementById('kb-content').innerHTML = '<div class="kb-empty"><div class="kb-empty-icon">⚠️</div><p>' + escHtml(msg) + '</p></div>';
    }

    function load(days, dateFrom, dateTo) {
        var seq = ++loadSeq; // un clic rapide sur un autre onglet annule l'affichage de l'ancien résultat
        document.getElementById('kb-content').innerHTML = '<div class="kb-loader"><div class="kb-spinner"></div></div>';
        if (chart) { chart.destroy(); chart = null; }

        var fd = new FormData();
        fd.append('action', 'kb_dash_data');
        fd.append('nonce',  NONCE);
        fd.append('period', days);
        if (dateFrom && dateTo) { fd.append('date_from', dateFrom); fd.append('date_to', dateTo); }

        var ctrl  = window.AbortController ? new AbortController() : null;
        var timer = setTimeout(function() { if (ctrl) ctrl.abort(); }, 60000);

        fetch(AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
            .then(function(r) {
                return r.text().then(function(txt) {
                    try { return JSON.parse(txt); }
                    catch (e) { throw new Error('Réponse serveur invalide (HTTP ' + r.status + ') : ' + txt.replace(/<[^>]+>/g, ' ').trim().slice(0, 200)); }
                });
            })
            .then(function(res) {
                if (seq !== loadSeq) return;
                if (res === -1 || res === 0) { showError('Session expirée — recharge la page.'); return; }
                if (!res || !res.success) { showError('Erreur lors du chargement' + (res && res.data ? ' : ' + res.data : '.')); return; }
                try { render(res.data); }
                catch (e) { console.error('[WeConvert.io Dashboard]', e); showError('Erreur d\'affichage : ' + e.message); }
            })
            .catch(function(e) {
                if (seq !== loadSeq) return;
                console.error('[WeConvert.io Dashboard]', e);
                showError(e && e.name === 'AbortError' ? 'Le serveur met trop de temps à répondre (> 60 s).' : (e.message || 'Connexion impossible.'));
            })
            .finally(function() { clearTimeout(timer); });
    }

    // ── Render ────────────────────────────────────────────────────────────
    function render(d) {
        recentData = d.recent || [];
        var confColor = d.rate_conf >= 70 ? 'good' : (d.rate_conf >= 50 ? 'warn' : 'bad');
        var delColor  = d.rate_del  >= 80 ? 'good' : (d.rate_del  >= 60 ? 'warn' : 'bad');
        var globColor = d.rate_glob >= 60 ? 'good' : (d.rate_glob >= 40 ? 'warn' : 'bad');

        var periodLabel = d.days === 1 ? "Aujourd'hui" : ('Les ' + d.days + ' derniers jours');

        var html = '';

        // ── Funnel ───────────────────────────────────────────────────────
        html += '<div class="kb-funnel">';
        html += funnelCard('purchase',  'Purchase CAPI',   d.n_purchase,  'Checkout soumis',          d.purchase_dzd);
        html += '<div class="kb-funnel-arrow">'
              + '<div class="kb-arrow-line"></div>'
              + '<span class="kb-arrow-pct ' + confColor + '">' + d.rate_conf + '%</span>'
              + '<div class="kb-arrow-line"></div>'
              + '<div class="kb-arrow-icon">→</div>'
              + '</div>';
        html += funnelCard('confirmed', 'OrderConfirmed',  d.n_confirmed, 'Validés par call center',   d.confirmed_dzd);
        html += '<div class="kb-funnel-arrow">'
              + '<div class="kb-arrow-line"></div>'
              + '<span class="kb-arrow-pct ' + delColor + '">' + d.rate_del + '%</span>'
              + '<div class="kb-arrow-line"></div>'
              + '<div class="kb-arrow-icon">→</div>'
              + '</div>';
        html += funnelCard('delivered', 'OrderDelivered',  d.n_delivered, 'Cash encaissé',             d.delivered_dzd);
        html += '</div>';

        // ── KPIs ─────────────────────────────────────────────────────────
        html += '<div class="kb-kpis">';
        html += kpi('Taux confirmation',   d.rate_conf  + '%', 'Purchase → Confirmed', d.rate_conf,  confColor, 100);
        html += kpi('Taux livraison',      d.rate_del   + '%', 'Confirmed → Delivered', d.rate_del,  delColor,  100);
        html += kpi('Taux global COD',     d.rate_glob  + '%', 'Purchase → Cash encaissé', d.rate_glob, globColor, 100);
        html += kpi('Pertes estimées',     d.delta_pct  + '%', 'Purchase non converti en cash',  d.delta_pct, 'bad', 100);
        html += '</div>';

        // ── Chart + Delta ─────────────────────────────────────────────────
        html += '<div class="kb-grid-2">';

        // Chart
        html += '<div class="kb-panel">';
        html += '<div class="kb-panel-title">'
              + '<span>Évolution ' + d.days + 'j</span>'
              + '<div class="kb-chart-filters">'
              + '<button class="kb-chart-filter active-purchase"  onclick="kbToggleDataset(0,this)" data-idx="0">Purchase</button>'
              + '<button class="kb-chart-filter active-confirmed" onclick="kbToggleDataset(1,this)" data-idx="1">Confirmed</button>'
              + '<button class="kb-chart-filter active-delivered" onclick="kbToggleDataset(2,this)" data-idx="2">Delivered</button>'
              + '</div>'
              + '</div>';
        html += '<div class="kb-chart-wrap"><canvas id="kb-chart"></canvas></div>';
        html += '</div>';

        // Cash encaissé réel
        html += '<div class="kb-panel">';
        html += '<div class="kb-panel-title">Cash encaissé réel</div>';
        html += '<div style="margin-bottom:24px">';
        html += '<div style="font-size:11px;color:var(--muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.08em">DZD réel (taux ' + d.rate_dzd_eur + ')</div>';
        html += '<div style="font-family:var(--mono);font-size:32px;font-weight:600;color:var(--green)">' + fmtNum(d.cash_dzd) + ' <span style="font-size:16px">DZD</span></div>';
        html += '</div>';
        html += '<div style="margin-bottom:24px">';
        html += '<div style="font-size:11px;color:var(--muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.08em">EUR converti</div>';
        html += '<div style="font-family:var(--mono);font-size:28px;font-weight:600;color:var(--amber)">' + fmtNum(d.cash_eur) + ' <span style="font-size:14px">EUR</span></div>';
        html += '</div>';
        html += '<div class="kb-delta">';
        html += '<div class="kb-delta-row"><span class="kb-delta-label">Purchase total DZD</span><span class="kb-delta-val" style="color:var(--blue)">' + fmtNum(d.purchase_dzd) + '</span></div>';
        html += '<div class="kb-delta-row"><span class="kb-delta-label">Cash encaissé DZD</span><span class="kb-delta-val" style="color:var(--green)">' + fmtNum(d.cash_dzd) + '</span></div>';
        html += '<div class="kb-delta-row"><span class="kb-delta-label">Pertes COD</span><span class="kb-delta-val" style="color:var(--red)">-' + fmtNum(d.purchase_dzd - d.cash_dzd) + ' DZD</span></div>';
        html += '</div>';
        html += '<div class="kb-legend" style="margin-top:20px">';
        html += legendItem('#378ADD', 'Purchase CAPI', d.n_purchase);
        html += legendItem('#F5A623', 'OrderConfirmed', d.n_confirmed);
        html += legendItem('#1D9E75', 'OrderDelivered', d.n_delivered);
        html += '</div>';
        html += '</div>';
        html += '</div>';

        // ── Tableau récents ───────────────────────────────────────────────
        html += '<div class="kb-panel">';
        html += '<div class="kb-panel-title">Commandes récentes</div>';
        if (d.recent.length === 0) {
            html += '<div class="kb-empty"><div class="kb-empty-icon">📦</div><p>Aucune commande dans cette période.</p></div>';
        } else {
            html += '<div class="kb-table-wrap"><table class="kb-table">';
            html += '<thead><tr><th>#</th><th>Statut</th><th>Montant</th><th>Téléphone</th><th>Tracking</th><th>Date</th></tr></thead>';
            html += '<tbody>';
            d.recent.forEach(function(row) {
                html += '<tr onclick="kbOpenModal(' + row.id + ')" style="cursor:pointer">';
                html += '<td>#' + row.id + '</td>';
                html += '<td>' + badge(row.status) + '</td>';
                html += '<td><span style="font-family:var(--mono);font-size:13px">' + fmtNum(row.total) + ' ' + row.currency + '</span></td>';
                html += '<td class="kb-mono">' + escHtml(row.phone_masked) + '</td>';
                html += '<td>' + (row.tracking ? '<span class="kb-tracking">' + escHtml(row.tracking) + '</span>' : '<span class="kb-mono">—</span>') + '</td>';
                html += '<td class="kb-mono">' + escHtml(row.date) + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
        }
        html += '</div>';

        document.getElementById('kb-content').innerHTML = html;

        // ── Chart.js ─────────────────────────────────────────────────────
        var ctx = document.getElementById('kb-chart');
        if (!ctx) return;
        if (chart) chart.destroy();

        chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: d.timeline.labels,
                datasets: [
                    {
                        label: 'Purchase',
                        data: d.timeline.purchase,
                        borderColor: '#378ADD',
                        backgroundColor: 'rgba(55,138,221,.08)',
                        borderWidth: 2,
                        tension: .4,
                        fill: true,
                        pointRadius: 3,
                        pointBackgroundColor: '#378ADD',
                    },
                    {
                        label: 'Confirmed',
                        data: d.timeline.confirmed,
                        borderColor: '#F5A623',
                        backgroundColor: 'rgba(245,166,35,.06)',
                        borderWidth: 2,
                        tension: .4,
                        fill: true,
                        pointRadius: 3,
                        pointBackgroundColor: '#F5A623',
                    },
                    {
                        label: 'Delivered',
                        data: d.timeline.delivered,
                        borderColor: '#1D9E75',
                        backgroundColor: 'rgba(29,158,117,.08)',
                        borderWidth: 2,
                        tension: .4,
                        fill: true,
                        pointRadius: 3,
                        pointBackgroundColor: '#1D9E75',
                    },
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1C2128',
                        borderColor: '#30363D',
                        borderWidth: 1,
                        titleColor: '#E6EDF3',
                        bodyColor: '#7D8590',
                        titleFont: { family: "'IBM Plex Mono', monospace", size: 11 },
                        bodyFont:  { family: "'IBM Plex Mono', monospace", size: 11 },
                        padding: 10,
                    },
                },
                scales: {
                    x: {
                        ticks: { color: '#7D8590', font: { family: "'IBM Plex Mono', monospace", size: 10 }, maxTicksLimit: 8 },
                        grid:  { color: '#1C2128' },
                        border:{ color: '#30363D' },
                    },
                    y: {
                        ticks: { color: '#7D8590', font: { family: "'IBM Plex Mono', monospace", size: 10 }, stepSize: 1 },
                        grid:  { color: '#1C2128' },
                        border:{ color: '#30363D' },
                        beginAtZero: true,
                    },
                },
            }
        });
    }

    // ── Helpers HTML ──────────────────────────────────────────────────────
    function funnelCard(cls, label, num, sub, dzd) {
        return '<div class="kb-funnel-card ' + cls + '">'
             + '<div class="kb-funnel-label">' + label + '</div>'
             + '<div class="kb-funnel-num">' + num + '</div>'
             + '<div class="kb-funnel-sub">' + sub + '</div>'
             + (dzd > 0 ? '<div style="font-family:var(--mono);font-size:13px;color:var(--muted);margin-top:8px">' + fmtNum(dzd) + ' DZD</div>' : '')
             + '</div>';
    }

    function kpi(label, value, sub, pct, color, max) {
        var barColor = color === 'good' ? 'var(--green)' : (color === 'warn' ? 'var(--amber)' : 'var(--red)');
        var fillPct  = Math.min(100, Math.abs(pct)) + '%';
        return '<div class="kb-kpi">'
             + '<div class="kb-kpi-label">' + label + '</div>'
             + '<div class="kb-kpi-value">' + value + '</div>'
             + '<div class="kb-kpi-sub">' + sub + '</div>'
             + '<div class="kb-kpi-bar"><div class="kb-kpi-bar-fill" style="width:' + fillPct + ';background:' + barColor + '"></div></div>'
             + '</div>';
    }

    function legendItem(color, label, val) {
        return '<div class="kb-legend-item">'
             + '<div class="kb-legend-dot" style="background:' + color + '"></div>'
             + '<span class="kb-legend-label">' + label + '</span>'
             + '<span class="kb-legend-val">' + val + '</span>'
             + '</div>';
    }

    function badge(status) {
        var labels = { purchase: 'Purchase', confirmed: 'Confirmed', delivered: 'Delivered', pending: 'En attente' };
        var dots   = { purchase: '●', confirmed: '●', delivered: '●', pending: '○' };
        return '<span class="kb-badge ' + status + '"><span class="kb-badge-dot"></span>' + (labels[status] || status) + '</span>';
    }

    function fmtNum(n) { return Math.round(n).toLocaleString('fr-DZ'); }
    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = String(s || '');
        return d.innerHTML;
    }

    // ── Init ──────────────────────────────────────────────────────────────
    var initialTab = document.querySelector('.kb-tab.active[data-days]');
    load(initialTab ? initialTab.getAttribute('data-days') : activeDays);
})();
</script>
</body>
</html>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// DÉCLARATION MODULE
// ═══════════════════════════════════════════════════════════════════════════════

add_filter( 'kb_active_modules', function( $modules ) {
    $modules['dashboard'] = [
        'label'    => '📊 COD Dashboard',
        'features' => [
            'Entonnoir Purchase→Confirmed→Delivered',
            'Cash DZD réel encaissé (≠ Purchase)',
            'Graphe évolution 30j',
            'Tableau commandes récentes',
        ],
    ];
    return $modules;
} );
