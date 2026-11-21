# Rapport de Clôture — Sprint 17 (Lot R3 : Modules Normalisés Api/Ui, Manifeste v2, Routes & Assets)

- **Date :** 21/11/2026
- **Jalon :** Phase V2 — Sprint 17 (Lot R3 — Priorité P1, 18 SP)
- **Statut global :** ✅ **100 % VALIDÉ EN RECETTE (268/268 E2E Pass, 0 erreur PHPStan, 0 erreur TS/CSS, PSR-4 strict OK)**

---

## 1. Synthèse des Livrables

### US 17.1 — Manifeste v2, Autoload PSR-4 `Modules\` & Cycle de Vie Assets
- **`composer.json` :**
  - Déclaration du mapping PSR-4 `"Modules\\": "modules/"` avec génération optimisée stricte (`25 classes`, 0 avertissement).
- **`Core/Api/Services/ModuleScanner.php` & `Core/Api/Services/ModuleManager.php` :**
  - Découverte normalisée des modules v2 dans `modules/{NomModule}Api/manifest.json` (schéma v2 : `schema_version: 2`, `entry_class`, `ui_folder`, `compatibility.core_min`, `compatibility.php_min`, `tables_prefix`, `assets.publish_dir`, `assets.public_slug`), en ignorant les dossiers purement UI (`*Ui`).
  - Chargement prioritaire via `entry_class` PSR-4 et gestion du cycle de vie complet (`install`, `boot`, `uninstall`, Safe Mode automatique).
  - Publication sécurisée des assets statiques autorisés (`.js`, `.css`, `.map`, `.svg`, `.png`, `.jpg`, `.jpeg`, `.webp`, `.woff`, `.woff2` — jamais `.php` ni dotfiles, contrôle anti-path-traversal `realpath()`) depuis `modules/{publish_dir}` vers `public/assets/modules/{public_slug}/`, et purge récursive à la désactivation/désinstallation.

### US 17.2 — Routes Modulaires UI (`ui_routes`) & API (`api_routes`) Confinées
- **`Core/Ui/Router/PageRouter.php` :**
  - Suppression définitive des routes `/boutique` et `/boutique.php` codées en dur dans `buildRoutes()`.
  - Résolution dynamique des routes UI déclarées via le filtre `ui_routes` avec contexte `['uri' => $uri]` et validation stricte `realpath()` dans `isAllowedPagePath()` (fichiers `.php` uniquement dans `Core/Ui/Pages/` ou `modules/*Ui/Pages/`).
  - Contrôle d'accès déclaratif sur les routes UI (`auth`, `perms`, `superadmin_only`) via `checkRouteAccess()` / `renderAccessDenied()` (HTTP 401 / HTTP 403).
