# Kanso-ya — CMS & CRM Associatif Modulaire (V2.3)

**Kanso-ya** est une plateforme web légère, autonome et sans framework lourd conçue pour les clubs sportifs et associations (gestion d'adhérents, inscriptions familiales, trésorerie, studio éditorial, statistiques, console Superadmin et moteur de modules SDK).

---

## Stack Technique

- **Backend :** PHP 8.5+ strict (`declare(strict_types=1)`), architecture modulaire PSR-4 (`Core\Api\*`, `Core\Ui\*`, `Modules\*`), analyse statique **PHPStan niveau 5** (`phpstan.neon` — 0 erreur).
- **Base de données :** PDO pur avec requêtes préparées, **SQLite 3 en mode WAL** (`storage/db/kanso-ya.sqlite`, `busy_timeout = 15000`, transactions `BEGIN IMMEDIATE`), compatibilité MySQL prévue via `.env`.
- **Frontend :** Architecture MPA derrière un Front-Controller unique (`public/index.php` → `Core\Ui\Router\PageRouter`), **TypeScript strict** (`Core/Ui/ts/` → `public/assets/ts/`), **Tailwind CSS compilé localement** (`tailwind.config.js` → `public/assets/css/tailwind.css`, zéro CDN).
- **API REST :** Front-Controller unique `public/api.php` → `Core\Api\Http\ApiKernel`, enveloppe **JSend sur HTTP 200** systématique.
- **Qualité & Tests :** Suite End-to-End **Playwright** (**324/324 Pass** sur Desktop Chrome et Mobile Chrome Pixel 5).

---

## Arborescence Globale

```text
kanso-ya/
├── Core/
│   ├── Api/           # Backend pur (Database, Endpoints, Http, Middleware, Services, Sdk)
│   └── Ui/            # Frontend pur (Router, Templates, Components, Pages, ts/)
├── modules/           # Extensions normalisées ({NomModule}Api/ & {NomModule}Ui/)
├── public/            # DocumentRoot Web (index.php, api.php, .htaccess, assets/, uploads/)
├── storage/           # Données privées hors accès web (db/, backups/, private_uploads/)
├── tests/e2e/         # Suites Playwright isolées (snapshot/restauration SQLite)
└── docs/              # Documentation de référence (ARCHITECTURE.md, ROADMAP.md)
```

---

## Commandes Utiles

```powershell
# 1. Compilation Frontend (TypeScript strict & Tailwind CSS)
npm run build:ts
npm run build:css

# 2. Autoloader PSR-4 strict & Analyse statique PHPStan (niveau 5)
composer dump-autoload -o --strict-psr
vendor\bin\phpstan analyse

# 3. Réinitialisation de la base de test & audit compatibilité MySQL/MariaDB
npm run db:reset
npm run db:check-mysql

# 4. Exécution complète des tests E2E Playwright (Desktop + Mobile)
npm run test:e2e
```

---

## Documentation de Référence

- **Architecture technique :** [`docs/ARCHITECTURE.md`](./docs/ARCHITECTURE.md)
- **Feuille de route (Lots 4 à 6) :** [`docs/ROADMAP.md`](./docs/ROADMAP.md)
- **Dernier sprint clôturé (Lot 6 — Sprint 26 / V2.3 complète) :** `docs/archive/sprint-26/`
- **Archives historiques (V1 & V2) :** `docs/archive/reprise-docs/` (incluant `SDK-MODULES.md`, `DEPLOIEMENT-PRODUCTION.md`, `ETAT-DES-LIEUX.md` et les rapports de sprints).
