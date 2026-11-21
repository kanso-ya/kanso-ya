# Journal de Livraison Backend — Lot 6 / Sprint 25 (Boutique Club — Catalogue Avancé, Variantes, Remises, Flocage & Lots)

**Rôle :** @dev-back  
**Statut :** Terminé & Vérifié (`php -l` OK · `composer dump-autoload -o --strict-psr` : 36 classes, 0 avertissement · `vendor\bin\phpstan analyse` niveau 5 : 0 erreur)

## 1. Évolutions `modules/BoutiqueApi/`
- `manifest.json` : passage en version `2.1.0`.
- `Module.php` :
  - `install()` : création idempotente des 4 tables `mod_boutique_products`, `mod_boutique_variants`, `mod_boutique_bundles`, `mod_boutique_bundle_items` via `Database::execDdlStatements()` (SQLite & MySQL) + migration incrémentale des colonnes ajoutées + conservation des 3 produits de référence initiaux (rétrocompatibilité Sprint 17) enrichis de tailles, remises adhérents et option flocage.
  - `uninstall()` : `DROP TABLE IF EXISTS` sur les 4 tables `mod_boutique_*`.
  - `boot(EventManager)` : déclaration des 7 endpoints `/api/v1/modules/boutique/{products,bundles}`.
- `Endpoints/BoutiqueController.php` :
  - `GET /api/v1/modules/boutique/products` (rétrocompatible Sprint 17 : retourne un tableau JSend `200`, filtre `masque` hors gestionnaires et `members_only` hors connectés, calcule `member_price` et `effective_price`).
  - `POST /products`, `PUT/PATCH/DELETE /products/{id}` (CRUD complet + variantes de taille avec synchronisation du stock total, réservé à `finances.manage` / Superadmin).
  - `GET /bundles`, `POST /bundles`, `DELETE /bundles/{id}` (lots combinant articles existants `product_id` et/ou articles exclusifs `custom_item_name`).

## 2. Handoff vers `@dev-front`
- **Pour le Dev-Front :** Les endpoints produits, variantes et lots sont prêts. Tu peux mettre à jour `modules/BoutiqueUi/Pages/boutique.php`, `modules/BoutiqueUi/dist/boutique.css` et créer `modules/BoutiqueUi/dist/boutique.js` avec tous les `data-testid` de `02-specs-metier.md`, en conservant `#boutique-products-grid` et `/assets/modules/boutique/boutique.css` pour la non-régression Sprint 17.
