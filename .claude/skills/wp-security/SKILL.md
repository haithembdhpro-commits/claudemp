---
name: wp-security
description: Checklist de sécurité WordPress/WooCommerce (nonces, capacités, échappement, SQL préparé, REST publics, AJAX, secrets). À utiliser pour écrire ou relire du PHP de plugin, en particulier toute route REST, action AJAX, page d'admin ou requête $wpdb.
---

# Sécurité WordPress — checklist

## Entrées
- Toujours `wp_unslash()` puis assainir : `sanitize_text_field`, `sanitize_email`, `absint`, `sanitize_key`, `esc_url_raw`, `wc_clean`.
- Valider le format (regex téléphone, liste blanche de valeurs) avant usage.
- REST : déclarer `args` avec `sanitize_callback` / `validate_callback`.

## Sorties
- Échapper au dernier moment : `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`, `esc_js`, `wp_json_encode` pour injecter du JSON dans un `<script>`.

## Autorisation
- Admin / AJAX privé : `current_user_can( 'manage_woocommerce' )` (ou `manage_options`) **et** `check_ajax_referer()` / `wp_verify_nonce()`.
- Ne jamais enregistrer `wp_ajax_nopriv_*` pour une action qui écrit ou lit des données sensibles.
- REST : `permission_callback => '__return_true'` uniquement pour les endpoints de tracking publics. Ils doivent alors :
  - n'accepter que des données non sensibles,
  - limiter la taille du payload et le débit (transient par IP/fbp),
  - ne jamais renvoyer de données d'autres utilisateurs ni de secrets,
  - vérifier que l'ID produit/commande existe et est cohérent.

## SQL
- Toujours `$wpdb->prepare()` avec `%s/%d/%f` ; noms de table via `$wpdb->prefix`, jamais depuis l'entrée utilisateur.
- `dbDelta` pour créer/mettre à jour les tables.

## Secrets
- Tokens (Meta, EcoTrack) dans les options, jamais en dur ni dans le JS front ni dans les logs.
- Masquer les tokens dans l'admin (afficher les 4 derniers caractères).

## Requêtes sortantes
- `wp_remote_post/get` avec `timeout` explicite, vérifier `is_wp_error()` et le code HTTP, ne pas désactiver `sslverify`.

## Fichiers
- `if ( ! defined( 'ABSPATH' ) ) exit;` en tête de chaque fichier.
