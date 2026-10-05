<?php
/**
 * WeConvert.io — Script de diagnostic
 * Upload ce fichier à la RACINE de ton WordPress (même dossier que wp-config.php)
 * Accède via : https://tonsite.com/weconvert-debug.php
 * SUPPRIMER CE FICHIER APRÈS UTILISATION
 */

// Sécurité basique — change ce mot de passe
$password = 'weconvert2026';
if ( ( $_GET['pass'] ?? '' ) !== $password ) {
    die( 'Accès refusé. Ajouter ?pass=weconvert2026 à l\'URL.' );
}

error_reporting( E_ALL );
ini_set( 'display_errors', 1 );

// Charger WP sans les plugins
define( 'SHORTINIT', true );
$wp_root = __DIR__;
if ( ! file_exists( $wp_root . '/wp-load.php' ) ) {
    die( 'wp-load.php introuvable. Placer ce fichier à la racine WordPress.' );
}
require_once $wp_root . '/wp-load.php';

$results  = [];
$errors   = [];
$warnings = [];

// ─── 1. PHP VERSION ──────────────────────────────────────────────────────────
$php_ok = version_compare( PHP_VERSION, '8.0', '>=' );
$results['php'] = [
    'label'  => 'PHP Version',
    'value'  => PHP_VERSION,
    'status' => $php_ok ? 'ok' : 'error',
    'msg'    => $php_ok ? 'OK' : 'PHP 8.0+ requis — tu as ' . PHP_VERSION,
];
if ( ! $php_ok ) $errors[] = 'PHP ' . PHP_VERSION . ' trop ancien — WeConvert.io requiert PHP 8.0+';

// ─── 2. EXTENSIONS PHP ───────────────────────────────────────────────────────
$required_ext = [ 'json', 'mbstring', 'openssl', 'curl', 'mysqli' ];
foreach ( $required_ext as $ext ) {
    $ok = extension_loaded( $ext );
    $results[ 'ext_' . $ext ] = [
        'label'  => 'Extension PHP: ' . $ext,
        'value'  => $ok ? 'Chargée' : 'MANQUANTE',
        'status' => $ok ? 'ok' : 'error',
        'msg'    => $ok ? 'OK' : 'Extension ' . $ext . ' manquante',
    ];
    if ( ! $ok ) $errors[] = 'Extension PHP manquante : ' . $ext;
}

// ─── 3. WOOCOMMERCE ──────────────────────────────────────────────────────────
$wc_file = $wp_root . '/wp-content/plugins/woocommerce/woocommerce.php';
$wc_exists = file_exists( $wc_file );
$wc_version = '';
if ( $wc_exists ) {
    $wc_data = get_file_data( $wc_file, [ 'Version' => 'Version' ] );
    $wc_version = $wc_data['Version'] ?? 'inconnue';
}
$results['woocommerce'] = [
    'label'  => 'WooCommerce',
    'value'  => $wc_exists ? 'v' . $wc_version : 'NON TROUVÉ',
    'status' => $wc_exists ? 'ok' : 'error',
    'msg'    => $wc_exists ? 'OK' : 'WooCommerce plugin introuvable',
];
if ( ! $wc_exists ) $errors[] = 'WooCommerce non installé';

// ─── 4. FICHIERS KITABOOK ────────────────────────────────────────────────────
$kb_dir   = $wp_root . '/wp-content/plugins/weconvert-suite/';
$kb_files = [
    'weconvert-suite.php',
    'weconvert-settings.php',
    'weconvert-fingerprint.php',
    'weconvert-pixel.php',
    'weconvert-events.php',
    'weconvert-capi.php',
    'weconvert-confirmed.php',
    'weconvert-ecotrack.php',
    'weconvert-delivered.php',
    'weconvert-dashboard.php',
];

