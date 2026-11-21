# État des Lieux — Kanso-ya CMS (Baseline v1.1, post-Sprint 14)

**Rôle :** @cp (Chef de Projet)  
**Date :** 21 novembre 2026  
**Référence Git :** `main` @ `0f565aa` (« V0.9 - avant refonte arborescence »), code applicatif identique à `96c4b1c` (Sprint 14 — 246/246 E2E)  
**Périmètre audité :** `core/`, `public/`, `modules/`, `src/`, `tests/`, `docs/`, fichiers racine. **Exclus volontairement :** `node_modules/`, `storage/`, `vendor/`.  
**Objet :** Bilan factuel de ce qui est opérationnel et validé, de ce qui reste simulé ou partiel, et de tout ce qu'il reste à finaliser dans la refactorisation V2.

> [!IMPORTANT]
> Ce document distingue **« validé par les tests »** et **« prêt pour la production »**. Les 246 tests E2E sont au vert, mais plusieurs points critiques (§3) **bloquent toute mise en ligne publique** en l'état.

---

## 1. Indicateurs de Qualité Mesurés

| Indicateur | Valeur mesurée | Lecture honnête |
|---|---|---|
| Tests E2E Playwright | **246/246** (Desktop Chrome + Mobile Chrome Pixel 5) | Couverture fonctionnelle large. Plusieurs anciens tests ciblent encore des URLs `.php` legacy (`/stats.php`, `/members.php`, `/sys-console.php`). |
| PHPStan | **0 erreur — niveau 0** (43 fichiers) | Le niveau 0 ne détecte que les erreurs grossières ; aucune garantie de typage fort à ce stade. |
| TypeScript (`npm run build:ts`) | 0 erreur (`tsc` strict) | Compilation propre vers `public/assets/ts/`. |
| Tailwind (`npm run build:css`) | « Done » **mais CSS vide** | Avertissement *« No utility classes were detected »* : aucun `tailwind.config.js` présent. Le rendu repose sur `<script src="https://cdn.tailwindcss.com">` dans `core/ui/templates/layout.php` (non destiné à la production). |
| Base de données | SQLite WAL (`storage/db/kanso-ya.sqlite`, d'après `Database.php`) | Le pilote MySQL existe dans `Database.php` (`DB_CONNECTION`) mais n'a **jamais été testé** en E2E. |

---

## 2. Ce qui est Opérationnel et Validé

### 2.1. Core & Socle Technique
- Routeur d'API REST unique `public/api.php` (839 lignes) : enveloppe **JSend sur HTTP 200** systématique, filet anti-crash (`sendJson` + shutdown handler), protection CSRF.
- Singleton PDO `Core\Api\Database\Database` (SQLite WAL, `busy_timeout` 15 s), transactions `BEGIN IMMEDIATE` sur les écritures, migrations `001` (schéma), `002` (seed QA), `003` (modules).
- URLs propres sans `.php` via `public/.htaccess` (`/login`, `/stats`, `/sys-console`, `/rgpd`, `/questionnaire-sante`…).
- Session **Visiteur strict** par défaut dans `layout.php`.
- Thème dynamique réel (`--brand-primary`, `--brand-primary-hover`, `--brand-sidebar`, `--brand-header`, `--brand-accent`) et classes `.bg-brand-primary`…

### 2.2. Authentification, Sécurité Compte & 2FA
- Login standard `/login` + Shadow Login Superadmin sur URL furtive (`SUPERADMIN_URL`, `/sys-admin-xyz`), étanchéité croisée testée (TC-SEC-01/02).
- Comptes système isolés (`sys_superadmins`) : création de Superadmins secondaires, changement de mot de passe, verrouillage après tentatives échouées.
- Mot de passe oublié anti-énumération, activation par OTP 48 h, règles de complexité et double saisie obligatoire.
- 2FA multi-méthode : TOTP (QR Code SVG natif 30 s + clé manuelle via `core/ui/ts/qrcode.ts`) et code e-mail.
- Matrice RBAC dynamique multi-colonnes (`/roles`), libellés en langage humain, filtrage de la Sidebar par permission.

### 2.3. CRM Adhérents & RGPD
- Annuaire `/members` compact (Sprint 14) : filtres combinés (statut, âge, santé, discipline/grade/niveau), tri, recherche instantanée, export CSV, renvoi d'invitation groupé, actions sur 2 lignes avec infobulles.
- Multi-disciplines par adhérent (Discipline → Grade d'âge → Niveau/ceinture).
- Rattachements familiaux, co-tuteurs avec consentement de partage RGPD (`info_shared`), anonymisation stricte pour l'Entraîneur (API + UI).
- Certificats médicaux enregistrés hors racine web (`storage/private_uploads/`), contrôle MIME, rejet des exécutables, accès HTTP direct refusé (test E2E REQ-CMS-FILE-03).

### 2.4. Inscriptions, Trésorerie & Santé
- Wizard d'inscription public adaptatif (majeur/mineur), panier familial, remises paramétrables, brouillon `localStorage` restauré automatiquement, pré-sélection `?discipline=ID`, réinscription pré-remplie.
- Questionnaire de santé / option certificat médical paramétrable (`/questionnaire-sante`).
- Trésorerie `/registrations` compacte : inspection avec ajustement grade/niveau, validation + OTP, radiation réversible, suppression, reçu officiel et attestation CSE.
- Bascule de saison (`POST /api/v1/tasks/rollover-season` + alias `POST /api/v1/rollover`).

### 2.5. Studio Éditorial (CMS Builder)
- Blocs (titre, texte, encadré, liste, citation, photo, vidéo courte), colonnes symétriques et asymétriques, drag & drop barre d'outils → canevas et réordonnancement, aperçu, workflow brouillon/relecture/publication, traçabilité auteur/éditeur.
- `CmsController::sanitizeHtml()` en liste blanche ; pages fixes (`/rgpd`, `/cgv`, `/mentions-legales`, `/horaires`).
- Studio interdit au Superadmin (403 + masquage menu).

### 2.6. Statistiques
- `/stats` à onglets exclusifs (Vue Générale, Finances [Bureau uniquement, absent du DOM pour l'Entraîneur], Démographie & Occupation, Liste d'appel), une discipline affichée à la fois, alerte de saturation, capacité `default_course_capacity`.
- Filtre `stats_tabs` exploitable par les modules.

### 2.7. Console Superadmin
- Navigation unique par Sidebar (sous-navigation horizontale retirée au Sprint 14).
- `/sys-console` synthétique : Mode Démo (bascule `demo_mode_enabled`), santé SQLite + sauvegarde 1 clic, modules actifs, derniers événements, rollover, gestion des comptes Superadmin.
- `/sys-branding`, `/sys-smtp` (3 onglets : configuration, modèles, historique), `/sys-modules`, `/sys-logs`.

### 2.8. SDK & Moteur de Modules
- `Core\Sdk\EventManager` (actions/filtres, priorités, `try/catch` par callback) et `Core\Sdk\ModuleInterface` (`install/uninstall/boot`).
- `ModuleScanner` (manifeste, compatibilité → rejet 422), `ModuleManager` (cycle de vie, tables `mod_*`, Safe Mode au `boot()` → `status = crashed` + `sys_logs`).
- Démonstrateurs : `Boutique` (fonctionnel), `CrashTest` (Safe Mode), `IncompatibleModule` (rejet).
- Filtres Core réellement branchés : **`admin_sidebar_menu`** et **`stats_tabs` uniquement**.

---

## 3. Points Bloquants pour la Production (Constats d'Audit)

| # | Sévérité | Constat vérifié dans le code | Risque |
|---|---|---|---|
| **S1** | 🔴 **Critique (P0)** | `POST /api/v1/auth/switch-persona` et les jetons `stub-*-token` (dont `stub-superadmin-token`) sont actifs **sans condition d'environnement** dans `public/api.php` (l.106, l.159-184). `.env` ne définit aucun `APP_ENV`. | N'importe quel visiteur anonyme peut s'octroyer les droits Superadmin en une requête. |
| **S2** | 🟠 P1 | `public/dashboard.php`, `settings.php`, `stats.php` initialisent `$_SESSION['role_name'] ?? 'Président'` (et `user_id ?? 1` dans `stats.php`) **avant** le contrôle d'accès du gabarit. | Exécution de requêtes métier avec un rôle Bureau fictif avant le refus d'accès (pas de fuite d'affichage grâce au buffer, mais logique exécutée à tort). |
| **S3** | 🟠 P1 | 22 fichiers `.php` directement exécutables dans `public/`, dont des vues embarquant du SQL (`stats.php`, `horaires.php`) ; `layout.php` interroge également la BDD. | Contrôle d'accès non centralisé et surface d'attaque exposée. |
| **S4** | 🟠 P1 | Aucun `.htaccess` à la racine du projet : la protection repose uniquement sur le `DocumentRoot` Laragon (`…/kanso-ya/public`). | Sur un hébergement mutualisé où la racine web = racine projet, `.env`, la base SQLite, `core/`, `docs/` deviendraient téléchargeables. |
| **S5** | 🟡 P2 | Tailwind chargé via CDN à l'exécution, build CSS vide. | Dépendance tierce, CSP stricte impossible. |
| **S6** | 🟡 P2 | Absence d'en-têtes de sécurité HTTP (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`). | Clickjacking, MIME sniffing. |
| **S7** | 🟡 P2 | `public/uploads/` sans `.htaccess` interdisant l'exécution de scripts. | Défense en profondeur absente en cas de contournement d'upload. |

---

## 4. Fonctionnalités Simulées ou Partielles

- **E-mails :** aucun transport SMTP réel ; les envois sont uniquement **tracés** dans `email_logs` (réponse `250 OK` simulée). La configuration SMTP est stockée mais inexploitée.
- **Ratio H/F** sur `/stats` : valeurs **codées en dur** (54 % / 46 %).
- **MySQL/MariaDB :** code présent dans `Database.php`, jamais validé en E2E.
- **Safe Mode des filtres :** une exception dans un callback de filtre est seulement consignée dans `error_log()` sans identifier ni isoler le module fautif (seul `boot()` est couvert).
- **Hooks de surcharge UI :** inexistants hors `admin_sidebar_menu` et `stats_tabs` (pas de surcharge de texte, d'onglets génériques, de pages ni de slots).
- **Pages & assets de modules :** la vue Boutique est servie par `public/boutique.php` (fichier du Core) ; aucun mécanisme de publication des assets d'un module.

---

## 5. Dette Structurelle

1. **Casse incohérente avec PSR-4 Linux :** dossiers `core/`, `core/ui/`, `core/ui/templates/`, `core/ui/components/` en minuscules face aux namespaces `Core\…` (`composer.json` mappe `"Core\\": "core/"`).
2. **`Core\Sdk` hors de `Core/Api/`** (`core/Sdk/`).
3. **Monolithe `public/api.php` (839 lignes)** mêlant bootstrap, hooks QA, authentification, table de routage et dispatch.
4. **Logique métier/SQL dans les vues** (`public/stats.php`, `public/horaires.php`, `core/ui/templates/layout.php`).
5. **Modules monolithiques** (`modules/Boutique/Module.php` mélange backend et rendu), sans séparation Api/Ui.
6. **Hygiène des tests :** deux specs préfixées `14-` (`14-sprint12-theme-auth-ux`, `14-sprint13-ux-flows`), dossier `tests/legacy/`.

---

## 6. Reste à Finaliser — Backlog de la Refactorisation V2

Spécification technique complète : [`ARCHITECTURE-CIBLE-V2.md`](./ARCHITECTURE-CIBLE-V2.md).

| Lot | Contenu | Priorité | Dépend de |
|---|---|---|---|
| **R0 — Sécurisation immédiate** | Confiner les hooks QA (`switch-persona`, `stub-*`) à `APP_ENV=testing` **et** `QA_HOOKS_ENABLED=1` ; supprimer les fallbacks `?? 'Président'` ; ajouter `.htaccess` racine + `public/uploads/.htaccess` + en-têtes HTTP. | **P0** | — |
| **R1 — Restructuration `Core/Api` & `Core/Ui`** | Renommage sensible à la casse (`core`→`Core`, `ui`→`Ui`, `templates`→`Templates`…), déplacement `Sdk` → `Core/Api/Sdk`, éclatement de `api.php` (`Http/ApiKernel`, `Http/ApiRouter`, `Routes/`), extraction du SQL des vues vers des Services, `composer dump-autoload -o --strict-psr` sans avertissement. | P1 | R0 |
| **R2 — Front-Controller unique** | `public/index.php` → `Core/Ui/Router/PageRouter` → `Core/Ui/Pages/*` ; authentification et permissions vérifiées **avant** exécution de la page ; suppression de tous les autres `.php` de `public/`. | P1 | R1 |
| **R3 — Modules `{Nom}Api` / `{Nom}Ui`** | Manifeste v2, autoload `Modules\…`, routes API/UI déclarées par les modules, publication d'assets vers `public/assets/modules/{slug}/`, migration de `Boutique`, `CrashTest`, `IncompatibleModule`, suppression de `public/boutique.php`. | P1 | R2 |
| **R4 — Hooks de surcharge UI** | `ui_text`, `ui_tabs` (+ alias `stats_tabs`), `ui_page_override`, `ui_slot_*`, `ui_routes`, `ui_assets` ; Safe Mode étendu aux filtres avec quarantaine du module fautif. | P1 | R3 |
| **R5 — Verrouillage strict** | `.htaccess` racine et `/public/` définitifs (blocage 403 des dossiers internes et de tout `.php` hors `index.php`/`api.php`), garde `KANSO_ENTRY`, équivalent Nginx, tests E2E de non-exposition. | P0/P1 | R2 |
| **R6 — Build & Qualité** | `tailwind.config.js` (`Core/Ui/**`, `modules/*Ui/**`), retrait du CDN Tailwind, PHPStan niveau 5, politique de rétention des sauvegardes, normalisation des noms de specs. | P2 | R1 |
| **R7 — Documentation finale** | `ARCHITECTURE.md` v2, guide développeur de modules (`docs/SDK-MODULES.md`), catalogue des hooks UI, guide de déploiement (Apache / Nginx / mutualisé) et procédure d'exploitation. | P2 | R3–R5 |

### Définition de « Done » de la refactorisation
- 100 % des tests E2E existants verts (URLs legacy migrées) + nouveaux tests de sécurité (accès direct refusé) et de surcharge UI.
- `/public/` ne contient plus que `index.php`, `api.php`, `.htaccess`, `assets/`, `uploads/` (+ `favicon.ico`, `robots.txt` tolérés).
- `composer dump-autoload -o --strict-psr` : 0 classe ignorée ; PHPStan niveau 5 : 0 erreur.
- Aucune requête HTTP vers `/Core/`, `/modules/`, `/storage/`, `/docs/`, `/tests/`, `/vendor/`, `/.env` ou un `.php` autre que `index.php`/`api.php` n'aboutit (403/404), quel que soit le `DocumentRoot`.
