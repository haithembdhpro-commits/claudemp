# claudemp — WeConvert

Dépôt de **WeConvert** (Annaba, Algérie). Réponds à l'utilisateur en français.

## Contenu
| Chemin | Quoi |
|---|---|
| `weconvert-suite/` | Plugin WordPress/WooCommerce « WeConvert.io Suite » : Meta Pixel + CAPI, OrderConfirmed, OrderDelivered, EcoTrack, fingerprint, dashboard COD. Voir `weconvert-suite/README.md`. |
| `elearning/` (à venir) | Plateforme e-learning Next.js + Supabase + Bunny Stream + Chargily/Stripe (client : Tradeversity). |
| `*.pdf` | Documents commerciaux (devis, récapitulatif des charges). |

## Skills du projet (`.claude/skills/`) — à charger avant de coder
- `weconvert-plugin` : architecture, ordre de chargement, conventions `kb_*`, dédup `event_id`, EcoTrack, release.
- `wp-security` : checklist sécurité WordPress (nonces, capacités, échappement, SQL, REST publics).
- `nextjs-lms` : architecture de la plateforme e-learning.
- `supabase-rls` : migrations, RLS, tests RLS, types.
- `payment-webhooks` : Chargily/Stripe, signatures, idempotence.

## Commandes
| Commande | Effet |
|---|---|
| `composer syntax` | `php -l` sur tout le plugin |
| `composer lint` / `composer fix` | PHPCS (WordPress-Extra + Security + PHPCompatibility) / correction auto |
| `/lint`, `/release`, `/secure` | Vérifications, zip du plugin, revue sécurité (sous-agents `test-runner`, `security-reviewer`) |

`vendor/` est installé par le hook SessionStart (paquets clonés depuis GitHub) ; s'il manque, seul `php -l` est disponible. PHPStan n'est pas installé : son paquet n'est distribué qu'en archive GitHub, bloquée par le réseau de l'environnement.

## MCP (`.mcp.json`)
- `playwright` : navigateur headless (tests de pages, captures).
- `context7` : documentation à jour des librairies — l'utiliser avant d'écrire du code sur une API récente.
- `supabase` (lecture seule) et `stripe` : nécessitent les secrets d'environnement `SUPABASE_ACCESS_TOKEN` et `STRIPE_SECRET_KEY` (clé test restreinte).

## Règles
- Une fonctionnalité par commit ; messages de commit en anglais, à l'impératif.
- Ne jamais commiter de secret, de `.env`, de `vendor/`, de `node_modules/` ni de zip.
- Plugin : PHP ≥ 8.0, WooCommerce ≥ 7.0, HPOS (passer par `WC_Order`), monter les versions (module + suite) à chaque changement.
- E-learning : RLS partout, `service_role` côté serveur uniquement, prix recalculé côté serveur, webhooks signés et idempotents.
- Avant un push touchant du PHP, du SQL ou des paiements : lancer `/secure`.