- **`Core/Api/Http/ApiRouter.php` :**
  - Confinement strict des routes API injectées via `api_routes` au namespace `/api/v1/modules/{slug}/*` (validation du pattern et de l'URI matchée).
  - Support des méthodes HTTP (`string|array`), patterns exacts ou regex `#...#`, RBAC déclaratif (`auth`, `perms`) et garantie d'enveloppe JSend HTTP 200 via `ApiKernel::sendJson()`.

### US 17.3 — Migration des Modules Démonstrateurs vers l'Architecture V2
- **Boutique → `modules/BoutiqueApi/` + `modules/BoutiqueUi/` :**
  - `modules/BoutiqueApi/manifest.json` (schéma v2, `id: "boutique"`, `version: "2.0.0"`, `entry_class: "Modules\\BoutiqueApi\\Module"`, `ui_folder: "BoutiqueUi"`, `assets: {"publish_dir": "BoutiqueUi/dist", "public_slug": "boutique"}`).
  - `modules/BoutiqueApi/Endpoints/BoutiqueController.php` exposant `GET /api/v1/modules/boutique/products` (JSend HTTP 200).
  - `modules/BoutiqueApi/Module.php` déclarant `admin_sidebar_menu` (`/boutique`), `ui_routes` (`/boutique`, `/boutique.php`), `api_routes` (`/api/v1/modules/boutique/products`), ainsi que `install()` / `uninstall()` sur `mod_boutique_products`.
  - Déplacement de la vue vers `modules/BoutiqueUi/Pages/boutique.php` (garde `KANSO_ENTRY`, chargement de `/assets/modules/boutique/boutique.css`) et création de `modules/BoutiqueUi/dist/boutique.css`.
  - Suppression définitive de `modules/Boutique/` et `Core/Ui/Pages/boutique.php`.
- **CrashTest → `modules/CrashTestApi/` & IncompatibleModule → `modules/IncompatibleModuleApi/` :**
  - Migration complète en manifeste v2 (`version: "2.0.0"`, `Modules\CrashTestApi\Module`, `Modules\IncompatibleModuleApi\Module`) et suppression des anciens dossiers V1.
- **Hygiène Git :**
  - Ajout de `/public/assets/modules/*` et `!/public/assets/modules/.gitkeep` dans `.gitignore` avec `public/assets/modules/.gitkeep`.

### Correctif QA détecté en recette (`Core/Ui/ts/cms.ts`)
- **Anomalie détectée par `13-cms-builder-advanced.spec.ts` (`US 13.1`) :** sous forte charge parallèle (6 workers Playwright), le `setTimeout(..., 200)` du gestionnaire `dragend` précédent pouvait s'exécuter juste après le `dragstart` suivant et réinitialiser `draggedToolbarType` à `null` avant le `drop`.
- **Correction appliquée :** mémorisation de l'identifiant du timer (`dragEndCleanupTimer`) et annulation systématique (`clearTimeout`) au début de chaque `dragstart` et avant chaque nouveau `dragend`.

---

## 2. Vérifications Qualité, PSR-4 & Non-Régression

| Contrôle | Commande | Résultat |
|---|---|---|
| Autoload PSR-4 strict | `composer dump-autoload -o --strict-psr` | ✅ **25 classes — 0 avertissement, 0 classe ignorée** |
| Analyse Statique PHP | `vendor\bin\phpstan analyse Core public modules` | ✅ **55/55 fichiers — 0 erreur** |
| Compilation TypeScript | `npm run build:ts` | ✅ **0 erreur** |
| Compilation Tailwind CSS | `npm run build:css` | ✅ **0 erreur** (854 ms) |
| Réinitialisation Fixtures SQLite | `npm run db:reset` | ✅ **OK** (58 adhérents, 3 disciplines, 21 grades, 6 dossiers) |
| Suite Sprint 6 (Modules SDK V2) | `tests/e2e/07-sprint6-modules-sdk.spec.ts` | ✅ **12/12 Pass** (6/6 Desktop `chromium` + 6/6 `Mobile Chrome`) |
| Suite Sprint 16 (PSR-4 & Front-Controller) | `tests/e2e/17-sprint16-psr4-frontcontroller.spec.ts` | ✅ **8/8 Pass** (4/4 Desktop `chromium` + 4/4 `Mobile Chrome`) |
| Suite Sprint 17 (Modules V2 Api/Ui & Assets) | `tests/e2e/18-sprint17-modules-v2.spec.ts` | ✅ **6/6 Pass** (3/3 Desktop `chromium` + 3/3 `Mobile Chrome`) |
| Suite E2E Complète (Desktop + Mobile Pixel 5) | `npm run db:reset && npm run test:e2e` | ✅ **268/268 Pass** (0 échec, 0 flaky, 2.2 min) |

### Détail de la nouvelle suite dédiée `tests/e2e/18-sprint17-modules-v2.spec.ts`
- **S17-01 :** Vérification sur disque de la structure normalisée (`modules/BoutiqueApi`, `modules/BoutiqueUi`, `modules/CrashTestApi`, `modules/IncompatibleModuleApi`), du manifeste v2 (`schema_version: 2`, `version: "2.0.0"`), et de l'absence définitive de `Core/Ui/Pages/boutique.php`, `public/boutique.php` et des anciens dossiers V1.
- **S17-02 :** Activation de `BoutiqueApi` → publication automatique de `public/assets/modules/boutique/boutique.css` (HTTP 200, zéro `.php` publié), page `/boutique` servie dynamiquement via `ui_routes` avec ses 3 produits, et endpoint confiné `GET /api/v1/modules/boutique/products` retournant JSend HTTP 200 avec les 3 produits.
- **S17-03 :** Désinstallation de `BoutiqueApi` → purge complète du dossier `public/assets/modules/boutique/` (`boutique.css` en 404), `/boutique` renvoyant HTTP `404`, et `GET /api/v1/modules/boutique/products` renvoyant JSend `404`.
