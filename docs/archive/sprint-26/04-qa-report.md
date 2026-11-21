STATUT: VALIDATED

# Rapport de Recette QA / UAT — Lot 6 / Sprint 26 (Boutique Club : Commandes, Paiement Hors-Ligne, Livraison, Suivi & Facturation PDF)

## 1. Synthèse de Validation
- **Statut global :** `VALIDATED`
- **Build & Typage :** `npm run build:ts` (0 erreur), `npm run build:css` (OK), `php -l` (OK), `composer dump-autoload -o --strict-psr` (36 classes), `vendor\bin\phpstan analyse` niveau 5 (**0 erreur**).
- **Suite E2E complète :** **324 / 324 tests PASS** (`chromium` + `Mobile Chrome`, `--workers=1`), incluant :
  - [27-sprint26-boutique-commandes.spec.ts](file:///c:/laragon/www/kanso-ya/tests/e2e/27-sprint26-boutique-commandes.spec.ts) (`4/4` PASS)
  - [26-sprint25-boutique-catalogue.spec.ts](file:///c:/laragon/www/kanso-ya/tests/e2e/26-sprint25-boutique-catalogue.spec.ts) (`4/4` PASS)
  - [18-sprint17-modules-v2.spec.ts](file:///c:/laragon/www/kanso-ya/tests/e2e/18-sprint17-modules-v2.spec.ts) (`6/6` PASS — affranchi des assertions démonstrateur Sprint 17)
  - [07-sprint6-modules-sdk.spec.ts](file:///c:/laragon/www/kanso-ya/tests/e2e/07-sprint6-modules-sdk.spec.ts) (`12/12` PASS — version vérifiée en semver générique)
  - [20-sprint19-lockdown-v2.spec.ts](file:///c:/laragon/www/kanso-ya/tests/e2e/20-sprint19-lockdown-v2.spec.ts) (`8/8` PASS — PHPStan niveau 5 & sécurité HTTP)

## 2. Couverture des Scénarios & Règles de Gestion
| Scénario | Couverture | Résultat |
|---|---|---|
| `S26-01` (`RG-01`, `RG-02`, `RG-03`, `RG-04`, `RG-05`) | Paramétrage Bureau (`setting-pay-online-disabled` verrouillé « Indisponible pour le moment », frais postaux `7,00 €`), commande Adhérent Kimono taille `160cm` + flocage `K. Tanaka` (+6 €) + livraison postale (+7 €) = `53,50 €`, génération `CMD-YYYY-0001`, décrémentation stock, suivi commandes, transition `payee` -> facture PDF officielle (`source_type='boutique'`, `%PDF-`) générée automatiquement par `FacturationApi` | **PASS** |
| `S26-02` (`RG-01`, `RG-03`, `RG-04`, `RG-05`) | Rejet `422` si tentative d'activation paiement en ligne ou commande `payment_method='online'`, blocage `403` Adhérent sur paramètres et transitions de statut, restauration atomique du stock sur annulation (`annulee`), et dégradation gracieuse (`200`) sur passage à `payee` lorsque `FacturationApi` est désinstallé | **PASS** |
| Nettoyage Sprint 17 / Sprint 6 | Suppression du couplage legacy `version === '2.0.0'` et des sélecteurs fragiles dans `18-sprint17-modules-v2.spec.ts` et `07-sprint6-modules-sdk.spec.ts` | **PASS** |