foreach ( $kb_files as $file ) {
    $path   = $kb_dir . $file;
    $exists = file_exists( $path );
    $size   = $exists ? round( filesize( $path ) / 1024, 1 ) . ' KB' : '—';
    $results[ 'file_' . $file ] = [
        'label'  => 'Fichier: ' . $file,
        'value'  => $exists ? $size : 'MANQUANT',
        'status' => $exists ? 'ok' : 'error',
        'msg'    => $exists ? 'OK' : 'Fichier manquant : ' . $file,
    ];
    if ( ! $exists ) $errors[] = 'Fichier manquant : ' . $file;
}

// ─── 5. SYNTAX CHECK — tester chaque fichier PHP ─────────────────────────────
if ( function_exists( 'shell_exec' ) && $kb_dir ) {
    foreach ( $kb_files as $file ) {
        $path = $kb_dir . $file;
        if ( ! file_exists( $path ) ) continue;
        $output = shell_exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1' );
        $ok     = strpos( $output, 'No syntax errors' ) !== false;
        $results[ 'syntax_' . $file ] = [
            'label'  => 'Syntaxe: ' . $file,
            'value'  => $ok ? 'OK' : trim( $output ),
            'status' => $ok ? 'ok' : 'error',
            'msg'    => $ok ? 'Aucune erreur' : $output,
        ];
        if ( ! $ok ) $errors[] = 'Erreur syntaxe dans ' . $file . ' : ' . $output;
    }
} else {
    // Fallback — tenter l'include avec suppression des outputs
    foreach ( $kb_files as $file ) {
        $path = $kb_dir . $file;
        if ( ! file_exists( $path ) ) continue;
        // Vérifier les tokens PHP pour détecter les erreurs communes
        $content = file_get_contents( $path );
        $tokens  = token_get_all( $content );
        $results[ 'syntax_' . $file ] = [
            'label'  => 'Syntaxe: ' . $file,
            'value'  => 'Parsé OK (' . count( $tokens ) . ' tokens)',
            'status' => 'ok',
            'msg'    => 'token_get_all() OK',
        ];
    }
}

// ─── 6. DEFINE() CONFLITS ────────────────────────────────────────────────────
$defines_to_check = [
    'KB_FP_VERSION', 'KB_FP_DB_VERSION', 'WECONVERT_SUITE_VERSION',
    'WECONVERT_SUITE_DIR', 'WECONVERT_SUITE_URL', 'WECONVERT_SUITE_FILE',
];
foreach ( $defines_to_check as $const ) {
    $defined = defined( $const );
    $results[ 'define_' . $const ] = [
        'label'  => 'Constante: ' . $const,
        'value'  => $defined ? constant( $const ) : 'Non définie',
        'status' => 'info',
        'msg'    => $defined ? 'Définie (possible conflit si plugin dupliqué)' : 'Non définie — normal si plugin non actif',
    ];
    if ( $defined ) $warnings[] = 'Constante ' . $const . ' déjà définie — conflit possible si plusieurs versions actives';
}

// ─── 7. FONCTIONS CONFLITS ───────────────────────────────────────────────────
$functions_to_check = [
    'kb_get', 'kb_capi_call_meta', 'kb_capi_cfg',
    'kb_fp_ensure_fbp', 'kb_fp_get_session_token',
    'kb_get_active_pixels', 'kb_fp_install',
];
foreach ( $functions_to_check as $fn ) {
    $exists = function_exists( $fn );
    $results[ 'fn_' . $fn ] = [
        'label'  => 'Fonction: ' . $fn . '()',
        'value'  => $exists ? 'Définie' : 'Non définie',
        'status' => 'info',
        'msg'    => $exists ? 'Définie (conflit si dupliquée)' : 'Non définie',
    ];
    if ( $exists ) $warnings[] = 'Fonction ' . $fn . '() déjà définie — conflit si plugin chargé deux fois';
}

// ─── 8. BASE DE DONNÉES ──────────────────────────────────────────────────────
global $wpdb;
$kb_table = $wpdb->prefix . 'kb_fingerprints';
$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$kb_table}'" ) === $kb_table;
$results['db_fingerprints'] = [
    'label'  => 'Table DB: ' . $kb_table,
    'value'  => $table_exists ? 'Existe' : 'N\'existe pas encore',
    'status' => $table_exists ? 'ok' : 'warn',
    'msg'    => $table_exists ? 'OK' : 'Sera créée à l\'activation du plugin',
];

