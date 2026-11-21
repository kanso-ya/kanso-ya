# Architecture Technique : Lot 6 — Sprint 26 (Boutique Club — Commandes, Paiement Hors-Ligne, Livraison, Suivi & Facturation PDF)

## 1. Modèle de Données (MCD) et Script SQL (`mod_boutique_*`)

| Table | Colonne | Type SQL | Contraintes (PK, FK) | Description |
|---|---|---|---|---|
| `mod_boutique_settings` | `setting_key` | `VARCHAR(100)` | PK | Clé de paramétrage (`payment_methods`, `delivery_methods`, `shipping_postal_fee`) |
| `mod_boutique_settings` | `setting_value` | `TEXT` | NOT NULL | Valeur JSON ou scalaire |
| `mod_boutique_settings` | `updated_at` | `DATETIME` | NOT NULL, DEFAULT CURRENT_TIMESTAMP | Date de mise à jour |
| `mod_boutique_orders` | `id` | `INTEGER` | PK, Auto Increment | Identifiant commande |
| `mod_boutique_orders` | `order_number` | `VARCHAR(40)` | NOT NULL, UNIQUE | Référence séquentielle (`CMD-2026-0001`) |
| `mod_boutique_orders` | `user_id` | `INTEGER` | NULL | Acheteur connecté (`users.id`) |
| `mod_boutique_orders` | `buyer_name` | `VARCHAR(200)` | NOT NULL | Nom complet de l'acheteur |
| `mod_boutique_orders` | `buyer_email` | `VARCHAR(255)` | NOT NULL | E-mail de contact & facturation |
| `mod_boutique_orders` | `buyer_address` | `TEXT` | NULL | Adresse de livraison (obligatoire si `livraison_postale`) |
| `mod_boutique_orders` | `payment_method` | `VARCHAR(40)` | NOT NULL | `cheque`, `especes`, `virement` (jamais `online`) |
| `mod_boutique_orders` | `delivery_method` | `VARCHAR(40)` | NOT NULL | `retrait_dojo`, `remise_main_propre`, `livraison_postale` |
| `mod_boutique_orders` | `subtotal_amount` | `DECIMAL(10, 2)` | NOT NULL | Sous-total articles + flocages (après remise adhérent) |
| `mod_boutique_orders` | `shipping_amount` | `DECIMAL(10, 2)` | NOT NULL, DEFAULT `0.00` | Frais de livraison appliqués |
| `mod_boutique_orders` | `total_amount` | `DECIMAL(10, 2)` | NOT NULL | Total net TTC |
| `mod_boutique_orders` | `status` | `VARCHAR(30)` | NOT NULL, DEFAULT `'en_attente_paiement'` | Statut de suivi courant |
| `mod_boutique_orders` | `status_history_json` | `TEXT` | NOT NULL | Historique JSON `[{status, at, note}]` |
| `mod_boutique_orders` | `created_at` | `DATETIME` | NOT NULL, DEFAULT CURRENT_TIMESTAMP | Date de commande |
| `mod_boutique_order_items` | `id` | `INTEGER` | PK, Auto Increment | Identifiant ligne de commande |
| `mod_boutique_order_items` | `order_id` | `INTEGER` | NOT NULL, FK → `mod_boutique_orders(id)` ON DELETE CASCADE | Commande parente |
| `mod_boutique_order_items` | `item_type` | `VARCHAR(20)` | NOT NULL | `product` ou `bundle` |
| `mod_boutique_order_items` | `item_id` | `INTEGER` | NOT NULL | ID produit ou lot |
| `mod_boutique_order_items` | `item_label` | `VARCHAR(255)` | NOT NULL | Libellé figé au moment de la commande |
| `mod_boutique_order_items` | `size_label` | `VARCHAR(40)` | NULL | Taille sélectionnée |
| `mod_boutique_order_items` | `flocking_text` | `VARCHAR(60)` | NULL | Texte floqué éventuel |
| `mod_boutique_order_items` | `flocking_price` | `DECIMAL(10, 2)` | NOT NULL, DEFAULT `0.00` | Supplément flocage unitaire |
| `mod_boutique_order_items` | `quantity` | `INTEGER` | NOT NULL, DEFAULT `1` | Quantité commandée |
| `mod_boutique_order_items` | `unit_price` | `DECIMAL(10, 2)` | NOT NULL | Prix unitaire net (après remise adhérent éventuelle) |
| `mod_boutique_order_items` | `line_total` | `DECIMAL(10, 2)` | NOT NULL | `(unit_price + flocking_price) * quantity` |

