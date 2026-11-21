# Architecture Technique : Lot 6 — Sprint 25 (Boutique Club — Catalogue Avancé, Variantes, Remises, Flocage & Lots)

## 1. Modèle de Données (MCD) et Script SQL (`mod_boutique_*`)

| Table | Colonne | Type SQL | Contraintes (PK, FK) | Description |
|---|---|---|---|---|
| `mod_boutique_products` | `id` | `INTEGER` | PK, Auto Increment | Identifiant produit |
| `mod_boutique_products` | `name` | `VARCHAR(200)` | NOT NULL | Nom du produit |
| `mod_boutique_products` | `description` | `TEXT` | NULL | Descriptif court |
| `mod_boutique_products` | `price` | `DECIMAL(10, 2)` | NOT NULL | Prix public (€) |
| `mod_boutique_products` | `stock` | `INTEGER` | NOT NULL, DEFAULT `0` | Stock global (somme des variantes si définies) |
| `mod_boutique_products` | `category` | `VARCHAR(100)` | NOT NULL, DEFAULT `'Équipement'` | Catégorie |
| `mod_boutique_products` | `availability` | `VARCHAR(30)` | NOT NULL, DEFAULT `'en_stock'` | `en_stock`, `sur_commande`, `rupture`, `masque` |
| `mod_boutique_products` | `member_discount_type` | `VARCHAR(20)` | NOT NULL, DEFAULT `'none'` | `none`, `percent`, `fixed` |
| `mod_boutique_products` | `member_discount_value` | `DECIMAL(10, 2)` | NOT NULL, DEFAULT `0.00` | Valeur remise adhérent |
| `mod_boutique_products` | `members_only` | `INTEGER` | NOT NULL, DEFAULT `0` | `1` = réservé aux adhérents connectés |
| `mod_boutique_products` | `flocking_enabled` | `INTEGER` | NOT NULL, DEFAULT `0` | `1` = option flocage activée |
| `mod_boutique_products` | `flocking_price` | `DECIMAL(10, 2)` | NOT NULL, DEFAULT `0.00` | Supplément tarifaire flocage (€) |
| `mod_boutique_products` | `flocking_max_chars` | `INTEGER` | NOT NULL, DEFAULT `20` | Longueur max du texte floqué |
| `mod_boutique_variants` | `id` | `INTEGER` | PK, Auto Increment | Identifiant variante taille |
| `mod_boutique_variants` | `product_id` | `INTEGER` | NOT NULL, FK → `mod_boutique_products(id)` ON DELETE CASCADE | Produit parent |
| `mod_boutique_variants` | `size_label` | `VARCHAR(40)` | NOT NULL | Taille (`XS`, `S`, `M`, `L`, `XL`, `150cm`, etc.) |
| `mod_boutique_variants` | `stock` | `INTEGER` | NOT NULL, DEFAULT `0` | Stock pour cette taille |
| `mod_boutique_bundles` | `id` | `INTEGER` | PK, Auto Increment | Identifiant du lot/pack |
| `mod_boutique_bundles` | `name` | `VARCHAR(200)` | NOT NULL | Nom du lot |
| `mod_boutique_bundles` | `description` | `TEXT` | NULL | Descriptif du lot |
| `mod_boutique_bundles` | `price` | `DECIMAL(10, 2)` | NOT NULL | Prix public du lot (€) |
| `mod_boutique_bundles` | `member_discount_type` | `VARCHAR(20)` | NOT NULL, DEFAULT `'none'` | `none`, `percent`, `fixed` |
| `mod_boutique_bundles` | `member_discount_value` | `DECIMAL(10, 2)` | NOT NULL, DEFAULT `0.00` | Valeur remise adhérent sur le lot |
| `mod_boutique_bundles` | `members_only` | `INTEGER` | NOT NULL, DEFAULT `0` | `1` = réservé aux adhérents connectés |
| `mod_boutique_bundles` | `availability` | `VARCHAR(30)` | NOT NULL, DEFAULT `'en_stock'` | `en_stock`, `sur_commande`, `rupture`, `masque` |
| `mod_boutique_bundle_items` | `id` | `INTEGER` | PK, Auto Increment | Identifiant composant de lot |
| `mod_boutique_bundle_items` | `bundle_id` | `INTEGER` | NOT NULL, FK → `mod_boutique_bundles(id)` ON DELETE CASCADE | Lot parent |
| `mod_boutique_bundle_items` | `product_id` | `INTEGER` | NULL | Référence article existant (`NULL` si exclusif au lot) |
| `mod_boutique_bundle_items` | `custom_item_name` | `VARCHAR(200)` | NULL | Nom libre d'un article exclusif au lot |
| `mod_boutique_bundle_items` | `quantity` | `INTEGER` | NOT NULL, DEFAULT `1` | Quantité incluse dans le lot |

