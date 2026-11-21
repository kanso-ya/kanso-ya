# Architecture Technique de Référence — Kanso-ya CMS (V2.0)

**Rôle :** @architect (Architecture Logicielle & Base de Données)  
**Dernière mise à jour :** 21 novembre 2026  
**Statut :** Référence Unique Active — Alignée à 100 % sur le code réel (`Core/`, `modules/`, `public/`, `tests/e2e/`)  
**Feuille de route associée :** [`docs/ROADMAP.md`](./ROADMAP.md)

---

## 1. Stack Technique & Principes Directeurs

- **Backend :** Vanilla PHP 8.5+ (`declare(strict_types=1)`), zéro framework lourd, autoloader PSR-4 strict (`composer.json` : `Core\` → `Core/`, `Modules\` → `modules/`, 36 classes, 0 avertissement sous `composer dump-autoload -o --strict-psr`), analyse statique **PHPStan niveau 5** (`phpstan.neon`, 0 erreur).
- **Base de Données :** PDO pur avec requêtes préparées via le Singleton `Core\Api\Database\Database`.
  - Moteur actif : **SQLite 3 en mode WAL** (`storage/db/kanso-ya.sqlite`, `PRAGMA busy_timeout = 15000`, `PRAGMA journal_mode = WAL`, `PRAGMA synchronous = NORMAL`), transactions `BEGIN IMMEDIATE` avec retries.
  - Compatibilité MySQL/MariaDB prévue via `.env` (`DB_CONNECTION=mysql`).
- **Frontend :** Architecture MPA derrière un Front-Controller unique (`public/index.php` → `Core\Ui\Router\PageRouter`), **TypeScript strict** (`Core/Ui/ts/` → `public/assets/ts/`), **Tailwind CSS compilé localement** (`tailwind.config.js` → `public/assets/css/tailwind.css`, 44 Ko, zéro CDN), thème dynamique CSS réel (`--brand-primary`, `--brand-primary-hover`, `--brand-sidebar`, `--brand-header`, `--brand-accent`).
- **Qualité & Non-Régression :** 27 suites Playwright E2E dans `tests/e2e/` (**320/320 Pass** Desktop Chrome + Mobile Chrome Pixel 5) avec snapshot/restauration bit-à-bit de la base SQLite (`tests/setup/`).

---

## 2. Arborescence Réelle du Projet (PSR-4 Sensible à la Casse)

```text
kanso-ya/
├── .env / .env.example                 # APP_ENV, QA_HOOKS_ENABLED, DB_*, SUPERADMIN_URL
├── .htaccess                           # Verrouillage racine (403 sur dossiers internes & dotfiles)
├── composer.json                       # PSR-4 : Core\ -> Core/, Modules\ -> modules/
├── phpstan.neon                        # Analyse statique niveau 5 (Core, public, modules)
├── package.json                        # Scripts build:ts, build:css, db:reset, test:e2e
├── tailwind.config.js                  # Scanning Core/Ui/**, modules/*Ui/**, public/index.php
├── tsconfig.json                       # Compilation Core/Ui/ts/ -> public/assets/ts/
├── playwright.config.ts                # Configuration Playwright (Desktop + Mobile Pixel 5)
├── README.md
│
├── Core/                               # NOYAU APPLICATIF (strictement hors accès HTTP)
│   ├── Api/                            # Couche Backend (namespace Core\Api\*)
│   │   ├── Database/
│   │   │   ├── Database.php               # Singleton PDO + helpers dialectaux SQLite/MySQL + transaction()
│   │   │   ├── check_mysql_compat.php     # Audit SQLite-only & validation DDL MySQL 8 / MariaDB 10.6+
│   │   │   └── Migrations/
│   │   │       ├── 001_core_schema.php      # 17 tables Core (incluant members.gender)
│   │   │       ├── 002_core_seed.php        # Fixtures QA (58 adhérents, 3 disciplines, 21 grades/niveaux, 6 dossiers)
│   │   │       ├── 003_modules_schema.php   # Table modules
│   │   │       ├── 004_smtp_reliability.php # Colonnes relance email_logs & défauts smtp_transport/timeout
│   │   │       └── 005_member_gender.php    # Colonne optionnelle members.gender (RGPD)
│   │   ├── Endpoints/                  # 7 contrôleurs métier REST (JSend HTTP 200)
│   │   │   ├── AuthController.php
│   │   │   ├── CmsController.php
│   │   │   ├── DisciplineController.php
│   │   │   ├── MemberController.php
│   │   │   ├── RegistrationController.php
│   │   │   ├── RoleController.php
│   │   │   └── SystemController.php
│   │   ├── Http/                       # Front-Controller API découpé
│   │   │   ├── ApiKernel.php           # Bootstrap, sendJson(), shutdown handler, hooks QA confinés
│   │   │   ├── ApiRouter.php           # Matching Core + filtre api_routes (/api/v1/modules/{slug}/*)
│   │   │   └── Routes/CoreApiRoutes.php # Déclaration centralisée de toutes les routes /api/v1/*
│   │   ├── Middleware/
│   │   │   └── AuthMiddleware.php
│   │   ├── Sdk/                        # SDK d'extensibilité & Surcharge UI
│   │   │   ├── EventManager.php        # Bus Actions/Filtres + attribution module + quarantaine Safe Mode
│   │   │   ├── ModuleInterface.php     # Contrat install(), uninstall(), boot(EventManager)
│   │   │   └── UiHelpers.php           # Helpers statiques text(), tabs(), slot(), assets()
│   │   └── Services/
│   │       ├── MailerService.php       # Envoi centralisé, chiffrement AES-256-GCM, garde testing & retry
│   │       ├── ModuleManager.php       # Cycle de vie, publication/purge assets, quarantineModule()
│   │       ├── ModuleScanner.php       # Découverte modules/*Api/manifest.json (Core 2.0.0)
│   │       ├── RolloverService.php     # Bascule annuelle de saison
│   │       ├── ScheduleService.php     # Données publiques /horaires
│   │       ├── SmtpClient.php          # Client SMTP natif PHP (TCP/SSL/STARTTLS, AUTH LOGIN/PLAIN)
│   │       └── StatsService.php        # Agrégations KPIs, finances & occupation /stats
│   │
│   └── Ui/                             # Couche Frontend (namespace Core\Ui\*)
│       ├── Router/PageRouter.php       # Routage unique, contrôle Auth/RBAC, ui_routes & ui_page_override
│       ├── Templates/layout.php        # Gabarit maître (garde KANSO_ENTRY, thème --brand-*, ui_assets)
│       ├── Components/feedback.php     # Modales de confirmation & conteneur Toasts
│       ├── Pages/                      # 21 vues internes + errors/403.php & errors/404.php
│       │   ├── home.php, login.php, register.php, activate-otp.php, health-check.php
│       │   ├── horaires.php, legal.php, dashboard.php, settings.php, stats.php
│       │   ├── members.php, registrations.php, cms.php, disciplines.php, roles.php
│       │   ├── sys-admin-login.php, sys-console.php, sys-branding.php, sys-smtp.php
│       │   ├── sys-modules.php, sys-logs.php
│       │   └── errors/{403.php, 404.php}
│       └── ts/                         # 18 modules TypeScript stricts (compilés -> public/assets/ts/)
│
├── modules/                            # MODULES NORMALISÉS ({NomModule}Api / {NomModule}Ui)
│   ├── BoutiqueApi/                    # manifest.json (v2), Module.php, Endpoints/BoutiqueController.php
│   ├── BoutiqueUi/                     # Pages/boutique.php, dist/boutique.css
│   ├── EvenementsApi/                  # manifest.json (v2), Module.php, Endpoints/EvenementsController.php (mod_evenements_*)
│   ├── EvenementsUi/                   # Pages/evenements.php, dist/{evenements.css, evenements.js}
│   ├── FacturationApi/                 # manifest.json (v2), Module.php, Services/{NativePdfBuilder,InvoiceService}, Endpoints/FacturationController.php (mod_facturation_*)
│   ├── FacturationUi/                  # Pages/factures.php, dist/{facturation.css, facturation.js}
│   ├── PresenceApi/                    # manifest.json (v2), Module.php, Endpoints/PresenceController.php (mod_presence_*)
│   ├── PresenceUi/                     # Pages/presences.php, dist/{presence.css, presence.js}
│   ├── CrashTestApi/                   # Démonstrateur Safe Mode (exception volontaire au boot)
│   └── IncompatibleModuleApi/          # Démonstrateur rejet compatibilité (core_min: 3.0.0)
│
├── public/                             # DOCUMENT ROOT WEB (seul dossier exposé HTTP)
│   ├── .htaccess                       # 403 sur .php directs hors index/api + headers sécurité
│   ├── index.php                       # Front-Controller UI unique (define('KANSO_ENTRY', true))
│   ├── api.php                         # Front-Controller API unique (define('KANSO_API', true))
│   ├── assets/                         # css/tailwind.css, ts/*.js, modules/{slug}/ (généré)
│   └── uploads/                        # .htaccess (no-exec), branding/
│
├── storage/                            # Stockage privé hors webroot (db/, backups/, private_uploads/)
├── docs/                               # ARCHITECTURE.md, ROADMAP.md, current-sprint/ (vide), archive/, sources-v2/
└── tests/e2e/                          # 21 suites Playwright (01 à 20, incluant 14a & 14b)
```

---

## 3. Schéma de Base de Données (18 Tables Core + Tables Modulaires `mod_*`)

Défini dans `Core/Api/Database/Migrations/001_core_schema.php` et `003_modules_schema.php` :

| Domaine | Table | Rôle principal |
|---|---|---|
| **Système & Sécurité** | `sys_superadmins` | Comptes Superadmin isolés (étanches vis-à-vis de `users`, anti-brute-force) |
| | `sys_settings` | Paramètres clé/valeur (branding, thème `--brand-*`, SMTP, remises, `medical_cert_mode`, `default_course_capacity`, `demo_mode_enabled`) |
| | `sys_logs` | Journal d'audit technique & sécurité (`UNAUTHORIZED_ACCESS_ATTEMPT`, `MODULE_SAFE_MODE_TRIGGERED`, `UI_PAGE_OVERRIDE_BLOCKED`, backups) |
| | `email_logs` | Traçabilité des envois d'e-mails (destinataire, sujet, type, réponse SMTP, horodatage) |
| | `email_templates` | Modèles d'e-mails transactionnels personnalisables (sujet, HTML, variables) |
| **Utilisateurs & RBAC** | `roles` | Rôles associatifs et permissions JSON (Président, Secrétaire, Trésorier, Entraîneur, Rédacteur, Adhérent, personnalisés) |
| | `users` | Comptes club (mot de passe haché, `role_id`, `is_active`, `otp_code`/`otp_expires_at`, 2FA `totp`/`email`, `custom_fields`) |
| | `app_logs` | Journal d'activité métier du club |
| **CRM & Inscriptions** | `members` | Fiches adhérents (identité, naissance, coordonnées, urgence enrichie, santé Cerfa, statut) |
| | `member_tutor` | Liaison N-N adhérents ↔ tuteurs légaux avec consentement de partage RGPD (`info_shared`) |
| | `registrations` | Dossiers d'adhésion / cotisations (payeur, saison, montant, remise, mode de paiement, échéances, statut) |
| **Sportif** | `disciplines` | Disciplines du club (Judo, Karaté, Aïkido…) et référent enseignant (`teacher_user_id`) |
| | `levels` | Grades d'âge (`level_type = 'grade'`, `age_min`/`age_max`) et niveaux techniques (`level_type = 'niveau'`, `color_hex`) |
| | `member_practices` | Affectations multi-disciplines d'un adhérent (`discipline_id`, `grade_id`, `level_id`) |
| **CMS Éditorial** | `article_categories` | Catégories d'actualités |
| | `articles` | Articles multi-blocs assainis (`status`, `visibility`, `created_by`, `updated_by`) |
| | `pages` | Pages institutionnelles fixes (`rgpd`, `cgv`, `mentions-legales`, `horaires`) |
| **Extensibilité** | `modules` | Registre des modules (`folder_name`, `name`, `version`, `is_active`, `status`, `last_error`) |
| *Modules tiers* | `mod_{slug}_*` | Tables isolées préfixées obligatoirement (ex. `mod_boutique_products`) |

---

## 4. Routage UI (`PageRouter`) & Contrats d'API (`ApiKernel` / `CoreApiRoutes`)

### 4.1. Routes UI Propres (`Core/Ui/Router/PageRouter.php`)
- **Public & Institutionnel :** `/`, `/login`, `/register`, `/activate-otp`, `/questionnaire-sante`, `/horaires`, `/rgpd`, `/cgv`, `/mentions-legales`.
- **Espace Connecté & Gestion Club :** `/dashboard`, `/settings`, `/stats`, `/members`, `/registrations`, `/cms`, `/disciplines`, `/roles`.
- **Console Superadmin :** `/{SUPERADMIN_URL}` (défaut `/sys-admin-xyz`), `/sys-console`, `/sys-branding`, `/sys-smtp`, `/sys-modules`, `/sys-logs`.
- **Modules dynamiques (`ui_routes`) :** ex. `/boutique` (servi uniquement lorsque `BoutiqueApi` est actif, sinon HTTP `404`).

### 4.2. Contrat d'API REST JSend (HTTP 200 Constant)
Toute route API transite par `public/api.php` → `Core\Api\Http\ApiKernel::handle()` → `sendJson()` et retourne obligatoirement **HTTP 200** avec enveloppe normalisée :
```json
{ "status": "success|error", "success": true, "code": 200, "message": "...", "data": {} }
```

| Domaine | Endpoints (`Core/Api/Http/Routes/CoreApiRoutes.php`) |
|---|---|
| **Auth, 2FA & Profil** | `POST /api/v1/auth/login`, `POST /api/v1/auth/forgot-password`, `POST /api/v1/auth/activate-otp`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me`, `POST /api/v1/sys/login`, `GET/PUT /api/v1/users/me/profile`, `POST /api/v1/users/me/2fa`, `POST /api/v1/users/me/2fa/send-email-code`, `PATCH /api/v1/users/me/password` |
| **CRM Adhérents** | `GET/POST /api/v1/members`, `PUT/PATCH/DELETE /api/v1/members/{id}`, `GET /api/v1/members/export`, `POST /api/v1/members/bulk-resend-otp`, `POST /api/v1/members/{id}/resend-otp`, `GET/POST /api/v1/members/{id}/medical-file`, `POST /api/v1/members/{id}/radiate`, `PATCH /api/v1/members/{id}/share-tutor-info` |
| **Inscriptions & Trésorerie** | `GET/POST /api/v1/registrations`, `PATCH /api/v1/registrations/{id}/validate`, `PATCH /api/v1/registrations/{id}/status`, `DELETE /api/v1/registrations/{id}`, `GET /api/v1/registrations/{id}/receipt`, `GET /api/v1/registrations/{id}/cse-attestation` |
| **CMS & Pages** | `GET/POST /api/v1/articles`, `GET/PUT/DELETE /api/v1/articles/{id}`, `PATCH /api/v1/articles/{id}/status`, `POST /api/v1/articles/media`, `POST /api/v1/articles/media/cleanup`, `GET /api/v1/pages`, `GET/PUT /api/v1/pages/{slug}` |
| **Rôles & Disciplines** | `GET/POST /api/v1/roles`, `PATCH /api/v1/roles/{id}[/permissions]`, `DELETE /api/v1/roles/{id}`, `GET/POST /api/v1/disciplines`, `PUT/PATCH/DELETE /api/v1/disciplines/{id}` |
| **Stats & Saison** | `GET /api/v1/stats/kpis`, `GET /api/v1/stats/demographics`, `POST /api/v1/tasks/rollover-season` (alias `POST /api/v1/rollover`) |
| **Système & Modules** | `GET/PATCH/PUT /api/v1/settings`, `GET /api/v1/app-logs`, `GET /api/v1/sys/logs`, `GET /api/v1/sys/email-logs`, `POST /api/v1/sys/email-logs/{id}/retry`, `POST /api/v1/sys/test-email`, `GET /api/v1/sys/email-templates`, `PUT/PATCH /api/v1/sys/email-templates/{key}`, `POST /api/v1/sys/branding-upload`, `GET/POST /api/v1/sys/superadmins`, `PATCH /api/v1/sys/password`, `GET /api/v1/sys/metrics`, `POST /api/v1/sys/backup`, `GET /api/v1/sys/modules`, `POST /api/v1/sys/modules/scan`, `PATCH /api/v1/sys/modules/{id}/toggle`, `POST /api/v1/sys/modules/{id}/uninstall`, `DELETE /api/v1/sys/modules/{id}` |
| **Modules (`api_routes`)** | Confinés sous `/api/v1/modules/{slug}/*` (ex. `GET /api/v1/modules/boutique/products`) |

---

## 5. Architecture Modulaire, Surcharge UI & Safe Mode

- **Découpage `{NomModule}Api` / `{NomModule}Ui` :** `ModuleScanner` découvre `modules/*Api/manifest.json` (schéma v2, `CURRENT_CORE_VERSION = '2.0.0'`) et ignore les dossiers frères `*Ui`. `ModuleManager` publie les assets statiques autorisés (`.js`, `.css`, images — jamais `.php`) vers `public/assets/modules/{public_slug}/` à l'activation et les purge à la désactivation/désinstallation.
- **Bus d'événements (`Core\Api\Sdk\EventManager`) & Helpers (`Core\Api\Sdk\UiHelpers`) :**
  - **Actions :** `on_member_created`, `on_member_deleted`, `on_registration_validated`, `on_article_published`.
  - **Filtres :** `ui_routes`, `api_routes`, `admin_sidebar_menu`, `ui_text`, `ui_tabs` (+ alias `stats_tabs`), `ui_slot_{zone}` (`home_hero`, `home_after_articles`, `dashboard_top`, `dashboard_bottom`, `footer_links`), `ui_page_override` (validation `realpath()` confinée à `Core/Ui/Pages/` et `modules/*Ui/Pages/` + log `UI_PAGE_OVERRIDE_BLOCKED`), `ui_assets` (liste blanche `/assets/...`).
- **Safe Mode Automatique :** chaque hook enregistré pendant `boot()` est attribué à son module (`setCurrentModule()`). Toute `Throwable` levée au `boot()` ou dans un filtre/action UI préserve le rendu HTTP 200, bascule le module en `status = 'crashed'` (`is_active = 0`), purge ses assets et journalise `MODULE_SAFE_MODE_TRIGGERED` dans `sys_logs`.

---

## 6. Conventions & Verrouillage de Sécurité en Place

1. **Verrouillage HTTP multi-couches :**
   - `/.htaccess` (racine) : bloque `403` sur `Core/`, `modules/`, `storage/`, `docs/`, `tests/`, `vendor/`, `.env`, dotfiles et fichiers de build.
   - `/public/.htaccess` : bloque `403` sur tout `.php` direct hors `index.php`/`api.php`, injecte `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`.
   - `/public/uploads/.htaccess` (no-exec PHP/CGI) et `/storage/private_uploads/.htaccess` (`Require all denied`).
2. **Garde applicative :** chaque vue/template/composant sous `Core/Ui/` et `modules/*Ui/` vérifie `if (!defined('KANSO_ENTRY')) { http_response_code(403); exit; }`.
3. **Isolation des hooks QA :** `POST /api/v1/auth/switch-persona` et les jetons `stub-*` ne sont actifs que si `APP_ENV=testing` **ET** `QA_HOOKS_ENABLED=1`.
4. **Rétention des sauvegardes :** `SystemController::backupDatabase()` conserve automatiquement les **10 dernières sauvegardes** SQLite dans `storage/backups/`.

---

## 7. Modules Métier V2.2 / V2.3 (Lot 5 & Lot 6)
- **`PresenceApi` / `PresenceUi` (Sprint 22) :** `/presences`, tables `mod_presence_sessions` & `mod_presence_records`, onglet `/stats` via `ui_tabs`.
- **`EvenementsApi` / `EvenementsUi` (Sprint 23) :** `/evenements`, tables `mod_evenements_events` & `mod_evenements_registrations`, widget accueil via `ui_slot_home_after_articles`.
- **`FacturationApi` / `FacturationUi` (Sprint 24) :** `/factures`, tables `mod_facturation_settings` & `mod_facturation_invoices`, générateur PDF 1.4 natif (`NativePdfBuilder`), pièces jointes PDF MIME dans `SmtpClient`/`MailerService`, hooks `on_registration_validated` et `issue_official_invoice`.
- **`BoutiqueApi` / `BoutiqueUi` v2.3.0 (Sprints 25 & 26) :** `/boutique` (3 onglets : Catalogue & Panier, Suivi Commandes, Administration & Paramètres), 7 tables `mod_boutique_*` (`products`, `variants`, `bundles`, `bundle_items`, `settings`, `orders`, `order_items`), variantes de taille, disponibilité, tarifs préférentiels & `members_only`, flocage personnalisé, lots/packs, paramétrage paiements hors-ligne (paiement en ligne verrouillé « Indisponible pour le moment » et rejeté en `422`) & livraisons, décrémentation/restauration atomique du stock et déclenchement automatique de `issue_official_invoice` sur passage au statut `payee`.
