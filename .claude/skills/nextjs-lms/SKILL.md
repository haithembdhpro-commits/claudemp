---
name: nextjs-lms
description: Architecture de la plateforme e-learning (Next.js App Router + Supabase + Bunny Stream + Chargily/Stripe + Resend) dans elearning/. À charger pour créer le projet, ajouter une page, une route API, le lecteur vidéo, la progression, les quiz ou les certificats.
---

# Plateforme e-learning — architecture

## Stack
- Next.js (App Router, TypeScript, Tailwind, shadcn/ui) dans `elearning/`, déployé sur Vercel.
- Supabase : Auth, Postgres (RLS), Storage (PDF/images privés), Edge Functions (webhooks).
- Bunny Stream : vidéos, **Token Authentication** + domaines autorisés, réseau « Volume ».
- Paiements : Chargily Pay (DZD) + Stripe (international) — voir skill `payment-webhooks`.
- Emails : Resend + React Email.
- Monitoring : Sentry.

## Données (voir skill `supabase-rls`)
`profiles`, `courses`, `modules`, `lessons`, `enrollments`, `lesson_progress`, `payments`, `coupons`, `quizzes`, `questions`, `quiz_attempts`, `certificates`.

## Routes
| Route | Rôle |
|---|---|
| `/` , `/courses`, `/courses/[slug]` | Marketing + vente (ISR) |
| `/login`, `/signup`, `/reset` | Auth Supabase |
| `/dashboard` | Mes cours + progression |
| `/learn/[course]/[lesson]` | Lecteur + liste des leçons |
| `/admin/**` | Gestion (middleware `is_admin`) |
| `/certificate/[code]` | Vérification publique |
| `POST /api/checkout` | Création paiement |
| `GET /api/video/[lessonId]` | Vérifie `is_enrolled` → URL Bunny signée (expiration courte) |

## Règles
- Client Supabase serveur via `@supabase/ssr` ; middleware qui rafraîchit la session et protège `/dashboard`, `/learn`, `/admin`.
- Secrets uniquement dans les variables d'environnement serveur ; `.env.example` à jour, jamais de `.env` commité.
- Progression : sauvegarde toutes les 15 s max, leçon terminée à 90 %.
- Mobile d'abord, FR/AR (RTL) prévus.
- Vérifier la doc à jour des librairies via le MCP Context7 avant d'utiliser une API récente (Next.js, Supabase, Stripe).

## Qualité
- `npm run lint`, `npx tsc --noEmit`, tests Vitest (prix, coupons, webhooks), Playwright (inscription → achat → accès → certificat).
- Test de charge k6 avant le lancement (150 utilisateurs simulés).