## 2. Arborescence Modulaire (`modules/BoutiqueApi/` & `modules/BoutiqueUi/`)
```text
modules/
├── BoutiqueApi/
│   ├── manifest.json                     # Version 2.1.0 (schéma v2, assets BoutiqueUi/dist -> boutique)
│   ├── Module.php                        # install() (4 tables + 3 produits initiaux + variantes), uninstall() (DROP 4 tables), boot()
│   └── Endpoints/
│       └── BoutiqueController.php        # CRUD produits/variantes/lots, calcul remises adhérents, filtrage members_only
└── BoutiqueUi/
    ├── Pages/
    │   └── boutique.php                  # Vue /boutique enrichie (conserve #boutique-products-grid + boutique.css + ajout lots & admin)
    └── dist/
        ├── boutique.css                  # Styles existants + badges disponibilité/adhérent
        └── boutique.js                   # Script Vanilla ES module (tailles, flocage live, admin produits & lots)
```

## 3. Contrats d'API (Endpoints — HTTP 200 Constant JSend sous `/api/v1/modules/boutique/*`)
| ID API | Endpoint | Méthode | Payload attendu (JSON) | Réponse JSON (Status 200) |
|---|---|---|---|---|
| `API-25-01` | `/api/v1/modules/boutique/products` | `GET` | *(aucun)* | **Tableau** des produits visibles (`data: [...]`, rétrocompatible Sprint 17), chaque produit enrichi avec `variants`, `member_price`, `effective_price`, `flocking_*`, `availability`, `members_only`. |
| `API-25-02` | `/api/v1/modules/boutique/products` | `POST` | `{"name":"...","price":45,"category":"Tenues","availability":"en_stock","member_discount_type":"percent","member_discount_value":20,"members_only":1,"flocking_enabled":1,"flocking_price":8,"variants":[{"size_label":"S","stock":3},{"size_label":"M","stock":5}]}` | `code: 201` (gestionnaire `finances.manage` requis, sinon `403` ; `422` si invalide) |
| `API-25-03` | `/api/v1/modules/boutique/products/{id}` | `PUT` / `PATCH` / `DELETE` | *(champs produit & variantes)* | `code: 200` (`403` / `404` / `422`) |
| `API-25-04` | `/api/v1/modules/boutique/bundles` | `GET` | *(aucun)* | `data: [...]` (lots visibles avec leurs `items` résolus : articles existants + exclusifs) |
| `API-25-05` | `/api/v1/modules/boutique/bundles` | `POST` | `{"name":"Pack Rentrée","price":55,"members_only":0,"items":[{"product_id":1,"quantity":1},{"custom_item_name":"Sac Dojo Exclusif","quantity":1}]}` | `code: 201` (`403` / `422`) |
| `API-25-06` | `/api/v1/modules/boutique/bundles/{id}` | `DELETE` | *(aucun)* | `code: 200` (`403` / `404`) |

## 4. Instructions de passage de relais (Handoff)
- **Pour le Dev-Back :** Mets à jour `modules/BoutiqueApi/Module.php`, `BoutiqueController.php` et `manifest.json` en préservant la rétrocompatibilité stricte avec `18-sprint17-modules-v2.spec.ts` et la compatibilité SQLite/MySQL (`Database::execDdlStatements`).
