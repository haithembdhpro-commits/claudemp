<?php
/**
 * Plugin Name: WeConvert.io Suite
 * Plugin URI:  https://weconvert.io
 * Description: COD Algérie — S-TIER Tracking Platform. Meta CAPI complet,
 *              OrderConfirmed + OrderDelivered natifs, EcoTrack (Packers) auto-colis + suivi,
 *              fingerprint first-party iOS-proof, multi-pixels illimités.
 *              Un seul plugin à activer — charge tous les modules automatiquement.
 * Version:     6.1.0
 * Author:      WeConvert.io
 * Requires WC: 7.0
 * Requires PHP: 8.0
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ORDRE DE CHARGEMENT
 * ═══════════════════════════════════════════════════════════════════════════
 * 1. weconvert-settings.php   — config + helpers globaux (kb_get, kb_get_active_pixels)
 * 2. weconvert-fingerprint.php — first-party fbp, cross-session DB, proxy pixel
 * 3. weconvert-pixel.php       — PageView + ViewContent
 * 4. weconvert-events.php      — AddToCart + InitiateCheckout
 * 5. weconvert-capi.php        — Purchase CAPI multi-pixels
 * 6. weconvert-confirmed.php   — OrderConfirmed CAPI
 * 7. weconvert-ecotrack.php    — création colis EcoTrack + poll livraisons
 * 8. weconvert-delivered.php   — OrderDelivered (livreur-agnostique)
 * 9. weconvert-dashboard.php   — COD dashboard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Ancienne « Kitabook Suite » encore active → elle a déjà chargé les mêmes modules.
// On s'arrête ici (aucune erreur fatale) et on affiche quoi faire.
if ( defined( 'KB_SUITE_FILE' ) || defined( 'WECONVERT_SUITE_FILE' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>WeConvert.io Suite</strong> : l\'ancienne extension '
            . '<strong>Kitabook Suite</strong> est encore active. Désactive-la puis supprime-la dans Extensions — '
            . 'WeConvert.io la remplace et reprend tous tes réglages automatiquement.</p></div>';
    } );
    return;
}

// ═══════════════════════════════════════════════════════════════════════════════
// CRON INTERVALS — enregistrés tôt pour être disponibles dès l'activation
// ═══════════════════════════════════════════════════════════════════════════════
add_filter( 'cron_schedules', function( $s ) {
    if ( ! isset( $s['kb_15min'] ) ) {
        $s['kb_15min'] = [ 'interval' => 900, 'display' => 'WeConvert.io — 15 minutes' ];
    }
    return $s;
} );

define( 'WECONVERT_SUITE_VERSION',  '6.1.0' );
define( 'WECONVERT_SUITE_DIR',      plugin_dir_path( __FILE__ ) );
define( 'WECONVERT_SUITE_URL',      plugin_dir_url( __FILE__ ) );
define( 'WECONVERT_SUITE_FILE',     __FILE__ );
define( 'WECONVERT_MIN_WC_VERSION', '7.0' );
define( 'WECONVERT_MIN_PHP',        '8.0' );

// Compatibilité HPOS (tables de commandes WooCommerce) — tous les modules passent par WC_Order
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// ═══════════════════════════════════════════════════════════════════════════════
// VÉRIFICATIONS PRÉREQUIS
// ═══════════════════════════════════════════════════════════════════════════════

register_activation_hook( __FILE__, 'weconvert_suite_activate' );

function weconvert_suite_activate(): void {
    weconvert_suite_check_requirements();

    // ── Fingerprint DB ────────────────────────────────────────────────────────
    // require_once ici car le module n'est pas encore chargé à ce stade
    $fp_file = WECONVERT_SUITE_DIR . 'weconvert-fingerprint.php';
    if ( file_exists( $fp_file ) ) {
        require_once $fp_file;
        if ( function_exists( 'kb_fp_install' ) ) {
            kb_fp_install();
        }
    }

    // ── Cron fingerprint cleanup ──────────────────────────────────────────────
    if ( ! wp_next_scheduled( 'kb_fp_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'kb_fp_cleanup' );
    }

    // ── Cron delivered poll ───────────────────────────────────────────────────
    wp_clear_scheduled_hook( 'kb_del_poll_zrexpress' ); // ancien hook v5
    if ( ! wp_next_scheduled( 'kb_del_poll_delivery' ) ) {
        wp_schedule_event( time(), 'kb_15min', 'kb_del_poll_delivery' );
    }
}

register_deactivation_hook( __FILE__, 'weconvert_suite_deactivate' );

function weconvert_suite_deactivate(): void {
    wp_clear_scheduled_hook( 'kb_fp_cleanup' );
    wp_clear_scheduled_hook( 'kb_del_poll_zrexpress' );
    wp_clear_scheduled_hook( 'kb_del_poll_delivery' );
}

function weconvert_suite_check_requirements(): void {
    // PHP version
    if ( version_compare( PHP_VERSION, WECONVERT_MIN_PHP, '<' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( sprintf(
            'WeConvert.io Suite requiert PHP %s ou supérieur. Vous avez PHP %s.',
            WECONVERT_MIN_PHP, PHP_VERSION
        ) );
    }

    // WooCommerce
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( 'WeConvert.io Suite requiert WooCommerce. Merci d\'installer et activer WooCommerce d\'abord.' );
    }

    // WC version
    if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, WECONVERT_MIN_WC_VERSION, '<' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( sprintf(
            'WeConvert.io Suite requiert WooCommerce %s ou supérieur.',
            WECONVERT_MIN_WC_VERSION
        ) );
    }

    // Marquer pour afficher le wizard au premier run
    update_option( 'kb_suite_show_wizard', '1' );
    update_option( 'kb_suite_installed_at', current_time( 'mysql' ) );
    update_option( 'kb_suite_version', WECONVERT_SUITE_VERSION );
}

// ═══════════════════════════════════════════════════════════════════════════════
// CHARGEMENT DES MODULES
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'plugins_loaded', 'weconvert_suite_load_modules', 5 );

function weconvert_suite_load_modules(): void {
    // WooCommerce doit être actif
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>WeConvert.io Suite</strong> requiert WooCommerce. Merci de l\'activer.</p></div>';
        } );
        return;
    }

    // Conflit réel uniquement : les mêmes fonctions déjà déclarées par un fichier HORS de ce dossier
    // (ancien plugin Kitabook séparé). Un module de ce dossier chargé deux fois est ignoré par require_once.
    $foreign = weconvert_suite_foreign_module();
    if ( $foreign ) {
        add_action( 'admin_notices', function() use ( $foreign ) {
            echo '<div class="notice notice-error"><p><strong>WeConvert.io Suite</strong> : un ancien plugin Kitabook est encore actif '
                . '(<code>' . esc_html( $foreign ) . '</code>). Désactive-le et supprime-le dans Extensions — '
                . 'WeConvert.io le remplace entièrement. La Suite est en pause en attendant pour éviter une erreur fatale.</p></div>';
        } );
        return;
    }

    $modules = [
        'weconvert-settings.php',    // 1 — helpers globaux (kb_get, kb_get_active_pixels)
        'weconvert-capi.php',        // 2 — Purchase CAPI + helpers partagés (kb_capi_call_meta multi-pixels)
                                     //     AVANT les autres : sinon leurs versions de secours simplifiées
                                     //     (un seul pixel, ancien réglage) prendraient la place
        'weconvert-fingerprint.php', // 3 — first-party fbp, DB, proxy, enrichissement
        'weconvert-pixel.php',       // 4 — PageView + ViewContent
        'weconvert-events.php',      // 5 — AddToCart + InitiateCheckout
        'weconvert-confirmed.php',   // 6 — OrderConfirmed CAPI
        'weconvert-ecotrack.php',    // 7 — EcoTrack (Packers) : colis + poll livraisons
        'weconvert-delivered.php',   // 8 — OrderDelivered (livreur-agnostique)
        'weconvert-dashboard.php',   // 9 — COD Dashboard
    ];

    foreach ( $modules as $module ) {
        $path = WECONVERT_SUITE_DIR . $module;
        if ( file_exists( $path ) ) {
            require_once $path;
        } else {
            // Log silencieux — module manquant
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                error_log( "[WeConvert.io Suite] Module manquant : {$module}" );
            }
        }
    }

    // Modules optionnels Phase 2/3 (chargés si présents)
    $optional = [
        'weconvert-youcan.php',           // Adapter YouCan
        'weconvert-delivery-maystro.php', // Driver Maystro
        'weconvert-delivery-procolis.php',// Driver Procolis
        'weconvert-delivery-guepex.php',  // Driver Guepex
    ];
    foreach ( $optional as $module ) {
        $path = WECONVERT_SUITE_DIR . $module;
        if ( file_exists( $path ) ) require_once $path;
    }
}

/**
 * Chemin (relatif à wp-content/plugins) du fichier étranger qui déclare déjà une fonction
 * de la Suite, ou '' s'il n'y a aucun conflit.
 */
