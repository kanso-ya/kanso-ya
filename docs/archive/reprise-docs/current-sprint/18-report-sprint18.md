# Rapport de Clôture — Sprint 18 (Lot R4 : Moteur de Surcharge UI `EventManager` & Safe Mode Étendu)

- **Date :** 21/11/2026
- **Jalon :** Phase V2 — Sprint 18 (Lot R4, US 18.1 à 18.3)
- **Statut global :** ✅ **100 % VALIDÉ EN RECETTE (276/276 E2E Pass, PHPStan 56/56 — 0 erreur, PSR-4 26 classes — 0 warning)**

---

## 1. Synthèse des Implémentations

### US 18.1 — Filtres UI Officiels & `UiHelpers`
- **Création de `Core/Api/Sdk/UiHelpers.php`** (`Core\Api\Sdk\UiHelpers`, 100 % PSR-4 strict) :
  - `UiHelpers::text($key, $default, $context)` : applique le filtre `ui_text` avec échappement HTML automatique par défaut (option `raw => true` supportée).
  - `UiHelpers::tabs($page, $defaultTabs, $context)` : applique le filtre `ui_tabs` (+ alias rétrocompatible `stats_tabs` lorsque `$page === 'stats'`), normalise et trie les onglets par `order`.
  - `UiHelpers::slot($zone, $context)` : applique le filtre `ui_slot_{$zone}` pour injecter du contenu modulaire dans une zone prédéfinie.
  - `UiHelpers::assets($page, $scripts, $styles)` : applique `ui_assets` avec validation stricte de liste blanche locale (`/assets/...`, blocage des URLs externes et des séquences `..`).
- **Surcharge sécurisée de page complète (`ui_page_override`) dans `Core/Ui/Router/PageRouter.php` :**
  - Transmission du contexte complet (`route`, `page_id`, `role`, `params`).
  - Validation `realpath()` confinée à `Core/Ui/Pages/` et `modules/*Ui/Pages/` ; toute tentative hors liste blanche (path traversal, fichier hors périmètre) est rejetée, repliée sur la vue Core par défaut et consignée dans `sys_logs` sous l'action `UI_PAGE_OVERRIDE_BLOCKED`.
- **Branchement dans les vues Core :**
  - `Core/Ui/Templates/layout.php` (`ui_assets` dans `<head>`, slot `footer_links`).
  - `Core/Ui/Pages/home.php` (`ui_text` sur badge/titre/sous-titre/CTAs, slots `home_hero` et `home_after_articles`).
  - `Core/Ui/Pages/dashboard.php` (slots `dashboard_top` et `dashboard_bottom`).
  - `Core/Ui/Pages/stats.php` (`UiHelpers::tabs('stats', ...)` avec rétrocompatibilité `stats_tabs`).
  - `Core/Ui/Pages/sys-smtp.php` (`UiHelpers::tabs('sys-smtp', ...)` avec rendu dynamique des panneaux additionnels).

### US 18.2 — Safe Mode Étendu aux Filtres & Actions UI
- **`Core/Api/Sdk/EventManager.php` :**
  - Ajout de `setCurrentModule(?string $folderName)`, `getCurrentModule()` et `setQuarantineHandler(?callable $handler)`.
  - Étiquetage automatique de chaque action et filtre avec le module appelant (`module`).
  - Dans `applyFilters()` et `dispatch()`, toute `Throwable` levée par un callback préserve la valeur précédente intacte (zéro écran blanc, HTTP 200 garanti), écrit dans `error_log()` et invoque le gestionnaire de quarantaine si le hook appartient à un module.
- **`Core/Api/Services/ModuleManager.php` :**
  - Enregistrement du `quarantineHandler` au démarrage de `bootActiveModules()` et encadrement de chaque `$instance->boot()` par `setCurrentModule($folder)` / `finally { setCurrentModule(null); }`.
  - Méthode publique `quarantineModule($folderName, $e, $context)` : bascule le module fautif en `status = 'crashed'` (`is_active = 0`), enregistre la trace dans `last_error`, purge ses assets publiés et écrit `MODULE_SAFE_MODE_TRIGGERED` dans `sys_logs`.

### US 18.3 — Suite E2E Surcharge UI & Safe Mode
- **Création de `tests/e2e/19-sprint18-ui-hooks-safemode.spec.ts` (8/8 Pass Desktop + Mobile) :**
  - `S18-01` : `ui_text`, `ui_slot_*`, `ui_assets` (et filtrage strict des URLs externes/`..`).
  - `S18-02` : `ui_tabs` + alias `stats_tabs` + tri `order`.
  - `S18-03` : `ui_page_override` légitime (`modules/BoutiqueUi/Pages/boutique.php`) et rejet path traversal (`../../.env`, `Core/Api/Database/Database.php`) avec repli sur `home.php` et trace `UI_PAGE_OVERRIDE_BLOCKED` dans `sys_logs`.
  - `S18-04` : Safe Mode sur filtre `ui_text` défaillant — valeur initiale préservée, quarantaine automatique (`status = 'crashed'`, `is_active = 0`, `last_error` renseigné) et trace `MODULE_SAFE_MODE_TRIGGERED` dans `sys_logs`.

---

## 2. Vérifications Qualité & Non-Régression

| Contrôle | Commande | Résultat |
|---|---|---|
| Autoload PSR-4 strict | `composer dump-autoload -o --strict-psr` | ✅ 26 classes — 0 avertissement |
| Analyse statique PHP | `vendor/bin/phpstan analyse Core public modules` | ✅ 56/56 fichiers — 0 erreur |
| Compilation TS / CSS | `npm run build:ts && npm run build:css` | ✅ 0 erreur |
| Suite Sprint 18 | `tests/e2e/19-sprint18-ui-hooks-safemode.spec.ts` | ✅ 8/8 Pass (Desktop + Mobile) |
| Non-régression complète | `npm run db:reset && npm run test:e2e` | ✅ **276/276 Pass** (0 échec) |
