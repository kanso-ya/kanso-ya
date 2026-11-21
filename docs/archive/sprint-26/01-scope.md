# Sprint Actif : Lot 6 — Sprint 26 : Module Boutique Club — Commandes, Paiement Hors-Ligne, Livraison, Suivi & Liaison Facturation PDF
**Objectif principal :** Finaliser le module Boutique Club (`BoutiqueApi` / `BoutiqueUi`) en intégrant le paramétrage des modes de paiement hors-ligne (avec option paiement en ligne verrouillée « Indisponible pour le moment ») et de livraison, le tunnel panier/commande avec décrémentation de stock, le workflow complet de suivi des commandes (Bureau & Adhérent) et la liaison automatique avec le module Facturation PDF (Sprint 24), tout en affranchissant le module et les tests de la contrainte historique du démonstrateur Sprint 17.

## 1. Périmètre Macro (User Stories)
| ID | Fonctionnalité ciblée | Description métier | Priorité |
|---|---|---|---|
| `US-LOT6-09` | Paramétrages Paiement Hors-Ligne & Livraison (`mod_boutique_settings`) | Permettre au Bureau (`finances.manage` / Superadmin) de configurer les modes de paiement hors-ligne actifs (Chèque, Espèces, Virement club), en conservant explicitement l'option **« Paiement en ligne »** désactivée et libellée **« Indisponible pour le moment »**, ainsi que les modes de livraison (Retrait au dojo, Remise en main propre, Livraison postale avec frais configurables). | Haute |
| `US-LOT6-10` | Panier multi-articles & Passage de commande (`mod_boutique_orders`) | Permettre d'ajouter au panier des produits (avec taille, flocage + texte) et/ou des lots/packs, de choisir mode de paiement hors-ligne et mode de livraison, de calculer le total net en temps réel (remise adhérent + flocage + livraison) et de valider la commande (`CMD-YYYY-0001`) avec décrémentation atomique du stock. | Haute |
| `US-LOT6-11` | Suivi des commandes (Bureau & Adhérent) | Gérer le cycle de vie complet (`en_attente_paiement`, `payee`, `en_preparation`, `prete_ou_expediee`, `livree`, `annulee`) avec historique horodaté, pilotage côté Bureau et suivi cloisonné RGPD côté Adhérent. | Haute |
| `US-LOT6-12` | Liaison Facturation PDF Officielle & Assainissement tests Sprint 17 | Déclencher automatiquement `issue_official_invoice` (Sprint 24) lors du passage d'une commande au statut `payee` (avec dégradation gracieuse si `FacturationApi` est inactif), et réécrire les assertions obsolètes de `18-sprint17-modules-v2.spec.ts` qui figeaient le démonstrateur minimal V2.0. | Haute |

## 2. Hors Périmètre (Exclusions strictes)
- **Ne pas** activer de paiement en ligne (CB, Stripe, HelloAsso, virement automatisé — projet séparé ; rejet API `422` obligatoire si tenté).
- **Ne pas** générer d'étiquettes transporteurs externes (Colissimo, Mondial Relay).
- **Ne pas** modifier les endpoints ou tables du Core (tout vit dans `modules/BoutiqueApi` & `modules/BoutiqueUi` + mise à jour ciblée des tests `18-sprint17-modules-v2.spec.ts`).

## 3. Instructions de passage de relais (Handoff)
- **Pour le PO :** À partir de ce scope, rédige les spécifications fonctionnelles détaillées, les règles de gestion commandes/livraison/suivi/facturation et la liste des `data-testid` dans `docs/current-sprint/02-specs-metier.md`.
