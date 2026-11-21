# Rapport de Clôture — Sprint 16 (Lots R1 & R2 : Arborescence PSR-4 Stricte & Front-Controller Unique)

- **Date :** 21/11/2026
- **Jalon :** Phase V2 — Sprint 16 (Lots R1 & R2 — Priorité P1, 21 SP)
- **Statut global :** ✅ **100 % VALIDÉ EN RECETTE (262/262 E2E Pass, 0 erreur PHPStan, 0 erreur TS/CSS, PSR-4 strict OK)**

---

## 1. Synthèse des Livrables

### US 16.1 — Renommage PSR-4 sensible à la casse (`Core/Api`, `Core/Ui`) & déplacement du SDK
- **Renommages en 2 étapes (`git mv`, compatibilité Windows/Linux) & nettoyage :**
  - `core/` → `Core/`
  - `Core/ui/` → `Core/Ui/`
  - `Core/Ui/templates/` → `Core/Ui/Templates/`
  - `Core/Ui/components/` → `Core/Ui/Components/`
  - `Core/Api/Database/migrations/` → `Core/Api/Database/Migrations/`
  - `Core/Sdk/` → `Core/Api/Sdk/`
  - Suppression de l'ancien template orphelin `Core/Ui/Templates/sys-nav.php`.
- **SDK & rétrocompatibilité (`Core/Api/Sdk/`) :**
  - Migration de `EventManager.php` et `ModuleInterface.php` sous `namespace Core\Api\Sdk;`.
  - Ajout des `class_alias` rétrocompatibles `Core\Sdk\EventManager` et `Core\Sdk\ModuleInterface` (en conservant les alias `Core\Api\Services\*`) afin de préserver les modules v1 avant leur migration au Sprint 17.
- **Configuration & imports :**
  - Mise à jour de `composer.json` (`"Core\\": "Core/"`), `tsconfig.json` (`./Core/Ui/ts`), `package.json` (`db:reset`), `tests/setup/global-setup.ts`, `Core/` et `public/`.

### US 16.2 — Éclatement de `public/api.php` & Extraction SQL des vues
- **Architecture HTTP API (`Core/Api/Http/`) :**
  - `Core/Api/Http/ApiKernel.php` : bootstrap centralisé (`ob_start()`, `session_start()`, shutdown handler), `sendJson()` JSend HTTP 200 invariable, `isQaHooksEnabled()` strictement confiné à `APP_ENV=testing` & `QA_HOOKS_ENABLED=1`, journalisation `UNAUTHORIZED_ACCESS_ATTEMPT`, résolution du contexte session/auth/RBAC, boot des modules et délégation au routeur.
  - `Core/Api/Http/ApiRouter.php` : matching exact et regex `#...#` sur `CoreApiRoutes` + point d'extension `EventManager::applyFilters('api_routes', ...)` prêt pour les modules v2 (Sprint 17).
  - `Core/Api/Http/Routes/CoreApiRoutes.php` : centralisation de 100 % des routes `/api/v1/*` sans altération de contrat JSend ni des règles RBAC.
  - Réduction de `public/api.php` à un point d'entrée minimal (15 lignes) définissant `KANSO_API` et appelant `(new \Core\Api\Http\ApiKernel())->handle()`.
- **Extraction du SQL des vues vers des services dédiés :**
  - `Core/Api/Services/StatsService.php` (`getDashboardData()`) : remplace tout le SQL direct de `stats.php`.
  - `Core/Api/Services/ScheduleService.php` (`getPublicScheduleData()`) : remplace tout le SQL direct de `horaires.php`.

### US 16.3 & US 16.4 — Front-Controller UI unique (`PageRouter`), garde `KANSO_ENTRY` & épuration de `public/`
- **Migration des 22 vues métier de `public/` vers `Core/Ui/Pages/` (`git mv`) :**
  - `home.php` (ex `public/index.php`), `login.php`, `register.php`, `activate-otp.php`, `health-check.php`, `horaires.php`, `legal.php`, `dashboard.php`, `settings.php`, `stats.php`, `members.php`, `registrations.php`, `cms.php`, `disciplines.php`, `roles.php`, `boutique.php`, `sys-admin-login.php`, `sys-console.php`, `sys-branding.php`, `sys-smtp.php`, `sys-modules.php`, `sys-logs.php`.
  - Création des vues d'erreur dédiées `Core/Ui/Pages/errors/403.php` et `Core/Ui/Pages/errors/404.php`.