function weconvert_suite_foreign_module(): string {
    $own_dir = wp_normalize_path( realpath( WECONVERT_SUITE_DIR ) );
    foreach ( [ 'kb_get', 'kb_capi_send_purchase', 'kb_fp_install', 'kb_del_send_event', 'kb_conf_fire_on_confirmed' ] as $fn ) {
        if ( ! function_exists( $fn ) ) continue;
        $file = wp_normalize_path( (string) ( new ReflectionFunction( $fn ) )->getFileName() );
        if ( dirname( $file ) !== $own_dir ) {
            return str_replace( wp_normalize_path( WP_PLUGIN_DIR ) . '/', '', $file );
        }
    }
    return '';
}

// ═══════════════════════════════════════════════════════════════════════════════
// REST — ancien espace /wp-json/kitabook/v1/* conservé comme alias
// (pages encore en cache chez les visiteurs → le tracking continue sans trou)
// ═══════════════════════════════════════════════════════════════════════════════

add_filter( 'rest_endpoints', function( $endpoints ) {
    foreach ( $endpoints as $route => $handlers ) {
        if ( str_starts_with( $route, '/weconvert/v1/' ) ) {
            $legacy = '/kitabook/v1/' . substr( $route, strlen( '/weconvert/v1/' ) );
            if ( ! isset( $endpoints[ $legacy ] ) ) $endpoints[ $legacy ] = $handlers;
        }
    }
    return $endpoints;
} );

