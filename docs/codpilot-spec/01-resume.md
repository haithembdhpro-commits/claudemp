# Spec CODPilot — 1. Résumé

> Source : exploration du compte via Claude in Chrome. [OBSERVÉ] = vu à l'écran, [DÉDUIT] = hypothèse.
> Aucune donnée client (nom, téléphone, n° de commande) n'est reprise.

## À quoi sert l'outil

- [OBSERVÉ] Back-office SaaS de gestion de commandes COD (paiement à la livraison), qui couvre toute la chaîne : réception des commandes depuis les canaux de vente, confirmation, livraison, suivi, retours, stock, finance, messagerie, pub Meta et agents IA.
- [OBSERVÉ] Multi-tenant par sous-domaine (`<boutique>.codpilot.net`) : chaque client a son espace isolé.
- [OBSERVÉ] Interface en arabe, français et anglais. Mode sombre, plein écran, app iOS, page « App mobile ».

## Pour qui

- [OBSERVÉ] E-commerçants COD et leurs équipes. Types d'utilisateurs : Administrateurs, Vendeurs, Agents (confirmation et suivi), Marketers, Livreurs.
- [DÉDUIT] Vise les vendeurs solo comme les structures multi-vendeurs (agence ou plateforme), puisqu'il y a un module Vendeurs et des analyses par vendeur.

## Pays

- [OBSERVÉ] Algérie : devise DZD, carte des wilayas, ZR Express et EcoTrack connectés.
- [DÉDUIT] Aucun autre pays visible pour l'instant (à vérifier dans les Paramètres, section 11).

## Modules (menu latéral, 64 liens) [OBSERVÉ]

| Groupe | Modules |
|---|---|
| Pilotage | Tableau de bord, Performance (KPI) |
| Ventes | Canaux de vente, Produits, Point de Vente (POS), Scanner |
| Commandes | Nouvelles, En cours, Confirmées, Suivi, Terminées, Annulées, Tous |
| Inventaire | Entrepôts, Stock, Suivi de stock, Alerte de stock, Fournisseurs, Entrées d'achat, Entrées de retour, StockPilot Plugin |
| Achat | Sourcing, Charges |
| Finances | Portefeuilles, Versements, Paiements |
| Utilisateurs | Rôles, Administrateurs, Vendeurs, Agents, Marketers |
| Clients | Invités, Enregistrés (CRM), Liste noire, Config. fidélité |
| Partenaire | Intégration API, Société (de livraison), Livreur |
| Analyses | Commandes, Canaux de vente, Produits, Livraison, Wilayas, Agents de confirmation, Agents de suivi, Interventions, Marketer, Vendeurs |
| Sendpilot (messagerie) | Features, Canaux de messagerie, Campagnes, Automatisation, Modèles, Boîte WhatsApp |
| Adpilot (pub) | New, Comptes, Insights, Rentabilité, Comparatif, Suivi des campagnes |
| AI | New, Workflows, Integration |
| Gestion | Marques, Statuts (personnalisables), Tags d'intervention, Frais de livraison des produits, Préférences, Notifications |
| Divers | Archiver, Discussion (chat interne), App mobile, Tutoriel |

## Menu du haut [OBSERVÉ]

- Langue (AR / FR / EN), mode sombre, plein écran.
- Cloche de notifications (ex. « Nouvelle commande reçue #… », lien « Voir plus »).
- Menu profil : Profil, Abonnez-vous maintenant, Abonnement, Avis, Corbeille, Assistant d'importation de données, Se déconnecter.
- Bandeau « Enjoying Codpilot? » qui renvoie vers Trustpilot.

## Fonctions annoncées (carrousel « Nouveautés ») [OBSERVÉ]

- Statistiques sur la source des commandes confirmées
- CRM des clients enregistrés et points de fidélité
- Image de marque du magasin
- Outils MCP permettant à Claude et ChatGPT d'agir directement (créer des commandes, changer des statuts)
- Analytique des interventions
- AdPilot accessible dans ChatGPT et Claude
- Commissions sur les upsells
- Notifications personnalisables
- App iOS
- Intégrations ChatGPT et Claude
- Choix des bureaux EcoTrack
- Activité des agents en direct
- Échange partiel
- Poids des produits pour calculer automatiquement la livraison
- AdPilot multi-comptes pub

## Tableau de bord (aperçu, détaillé en section 10) [OBSERVÉ]

- Périodes : Tout le temps, Aujourd'hui, Hier, La semaine dernière, Le mois dernier.
- Affichage en pourcentage ou en nombres.
- Filtres : date de changement de statut ou date de création, Produits, Canaux de vente, Agent, Agent de suivi, Wilaya, Marketer.
- 4 cartes KPI : Total des commandes, Confirmées, Livrées, Ventes (DZD).
- Classements : Meilleurs produits, Meilleure wilaya (carte), Meilleures sociétés de livraison, Meilleurs clients, Meilleurs agents, Meilleurs agents de suivi, Meilleurs marketers.
- Statut des agents en ligne.