- **Garde `KANSO_ENTRY` :**
  - Protection `if (!defined('KANSO_ENTRY')) { http_response_code(403); exit('Direct access forbidden.'); }` ajoutée sur les 26 fichiers UI (`Core/Ui/Pages/*.php`, `Core/Ui/Pages/errors/*.php`, `Core/Ui/Templates/layout.php`, `Core/Ui/Components/feedback.php`).
- **Routeur frontal `Core/Ui/Router/PageRouter.php` & `public/index.php` minimal :**
  - Résolution de toutes les routes propres (`/` à `/sys-logs`, `$SUPERADMIN_URL`, injection `slug` pour `/rgpd`, `/cgv`, `/mentions-legales`), conservation temporaire des alias `.php` legacy jusqu'au Sprint 19, hooks `ui_routes` / `ui_page_override` filtrés par `realpath()`, et réponse HTTP `404` standardisée (`errors/404.php`) pour toute route inconnue.
  - Mise à jour de `public/.htaccess` : `/api(/|$)` → `api.php`, fichiers statiques non-`.php` servis directement, tout le reste → `index.php [L,QSA]`.
- **Épuration stricte de `public/` :**
  - Seuls `public/index.php` et `public/api.php` subsistent comme fichiers `.php` dans tout l'arbre `public/`.

### Correctif QA détecté en recette (`Core/Api/Services/ModuleManager.php`)
- **Anomalie détectée par `07-sprint6-modules-sdk.spec.ts` (`TC-MOD-03`) :** lors du chargement d'un module v1 (`modules/Boutique/Module.php` implémentant l'alias `Core\Sdk\ModuleInterface`), `Core\Api\Sdk\ModuleInterface` n'était pas encore autoloadé avant le `require_once` du module, empêchant l'enregistrement du `class_alias` et déclenchant le Safe Mode.
- **Correction appliquée :** préchargement explicite de `ModuleInterface` et `EventManager` (`interface_exists(ModuleInterface::class, true)` / `class_exists(EventManager::class, true)`) dans `ModuleManager::loadModuleInstance()` avant `require_once`, et ajout d'un garde d'idempotence `private static bool $booted = false` dans `ModuleManager::boot()` pour éviter un double amorçage entre `PageRouter` et `layout.php`.

---

## 2. Vérifications Qualité, PSR-4 & Non-Régression

| Contrôle | Commande | Résultat |
|---|---|---|
| Autoload PSR-4 strict | `composer dump-autoload -o --strict-psr` | ✅ **21 classes — 0 avertissement, 0 classe ignorée** |
| Analyse Statique PHP | `vendor\bin\phpstan analyse Core public` | ✅ **51/51 fichiers — 0 erreur** |
| Compilation TypeScript | `npm run build:ts` | ✅ **0 erreur** |
| Compilation Tailwind CSS | `npm run build:css` | ✅ **0 erreur** (813 ms) |
| Réinitialisation Fixtures SQLite | `npm run db:reset` | ✅ **OK** (58 adhérents, 3 disciplines, 21 grades, 6 dossiers) |
| Suite Sprint 16 (PSR-4 & Front-Controller) | `npx playwright test tests/e2e/17-sprint16-psr4-frontcontroller.spec.ts` | ✅ **8/8 Pass** (4/4 Desktop `chromium` + 4/4 `Mobile Chrome`) |
| Suite E2E Complète (Desktop + Mobile Pixel 5) | `npm run db:reset && npm run test:e2e` | ✅ **262/262 Pass** (0 échec, 0 flaky, 1.9 min) |

### Détail de la suite dédiée `tests/e2e/17-sprint16-psr4-frontcontroller.spec.ts`
- **S16-01 :** Vérification récursive (`fs.readdirSync`) que `public/` ne contient strictement que `api.php` et `index.php` comme fichiers `.php`.
- **S16-02 :** Vérification en CLI (`php Core/Ui/Pages/home.php`) de la garde `KANSO_ENTRY` (`Direct access forbidden` sans rendu HTML).
- **S16-03 :** Vérification du routage via `public/index.php` → `PageRouter` sur `/`, `/horaires`, `/login`, `/register`, `/rgpd`, `/boutique`, `/stats`, `/members`, `/sys-console`, et HTTP `404` avec vue 404 propre sur `/page-inexistante-v2`.
- **S16-04 :** Vérification de la conformité PSR-4 (`composer dump-autoload -o --strict-psr`) et du bon fonctionnement de l'API via `ApiKernel` (`GET /api/v1/settings` → JSend HTTP 200).
