# Sprint Actif : Lot 6 — Sprint 25 : Module Boutique Club — Catalogue Avancé, Variantes, Remises Adhérents, Flocage & Lots
**Objectif principal :** Faire évoluer le module `BoutiqueApi` / `BoutiqueUi` (en préservant 100 % de rétrocompatibilité avec les routes et fixtures du Sprint 17) en un catalogue associatif complet gérant les **options de taille** (stock par taille), la **disponibilité**, les **tarifs préférentiels / accès réservé aux adhérents**, le **flocage personnalisé** et la **création de lots/packs** combinant articles existants et/ou exclusifs au lot.

## 1. Périmètre Macro (User Stories)
| ID | Fonctionnalité ciblée | Description métier | Priorité |
|---|---|---|---|
| `US-LOT6-05` | Catalogue étendu, tailles & disponibilité (`mod_boutique_*`) | Enrichir `mod_boutique_products` + ajouter `mod_boutique_variants` (tailles XS/S/M/L/XL ou cm avec stock individuel), statuts de disponibilité (`en_stock`, `sur_commande`, `rupture`, `masque`) et CRUD complet back-office réservé à `finances.manage` / Superadmin. | Haute |
| `US-LOT6-06` | Tarification & visibilité adhérents | Permettre de définir par produit une **remise adhérent** (`percent` ou `fixed`, calcul dynamique du `member_price` / `effective_price`) et une option **« Réservé aux adhérents connectés uniquement »** (`members_only = 1`, filtré automatiquement pour les visiteurs). | Haute |
| `US-LOT6-07` | Option de flocage personnalisé | Permettre d'activer le **flocage** par produit (`flocking_enabled`, `flocking_price`, `flocking_max_chars`) et de saisir côté boutique le texte personnalisé avec prévisualisation temps réel du tarif total. | Haute |
| `US-LOT6-08` | Création de Lots / Packs (`mod_boutique_bundles`) | Permettre au Bureau de composer des **lots/packs** (`mod_boutique_bundles` + `mod_boutique_bundle_items`) réunissant des **articles existants du catalogue** et/ou des **articles exclusifs au lot** (nom libre + taille/flocage), avec tarif de lot, remise adhérent et disponibilité propres. | Haute |

## 2. Hors Périmètre (Exclusions strictes)
- **Ne pas** implémenter le tunnel de commande, le paiement hors-ligne, les frais de livraison ni le suivi de commande (traités dans le **Sprint 26**).
- **Ne pas** implémenter de paiement en ligne CB / Stripe / HelloAsso (hors périmètre projet).
- **Ne pas** casser le contrat Sprint 17 : `GET /api/v1/modules/boutique/products` doit continuer à retourner un tableau des 3 produits de démo à l'installation initiale et `/boutique` doit conserver `#boutique-products-grid` et `/assets/modules/boutique/boutique.css`.

## 3. Instructions de passage de relais (Handoff)
- **Pour le PO :** À partir de ce scope, rédige les spécifications fonctionnelles détaillées, les règles de calcul tarifaire/accès et la liste des `data-testid` dans `docs/current-sprint/02-specs-metier.md`.