## 2. Arborescence Modulaire & Intégration Transversale
```text
modules/
├── BoutiqueApi/
│   ├── manifest.json                     # Version 2.3.0 (affranchie du démonstrateur Sprint 17)
│   ├── Module.php                        # 7 tables mod_boutique_* (SQLite & MySQL) + routes settings/orders
│   └── Endpoints/
│       └── BoutiqueController.php        # Settings paiement/livraison, création commande + stock, suivi statuts + dispatch('issue_official_invoice')
└── BoutiqueUi/
    ├── Pages/
    │   └── boutique.php                  # Interface 3 onglets : Catalogue & Panier, Suivi Commandes, Administration & Paramètres
    └── dist/
        ├── boutique.css                  # Styles Boutique
        └── boutique.js                   # Panier, calcul livraison/flocage, soumission commande, suivi statuts & paramétrage
```

## 3. Contrats d'API (Endpoints — HTTP 200 Constant JSend sous `/api/v1/modules/boutique/*`)
| ID API | Endpoint | Méthode | Payload attendu (JSON) | Réponse JSON (Status 200) |
|---|---|---|---|---|
| `API-26-01` | `/api/v1/modules/boutique/settings` | `GET` | *(public)* | `{"status":"success","code":200,"data":{"payment_methods":["cheque","especes","virement"],"online_payment_available":false,"online_payment_label":"Indisponible pour le moment","delivery_methods":["retrait_dojo","remise_main_propre","livraison_postale"],"shipping_postal_fee":6.5}}` |
| `API-26-02` | `/api/v1/modules/boutique/settings` | `PUT` / `PATCH` | `{"payment_methods":["cheque","virement"],"delivery_methods":["retrait_dojo","livraison_postale"],"shipping_postal_fee":7.0}` | `code: 200` (`403` non gestionnaire, `422` si `online_payment_enabled=true` ou méthode invalide) |
| `API-26-03` | `/api/v1/modules/boutique/orders` | `GET` | *(authentifié requis)* | `{"status":"success","code":200,"data":{"can_manage":bool,"orders":[...]}}` (cloisonné sur l'acheteur si non gestionnaire) |
| `API-26-04` | `/api/v1/modules/boutique/orders` | `POST` | `{"buyer_name":"...","buyer_email":"...","buyer_address":"...","payment_method":"cheque","delivery_method":"livraison_postale","items":[{"item_type":"product","item_id":1,"size_label":"160cm","flocking_text":"K. Tanaka","quantity":1}]}` | `code: 201` avec `order` (`order_number: CMD-2026-0001`, totaux recalculés serveur) ; `422` si `online` ou invalide ; `409` si stock insuffisant |
| `API-26-05` | `/api/v1/modules/boutique/orders/{id}/status` | `PATCH` | `{"status":"payee\|en_preparation\|prete_ou_expediee\|livree\|annulee","note":"..."}` | `code: 200` (déclenche `issue_official_invoice` sur `payee`, restaure le stock sur `annulee` ; `403` si non gestionnaire) |

## 4. Instructions de passage de relais (Handoff)
- **Pour le Dev-Back :** Mets à jour `modules/BoutiqueApi/` (`manifest.json` en `2.3.0`, `Module.php` avec les 3 nouvelles tables et routes, `BoutiqueController.php` avec settings, orders, stock, suivi et dispatch `issue_official_invoice`).
