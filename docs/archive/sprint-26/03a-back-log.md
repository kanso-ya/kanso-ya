# Journal de Développement Backend — Lot 6 / Sprint 26 (Boutique Commandes, Paiement Hors-Ligne, Livraison, Suivi & Liaison Facturation PDF)

## 1. Fichiers Créés / Modifiés
- [manifest.json](file:///c:/laragon/www/kanso-ya/modules/BoutiqueApi/manifest.json) : Montée de version du module Boutique à `2.3.0`.
- [Module.php](file:///c:/laragon/www/kanso-ya/modules/BoutiqueApi/Module.php) :
  - Ajout des 3 tables `mod_boutique_settings`, `mod_boutique_orders` et `mod_boutique_order_items` dans `install()` via `Database::execDdlStatements()` (compatible SQLite et MySQL/MariaDB), avec seed des paramètres par défaut (`cheque`, `especes`, `virement`, `retrait_dojo`, `remise_main_propre`, `livraison_postale`, `shipping_postal_fee = 6.50`, `online_payment_enabled = 0`).
  - Nettoyage complet des 7 tables `mod_boutique_*` dans `uninstall()`.
  - Enregistrement des 5 routes REST dans `boot()` :
    - `GET /api/v1/modules/boutique/settings`
    - `PUT|PATCH /api/v1/modules/boutique/settings`
    - `GET /api/v1/modules/boutique/orders`
    - `POST /api/v1/modules/boutique/orders`
    - `PATCH /api/v1/modules/boutique/orders/{id}/status`
- [BoutiqueController.php](file:///c:/laragon/www/kanso-ya/modules/BoutiqueApi/Endpoints/BoutiqueController.php) :
  - `getSettings()` / `updateSettings()` : lecture/mise à jour des modes de paiement hors-ligne, modes de livraison et frais de port ; rejet strict JSend `422` si `online_payment_enabled = true` ou présence du mode `'online'` (`RG-01`).
  - `listOrders()` : liste des commandes avec leurs lignes et historique de statuts ; filtrée automatiquement par `user_id` ou e-mail pour les non-gestionnaires (`RG-04`).
  - `createOrder()` : recalcul intégral côté serveur (tarifs préférentiels adhérents, flocage, frais de port), contrôle `members_only` / `rupture` / `masque` / stock, rejet `422` pour `payment_method = 'online'`, décrémentation atomique du stock (variante + produit) et génération du numéro séquentiel `CMD-YYYY-0001` (`RG-02`, `RG-03`).
  - `updateOrderStatus()` : transitions de statut réservées à `finances.manage` / Superadmin, restauration du stock sur `annulee`, et déclenchement hors-transaction du hook SDK `issue_official_invoice` sur passage à `payee` (`RG-04`, `RG-05`) avec dégradation gracieuse si `FacturationApi` est inactif.

## 2. Vérifications Techniques Exécutées
- `php -l modules/BoutiqueApi/Module.php` : OK (0 erreur de syntaxe).
- `php -l modules/BoutiqueApi/Endpoints/BoutiqueController.php` : OK (0 erreur de syntaxe).
- `composer dump-autoload -o --strict-psr` : OK (36 classes optimisées).
- `vendor\bin\phpstan analyse` (Niveau 5, 72 fichiers) : `[OK] No errors`.