// ═══════════════════════════════════════════════════════════════════════════════
// REDIRECT VERS WIZARD AU PREMIER RUN
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_init', 'weconvert_suite_maybe_redirect_wizard' );

function weconvert_suite_maybe_redirect_wizard(): void {
    if ( ! get_option( 'kb_suite_show_wizard' ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;
    // Éviter les redirects en boucle et les activations en masse
    if ( isset( $_GET['activate-multi'] ) ) return;
    delete_option( 'kb_suite_show_wizard' );
    wp_safe_redirect( admin_url( 'admin.php?page=weconvert-wizard' ) );
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// WIZARD ONBOARDING — 4 étapes, no-code
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', 'weconvert_wizard_menu' );

function weconvert_wizard_menu(): void {
    add_submenu_page(
        null, // Caché du menu principal
        'WeConvert.io — Setup',
        'Setup Wizard',
        'manage_options',
        'weconvert-wizard',
        'weconvert_wizard_page'
    );
}

function weconvert_wizard_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $nonce    = wp_create_nonce( 'kb_settings_nonce' );
    $ajax_url = admin_url( 'admin-ajax.php' );

    // Données actuelles pour pré-remplir
    $pixels      = function_exists( 'kb_get_active_pixels' ) ? kb_get_active_pixels() : [];
    $first_pixel = $pixels[0] ?? [ 'pixel_id' => '', 'access_token' => '', 'label' => '' ];
    $eco_base    = function_exists( 'kb_get' ) ? kb_get( 'eco_api_base', 'https://packers.ecotrack.dz' ) : 'https://packers.ecotrack.dz';
    $eco_token   = function_exists( 'kb_get' ) ? kb_get( 'eco_api_token', '' ) : '';
    $rate_eur    = function_exists( 'kb_get' ) ? kb_get( 'rate_dzd_eur', '285' ) : '285';
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WeConvert.io — Setup</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1a1a1a}
.wz-wrap{max-width:680px;margin:40px auto;padding:0 16px 60px}
/* Header */
.wz-header{text-align:center;margin-bottom:32px}
.wz-logo{display:inline-flex;align-items:center;gap:10px;background:#0D1117;color:#F5A623;padding:10px 20px;border-radius:10px;margin-bottom:16px}
.wz-logo span{font-size:20px}
.wz-logo h1{font-size:16px;font-weight:700;letter-spacing:.04em}
.wz-subtitle{font-size:14px;color:#666}
/* Progress */
.wz-progress{display:flex;align-items:center;margin-bottom:32px;background:#fff;border-radius:10px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.wz-step{display:flex;align-items:center;gap:8px;flex:1;position:relative}
.wz-step:not(:last-child)::after{content:'';position:absolute;right:0;top:50%;transform:translateY(-50%);width:calc(100% - 120px);height:1px;background:#e0e0e0;margin-left:60px}
.wz-step-num{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;transition:all .3s}
.wz-step-num.done{background:#1D9E75;color:#fff}
.wz-step-num.active{background:#0D1117;color:#F5A623}
.wz-step-num.todo{background:#f0f0f0;color:#999}
.wz-step-label{font-size:11px;font-weight:600;white-space:nowrap}
.wz-step-label.active{color:#0D1117}
.wz-step-label.done{color:#1D9E75}
.wz-step-label.todo{color:#999}
/* Card */
.wz-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;margin-bottom:16px}
.wz-card-header{padding:20px 24px;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;gap:12px}
.wz-card-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.wz-card-title{font-size:16px;font-weight:700}
.wz-card-desc{font-size:12px;color:#888;margin-top:2px}
.wz-card-body{padding:24px}
/* Form */
.wz-field{margin-bottom:18px}
.wz-field label{display:block;font-size:12px;font-weight:700;color:#444;margin-bottom:7px;text-transform:uppercase;letter-spacing:.05em}
.wz-field input{width:100%;padding:11px 14px;border:1.5px solid #e0e0e0;border-radius:8px;font-size:14px;font-family:inherit;transition:border .15s;background:#fafafa}
.wz-field input:focus{outline:none;border-color:#0D1117;background:#fff;box-shadow:0 0 0 3px rgba(13,17,23,.06)}
.wz-field input.mono{font-family:'SF Mono','Fira Code',monospace;font-size:13px}
.wz-field-hint{font-size:11px;color:#999;margin-top:6px;line-height:1.5}
.wz-field-hint a{color:#1a73e8;text-decoration:none}
.wz-field-hint a:hover{text-decoration:underline}
/* Result */
.wz-result{padding:12px 16px;border-radius:8px;font-size:13px;font-weight:500;margin-top:12px;display:none}
.wz-result.ok{background:#e8f5e9;color:#2e7d32;border:1px solid #c8e6c9;display:block}
.wz-result.err{background:#fce4e4;color:#c62828;border:1px solid #ef9a9a;display:block}
.wz-result.info{background:#e3f2fd;color:#1565c0;border:1px solid #90caf9;display:block}
/* Nav */
.wz-nav{display:flex;gap:10px;margin-top:20px}
.wz-btn{padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;border:none;transition:all .15s;display:inline-flex;align-items:center;gap:8px}
.wz-btn-primary{background:#0D1117;color:#F5A623;flex:1;justify-content:center}
.wz-btn-primary:hover{background:#1a2332}
.wz-btn-secondary{background:#f0f0f0;color:#444}
.wz-btn-secondary:hover{background:#e0e0e0}
.wz-btn-test{background:#fff;color:#0D1117;border:1.5px solid #0D1117}
.wz-btn-test:hover{background:#0D1117;color:#F5A623}
.wz-btn.loading{opacity:.6;pointer-events:none}
.wz-btn-green{background:#1D9E75;color:#fff;flex:1;justify-content:center}
.wz-btn-green:hover{background:#178a63}
/* Rate preview */
.wz-rate-box{background:linear-gradient(135deg,#0D1117,#1a2332);border-radius:8px;padding:16px 20px;color:#fff;margin-top:14px}
.wz-rate-row{display:flex;justify-content:space-between;padding:4px 0;font-size:13px}
.wz-rate-label{color:#aaa}
.wz-rate-val{font-family:'SF Mono','Fira Code',monospace;color:#F5A623;font-weight:600}
/* Webhook */
.wz-webhook{background:#0D1117;color:#F5A623;font-family:'SF Mono','Fira Code',monospace;font-size:12px;padding:14px 16px;border-radius:8px;word-break:break-all;cursor:pointer;transition:opacity .15s;margin:12px 0}
.wz-webhook:hover{opacity:.9}
/* Final checklist */
.wz-check-list{list-style:none}
.wz-check-item{display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-bottom:1px solid #f5f5f5}
.wz-check-item:last-child{border-bottom:none}
.wz-check-icon{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;margin-top:1px}
.wz-check-icon.ok{background:#e8f5e9}
.wz-check-icon.warn{background:#fff3e0}
.wz-check-label{font-size:13px;font-weight:600;color:#1a1a1a}
.wz-check-sub{font-size:11px;color:#888;margin-top:2px}
/* Steps hidden */
.wz-step-panel{display:none}.wz-step-panel.active{display:block}
/* Mobile */
@media(max-width:500px){.wz-step-label{display:none}.wz-step:not(:last-child)::after{width:calc(100% - 60px)}}
</style>
</head>
<body>
<div class="wz-wrap">

    <!-- Header -->
    <div class="wz-header">
        <div class="wz-logo"><span>⚡</span><h1>WECONVERT.IO SUITE</h1></div>
        <div class="wz-subtitle">Configuration guidée — 4 étapes pour tout activer</div>
    </div>

    <!-- Progress bar -->
    <div class="wz-progress">
        <div class="wz-step">
            <div class="wz-step-num active" id="num-1">1</div>
            <div class="wz-step-label active" id="lbl-1">Meta CAPI</div>
        </div>
        <div class="wz-step">
            <div class="wz-step-num todo" id="num-2">2</div>
            <div class="wz-step-label todo" id="lbl-2">Taux DZD</div>
        </div>
        <div class="wz-step">
            <div class="wz-step-num todo" id="num-3">3</div>
            <div class="wz-step-label todo" id="lbl-3">EcoTrack</div>
        </div>
        <div class="wz-step">
            <div class="wz-step-num todo" id="num-4">4</div>
            <div class="wz-step-label todo" id="lbl-4">Vérification</div>
        </div>
    </div>

    <!-- ── ÉTAPE 1 — META CAPI ──────────────────────────────────────── -->
    <div class="wz-step-panel active" id="wz-step-1">
        <div class="wz-card">
            <div class="wz-card-header">
                <div class="wz-card-icon" style="background:#e3f2fd">🎯</div>
                <div>
                    <div class="wz-card-title">Meta Conversions API</div>
                    <div class="wz-card-desc">Connecter ton pixel Meta pour envoyer les events côté serveur</div>
                </div>
            </div>
            <div class="wz-card-body">
                <div class="wz-field">
                    <label>Pixel ID</label>
                    <input type="text" id="wz-pixel-id" class="mono" placeholder="ex: 1234567890123456"
                        value="<?= esc_attr( $first_pixel['pixel_id'] ) ?>">
                    <div class="wz-field-hint">
                        Business Manager → <strong>Events Manager</strong> → Ton pixel → Paramètres → ID du pixel
                    </div>
                </div>
                <div class="wz-field">
                    <label>Access Token CAPI</label>
                    <input type="text" id="wz-token" class="mono" placeholder="EAAPs...">
                    <div class="wz-field-hint">
                        Events Manager → Ton pixel → Paramètres → <strong>Conversions API</strong> →
                        <a href="https://developers.facebook.com/docs/marketing-api/conversions-api/get-started" target="_blank">Générer un token d'accès</a>
                    </div>
                </div>
                <div class="wz-field">
                    <label>Label du pixel (optionnel)</label>
                    <input type="text" id="wz-pixel-label" placeholder="ex: Ma boutique principale, BM Cosmétiques..."
                        value="<?= esc_attr( $first_pixel['label'] ) ?>">
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <button class="wz-btn wz-btn-test" onclick="wzTestMeta()">🧪 Tester la connexion</button>
                </div>
                <div class="wz-result" id="wz-meta-result"></div>
            </div>
        </div>
        <div class="wz-nav">
            <button class="wz-btn wz-btn-primary" onclick="wzNext(1)">Suivant — Taux DZD →</button>
        </div>
    </div>

    <!-- ── ÉTAPE 2 — TAUX DZD ───────────────────────────────────────── -->
    <div class="wz-step-panel" id="wz-step-2">
        <div class="wz-card">
            <div class="wz-card-header">
                <div class="wz-card-icon" style="background:#fff3e0">💱</div>
                <div>
                    <div class="wz-card-title">Taux de change DZD</div>
                    <div class="wz-card-desc">Critique pour que Meta voie les vraies valeurs de tes commandes</div>
                </div>
            </div>
            <div class="wz-card-body">
                <div style="background:#fff3e0;border:1px solid #ffe0b2;border-radius:8px;padding:14px 16px;margin-bottom:20px;font-size:13px;color:#bf360c">
                    <strong>⚠️ Pourquoi c'est important :</strong> Meta utilise le taux officiel (~156 DZD/EUR).
                    Avec ce taux, une commande de 3 500 DZD = 22 EUR dans Meta.
                    Avec le taux réel (285), elle = 12 EUR. L'algo optimise sur les vraies valeurs → meilleur ROAS.
                </div>
                <div class="wz-field">
                    <label>1 EUR = combien de DZD ? (taux marché)</label>
                    <input type="number" id="wz-rate-eur" value="<?= esc_attr( $rate_eur ) ?>" step="0.5" min="100" max="999" oninput="wzUpdateRatePreview()">
                    <div class="wz-field-hint">Vérifier sur <strong>Algérie Change</strong> ou Bureau de change local. Mettre à jour régulièrement.</div>
                </div>
                <div class="wz-rate-box" id="wz-rate-preview">
                    <div style="font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Aperçu — conversions envoyées à Meta</div>
                    <div class="wz-rate-row"><span class="wz-rate-label">2 500 DZD</span><span class="wz-rate-val" id="wr-2500">—</span></div>
                    <div class="wz-rate-row"><span class="wz-rate-label">5 000 DZD</span><span class="wz-rate-val" id="wr-5000">—</span></div>
                    <div class="wz-rate-row"><span class="wz-rate-label">12 000 DZD</span><span class="wz-rate-val" id="wr-12000">—</span></div>
                    <div class="wz-rate-row"><span class="wz-rate-label">25 000 DZD</span><span class="wz-rate-val" id="wr-25000">—</span></div>
                </div>
            </div>
        </div>
        <div class="wz-nav">
            <button class="wz-btn wz-btn-secondary" onclick="wzGoto(1)">← Retour</button>
            <button class="wz-btn wz-btn-primary" onclick="wzNext(2)">Suivant — EcoTrack →</button>
        </div>
    </div>

    <!-- ── ÉTAPE 3 — ECOTRACK ───────────────────────────────────────── -->
    <div class="wz-step-panel" id="wz-step-3">
        <div class="wz-card">
            <div class="wz-card-header">
                <div class="wz-card-icon" style="background:#e8f5e9">🚚</div>
                <div>
                    <div class="wz-card-title">EcoTrack — Packers</div>
                    <div class="wz-card-desc">Création automatique des colis + event OrderDelivered quand le colis est livré</div>
                </div>
            </div>
            <div class="wz-card-body">
                <div class="wz-field">
                    <label>URL plateforme EcoTrack</label>
                    <input type="text" id="wz-eco-base" class="mono" placeholder="https://packers.ecotrack.dz"
                        value="<?= esc_attr( $eco_base ) ?>">
                </div>
                <div class="wz-field">
                    <label>Token API EcoTrack</label>
                    <input type="password" id="wz-eco-token" class="mono" placeholder="Compte EcoTrack → Paramètres → API" autocomplete="off"
                        value="<?= esc_attr( $eco_token ) ?>">
                    <div class="wz-field-hint">Compte expéditeur EcoTrack → Paramètres → API → Générer un token</div>
                </div>

                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
                    <button class="wz-btn wz-btn-test" onclick="wzTestEco()">🔍 Tester EcoTrack</button>
                </div>
                <div class="wz-result" id="wz-eco-result"></div>
                <div style="font-size:11px;color:#999;margin-top:8px">Pas de webhook à configurer : WeConvert.io lit le statut des colis toutes les 15 min.</div>
            </div>
        </div>
        <div class="wz-nav">
            <button class="wz-btn wz-btn-secondary" onclick="wzGoto(2)">← Retour</button>
            <button class="wz-btn wz-btn-primary" onclick="wzNext(3)">Suivant — Vérification →</button>
        </div>
    </div>

    <!-- ── ÉTAPE 4 — VÉRIFICATION ────────────────────────────────────── -->
    <div class="wz-step-panel" id="wz-step-4">
        <div class="wz-card">
            <div class="wz-card-header">
                <div class="wz-card-icon" style="background:#f3e5f5">✅</div>
                <div>
                    <div class="wz-card-title">Vérification finale</div>
                    <div class="wz-card-desc">Récapitulatif de ta configuration WeConvert.io</div>
                </div>
            </div>
            <div class="wz-card-body">
                <ul class="wz-check-list" id="wz-checklist">
                    <!-- Rempli par JS -->
                </ul>
            </div>
        </div>

        <div class="wz-card">
            <div class="wz-card-header">
                <div class="wz-card-icon" style="background:#fce4e4">🧪</div>
                <div>
                    <div class="wz-card-title">Events Meta à surveiller</div>
                    <div class="wz-card-desc">Dans Meta Events Manager → Test Events — passe une commande test pour tout vérifier</div>
                </div>
            </div>
            <div class="wz-card-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <?php foreach ( [
                        ['PageView',          '📄', 'Chargement page'],
                        ['ViewContent',       '👁️', 'Fiche produit'],
                        ['AddToCart',         '🛒', 'Ajout panier'],
                        ['InitiateCheckout',  '📋', 'Ouverture checkout'],
                        ['Purchase',          '💳', 'Commande soumise'],
                        ['OrderConfirmed',    '✅', 'Call center confirme'],
                        ['OrderDelivered',    '📦', 'Cash encaissé'],
                    ] as [$name, $icon, $desc] ) : ?>
                    <div style="background:#fafafa;border:1px solid #e8e8e8;border-radius:8px;padding:10px 12px;display:flex;align-items:center;gap:8px">
                        <span style="font-size:16px"><?= $icon ?></span>
                        <div>
                            <div style="font-size:12px;font-weight:700;color:#1a1a1a"><?= $name ?></div>
                            <div style="font-size:10px;color:#888"><?= $desc ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="wz-nav">
            <button class="wz-btn wz-btn-secondary" onclick="wzGoto(3)">← Retour</button>
            <button class="wz-btn wz-btn-green" onclick="wzFinish()">
                🚀 Terminer & Aller au Dashboard
            </button>
        </div>
    </div>

</div><!-- /.wz-wrap -->

<script>
var KB_AJAX  = '<?php echo esc_js( $ajax_url ); ?>';
var KB_NONCE = '<?php echo esc_js( $nonce ); ?>';
var wzData   = { pixel_id:'', token:'', label:'', rate_eur:'<?= esc_js( $rate_eur ) ?>', eco_base:'', eco_token:'' };

// ── Step navigation ──────────────────────────────────────────────────────
function wzGoto(step) {
    document.querySelectorAll('.wz-step-panel').forEach(function(p){ p.classList.remove('active'); });
    document.getElementById('wz-step-' + step).classList.add('active');
    for (var i = 1; i <= 4; i++) {
        var num = document.getElementById('num-' + i);
        var lbl = document.getElementById('lbl-' + i);
        if (i < step)       { num.className='wz-step-num done';   lbl.className='wz-step-label done';   num.textContent='✓'; }
        else if (i === step){ num.className='wz-step-num active'; lbl.className='wz-step-label active'; num.textContent=i; }
        else                { num.className='wz-step-num todo';   lbl.className='wz-step-label todo';   num.textContent=i; }
    }
    window.scrollTo({ top:0, behavior:'smooth' });
}

function wzNext(from) {
    // Collecte et save avant de passer à la suite
    wzCollect();
    wzSaveStep(from, function() { wzGoto(from + 1); if (from + 1 === 4) wzBuildChecklist(); });
}

function wzCollect() {
    wzData.pixel_id  = document.getElementById('wz-pixel-id').value.trim();
    wzData.token     = document.getElementById('wz-token').value.trim();
    wzData.label     = document.getElementById('wz-pixel-label').value.trim();
    wzData.rate_eur  = document.getElementById('wz-rate-eur').value.trim();
    wzData.eco_base  = document.getElementById('wz-eco-base').value.trim();
    wzData.eco_token = document.getElementById('wz-eco-token').value.trim();
}

function wzSaveStep(step, cb) {
    var pixels = [];
    if (wzData.pixel_id && wzData.token) {
        pixels.push({ label: wzData.label || 'Principal', pixel_id: wzData.pixel_id, access_token: wzData.token, active: true });
    }
    var fd = new FormData();
    fd.append('action',       'kb_save_settings');
    fd.append('nonce',        KB_NONCE);
    fd.append('meta_pixels',  JSON.stringify(pixels));
    fd.append('meta_api_version', 'v19.0');
    fd.append('meta_test_code',   '');
    fd.append('rate_dzd_eur', wzData.rate_eur || '285');
    fd.append('rate_dzd_usd', '345');
    fd.append('eco_api_base',      wzData.eco_base || 'https://packers.ecotrack.dz');
    fd.append('eco_api_token',     wzData.eco_token);
    fd.append('eco_lookback_days', '<?= esc_js( kb_get( 'eco_lookback_days', '30' ) ) ?>');
    fd.append('eco_ref_prefix',    '<?= esc_js( kb_get( 'eco_ref_prefix', '#' ) ) ?>');
    fd.append('eco_desk_keywords', '<?= esc_js( kb_get( 'eco_desk_keywords', 'stop desk,stopdesk,bureau,agence,point relais,مكتب' ) ) ?>');
    fd.append('eco_auto_create',   '<?= esc_js( kb_get( 'eco_auto_create', '1' ) ) ?>');
    fd.append('eco_auto_ship',     '<?= esc_js( kb_get( 'eco_auto_ship', '1' ) ) ?>');
    fd.append('eco_ask_collection','<?= esc_js( kb_get( 'eco_ask_collection', '0' ) ) ?>');
    fd.append('del_autocomplete',  '<?= esc_js( kb_get( 'del_autocomplete', '1' ) ) ?>');
    fd.append('maystro_api_key', ''); fd.append('maystro_store_id', ''); fd.append('maystro_enabled', '');
    fd.append('procolis_api_key', ''); fd.append('procolis_enabled', '');
    fd.append('guepex_api_key', ''); fd.append('guepex_enabled', '');
    fd.append('default_delivery_provider', 'ecotrack');
    fetch(KB_AJAX, { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(res){ if (cb) cb(res); })
        .catch(function(){ if (cb) cb(null); });
}

// ── Rate preview ─────────────────────────────────────────────────────────
function wzUpdateRatePreview() {
    var rate = parseFloat(document.getElementById('wz-rate-eur').value) || 285;
    [2500,5000,12000,25000].forEach(function(dzd){
        var el = document.getElementById('wr-' + dzd);
        if (el) el.textContent = (Math.round(dzd/rate*100)/100).toFixed(2) + ' EUR';
    });
}
wzUpdateRatePreview();

// ── Test Meta ────────────────────────────────────────────────────────────
function wzTestMeta() {
    var pid = document.getElementById('wz-pixel-id').value.trim();
    var tok = document.getElementById('wz-token').value.trim();
    var res = document.getElementById('wz-meta-result');
    if (!pid || !tok) { wzShowResult(res,'err','Pixel ID et Access Token requis'); return; }
    wzShowResult(res,'info','⏳ Test en cours...');
    var fd = new FormData();
    fd.append('action','kb_test_meta'); fd.append('nonce',KB_NONCE);
    fd.append('pixel_id',pid); fd.append('token',tok); fd.append('api_ver','v19.0'); fd.append('test_code','');
    fetch(KB_AJAX,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(r){
        if(r.success) {
            var name = r.data.pixel_name ? ' — <strong>' + r.data.pixel_name + '</strong>' : '';
            wzShowResult(res,'ok','✅ Connexion Meta réussie' + name + ' | Pixel ID: ' + pid);
        } else { wzShowResult(res,'err',r.data.message); }
    }).catch(function(){ wzShowResult(res,'err','Erreur réseau'); });
}

// ── Test EcoTrack ────────────────────────────────────────────────────────
function wzTestEco() {
    var base  = document.getElementById('wz-eco-base').value.trim();
    var token = document.getElementById('wz-eco-token').value.trim();
    var res   = document.getElementById('wz-eco-result');
    if (!base||!token){ wzShowResult(res,'err','URL et token requis'); return; }
    wzShowResult(res,'info','⏳ Test EcoTrack...');
    var fd = new FormData();
    fd.append('action','kb_test_eco'); fd.append('nonce',KB_NONCE);
    fd.append('token',token); fd.append('api_base',base);
    fetch(KB_AJAX,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(r){
        if(r.success) wzShowResult(res,'ok',r.data.message);
        else wzShowResult(res,'err',r.data.message);
    }).catch(function(){ wzShowResult(res,'err','Erreur réseau'); });
}

// ── Copy ─────────────────────────────────────────────────────────────────
function wzCopy(el) {
    navigator.clipboard.writeText(el.textContent.trim()).then(function(){
        el.style.background='#1D9E75';
        setTimeout(function(){ el.style.background=''; }, 1200);
    });
}

// ── Checklist finale ─────────────────────────────────────────────────────
function wzBuildChecklist() {
    wzCollect();
    var items = [
        { ok: !!wzData.pixel_id && !!wzData.token, label:'Meta Pixel ID + Token CAPI', sub: wzData.pixel_id ? 'Pixel: ' + wzData.pixel_id : 'Non configuré' },
        { ok: parseFloat(wzData.rate_eur) > 100,   label:'Taux DZD/EUR', sub: '1 EUR = ' + (wzData.rate_eur||'285') + ' DZD (taux marché)' },
        { ok: !!wzData.eco_token, label:'EcoTrack (Packers)', sub: wzData.eco_token ? 'Token configuré — ' + (wzData.eco_base||'') : 'Non configuré — optionnel au départ' },
        { ok: true, label:'Fingerprint S-TIER', sub:'First-party fbp iOS-proof + cross-session DB actif' },
        { ok: true, label:'Events COD natifs', sub:'OrderConfirmed + OrderDelivered — exclusifs WeConvert.io' },
        { ok: true, label:'Dashboard COD', sub:'Entonnoir Purchase→Confirmed→Delivered actif' },
    ];
    var html = '';
    items.forEach(function(item){
        html += '<li class="wz-check-item">'
            + '<div class="wz-check-icon ' + (item.ok?'ok':'warn') + '">' + (item.ok?'✅':'⚠️') + '</div>'
            + '<div><div class="wz-check-label">' + item.label + '</div>'
            + '<div class="wz-check-sub">' + item.sub + '</div></div>'
            + '</li>';
    });
    document.getElementById('wz-checklist').innerHTML = html;
}

// ── Finish ───────────────────────────────────────────────────────────────
function wzFinish() {
    wzCollect();
    wzSaveStep(4, function() {
        window.location.href = '<?php echo esc_js( admin_url( "admin.php?page=weconvert-dashboard" ) ); ?>';
    });
}

// ── Helper ───────────────────────────────────────────────────────────────
function wzShowResult(el, type, msg) {
    el.className = 'wz-result ' + type;
    el.innerHTML = msg;
    el.style.display = 'block';
}
</script>
</body>
</html>
<?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// ADMIN NOTICE — si WooCommerce désactivé après coup
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_notices', 'weconvert_suite_wc_notice' );

function weconvert_suite_wc_notice(): void {
    if ( class_exists( 'WooCommerce' ) ) return;
    echo '<div class="notice notice-error"><p>'
        . '<strong>WeConvert.io Suite</strong> requiert WooCommerce actif. '
        . 'Les modules sont désactivés jusqu\'à la réactivation de WooCommerce.'
        . '</p></div>';
}

// ═══════════════════════════════════════════════════════════════════════════════
// INFOS PLUGIN — page Plugins WP
// ═══════════════════════════════════════════════════════════════════════════════

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'weconvert_suite_action_links' );

function weconvert_suite_action_links( array $links ): array {
    $custom = [
        '<a href="' . admin_url( 'admin.php?page=weconvert-settings' ) . '">⚙️ Settings</a>',
        '<a href="' . admin_url( 'admin.php?page=weconvert-dashboard' ) . '">📊 Dashboard</a>',
        '<a href="' . admin_url( 'admin.php?page=weconvert-wizard' ) . '">🚀 Setup Wizard</a>',
    ];
    return array_merge( $custom, $links );
}