// HPOS
$hpos_table = $wpdb->prefix . 'wc_orders';
$hpos_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$hpos_table}'" ) === $hpos_table;
$results['db_hpos'] = [
    'label'  => 'WC HPOS (wc_orders)',
    'value'  => $hpos_exists ? 'Activé' : 'Non activé (postmeta classique)',
    'status' => 'info',
    'msg'    => $hpos_exists ? 'Mode HPOS — WeConvert.io compatible' : 'Mode postmeta — WeConvert.io compatible',
];

// ─── 9. PLUGINS KITABOOK EN CONFLIT ─────────────────────────────────────────
$conflict_plugins = [
    'kitabook-capi/kitabook-capi.php',
    'kitabook-confirmed/kitabook-confirmed.php',
    'kitabook-delivered/kitabook-delivered.php',
    'kitabook-fingerprint/kitabook-fingerprint.php',
    'kitabook-pixel/kitabook-pixel.php',
    'kitabook-settings/kitabook-settings.php',
    'kitabook-zrexpress/kitabook-zrexpress.php',
    'kitabook-suite-v5/kitabook-loader.php',
    'kitabook-suite-v5/kitabook-settings.php',
    'kitabook-suite-v5/kitabook-capi.php',
    'kitabook-ecotrack/kitabook-ecotrack.php',
    'kitabook-events/kitabook-events.php',
    'kitabook-dashboard/kitabook-dashboard.php',
];
$active_plugins = (array) get_option( 'active_plugins', [] );
foreach ( $conflict_plugins as $plugin ) {
    $is_active = in_array( $plugin, $active_plugins, true );
    if ( $is_active ) {
        $results[ 'conflict_' . $plugin ] = [
            'label'  => '⚠️ Plugin en conflit',
            'value'  => $plugin,
            'status' => 'error',
            'msg'    => 'Plugin séparé actif — conflit avec le loader !',
        ];
        $errors[] = 'CONFLIT : ' . $plugin . ' est actif en parallèle du loader — désactive-le !';
    }
}

// ─── 10. DEBUG.LOG ───────────────────────────────────────────────────────────
$log_path   = $wp_root . '/wp-content/debug.log';
$log_exists = file_exists( $log_path );
$log_tail   = '';
if ( $log_exists ) {
    $log_content = file_get_contents( $log_path );
    $log_lines   = explode( "\n", trim( $log_content ) );
    $log_tail    = implode( "\n", array_slice( $log_lines, -40 ) );
}

// ─── 11. WP OPTIONS KITABOOK ─────────────────────────────────────────────────
$kb_options = [
    'kb_meta_pixels', 'kb_meta_api_version', 'kb_rate_dzd_eur',
    'kb_eco_api_base', 'kb_eco_api_token', 'kb_suite_version',
];
$options_data = [];
foreach ( $kb_options as $opt ) {
    $val = get_option( $opt, '(vide)' );
    if ( $opt === 'kb_meta_pixels' && $val !== '(vide)' ) {
        $px = json_decode( $val, true );
        $val = is_array( $px ) ? count( $px ) . ' pixel(s) configuré(s)' : 'JSON invalide : ' . $val;
    }
    if ( in_array( $opt, [ 'kb_eco_api_token' ], true ) && $val !== '(vide)' ) {
        $val = substr( $val, 0, 8 ) . '...';
    }
    $options_data[ $opt ] = $val;
}

