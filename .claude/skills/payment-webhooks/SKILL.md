---
name: payment-webhooks
description: Intégration des paiements Chargily Pay (CIB/Edahabia) et Stripe pour la plateforme e-learning — checkout côté serveur, webhooks signés, idempotence, activation de l'accès. À charger avant d'écrire ou de modifier tout code de paiement, de webhook ou d'inscription payante.
---

# Paiements et webhooks

## Flux
1. Client → `POST /api/checkout { courseId, coupon? }`.
2. Serveur : lit le prix **depuis la base**, applique le coupon **côté serveur**, crée une ligne `payments` (`pending`, `provider`, `amount`, `currency`), crée le checkout chez Chargily ou Stripe avec `metadata.payment_id`.
3. Le prestataire appelle le webhook → vérification de signature → `payments.status = 'paid'` → `insert enrollments` → email de confirmation.
4. La page de succès **n'active rien** : elle lit seulement l'état (le webhook fait foi).

## Vérification de signature (obligatoire, avant tout traitement)
- **Stripe** : `stripe.webhooks.constructEvent(rawBody, sig, STRIPE_WEBHOOK_SECRET)` — utiliser le corps brut (`await req.text()`), pas le JSON parsé.
- **Chargily Pay v2** : en-tête `signature` = HMAC-SHA256 hex du corps brut avec la clé secrète API ; comparer en temps constant (`crypto.timingSafeEqual`). Vérifier la doc Chargily à jour (Context7) avant d'implémenter.
- Signature invalide → `400`, rien n'est écrit.

## Idempotence
- Contrainte `unique (provider, provider_ref)` sur `payments` et `unique (user_id, course_id)` sur `enrollments`.
- Traiter l'événement dans une transaction ; si déjà `paid` → répondre `200` sans rien refaire.
- Répondre `200` vite ; travail lourd (emails) après.

## Montants
- Chargily : montants en DZD entiers. Stripe : en plus petite unité (centimes).
- Vérifier que le montant/devise reçus = ceux de la ligne `payments`.

## Remboursement
- Événement de remboursement → `payments.status = 'refunded'` + suppression ou expiration de l'inscription.

## Tests
- Webhook avec fausse signature → 400.
- Même webhook envoyé deux fois → une seule inscription.
- Montant modifié côté client → ignoré (prix serveur).
- Stripe CLI : `stripe trigger checkout.session.completed` ; Chargily : mode test.
