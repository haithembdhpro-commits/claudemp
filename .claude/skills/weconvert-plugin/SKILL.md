---
name: weconvert-plugin
description: Architecture et conventions du plugin WordPress « WeConvert.io Suite » (weconvert-suite/). À charger avant toute modification des modules Meta Pixel/CAPI, OrderConfirmed, OrderDelivered, EcoTrack, fingerprint, dashboard ou réglages, et avant de construire le zip de release.
---

# WeConvert.io Suite — guide de travail

## Structure
Un seul plugin à activer : `weconvert-suite/weconvert-suite.php`. Il charge les modules dans cet ordre (ne pas le changer sans raison) :

1. `weconvert-settings.php` — réglages + helpers globaux (`kb_get`, `kb_get_active_pixels`, conversions `kb_convert_dzd_eur/usd`)
2. `weconvert-fingerprint.php` — fbp first-party, table DB fingerprint, proxy pixel (`/weconvert/v1/fbevents.js`, `/pixel-proxy`, `/fp-collect`)
3. `weconvert-pixel.php` — PageView + ViewContent (navigateur + CAPI)
4. `weconvert-events.php` — AddToCart + InitiateCheckout (REST publics `/addtocart`, `/initiatecheckout`, `/save-fb-context`)
5. `weconvert-capi.php` — Purchase CAPI multi-pixels (hooks `woocommerce_order_status_processing` / `on-hold`)
6. `weconvert-confirmed.php` — OrderConfirmed (statut `completed`, priorité 20)
7. `weconvert-ecotrack.php` — création + validation du colis EcoTrack au statut `completed` (priorité 10), retries planifiés, poll des livraisons
8. `weconvert-delivered.php` — OrderDelivered, livreur-agnostique (cron `kb_15min` → `kb_del_poll_delivery`, action `kb_parcel_delivered`)
9. `weconvert-dashboard.php` — dashboard COD (AJAX `kb_dash_data`)

## Conventions
- Préfixes : fonctions/options/hooks `kb_*` (historique Kitabook, à conserver pour la reprise des réglages), constantes `WECONVERT_*`. Nouveau code : rester sur `kb_` pour la cohérence.
- Méta de commande : préfixe `_kb_` (ex. `_kb_capi_event_id_browser`). Toujours passer par `WC_Order` (`$order->get_meta()` / `update_meta_data()` + `save()`), jamais `get_post_meta` — la suite déclare la compatibilité HPOS.
- Versions : chaque module a un en-tête de version + changelog en commentaire. Monter la version du module modifié ET `WECONVERT_SUITE_VERSION` + `Version:` dans `weconvert-suite.php`.
- Commentaires et textes admin en français.
- PHP ≥ 8.0, WooCommerce ≥ 7.0.

## Règles métier critiques
- **Déduplication Pixel/CAPI** : chaque événement serveur doit avoir le même `event_id` que l'événement navigateur. Les `event_id` sont suffixés par pixel pour le multi-pixels ; ne jamais ré-suffixer un id déjà suffixé.
- **Purchase envoyé une seule fois** par commande (garde via méta). OrderConfirmed/OrderDelivered aussi.
- **Valeur envoyée** = valeur des produits **sans** frais de livraison, convertie avec le taux DZD configuré (marché parallèle), pas le taux officiel.
- **Données personnelles** : hasher (SHA-256, normalisation téléphone DZ `213…`) avant envoi à Meta (`kb_capi_hash*`, `kb_capi_norm_phone`).
- **EcoTrack** : pas de webhook côté EcoTrack → poll toutes les 15 min ; erreurs réseau = retry (2, 5, 15, 30, 60 min) ; erreurs de données = message rouge dans la metabox, pas de retry.

## Avant de commiter
1. `php -l` sur chaque fichier modifié (le hook PostToolUse le fait).
2. `composer lint` (PHPCS) si `vendor/` est installé.
3. Relire avec le skill `wp-security` toute route REST, action AJAX ou requête SQL touchée.

## Release
```bash
cd /path/to/repo && rm -f weconvert-suite.zip && zip -r weconvert-suite.zip weconvert-suite -x '*.DS_Store'
```
Le zip est ignoré par git (`*.zip`).
