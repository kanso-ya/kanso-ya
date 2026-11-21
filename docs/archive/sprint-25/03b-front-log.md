# Journal de Livraison Frontend — Lot 6 / Sprint 25 (Boutique Club — Catalogue Avancé, Variantes, Remises, Flocage & Lots)

**Rôle :** @dev-front  
**Statut :** Terminé & Compilé (`php -l modules/BoutiqueUi/Pages/boutique.php` OK · `npm run build:ts` : 0 erreur · `npm run build:css` : OK)

## 1. Livrables UI Modulaires (`modules/BoutiqueUi/`)
- `modules/BoutiqueUi/Pages/boutique.php` :
  - Préserve 100 % des éléments attendus par Sprint 17 (`#boutique-products-grid`, `/assets/modules/boutique/boutique.css`, rendu SSR des produits).
  - Affiche pour chaque produit (`product-card-{id}`) : badge disponibilité, badge « Adhérents uniquement », prix barré + tarif préférentiel adhérent (`product-effective-price-{id}`), sélecteur de tailles (`product-size-select-{id}`) et option flocage personnalisé (`product-flocking-toggle-{id}`, `product-flocking-text-{id}`).
  - Ajoute la grille des Lots / Packs (`boutique-bundles-grid`, `bundle-card-{id}`) distinguant articles catalogue et articles exclusifs au lot.
  - Ajoute le panneau d'administration (`boutique-admin-panel`, visible uniquement `finances.manage` / Superadmin) avec formulaires `boutique-create-product-form` et `boutique-create-bundle-form`.
- `modules/BoutiqueUi/dist/boutique.js` :
  - Recalcul en temps réel du prix lors de l'activation du flocage, parsing des variantes `S:5, M:10, L:8` et câblage des formulaires création produit & lot.

## 2. Handoff vers `@qa-uat`
- **Pour le QA/UAT :** Le catalogue avancé Boutique est prêt. Tu peux créer `tests/e2e/26-sprint25-boutique-catalogue.spec.ts` et vérifier la non-régression complète (incluant `18-sprint17-modules-v2.spec.ts`).
