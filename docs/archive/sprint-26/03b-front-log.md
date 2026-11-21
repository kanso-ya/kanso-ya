# Journal de Développement Frontend — Lot 6 / Sprint 26 (Boutique Commandes, Panier, Suivi & Paramètres)

## 1. Fichiers Créés / Modifiés
- [boutique.php](file:///c:/laragon/www/kanso-ya/modules/BoutiqueUi/Pages/boutique.php) :
  - Refactorisation en interface **3 onglets** (`boutique-tab-catalog`, `boutique-tab-orders`, `boutique-tab-admin`) tout en préservant la compatibilité complète avec le bouton `btn-toggle-boutique-admin` et tous les `data-testid` du Sprint 25.
  - **Onglet 1 (`Catalogue & Panier`)** : grilles `#boutique-products-grid` et `#boutique-bundles-grid` enrichies des boutons `btn-add-to-cart-product-{id}` et `btn-add-to-cart-bundle-{id}`, accompagnées du panneau `boutique-cart-panel` (lignes panier, choix paiement hors-ligne, livraison, adresse conditionnelle, total TTC `cart-total-display`, validation `btn-submit-order` et confirmation `cart-order-confirmation`).
  - **Onglet 2 (`Suivi Commandes`)** : tableau `boutique-orders-table` listant les commandes (`order-row-{id}`, `order-status-badge-{id}`) avec sélecteur de statut (`order-status-select-{id}`) et bouton `btn-update-order-status-{id}` pour les gestionnaires (`finances.manage` / Superadmin).
  - **Onglet 3 (`Administration & Paramètres`)** : formulaire `boutique-settings-form` avec modes hors-ligne (`setting-pay-cheque`, `setting-pay-especes`, `setting-pay-virement`), option verrouillée **« Indisponible pour le moment »** (`setting-pay-online-disabled`), modes de livraison (`setting-deliv-dojo`, `setting-deliv-hand`, `setting-deliv-postal`), frais postaux (`setting-postal-fee`) et bouton `btn-save-boutique-settings`, complété par les formulaires produits et lots du Sprint 25.
- [boutique.js](file:///c:/laragon/www/kanso-ya/modules/BoutiqueUi/dist/boutique.js) :
  - Gestion de la navigation par onglets, du panier client (taille + flocage + calcul temps réel des frais postaux), de la soumission `POST /api/v1/modules/boutique/orders`, du suivi `GET /api/v1/modules/boutique/orders`, des transitions `PATCH /api/v1/modules/boutique/orders/{id}/status` et de la sauvegarde `PUT /api/v1/modules/boutique/settings`.

## 2. Vérifications Techniques Exécutées
- `php -l modules/BoutiqueUi/Pages/boutique.php` : OK (0 erreur de syntaxe).
- `npm run build:ts` : OK (`tsc` 0 erreur).
- `npm run build:css` : OK (Tailwind CSS compilé et minifié).
