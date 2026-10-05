<?php
/**
 * Module WeConvert.io Suite (chargé par weconvert-suite.php — ne pas activer seul) :
 *   WeConvert.io — Settings
 * Description: Dashboard de configuration centralisé — Meta CAPI multi-pixels,
 *              taux de change DZD, sociétés de livraison COD DZ.
 *              Onglets navigables, tests de connexion live, statuts visuels.
 * Version:     3.0.0
 * Author:      WeConvert.io
 *
 * v3.0.0 — Refonte complète S-TIER :
 *   - Navigation par onglets (Meta / Livraison / Taux DZD / Avancé)
 *   - Multi-pixels N paires indépendantes (label + pixel_id + token + actif + test individuel)
 *   - Boutons test connexion live : Meta CAPI, EcoTrack (Packers), Maystro, Procolis, Guepex
 *   - v3.1 : ZR Express remplacé par EcoTrack (packers.ecotrack.dz)
 *   - Statuts visuels ✅/⚠️/❌ par section
 *   - Indicateur EMQ live après test Meta
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS GLOBAUX
// ═══════════════════════════════════════════════════════════════════════════════

function kb_get( string $key, $default = '' ) {
    // Pixel principal : les modules (pixel, fingerprint, events…) lisent meta_pixel_id / meta_access_token,
    // mais Settings v3 enregistre les pixels dans la liste meta_pixels → on résout depuis le 1er pixel actif.
    if ( $key === 'meta_pixel_id' || $key === 'meta_access_token' ) {
        $pixels = json_decode( (string) get_option( 'kb_meta_pixels', '[]' ), true ) ?: [];
        foreach ( $pixels as $px ) {
            if ( empty( $px['active'] ) || empty( $px['pixel_id'] ) || empty( $px['access_token'] ) ) continue;
            return $key === 'meta_pixel_id' ? (string) $px['pixel_id'] : (string) $px['access_token'];
        }
    }
    return get_option( 'kb_' . $key, $default );
}

function kb_convert_dzd_eur( float $amount ): float {
    $rate = (float) kb_get( 'rate_dzd_eur', 285 );
    return $rate > 0 ? round( $amount / $rate, 2 ) : round( $amount / 285, 2 );
}

function kb_convert_dzd_usd( float $amount ): float {
    $rate = (float) kb_get( 'rate_dzd_usd', 345 );
    return $rate > 0 ? round( $amount / $rate, 2 ) : round( $amount / 345, 2 );
}

function kb_send_currency( string $currency ): string {
    return strtoupper( $currency ) === 'DZD' ? 'EUR' : strtoupper( $currency );
}

function kb_convert_amount( float $amount, string $currency ): float {
    if ( strtoupper( $currency ) === 'DZD' ) return kb_convert_dzd_eur( $amount );
    return $amount;
}

// ═══════════════════════════════════════════════════════════════════════════════
// MENU
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', 'kb_settings_menu' );

function kb_settings_menu() {
    add_menu_page(
        'WeConvert.io', 'WeConvert.io', 'manage_options',
        'weconvert-settings', 'kb_settings_page',
        'dashicons-chart-line', 58
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// OPTIONS
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_init', 'kb_register_settings' );

function kb_register_settings() {
    $keys = [
        'meta_pixels',           // JSON — N paires {label,pixel_id,access_token,active}
        'meta_api_version',
        'meta_test_code',
        'rate_dzd_eur',
        'rate_dzd_usd',
        'eco_api_base', 'eco_api_token', 'eco_lookback_days',
        'eco_auto_create', 'eco_auto_ship', 'eco_ask_collection', 'eco_ref_prefix', 'eco_desk_keywords',
        'del_autocomplete',
        'maystro_api_key', 'maystro_store_id', 'maystro_enabled',
        'procolis_api_key', 'procolis_enabled',
        'guepex_api_key', 'guepex_enabled',
        'default_delivery_provider',
    ];
    foreach ( $keys as $key ) {
        register_setting( 'weconvert_settings_group', 'kb_' . $key, [
            'sanitize_callback' => 'sanitize_text_field',
        ] );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// AJAX — Test connexion Meta CAPI
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_kb_test_meta', 'kb_ajax_test_meta' );

function kb_ajax_test_meta() {
    check_ajax_referer( 'kb_settings_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

    $pixel_id = sanitize_text_field( $_POST['pixel_id'] ?? '' );
    $token    = sanitize_text_field( $_POST['token']    ?? '' );
    $api_ver  = sanitize_text_field( $_POST['api_ver']  ?? 'v19.0' );
    $test_code= sanitize_text_field( $_POST['test_code']?? '' );

    if ( ! $pixel_id || ! $token ) {
        wp_send_json_error( [ 'message' => 'Pixel ID et Access Token requis.' ] );
    }

    $event_id = 'kb_test_' . substr( md5( $pixel_id . time() ), 0, 12 );
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $ua       = $_SERVER['HTTP_USER_AGENT'] ?? 'WeConvertTest/3.0';

    $payload = [
        'data' => [ [
            'event_name'       => 'Purchase',
            'event_time'       => time(),
            'event_id'         => $event_id,
            'action_source'    => 'website',
            'event_source_url' => home_url( '/' ),
            'user_data'        => [
                'client_ip_address' => $ip,
                'client_user_agent' => $ua,
                'external_id'       => hash( 'sha256', 'kb_test_' . $pixel_id ),
            ],
            'custom_data'      => [
                'value'    => 9.99,
                'currency' => 'EUR',
            ],
        ] ],
    ];

    if ( $test_code ) $payload['test_event_code'] = $test_code;

    $url = sprintf(
        'https://graph.facebook.com/%s/%s/events?access_token=%s',
        $api_ver, $pixel_id, $token
    );

    $response = wp_remote_post( $url, [
        'timeout' => 15,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( $payload ),
    ] );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( [ 'message' => $response->get_error_message() ] );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code === 200 ) {
        $received = $body['events_received'] ?? 0;
        // Appel diagnostic pour l'EMQ
        $diag_url = sprintf(
            'https://graph.facebook.com/%s/%s?fields=name,event_stats&access_token=%s',
            $api_ver, $pixel_id, $token
        );
        $diag = wp_remote_get( $diag_url, [ 'timeout' => 8 ] );
        $pixel_name = '';
        if ( ! is_wp_error( $diag ) ) {
            $diag_body  = json_decode( wp_remote_retrieve_body( $diag ), true );
            $pixel_name = $diag_body['name'] ?? '';
        }
        wp_send_json_success( [
            'message'     => '✅ Connexion réussie',
            'events_sent' => $received,
            'pixel_name'  => $pixel_name,
            'pixel_id'    => $pixel_id,
        ] );
    } else {
        $err = $body['error']['message'] ?? wp_json_encode( $body );
        wp_send_json_error( [ 'message' => "❌ HTTP {$code} — {$err}" ] );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// AJAX — Test connexion EcoTrack
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_kb_test_eco', 'kb_ajax_test_eco' );

function kb_ajax_test_eco() {
    check_ajax_referer( 'kb_settings_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

    $token = sanitize_text_field( $_POST['token'] ?? '' );
    $base  = esc_url_raw( $_POST['api_base'] ?? 'https://packers.ecotrack.dz' );
    $base  = preg_replace( '#/api/v1/?$#', '', rtrim( $base, '/' ) );

    if ( ! $token || ! $base ) {
        wp_send_json_error( [ 'message' => 'URL plateforme et token requis.' ] );
    }

    $response = wp_remote_get(
        $base . '/api/v1/validate/token?api_token=' . rawurlencode( $token ),
        [ 'timeout' => 15, 'headers' => [ 'Accept' => 'application/json' ] ]
    );
    if ( is_wp_error( $response ) ) {
        wp_send_json_error( [ 'message' => '❌ ' . $response->get_error_message() ] );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || empty( $body['success'] ) ) {
        $msg = $body['message'] ?? "HTTP {$code}";
        if ( $msg === 'TOKEN_NOT_ALLOWED' ) $msg = 'Accès API désactivé pour ce compte — contacter la société de livraison';
        if ( $msg === 'INVALID_TOKEN' )     $msg = 'Token invalide';
        wp_send_json_error( [ 'message' => '❌ ' . $msg ] );
    }

    // Nombre de colis (lecture seule) pour confirmer l'accès aux données
    $orders = wp_remote_get( $base . '/api/v1/get/orders?page=1', [
        'timeout' => 15,
        'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ],
    ] );
    $total  = is_wp_error( $orders ) ? null : ( json_decode( wp_remote_retrieve_body( $orders ), true )['total'] ?? null );

    wp_send_json_success( [
        'message' => '✅ Connexion EcoTrack réussie' . ( $total !== null ? " — {$total} colis sur les 90 derniers jours" : '' ),
        'remaining_day' => wp_remote_retrieve_header( $response, 'x-ratelimit-remaining-day' ),
    ] );
}

// ═══════════════════════════════════════════════════════════════════════════════
// AJAX — Save settings (appelé depuis le JS onglets)
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_kb_save_settings', 'kb_ajax_save_settings' );

function kb_ajax_save_settings() {
    check_ajax_referer( 'kb_settings_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

    // Pixels — JSON array de paires
    $pixels_raw = sanitize_text_field( $_POST['meta_pixels'] ?? '[]' );
    $pixels     = json_decode( stripslashes( $pixels_raw ), true );
    if ( ! is_array( $pixels ) ) $pixels = [];
    $pixels_clean = [];
    foreach ( $pixels as $px ) {
        $pid = preg_replace( '/[^0-9]/', '', $px['pixel_id'] ?? '' );
        $tok = sanitize_text_field( $px['access_token'] ?? '' );
        if ( $pid && $tok ) {
            $pixels_clean[] = [
                'label'        => sanitize_text_field( $px['label'] ?? '' ),
                'pixel_id'     => $pid,
                'access_token' => $tok,
                'active'       => ! empty( $px['active'] ),
            ];
        }
    }
    update_option( 'kb_meta_pixels', wp_json_encode( $pixels_clean ) );

    // Champs simples
    $fields = [
        'meta_api_version', 'meta_test_code',
        'rate_dzd_eur', 'rate_dzd_usd',
        'eco_api_base', 'eco_api_token', 'eco_lookback_days',
        'eco_auto_create', 'eco_auto_ship', 'eco_ask_collection', 'eco_ref_prefix', 'eco_desk_keywords',
        'del_autocomplete',
        'maystro_api_key', 'maystro_store_id', 'maystro_enabled',
        'procolis_api_key', 'procolis_enabled',
        'guepex_api_key', 'guepex_enabled',
        'default_delivery_provider',
    ];
    foreach ( $fields as $key ) {
        update_option( 'kb_' . $key, sanitize_text_field( $_POST[ $key ] ?? '' ) );
    }

    wp_send_json_success( [ 'message' => 'Configuration sauvegardée ✅' ] );
}

// ═══════════════════════════════════════════════════════════════════════════════
// PAGE HTML
// ═══════════════════════════════════════════════════════════════════════════════

function kb_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $nonce      = wp_create_nonce( 'kb_settings_nonce' );
    $ajax_url   = admin_url( 'admin-ajax.php' );

    // Charger valeurs actuelles
    $pixels_raw = kb_get( 'meta_pixels', '[]' );
    $pixels     = json_decode( $pixels_raw, true ) ?: [];
    // Toujours au moins une ligne vide
    if ( empty( $pixels ) ) $pixels = [ [ 'label' => '', 'pixel_id' => '', 'access_token' => '', 'active' => true ] ];

    $cfg = [
        'api_version'   => kb_get( 'meta_api_version', 'v19.0' ),
        'test_code'     => kb_get( 'meta_test_code', '' ),
        'rate_eur'      => kb_get( 'rate_dzd_eur', '285' ),
        'rate_usd'      => kb_get( 'rate_dzd_usd', '345' ),
        'eco_base'      => kb_get( 'eco_api_base', 'https://packers.ecotrack.dz' ),
        'eco_token'     => kb_get( 'eco_api_token', '' ),
        'eco_lookback'  => kb_get( 'eco_lookback_days', '30' ),
        'eco_create'    => kb_get( 'eco_auto_create', '1' ),
        'eco_ship'      => kb_get( 'eco_auto_ship', '1' ),
        'eco_collect'   => kb_get( 'eco_ask_collection', '0' ),
        'eco_prefix'    => kb_get( 'eco_ref_prefix', '#' ),
        'eco_desk_kw'   => kb_get( 'eco_desk_keywords', 'stop desk,stopdesk,bureau,agence,point relais,مكتب' ),
        'del_autocomp'  => kb_get( 'del_autocomplete', '1' ),
        'maystro_key'   => kb_get( 'maystro_api_key', '' ),
        'maystro_store' => kb_get( 'maystro_store_id', '' ),
        'maystro_on'    => kb_get( 'maystro_enabled', '' ),
        'procolis_key'  => kb_get( 'procolis_api_key', '' ),
        'procolis_on'   => kb_get( 'procolis_enabled', '' ),
        'guepex_key'    => kb_get( 'guepex_api_key', '' ),
        'guepex_on'     => kb_get( 'guepex_enabled', '' ),
        'default_del'   => kb_get( 'default_delivery_provider', 'ecotrack' ),
    ];

    $active_modules = apply_filters( 'kb_active_modules', [] );
    $pixels_json    = esc_attr( wp_json_encode( $pixels ) );
    ?>
<div class="wrap" id="kb-settings-root">
<style>
/* ── Reset & Base ──────────────────────────────────────────────────── */
#kb-settings-root *{box-sizing:border-box}
#kb-settings-root{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:900px;padding-bottom:40px}
/* ── Header ────────────────────────────────────────────────────────── */
.kb-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;padding-bottom:16px;border-bottom:2px solid #f0f0f0}
.kb-header-left{display:flex;align-items:center;gap:12px}
.kb-logo-icon{width:36px;height:36px;background:#0D1117;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px}
.kb-header h1{font-size:18px;font-weight:600;margin:0;color:#1a1a1a}
.kb-header-sub{font-size:12px;color:#888;margin-top:2px}
.kb-save-btn{background:#0D1117;color:#F5A623;border:none;border-radius:6px;padding:10px 24px;font-size:14px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all .15s}
.kb-save-btn:hover{background:#1a2332;transform:translateY(-1px)}
.kb-save-btn.saving{opacity:.7;pointer-events:none}
.kb-save-msg{font-size:13px;color:#1D9E75;font-weight:500;opacity:0;transition:opacity .3s}
.kb-save-msg.show{opacity:1}
/* ── Tabs ──────────────────────────────────────────────────────────── */
.kb-tabs{display:flex;gap:2px;background:#f5f5f5;border-radius:8px;padding:4px;margin-bottom:24px}
.kb-tab-btn{flex:1;padding:10px 12px;border:none;background:transparent;border-radius:6px;font-size:13px;font-weight:500;color:#666;cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center;gap:6px}
.kb-tab-btn.active{background:#fff;color:#0D1117;box-shadow:0 1px 3px rgba(0,0,0,.12);font-weight:600}
.kb-tab-btn:hover:not(.active){color:#333;background:rgba(255,255,255,.5)}
.kb-tab-dot{width:6px;height:6px;border-radius:50%;background:#ddd}
.kb-tab-dot.ok{background:#1D9E75}
.kb-tab-dot.warn{background:#F5A623}
.kb-tab-dot.err{background:#E24B4A}
/* ── Tab Panels ────────────────────────────────────────────────────── */
.kb-panel{display:none}.kb-panel.active{display:block}
/* ── Cards ─────────────────────────────────────────────────────────── */
.kb-card{background:#fff;border:1px solid #e0e0e0;border-radius:10px;margin-bottom:16px;overflow:hidden}
.kb-card-header{padding:16px 20px;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between}
.kb-card-title{font-size:14px;font-weight:600;color:#1a1a1a;display:flex;align-items:center;gap:8px}
.kb-card-body{padding:20px}
/* ── Status badges ─────────────────────────────────────────────────── */
.kb-status{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px}
.kb-status.ok{background:#e8f5e9;color:#2e7d32}
.kb-status.warn{background:#fff3e0;color:#e65100}
.kb-status.err{background:#fce4e4;color:#c62828}
.kb-status.new{background:#e8eaf6;color:#3949ab}
/* ── Form elements ─────────────────────────────────────────────────── */
.kb-field{margin-bottom:16px}
.kb-field label{display:block;font-size:12px;font-weight:600;color:#444;margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em}
.kb-field input[type=text],.kb-field input[type=number],.kb-field select{width:100%;padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;font-family:inherit;transition:border .15s;color:#1a1a1a}
.kb-field input[type=text]:focus,.kb-field input[type=number]:focus,.kb-field select:focus{outline:none;border-color:#0D1117;box-shadow:0 0 0 3px rgba(13,17,23,.08)}
.kb-field input.mono{font-family:'SF Mono','Fira Code',monospace;font-size:12px;background:#fafafa}
.kb-field-desc{font-size:11px;color:#888;margin-top:5px;line-height:1.5}
.kb-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.kb-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
/* ── Test buttons ───────────────────────────────────────────────────── */
.kb-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;transition:all .15s}
.kb-btn-primary{background:#0D1117;color:#fff}
.kb-btn-primary:hover{background:#1a2332}
.kb-btn-outline{background:#fff;color:#333;border:1px solid #ddd}
.kb-btn-outline:hover{background:#f5f5f5;border-color:#999}
.kb-btn-green{background:#1D9E75;color:#fff}
.kb-btn-green:hover{background:#178a63}
.kb-btn-amber{background:#F5A623;color:#000}
.kb-btn.loading{opacity:.6;pointer-events:none}
.kb-test-result{margin-top:10px;padding:10px 14px;border-radius:6px;font-size:12px;font-weight:500;display:none}
.kb-test-result.ok{background:#e8f5e9;color:#2e7d32;border:1px solid #c8e6c9;display:block}
.kb-test-result.err{background:#fce4e4;color:#c62828;border:1px solid #ef9a9a;display:block}
.kb-test-result.info{background:#e3f2fd;color:#1565c0;border:1px solid #90caf9;display:block}
/* ── Pixel rows ─────────────────────────────────────────────────────── */
.kb-pixel-row{background:#fafafa;border:1px solid #e8e8e8;border-radius:8px;padding:14px 16px;margin-bottom:10px;position:relative}
.kb-pixel-row-header{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.kb-pixel-label-input{flex:1;padding:6px 10px;border:1px solid #ddd;border-radius:5px;font-size:13px;font-weight:600;background:#fff}
.kb-pixel-toggle{position:relative;width:36px;height:20px;flex-shrink:0}
.kb-pixel-toggle input{opacity:0;width:0;height:0;position:absolute}
.kb-pixel-slider{position:absolute;inset:0;background:#ccc;border-radius:20px;cursor:pointer;transition:.2s}
.kb-pixel-slider::before{content:'';position:absolute;width:14px;height:14px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
.kb-pixel-toggle input:checked + .kb-pixel-slider{background:#1D9E75}
.kb-pixel-toggle input:checked + .kb-pixel-slider::before{transform:translateX(16px)}
.kb-pixel-remove{background:none;border:none;color:#ccc;cursor:pointer;font-size:18px;padding:0 4px;line-height:1;flex-shrink:0}
.kb-pixel-remove:hover{color:#E24B4A}
.kb-pixel-fields{display:grid;grid-template-columns:1fr 2fr;gap:10px;margin-bottom:10px}
.kb-pixel-fields input{padding:8px 10px;border:1px solid #ddd;border-radius:5px;font-size:12px;font-family:'SF Mono','Fira Code',monospace;background:#fff;color:#1a1a1a;width:100%}
.kb-pixel-actions{display:flex;align-items:center;gap:8px}
.kb-pixel-test-result{flex:1;font-size:11px;font-weight:600;padding:5px 10px;border-radius:4px;display:none}
.kb-pixel-test-result.ok{background:#e8f5e9;color:#2e7d32;display:block}
.kb-pixel-test-result.err{background:#fce4e4;color:#c62828;display:block}
/* ── Webhook box ────────────────────────────────────────────────────── */
.kb-webhook-url{background:#0D1117;color:#F5A623;font-family:'SF Mono','Fira Code',monospace;font-size:12px;padding:12px 16px;border-radius:6px;word-break:break-all;cursor:pointer;position:relative;transition:opacity .15s}
.kb-webhook-url:hover{opacity:.9}
.kb-webhook-url::after{content:'📋 Cliquer pour copier';position:absolute;bottom:6px;right:10px;font-size:10px;opacity:.5;font-family:inherit}
/* ── Toggle switch ──────────────────────────────────────────────────── */
.kb-toggle-row{display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid #f5f5f5}
.kb-toggle-row:last-child{border-bottom:none}
.kb-toggle-label{font-size:13px;font-weight:500;color:#333}
.kb-toggle-sub{font-size:11px;color:#888;margin-top:2px}
/* ── DZD rate display ───────────────────────────────────────────────── */
.kb-rate-preview{background:linear-gradient(135deg,#0D1117,#1a2332);color:#fff;border-radius:8px;padding:16px 20px;margin-top:12px}
.kb-rate-preview-title{font-size:11px;color:#aaa;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px}
.kb-rate-preview-row{display:flex;justify-content:space-between;align-items:center;padding:4px 0}
.kb-rate-preview-label{font-size:12px;color:#aaa}
.kb-rate-preview-val{font-family:'SF Mono','Fira Code',monospace;font-size:14px;font-weight:600;color:#F5A623}
/* ── Modules grid ───────────────────────────────────────────────────── */
.kb-modules-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.kb-module-card{background:#f9f9f9;border:1px solid #e8e8e8;border-radius:8px;padding:12px 14px}
.kb-module-card-title{font-size:13px;font-weight:600;color:#1a1a1a;margin-bottom:6px}
.kb-module-card-item{font-size:11px;color:#666;padding:2px 0;display:flex;align-items:flex-start;gap:5px}
/* ── Responsive ─────────────────────────────────────────────────────── */
@media(max-width:768px){.kb-grid-2,.kb-grid-3,.kb-pixel-fields{grid-template-columns:1fr}.kb-tabs{flex-wrap:wrap}}
</style>

<div class="kb-header">
    <div class="kb-header-left">
        <div class="kb-logo-icon">⚡</div>
        <div>
            <h1>WeConvert.io Settings</h1>
            <div class="kb-header-sub">COD Algérie — S-TIER Tracking Platform v3.0</div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:12px">
        <span class="kb-save-msg" id="kb-save-msg">✅ Sauvegardé</span>
        <button class="kb-save-btn" id="kb-save-btn" onclick="kbSaveAll()">
            <span>💾</span> Sauvegarder tout
        </button>
    </div>
</div>

<!-- TABS -->
<div class="kb-tabs">
    <button class="kb-tab-btn active" onclick="kbTab('meta',this)">
        <span class="kb-tab-dot <?php echo count($pixels) > 0 && !empty($pixels[0]['pixel_id']) ? 'ok' : 'warn'; ?>"></span>
        🎯 Meta CAPI
    </button>
    <button class="kb-tab-btn" onclick="kbTab('delivery',this)">
        <span class="kb-tab-dot <?php echo $cfg['eco_token'] ? 'ok' : 'warn'; ?>"></span>
        🚚 Livraison COD
    </button>
    <button class="kb-tab-btn" onclick="kbTab('rates',this)">
        <span class="kb-tab-dot ok"></span>
        💱 Taux DZD
    </button>
    <button class="kb-tab-btn" onclick="kbTab('advanced',this)">
        <span class="kb-tab-dot"></span>
        ⚙️ Avancé
    </button>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB META CAPI                                                         -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div class="kb-panel active" id="kb-panel-meta">

    <div class="kb-card">
        <div class="kb-card-header">
            <span class="kb-card-title">🎯 Pixels Meta & Tokens CAPI</span>
            <span class="kb-status new">Multi-pixel illimité</span>
        </div>
        <div class="kb-card-body">
            <p style="font-size:13px;color:#666;margin:0 0 16px">
                Chaque ligne = 1 pixel Meta + 1 token CAPI indépendant. Tous les pixels actifs reçoivent 100% des events.
                Utile pour multi-BM, multi-store, multi-niche, ou agence multi-clients.
            </p>

            <div id="kb-pixels-container">
            <?php foreach ( $pixels as $i => $px ) : ?>
            <div class="kb-pixel-row" data-index="<?= $i ?>">
                <div class="kb-pixel-row-header">
                    <label class="kb-pixel-toggle">
                        <input type="checkbox" <?= ! empty( $px['active'] ) ? 'checked' : '' ?> onchange="kbMarkDirty()">
                        <span class="kb-pixel-slider"></span>
                    </label>
                    <input type="text" class="kb-pixel-label-input" placeholder="Label (ex: LYNE Haircare, Client X, BM Principal...)"
                        value="<?= esc_attr( $px['label'] ?? '' ) ?>" onchange="kbMarkDirty()">
                    <button class="kb-pixel-remove" onclick="kbRemovePixel(this)" title="Supprimer">✕</button>
                </div>
                <div class="kb-pixel-fields">
                    <input type="text" class="kb-px-id" placeholder="Pixel ID (ex: 1234567890123)"
                        value="<?= esc_attr( $px['pixel_id'] ?? '' ) ?>" onchange="kbMarkDirty()">
                    <input type="text" class="kb-px-tok" placeholder="Access Token CAPI (EAAPs...)"
                        value="<?= esc_attr( $px['access_token'] ?? '' ) ?>" onchange="kbMarkDirty()">
                </div>
                <div class="kb-pixel-actions">
                    <button class="kb-btn kb-btn-outline" onclick="kbTestPixel(this)" style="font-size:11px;padding:6px 12px">
                        🧪 Tester la connexion
                    </button>
                    <div class="kb-pixel-test-result"></div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <button class="kb-btn kb-btn-primary" onclick="kbAddPixel()" style="margin-top:8px">
                ➕ Ajouter un pixel
            </button>
        </div>
    </div>

    <div class="kb-card">
        <div class="kb-card-header">
            <span class="kb-card-title">⚙️ Paramètres API Meta</span>
        </div>
        <div class="kb-card-body">
            <div class="kb-grid-2">
                <div class="kb-field">
                    <label>Version API Graph</label>
                    <input type="text" class="mono" id="meta_api_version" value="<?= esc_attr( $cfg['api_version'] ) ?>" placeholder="v19.0" onchange="kbMarkDirty()">
                    <div class="kb-field-desc">Mettre à jour selon la dernière version Meta disponible.</div>
                </div>
                <div class="kb-field">
                    <label>Test Event Code</label>
                    <input type="text" class="mono" id="meta_test_code" value="<?= esc_attr( $cfg['test_code'] ) ?>" placeholder="TEST12345 (vide = production)" onchange="kbMarkDirty()">
                    <div class="kb-field-desc">⚠️ Vider avant de passer en production.</div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB LIVRAISON                                                          -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div class="kb-panel" id="kb-panel-delivery">

    <!-- ECOTRACK (PACKERS) -->
    <div class="kb-card">
        <div class="kb-card-header">
            <span class="kb-card-title">🚚 EcoTrack — Packers</span>
            <span class="kb-status <?= $cfg['eco_token'] ? 'ok' : 'warn'; ?>"><?= $cfg['eco_token'] ? '✓ Configuré' : '⚠ Incomplet'; ?></span>
        </div>
        <div class="kb-card-body">
            <div class="kb-grid-2">
                <div class="kb-field">
                    <label>URL plateforme EcoTrack</label>
                    <input type="text" class="mono" id="eco_api_base" value="<?= esc_attr( $cfg['eco_base'] ) ?>" placeholder="https://packers.ecotrack.dz" onchange="kbMarkDirty()">
                    <div class="kb-field-desc">Toute société sur EcoTrack fonctionne (packers, dhd, …).ecotrack.dz</div>
                </div>
                <div class="kb-field">
                    <label>Token API</label>
                    <input type="password" class="mono" id="eco_api_token" value="<?= esc_attr( $cfg['eco_token'] ) ?>" placeholder="Compte EcoTrack → Paramètres → API" onchange="kbMarkDirty()" autocomplete="off">
                </div>
            </div>
            <div class="kb-grid-2">
                <div class="kb-field">
                    <label>Lookback poll (jours)</label>
                    <input type="number" id="eco_lookback_days" value="<?= esc_attr( $cfg['eco_lookback'] ) ?>" min="1" max="90" onchange="kbMarkDirty()">
                    <div class="kb-field-desc">Fenêtre lue toutes les 15 min pour détecter les colis livrés.</div>
                </div>
                <div class="kb-field">
                    <label>Préfixe référence colis</label>
                    <input type="text" class="mono" id="eco_ref_prefix" value="<?= esc_attr( $cfg['eco_prefix'] ) ?>" placeholder="#" onchange="kbMarkDirty()">
                    <div class="kb-field-desc">Référence envoyée = préfixe + N° commande (ex : #8787).</div>
                </div>
            </div>
            <div class="kb-field">
                <label>Mots-clés stop desk (méthode de livraison WooCommerce)</label>
                <input type="text" id="eco_desk_keywords" value="<?= esc_attr( $cfg['eco_desk_kw'] ) ?>" onchange="kbMarkDirty()">
                <div class="kb-field-desc">Si le nom de la méthode de livraison contient un de ces mots → colis en stop desk.</div>
            </div>
            <div class="kb-grid-2">
                <label style="display:flex;gap:8px;align-items:center;font-size:13px">
                    <input type="checkbox" id="eco_auto_create" <?= $cfg['eco_create'] === '1' ? 'checked' : '' ?> onchange="kbMarkDirty()">
                    Créer le colis automatiquement (commande → completed)
                </label>
                <label style="display:flex;gap:8px;align-items:center;font-size:13px">
                    <input type="checkbox" id="eco_auto_ship" <?= $cfg['eco_ship'] === '1' ? 'checked' : '' ?> onchange="kbMarkDirty()">
                    Valider automatiquement → transfert direct à la société de livraison
                </label>
                <label style="display:flex;gap:8px;align-items:center;font-size:13px">
                    <input type="checkbox" id="eco_ask_collection" <?= $cfg['eco_collect'] === '1' ? 'checked' : '' ?> onchange="kbMarkDirty()">
                    Demander le ramassage à la validation (sinon dépôt au bureau)
                </label>
                <label style="display:flex;gap:8px;align-items:center;font-size:13px">
                    <input type="checkbox" id="del_autocomplete" <?= $cfg['del_autocomp'] === '1' ? 'checked' : '' ?> onchange="kbMarkDirty()">
                    Colis livré → passer la commande en completed
                </label>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
                <button class="kb-btn kb-btn-outline" onclick="kbTestEco()">🔍 Tester la connexion EcoTrack</button>
                <a class="kb-btn kb-btn-outline" href="<?= esc_url( admin_url( 'tools.php?page=kb-ecotrack-tools' ) ) ?>">🧰 Outils EcoTrack (poll manuel, wilayas)</a>
            </div>
            <div class="kb-test-result" id="eco-test-result"></div>
            <div style="font-size:11px;color:#888;margin-top:10px">EcoTrack n'a pas de webhooks : les livraisons sont détectées par lecture de l'API toutes les 15 min (limite : 50 req/min).</div>
        </div>
    </div>

    <!-- MAYSTRO -->
    <div class="kb-card">
        <div class="kb-card-header">
            <span class="kb-card-title">📦 Maystro</span>
            <div style="display:flex;align-items:center;gap:10px">
                <span class="kb-status new">Phase 2</span>
                <label class="kb-pixel-toggle">
                    <input type="checkbox" id="maystro_enabled" <?= $cfg['maystro_on'] ? 'checked' : '' ?> onchange="kbMarkDirty()">
                    <span class="kb-pixel-slider"></span>
                </label>
            </div>
        </div>
        <div class="kb-card-body">
            <div class="kb-grid-2">
                <div class="kb-field">
                    <label>API Key Maystro</label>
                    <input type="text" class="mono" id="maystro_api_key" value="<?= esc_attr( $cfg['maystro_key'] ) ?>" placeholder="sk_live_..." onchange="kbMarkDirty()">
                </div>
                <div class="kb-field">
                    <label>Store ID</label>
                    <input type="text" class="mono" id="maystro_store_id" value="<?= esc_attr( $cfg['maystro_store'] ) ?>" placeholder="UUID store Maystro" onchange="kbMarkDirty()">
                </div>
            </div>
            <button class="kb-btn kb-btn-outline" onclick="kbTestDelivery('maystro')">🔍 Tester Maystro</button>
            <div class="kb-test-result" id="maystro-test-result"></div>
        </div>
    </div>

    <!-- PROCOLIS -->
    <div class="kb-card">
        <div class="kb-card-header">
            <span class="kb-card-title">📦 Procolis</span>
            <div style="display:flex;align-items:center;gap:10px">
                <span class="kb-status new">Phase 2</span>
                <label class="kb-pixel-toggle">
                    <input type="checkbox" id="procolis_enabled" <?= $cfg['procolis_on'] ? 'checked' : '' ?> onchange="kbMarkDirty()">
                    <span class="kb-pixel-slider"></span>
                </label>
            </div>
        </div>
        <div class="kb-card-body">
            <div class="kb-field">
                <label>Token Procolis</label>
                <input type="text" class="mono" id="procolis_api_key" value="<?= esc_attr( $cfg['procolis_key'] ) ?>" placeholder="Bearer token Procolis" onchange="kbMarkDirty()">
            </div>
            <button class="kb-btn kb-btn-outline" onclick="kbTestDelivery('procolis')">🔍 Tester Procolis</button>
            <div class="kb-test-result" id="procolis-test-result"></div>
        </div>
    </div>

    <!-- GUEPEX -->
    <div class="kb-card">
        <div class="kb-card-header">
            <span class="kb-card-title">📦 Guepex</span>
            <div style="display:flex;align-items:center;gap:10px">
                <span class="kb-status new">Phase 2</span>
                <label class="kb-pixel-toggle">
                    <input type="checkbox" id="guepex_enabled" <?= $cfg['guepex_on'] ? 'checked' : '' ?> onchange="kbMarkDirty()">
                    <span class="kb-pixel-slider"></span>
                </label>
            </div>
        </div>
        <div class="kb-card-body">
            <div class="kb-field">
                <label>API Key Guepex</label>
                <input type="text" class="mono" id="guepex_api_key" value="<?= esc_attr( $cfg['guepex_key'] ) ?>" placeholder="Clé API Guepex" onchange="kbMarkDirty()">
            </div>
            <button class="kb-btn kb-btn-outline" onclick="kbTestDelivery('guepex')">🔍 Tester Guepex</button>
            <div class="kb-test-result" id="guepex-test-result"></div>
        </div>
    </div>

    <!-- Livreur par défaut -->
    <div class="kb-card">
        <div class="kb-card-header">
            <span class="kb-card-title">⚙️ Livreur par défaut</span>
        </div>
        <div class="kb-card-body">
            <div class="kb-field" style="max-width:300px">
                <label>Livreur utilisé à la création automatique du colis</label>
                <select id="default_delivery_provider" onchange="kbMarkDirty()">
                    <option value="ecotrack"  <?= selected( $cfg['default_del'], 'ecotrack', false ) ?>>EcoTrack (Packers)</option>
                    <option value="maystro"   <?= selected( $cfg['default_del'], 'maystro', false ) ?>>Maystro</option>
                    <option value="procolis"  <?= selected( $cfg['default_del'], 'procolis', false ) ?>>Procolis</option>
                    <option value="guepex"    <?= selected( $cfg['default_del'], 'guepex', false ) ?>>Guepex</option>
                </select>
            </div>
        </div>
    </div>

</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB TAUX DZD                                                           -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div class="kb-panel" id="kb-panel-rates">

    <div class="kb-card">
        <div class="kb-card-header">
            <span class="kb-card-title">💱 Taux de change DZD</span>
            <span class="kb-status warn">⚠️ Mettre à jour manuellement</span>
        </div>
        <div class="kb-card-body">
            <div style="background:#fff3e0;border:1px solid #ffe0b2;border-radius:6px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#e65100">
                <strong>Pourquoi c'est critique :</strong> Meta utilise le taux officiel de la Banque d'Algérie (~156 DZD/EUR).
                Une commande de 3500 DZD apparaît comme 22 EUR dans Meta Ads Manager au lieu de ~12 EUR réels.
                L'algo Meta optimise sur de fausses valeurs → ROAS gonflé artificiellement → mauvaises décisions de scaling.
                Avec le bon taux, Meta reçoit les vraies valeurs et optimise correctement.
            </div>

            <div class="kb-grid-2">
                <div class="kb-field">
                    <label>1 EUR = X DZD (taux marché parallèle)</label>
                    <input type="number" id="rate_dzd_eur" value="<?= esc_attr( $cfg['rate_eur'] ) ?>" step="0.5" min="1" onchange="kbMarkDirty();kbUpdateRatePreview()">
                    <div class="kb-field-desc">Taux marché réel. Vérifier sur Algérie Change ou similaire.</div>
                </div>
                <div class="kb-field">
                    <label>1 USD = X DZD (pour TikTok / Google Ads)</label>
                    <input type="number" id="rate_dzd_usd" value="<?= esc_attr( $cfg['rate_usd'] ) ?>" step="0.5" min="1" onchange="kbMarkDirty();kbUpdateRatePreview()">
                    <div class="kb-field-desc">Réservé aux futures intégrations TikTok Ads / Google Ads.</div>
                </div>
            </div>

            <div class="kb-rate-preview" id="kb-rate-preview">
                <div class="kb-rate-preview-title">Aperçu de conversion — Exemples commandes COD DZ</div>
                <div class="kb-rate-preview-row">
                    <span class="kb-rate-preview-label">2 500 DZD →</span>
                    <span class="kb-rate-preview-val" id="prev-2500">— EUR</span>
                </div>
                <div class="kb-rate-preview-row">
                    <span class="kb-rate-preview-label">5 000 DZD →</span>
                    <span class="kb-rate-preview-val" id="prev-5000">— EUR</span>
                </div>
                <div class="kb-rate-preview-row">
                    <span class="kb-rate-preview-label">12 000 DZD →</span>
                    <span class="kb-rate-preview-val" id="prev-12000">— EUR</span>
                </div>
                <div class="kb-rate-preview-row">
                    <span class="kb-rate-preview-label">25 000 DZD →</span>
                    <span class="kb-rate-preview-val" id="prev-25000">— EUR</span>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB AVANCÉ                                                             -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div class="kb-panel" id="kb-panel-advanced">

    <?php if ( ! empty( $active_modules ) ) : ?>
    <div class="kb-card">
        <div class="kb-card-header">
            <span class="kb-card-title">🧩 Modules actifs</span>
            <span class="kb-status ok"><?= count( $active_modules ) ?> modules</span>
        </div>
        <div class="kb-card-body">
            <div class="kb-modules-grid">
                <?php foreach ( $active_modules as $id => $mod ) : ?>
                <div class="kb-module-card">
                    <div class="kb-module-card-title"><?= esc_html( $mod['label'] ) ?></div>
                    <?php $items = array_merge( $mod['events'] ?? [], $mod['features'] ?? [] ); ?>
                    <?php foreach ( $items as $item ) : ?>
                    <div class="kb-module-card-item"><span>•</span><?= esc_html( $item ) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="kb-card">
        <div class="kb-card-header">
            <span class="kb-card-title">🗑️ Cache & Debug</span>
        </div>
        <div class="kb-card-body" style="display:flex;gap:10px;flex-wrap:wrap">
            <button class="kb-btn kb-btn-outline" onclick="kbClearCache('territories')">🗺️ Vider cache wilayas / communes EcoTrack</button>
            <button class="kb-btn kb-btn-outline" onclick="kbClearCache('fbevents')">📜 Vider cache fbevents.js</button>
            <button class="kb-btn kb-btn-outline" onclick="kbClearCache('all')">🔄 Vider tous les caches</button>
            <div class="kb-test-result" id="cache-result" style="width:100%"></div>
        </div>
    </div>

</div>

<!-- JS -->
<script>
var KB_AJAX = '<?php echo esc_js( $ajax_url ); ?>';
var KB_NONCE = '<?php echo esc_js( $nonce ); ?>';
var KB_PIXELS = <?php echo wp_json_encode( $pixels ); ?>;
var kbDirty = false;

// ── Tabs ────────────────────────────────────────────────────────────────
function kbTab(id, btn) {
    document.querySelectorAll('.kb-panel').forEach(function(p){ p.classList.remove('active'); });
    document.querySelectorAll('.kb-tab-btn').forEach(function(b){ b.classList.remove('active'); });
    document.getElementById('kb-panel-' + id).classList.add('active');
    btn.classList.add('active');
}

// ── Dirty tracking ───────────────────────────────────────────────────────
function kbMarkDirty() { kbDirty = true; }
window.addEventListener('beforeunload', function(e) {
    if (kbDirty) { e.preventDefault(); e.returnValue = ''; }
});

// ── Rate preview ─────────────────────────────────────────────────────────
function kbUpdateRatePreview() {
    var rate = parseFloat(document.getElementById('rate_dzd_eur').value) || 285;
    [2500, 5000, 12000, 25000].forEach(function(dzd) {
        var el = document.getElementById('prev-' + dzd);
        if (el) el.textContent = (Math.round(dzd / rate * 100) / 100).toFixed(2) + ' EUR';
    });
}
kbUpdateRatePreview();

// ── Multi-pixel ──────────────────────────────────────────────────────────
function kbAddPixel() {
    var container = document.getElementById('kb-pixels-container');
    var idx = container.children.length;
    var div = document.createElement('div');
    div.className = 'kb-pixel-row';
    div.dataset.index = idx;
    div.innerHTML = `
        <div class="kb-pixel-row-header">
            <label class="kb-pixel-toggle">
                <input type="checkbox" checked onchange="kbMarkDirty()">
                <span class="kb-pixel-slider"></span>
            </label>
            <input type="text" class="kb-pixel-label-input" placeholder="Label (ex: BM Client X, Niche Cosmétiques...)" onchange="kbMarkDirty()">
            <button class="kb-pixel-remove" onclick="kbRemovePixel(this)">✕</button>
        </div>
        <div class="kb-pixel-fields">
            <input type="text" class="kb-px-id" placeholder="Pixel ID" onchange="kbMarkDirty()">
            <input type="text" class="kb-px-tok" placeholder="Access Token CAPI" onchange="kbMarkDirty()">
        </div>
        <div class="kb-pixel-actions">
            <button class="kb-btn kb-btn-outline" onclick="kbTestPixel(this)" style="font-size:11px;padding:6px 12px">
                🧪 Tester la connexion
            </button>
            <div class="kb-pixel-test-result"></div>
        </div>`;
    container.appendChild(div);
    kbMarkDirty();
    div.querySelector('.kb-pixel-label-input').focus();
}

function kbRemovePixel(btn) {
    var row = btn.closest('.kb-pixel-row');
    if (document.querySelectorAll('.kb-pixel-row').length <= 1) {
        alert('Il faut au moins un pixel configuré.');
        return;
    }
    if (confirm('Supprimer ce pixel ?')) { row.remove(); kbMarkDirty(); }
}

function kbCollectPixels() {
    var pixels = [];
    document.querySelectorAll('.kb-pixel-row').forEach(function(row) {
        var pid  = (row.querySelector('.kb-px-id')  || {}).value || '';
        var tok  = (row.querySelector('.kb-px-tok') || {}).value || '';
        var lbl  = (row.querySelector('.kb-pixel-label-input') || {}).value || '';
        var chk  = row.querySelector('input[type=checkbox]');
        var active = chk ? chk.checked : true;
        pixels.push({ label: lbl.trim(), pixel_id: pid.trim(), access_token: tok.trim(), active: active });
    });
    return pixels;
}

// ── Test pixel individuel ────────────────────────────────────────────────
function kbTestPixel(btn) {
    var row = btn.closest('.kb-pixel-row');
    var pid = (row.querySelector('.kb-px-id') || {}).value || '';
    var tok = (row.querySelector('.kb-px-tok') || {}).value || '';
    var resultEl = row.querySelector('.kb-pixel-test-result');
    if (!pid || !tok) { kbShowResult(resultEl, 'err', 'Pixel ID et Token requis'); return; }
    btn.classList.add('loading');
    btn.textContent = '⏳ Test en cours...';
    var fd = new FormData();
    fd.append('action', 'kb_test_meta');
    fd.append('nonce',  KB_NONCE);
    fd.append('pixel_id', pid);
    fd.append('token', tok);
    fd.append('api_ver', document.getElementById('meta_api_version').value || 'v19.0');
    fd.append('test_code', document.getElementById('meta_test_code').value || '');
    fetch(KB_AJAX, { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(res) {
            btn.classList.remove('loading');
            btn.textContent = '🧪 Tester la connexion';
            if (res.success) {
                var name = res.data.pixel_name ? ' — ' + res.data.pixel_name : '';
                kbShowResult(resultEl, 'ok', '✅ Connexion OK' + name + ' | ' + res.data.events_sent + ' event reçu');
            } else {
                kbShowResult(resultEl, 'err', res.data.message || 'Erreur inconnue');
            }
        })
        .catch(function() {
            btn.classList.remove('loading');
            btn.textContent = '🧪 Tester la connexion';
            kbShowResult(resultEl, 'err', 'Erreur réseau');
        });
}

// ── Test EcoTrack ────────────────────────────────────────────────────────
function kbTestEco() {
    var resultEl = document.getElementById('eco-test-result');
    var fd = new FormData();
    fd.append('action',   'kb_test_eco');
    fd.append('nonce',    KB_NONCE);
    fd.append('token',    document.getElementById('eco_api_token').value);
    fd.append('api_base', document.getElementById('eco_api_base').value);
    kbShowResult(resultEl, 'info', '⏳ Test en cours...');
    fetch(KB_AJAX, { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (res.success) kbShowResult(resultEl, 'ok', res.data.message + (res.data.remaining_day ? ' · ' + res.data.remaining_day + ' requêtes restantes aujourd\'hui' : ''));
            else kbShowResult(resultEl, 'err', res.data.message);
        }).catch(function() { kbShowResult(resultEl, 'err', 'Erreur réseau'); });
}

// ── Test autres livreurs (placeholder) ───────────────────────────────────
function kbTestDelivery(provider) {
    var resultEl = document.getElementById(provider + '-test-result');
    kbShowResult(resultEl, 'info', '⏳ Driver ' + provider + ' en cours de développement (Phase 2)...');
}

// ── Vider cache ───────────────────────────────────────────────────────────
function kbClearCache(type) {
    var resultEl = document.getElementById('cache-result');
    kbShowResult(resultEl, 'info', '⏳ Vidage en cours...');
    var fd = new FormData();
    fd.append('action', 'kb_clear_cache');
    fd.append('nonce',  KB_NONCE);
    fd.append('type',   type);
    fetch(KB_AJAX, { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (res.success) kbShowResult(resultEl, 'ok', '✅ ' + res.data.message);
            else kbShowResult(resultEl, 'err', '❌ Erreur');
        }).catch(function(){ kbShowResult(resultEl, 'err', 'Erreur réseau'); });
}

// ── Copy webhook URL ─────────────────────────────────────────────────────
function kbCopyWebhook(el) {
    var text = el.textContent.replace('📋 Cliquer pour copier','').trim();
    navigator.clipboard.writeText(text).then(function() {
        el.style.background='#1D9E75';
        setTimeout(function(){ el.style.background=''; }, 1200);
    });
}

// ── Save all ─────────────────────────────────────────────────────────────
function kbSaveAll() {
    var btn = document.getElementById('kb-save-btn');
    var msg = document.getElementById('kb-save-msg');
    btn.classList.add('saving');
    btn.innerHTML = '<span>⏳</span> Sauvegarde...';

    var pixels = kbCollectPixels();
    var fd = new FormData();
    fd.append('action',       'kb_save_settings');
    fd.append('nonce',        KB_NONCE);
    fd.append('meta_pixels',  JSON.stringify(pixels));
    fd.append('meta_api_version', document.getElementById('meta_api_version').value);
    fd.append('meta_test_code',   document.getElementById('meta_test_code').value);
    fd.append('rate_dzd_eur', document.getElementById('rate_dzd_eur').value);
    fd.append('rate_dzd_usd', document.getElementById('rate_dzd_usd').value);
    fd.append('eco_api_base',      document.getElementById('eco_api_base').value);
    fd.append('eco_api_token',     document.getElementById('eco_api_token').value);
    fd.append('eco_lookback_days', document.getElementById('eco_lookback_days').value);
    fd.append('eco_ref_prefix',    document.getElementById('eco_ref_prefix').value);
    fd.append('eco_desk_keywords', document.getElementById('eco_desk_keywords').value);
    fd.append('eco_auto_create',   document.getElementById('eco_auto_create').checked ? '1' : '0');
    fd.append('eco_auto_ship',     document.getElementById('eco_auto_ship').checked ? '1' : '0');
    fd.append('eco_ask_collection',document.getElementById('eco_ask_collection').checked ? '1' : '0');
    fd.append('del_autocomplete',  document.getElementById('del_autocomplete').checked ? '1' : '0');
    fd.append('maystro_api_key',  document.getElementById('maystro_api_key').value);
    fd.append('maystro_store_id', document.getElementById('maystro_store_id').value);
    fd.append('maystro_enabled',  document.getElementById('maystro_enabled').checked ? '1' : '');
    fd.append('procolis_api_key', document.getElementById('procolis_api_key').value);
    fd.append('procolis_enabled', document.getElementById('procolis_enabled').checked ? '1' : '');
    fd.append('guepex_api_key',   document.getElementById('guepex_api_key').value);
    fd.append('guepex_enabled',   document.getElementById('guepex_enabled').checked ? '1' : '');
    fd.append('default_delivery_provider', document.getElementById('default_delivery_provider').value);

    fetch(KB_AJAX, { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(res) {
            btn.classList.remove('saving');
            btn.innerHTML = '<span>💾</span> Sauvegarder tout';
            if (res.success) {
                kbDirty = false;
                msg.classList.add('show');
                setTimeout(function(){ msg.classList.remove('show'); }, 3000);
            } else {
                alert('Erreur lors de la sauvegarde.');
            }
        })
        .catch(function() {
            btn.classList.remove('saving');
            btn.innerHTML = '<span>💾</span> Sauvegarder tout';
            alert('Erreur réseau.');
        });
}

// ── Helper show result ────────────────────────────────────────────────────
function kbShowResult(el, type, msg) {
    if (!el) return;
    el.className = 'kb-test-result ' + type;
    el.innerHTML = msg;
    el.style.display = 'block';
}
</script>
</div>
<?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// AJAX — Vider les caches
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_kb_clear_cache', 'kb_ajax_clear_cache' );

function kb_ajax_clear_cache() {
    check_ajax_referer( 'kb_settings_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );
    $type = sanitize_text_field( $_POST['type'] ?? 'all' );
    $cleared = [];
    if ( $type === 'territories' || $type === 'all' ) {
        delete_transient( 'kb_eco_wilayas' );
        delete_transient( 'kb_eco_communes' );
        delete_transient( 'kb_eco_ref_index' );
        $cleared[] = 'Wilayas / communes EcoTrack';
    }
    if ( $type === 'fbevents' || $type === 'all' ) {
        delete_transient( 'kb_fbevents_js' );
        $cleared[] = 'fbevents.js';
    }
    wp_send_json_success( [ 'message' => implode( ' + ', $cleared ) . ' vidé(s) avec succès' ] );
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPER — lecture pixels pour les autres plugins
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Retourne toutes les paires pixel/token actives.
 * Utilisé par kb_capi_call_meta() pour le broadcast multi-pixel.
 *
 * @return array[] [ ['pixel_id'=>'...', 'access_token'=>'...', 'label'=>'...'], ... ]
 */
function kb_get_active_pixels(): array {
    $raw    = kb_get( 'meta_pixels', '[]' );
    $pixels = json_decode( $raw, true ) ?: [];
    return array_values( array_filter( $pixels, function( $px ) {
        return ! empty( $px['pixel_id'] ) && ! empty( $px['access_token'] ) && ! empty( $px['active'] );
    } ) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// DÉCLARATION MODULE
// ═══════════════════════════════════════════════════════════════════════════════

add_filter( 'kb_active_modules', function( $modules ) {
    $pixels = kb_get_active_pixels();
    $modules['settings'] = [
        'label'    => '⚙️ Settings v3.0',
        'features' => [
            count( $pixels ) . ' pixel(s) actif(s)',
            'Multi-livreurs COD DZ',
            'Taux DZD configurable',
        ],
    ];
    return $modules;
} );
