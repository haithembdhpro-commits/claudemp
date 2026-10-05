# WeConvert.io Suite v6.1

**Stack de tracking COD Algérie — Meta Conversions API (CAPI) + EcoTrack (Packers)**

---

## Plugins inclus

| Fichier | Rôle | Version |
|---|---|---|
| `weconvert-settings.php` | Config centralisée (pixel, taux DZD, credentials livreurs) | 2.0.0 |
| `weconvert-fingerprint.php` | Fingerprint navigateur → améliore le Match Rate EMQ | — |
| `weconvert-pixel.php` | PageView + ViewContent (browser + CAPI) | 1.0.0 |
| `weconvert-events.php` | AddToCart + InitiateCheckout (browser + CAPI) | **1.0.0 NEW** |
| `weconvert-capi.php` | Purchase CAPI (server-side + browser, dédupliqué) | 2.4.0 |
| `weconvert-confirmed.php` | OrderConfirmed CAPI (COD natif — call center confirme) | 2.1.0 |
| `weconvert-ecotrack.php` | Commande completed → colis créé + validé chez Packers · poll livraisons 15 min | **6.1.0** |
| `weconvert-delivered.php` | OrderDelivered CAPI (livreur-agnostique) | 3.0.0 |

---

## Installation ⚠️

**Une seule extension à activer : « WeConvert.io Suite »** (`weconvert-suite/weconvert-suite.php`).
Elle charge elle-même tous les modules dans le bon ordre — les modules n'apparaissent plus
séparément dans Extensions.

**Migration depuis Kitabook** :
1. Extensions → désactiver **toutes** les entrées « Kitabook » (Suite + modules activés un par un)
2. Supprimer l'extension Kitabook
3. Téléverser `weconvert-suite.zip` → activer **WeConvert.io Suite**
4. Tous les réglages (pixels, taux DZD, token EcoTrack) sont repris automatiquement