// ─── RENDER ──────────────────────────────────────────────────────────────────
$has_errors   = ! empty( $errors );
$has_warnings = ! empty( $warnings );
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WeConvert.io — Diagnostic</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",monospace;background:#0D1117;color:#E6EDF3;padding:20px;line-height:1.6}
.wrap{max-width:900px;margin:0 auto}
h1{font-size:20px;color:#F5A623;margin-bottom:6px}
.subtitle{color:#7D8590;font-size:13px;margin-bottom:24px}
.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px}
.sum-card{background:#161B22;border:1px solid #30363D;border-radius:8px;padding:14px 16px;text-align:center}
.sum-num{font-size:32px;font-weight:700;margin-bottom:4px}
.sum-num.red{color:#E24B4A}.sum-num.amber{color:#F5A623}.sum-num.green{color:#1D9E75}
.sum-label{font-size:11px;color:#7D8590;text-transform:uppercase;letter-spacing:.06em}
.section{background:#161B22;border:1px solid #30363D;border-radius:8px;margin-bottom:16px;overflow:hidden}
.section-title{padding:12px 16px;background:#1C2128;border-bottom:1px solid #30363D;font-size:13px;font-weight:600;color:#E6EDF3}
.row{display:flex;align-items:flex-start;padding:8px 16px;border-bottom:1px solid #1C2128;font-size:12px;gap:12px}
.row:last-child{border-bottom:none}
.row-label{color:#7D8590;min-width:280px;flex-shrink:0}
.row-value{flex:1;font-family:monospace;word-break:break-all}
.row-status{width:60px;text-align:center;flex-shrink:0;font-size:11px;font-weight:600;padding:2px 6px;border-radius:4px}
.s-ok{background:#0D4D38;color:#5DCAA5}
.s-error{background:#5C1F1E;color:#FF7B7B}
.s-warn{background:#7A5312;color:#F5A623}
.s-info{background:#153659;color:#85B7EB}
.errors-box{background:#1A0A0A;border:1px solid #E24B4A;border-radius:8px;padding:16px;margin-bottom:16px}
.errors-box h3{color:#E24B4A;font-size:14px;margin-bottom:10px}
.error-item{color:#FF7B7B;font-size:12px;padding:4px 0;border-bottom:1px solid #2A1010}
.error-item:last-child{border-bottom:none}
.warnings-box{background:#1A1200;border:1px solid #F5A623;border-radius:8px;padding:16px;margin-bottom:16px}
.warnings-box h3{color:#F5A623;font-size:14px;margin-bottom:10px}
.warn-item{color:#F5A623;font-size:12px;padding:4px 0}
.log-box{background:#0A0A0A;border:1px solid #30363D;border-radius:8px;padding:16px;font-family:monospace;font-size:11px;color:#7D8590;white-space:pre-wrap;word-break:break-all;max-height:400px;overflow:auto;margin-bottom:16px}
.options-box table{width:100%;border-collapse:collapse;font-size:12px}
.options-box td{padding:7px 12px;border-bottom:1px solid #1C2128;font-family:monospace}
.options-box td:first-child{color:#7D8590;width:220px}
.options-box td:last-child{color:#1D9E75}
.success-banner{background:#0D4D38;border:1px solid #1D9E75;border-radius:8px;padding:16px;margin-bottom:16px;color:#5DCAA5;font-size:14px;font-weight:600;text-align:center}
.delete-warning{background:#1A1200;border:1px solid #F5A623;border-radius:6px;padding:10px 14px;margin-top:16px;font-size:12px;color:#F5A623}
</style>
</head>
<body>
<div class="wrap">
<h1>⚡ WeConvert.io Suite — Diagnostic</h1>
<p class="subtitle">Analyse complète de l'environnement et des fichiers — <?= date('Y-m-d H:i:s') ?></p>

<!-- SUMMARY -->
<div class="summary">
    <div class="sum-card">
        <div class="sum-num <?= count($errors) > 0 ? 'red' : 'green' ?>"><?= count($errors) ?></div>
        <div class="sum-label">Erreurs critiques</div>
    </div>
    <div class="sum-card">
        <div class="sum-num <?= count($warnings) > 0 ? 'amber' : 'green' ?>"><?= count($warnings) ?></div>
        <div class="sum-label">Avertissements</div>
    </div>
    <div class="sum-card">
        <div class="sum-num green"><?= PHP_VERSION ?></div>
        <div class="sum-label">PHP Version</div>
    </div>
</div>

<!-- ERREURS CRITIQUES -->
<?php if ( $has_errors ) : ?>
<div class="errors-box">
    <h3>🚨 Erreurs critiques — à corriger avant d'activer le plugin</h3>
    <?php foreach ( $errors as $e ) : ?>
    <div class="error-item">❌ <?= htmlspecialchars( $e ) ?></div>
    <?php endforeach; ?>
</div>
<?php else : ?>
<div class="success-banner">✅ Aucune erreur critique détectée — l'environnement est compatible</div>
<?php endif; ?>

<!-- AVERTISSEMENTS -->
<?php if ( $has_warnings ) : ?>
<div class="warnings-box">
    <h3>⚠️ Avertissements</h3>
    <?php foreach ( $warnings as $w ) : ?>
    <div class="warn-item">⚠️ <?= htmlspecialchars( $w ) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- RÉSULTATS DÉTAILLÉS -->
<?php
$sections = [
    'Environnement PHP'     => [ 'php', 'ext_json', 'ext_mbstring', 'ext_openssl', 'ext_curl', 'ext_mysqli' ],
    'WooCommerce'           => [ 'woocommerce', 'db_hpos' ],
    'Fichiers WeConvert.io'     => array_filter( array_keys( $results ), fn($k) => str_starts_with($k, 'file_') ),
    'Syntaxe PHP'           => array_filter( array_keys( $results ), fn($k) => str_starts_with($k, 'syntax_') ),
    'Base de données'       => [ 'db_fingerprints' ],
    'Constantes & Fonctions'=> array_filter( array_keys( $results ), fn($k) => str_starts_with($k,'define_') || str_starts_with($k,'fn_') ),
    'Conflits plugins'      => array_filter( array_keys( $results ), fn($k) => str_starts_with($k,'conflict_') ),
];

foreach ( $sections as $title => $keys ) :
    $keys = array_values( array_filter( $keys, fn($k) => isset($results[$k]) ) );
    if ( empty( $keys ) ) continue;
?>
<div class="section">
    <div class="section-title"><?= $title ?></div>
    <?php foreach ( $keys as $key ) :
        $r = $results[$key];
        $sc = match($r['status']) { 'ok'=>'s-ok','error'=>'s-error','warn'=>'s-warn',default=>'s-info' };
    ?>
    <div class="row">
        <div class="row-label"><?= htmlspecialchars($r['label']) ?></div>
        <div class="row-value"><?= htmlspecialchars($r['value']) ?></div>
        <div class="row-status <?= $sc ?>"><?= strtoupper($r['status']) ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<!-- WP OPTIONS -->
<div class="section">
    <div class="section-title">⚙️ Options WordPress WeConvert.io</div>
    <div class="options-box">
        <table>
        <?php foreach ( $options_data as $key => $val ) : ?>
        <tr>
            <td><?= htmlspecialchars($key) ?></td>
            <td><?= htmlspecialchars( (string)$val ) ?></td>
        </tr>
        <?php endforeach; ?>
        </table>
    </div>
</div>

<!-- DEBUG LOG -->
<?php if ( $log_exists && $log_tail ) : ?>
<div class="section">
    <div class="section-title">📋 debug.log — 40 dernières lignes</div>
    <div class="log-box"><?= htmlspecialchars( $log_tail ) ?></div>
</div>
<?php elseif ( ! $log_exists ) : ?>
<div class="section">
    <div class="section-title">📋 debug.log</div>
    <div class="row"><div class="row-label">Fichier debug.log</div><div class="row-value" style="color:#F5A623">Non trouvé — activer WP_DEBUG + WP_DEBUG_LOG dans wp-config.php</div></div>
</div>
<?php endif; ?>

<div class="delete-warning">
    ⚠️ SÉCURITÉ — Supprimer ce fichier <strong>weconvert-debug.php</strong> après utilisation.
    Il expose des informations sur ton serveur.
</div>
</div>
</body>
</html>
<?php