Si une ancienne version Kitabook reste active, WeConvert.io se met en pause avec un message
indiquant le fichier exact à désactiver (au lieu d'une erreur fatale).

---

## Configuration initiale

**WordPress Admin → WeConvert.io → Settings**

### Meta CAPI
- **Pixel ID** : Business Manager → Events Manager → Ton pixel → Paramètres
- **Access Token** : Events Manager → Ton pixel → Paramètres → Conversions API → Générer un token
- **Test Event Code** : vide en production, `TEST12345` pendant les tests

### Taux de change DZD
- **1 EUR = X DZD** : taux marché parallèle (ex: 285)
- ⚠️ WeTracked utilise le taux officiel Meta (~156 DZD/EUR) — ce qui coupe ton ROAS en deux.
  WeConvert.io utilise le taux réel configuré ici.

### EcoTrack (Packers)
- **URL plateforme** : `https://packers.ecotrack.dz` (toute société EcoTrack fonctionne)
- **Token API** : compte expéditeur EcoTrack → Paramètres → API
- Bouton **Tester la connexion EcoTrack** → vérifie le token + compte les colis
- Aucun webhook à configurer : EcoTrack n'en propose pas, WeConvert.io lit l'API toutes les 15 min
- **Même logique que ZR Express** : commande → `completed` ⇒ colis créé **et validé** sur Packers (transféré à la société de livraison), sans action manuelle
- API indisponible / saturée ⇒ nouvel essai automatique (2, 5, 15, 30, 60 min), note sur la commande
- Données invalides (wilaya, téléphone…) ⇒ erreur rouge dans la metabox EcoTrack de la commande, corriger puis « Créer le colis »
- Options : création auto, validation auto (ON par défaut), demande de ramassage, préfixe référence (`#`), mots-clés stop desk
- Outils → **EcoTrack** : poll manuel, liste des wilayas, vider le cache
- Metabox **🚚 EcoTrack** sur chaque commande : tracking, étiquette PDF, expédier, créer le colis

---

## Flux complet d'events

```
Visiteur arrive
    → PageView (browser fbq)

Visiteur voit un produit
    → ViewContent (browser fbq + CAPI server via REST endpoint)
      event_id : kb_vc_{product_id}_{minute}

Visiteur ajoute au panier
    → AddToCart (browser fbq + CAPI server via REST endpoint)
      event_id : kb_atc_{product_id}_{minute}

Visiteur ouvre le checkout
    → InitiateCheckout (browser fbq + CAPI server via REST endpoint)
      Sauvegarde fbp/fbc/external_id en session WC
      event_id : kb_ic_{cart_hash}_{pixel_id}

Visiteur commande (submit checkout)
    → Purchase (CAPI server-side — weconvert-capi.php priority 10)
      + Purchase (browser fbq — thank-you page footer priority 20)
      Sauvegarde _kb_fbp, _kb_fbc, _kb_client_ip, _kb_client_ua en order meta
      event_id : kb_{order_id}_{12_chars}

Agent call center confirme la commande
    → WooCommerce order status → completed
    → OrderConfirmed (CAPI server-side — weconvert-confirmed.php priority 20)
      event_id : kb_conf_{order_id}_{12_chars}
    → EcoTrack crée le colis (weconvert-ecotrack.php priority 10)
      référence = #N° commande · anti-doublon si le colis existe déjà

Livreur remet le colis
    → cron 15 min : GET /api/v1/get/orders → global_status = "livre"
    → do_action('kb_parcel_delivered', $parcel_normalisé)
    → OrderDelivered (CAPI server-side — weconvert-delivered.php)
      event_id : kb_del_{order_id}_{12_chars}
      event_time = livred_at réel (heure d'Alger → UTC)
      livraisons > 7 jours : marquées mais non envoyées (limite Meta CAPI)
```

**Funnel Meta Events complet :**
`PageView → ViewContent → AddToCart → InitiateCheckout → Purchase → OrderConfirmed → OrderDelivered`

**WeTracked** : `PageView → ViewContent → Purchase` (3 events, pas de COD natif)
**WeConvert.io v5** : 7 events dont 2 exclusifs COD Algérie ✅

---

## Déduplication

Chaque event utilise un `event_id` déterministe partagé entre le browser (`fbq()`) et le CAPI server-side. Meta fusionne automatiquement les deux occurrences du même `event_id` — zéro doublon dans les rapports.

| Event | Strategy |
|---|---|
| PageView | browser seulement (volume trop élevé pour CAPI) |
| ViewContent | event_id par produit + pixel + minute |
| AddToCart | event_id par produit + minute + pageload |
| InitiateCheckout | event_id par cart_hash + pixel (stable tant que panier inchangé) |
| Purchase | event_id sauvé en DB (`_kb_capi_event_id`) + guard `_kb_capi_purchase_sent` |
| OrderConfirmed | event_id sauvé en DB + guard `_kb_capi_confirmed_sent` |
| OrderDelivered | event_id sauvé en DB + guard `_kb_capi_delivered_sent` |

---

## Guards anti-double-envoi

Chaque plugin sauvegarde un meta WooCommerce après l'envoi CAPI :

| Meta | Plugin | Signification |
|---|---|---|
| `_kb_capi_purchase_sent` | capi.php | Purchase envoyé (datetime) |
| `_kb_capi_confirmed_sent` | confirmed.php | OrderConfirmed envoyé |
| `_kb_capi_delivered_sent` | delivered.php | OrderDelivered envoyé |
| `_kb_fbp` | capi.php | Cookie `_fbp` sauvé au checkout |
| `_kb_fbc` | capi.php | Cookie `_fbc` sauvé au checkout |
| `_kb_client_ip` | capi.php | IP réelle au moment du checkout |
| `_kb_client_ua` | capi.php | User-Agent au moment du checkout |
| `_kb_external_id` | capi.php | Hash fingerprint |
| `_kb_capi_event_id` | capi.php | event_id Purchase (pour déduplication browser) |
| `_kb_eco_tracking` | ecotrack.php | Tracking colis EcoTrack |
| `_kb_eco_returned` | ecotrack.php | Colis en retour (date) |
| `_kb_capi_delivered_skipped` | delivered.php | Livré > 7 jours, non envoyé |

---

## Architecture multi-livreurs (Phase 2 — roadmap)

La v6 pose les bases :
- Cron `kb_del_poll_delivery` → `do_action('kb_delivery_poll')` : chaque driver s'y accroche
- Chaque driver émet `do_action('kb_parcel_delivered', $parcel)` au format normalisé
  (`source`, `order_id`, `tracking`, `phone`, `delivered_at`, `wilaya_id`, `commune`)
- `weconvert-delivered.php` reçoit l'action et fire CAPI — agnostique du livreur

**Drivers à venir :**
- `weconvert-delivery-maystro.php`
- `weconvert-delivery-procolis.php`
- `weconvert-delivery-guepex.php`

---

## Architecture multi-plateformes (Phase 3 — roadmap)

Adapters prévus via REST endpoint `/wp-json/weconvert/v1/track` :
- **Shopify** : webhook `orders/create` → adapter → `kb_capi_call_meta()`
- **YouCan** : webhook `order.created` → adapter → `kb_capi_call_meta()`
- **LightFunnels** : webhook `order.fulfilled` → adapter
- **API générique** : clé API + JSON standardisé pour tout autre CMS

---

## Tester les events

1. **Settings** → mettre le Test Event Code (`TEST12345`)
2. **Events Manager** → Ton pixel → Test Events → ouvrir ton site
3. Naviguer sur une fiche produit → vérifier ViewContent dans Events Manager
4. Ajouter au panier → vérifier AddToCart
5. Ouvrir le checkout → vérifier InitiateCheckout
6. Passer une commande test → vérifier Purchase
7. Passer la commande en "completed" dans WC Admin → vérifier OrderConfirmed
8. **Settings** → vider le Test Event Code avant de mettre en prod ✅

---

## Changelog

### v6.1 (actuelle)
- ✅ completed ⇒ colis créé + validé automatiquement (logique ZR Express)
- ✅ Verrou anti double colis, nouveaux essais auto, erreurs visibles sur la commande
- ✅ Zéro conflit : fonctions `kb_eco_*`, ancien module ZR neutralisé, anciens plugins séparés détectés (pas d'écran blanc), compatibilité HPOS déclarée

### v6.0
- ✅ ZR Express supprimé → `weconvert-ecotrack.php` (EcoTrack / Packers)
- ✅ Poll livraisons 15 min (EcoTrack n'a pas de webhooks), heure réelle de livraison
- ✅ Anti-doublon par référence, stop desk auto, metabox commande (étiquette PDF, expédier)
- ✅ `weconvert-delivered.php` livreur-agnostique + garde 7 jours Meta

### v5.0
- ✅ `weconvert-events.php` NEW : AddToCart + InitiateCheckout (browser + CAPI)
- ✅ Sauvegarde fbp/fbc/external_id en session WC au chargement checkout
- ✅ `kitabook-zrexpress.php` : `do_action('kb_zr_parcel_delivered')` → OrderDelivered temps réel
- ✅ `weconvert-delivered.php` : écoute `kb_zr_parcel_delivered` + poll cron fallback
- ✅ `weconvert-settings.php` : multi-livreurs (Maystro/Procolis/Guepex slots)
- ✅ Zéro `define()` hardcodé dans tous les plugins

### v4.x (suite)
- weconvert-settings.php centralisé
- kitabook-zrexpress v4 avec webhook REST
- weconvert-pixel v1.0 (ViewContent browser + CAPI)
