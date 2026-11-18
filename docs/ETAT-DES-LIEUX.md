# État des Lieux — Kanso-ya CMS (Baseline v1.1, post-Sprint 14)

**Rôle :** @cp (Chef de Projet)
**Date :** 18 novembre 2026
**Référence Git :** `main` @ `96c4b1c` — « Sprint 14 validé (246/246 E2E) »
**Objet :** Bilan factuel de ce qui fonctionne réellement, de ce qui est seulement simulé, et de ce qu'il reste à faire avant la refactorisation V2 (arborescence, modules, surcharge UI, verrouillage sécurité).

> [!IMPORTANT]
> Ce document distingue volontairement **« validé par les tests »** et **« prêt pour la production »**. Les 246 tests E2E sont au vert, mais plusieurs points (§3) **bloquent toute mise en ligne publique** en l'état.

---

## 1. Indicateurs de Qualité Mesurés

| Indicateur | Valeur mesurée | Commentaire honnête |
|---|---|---|
| Tests E2E Playwright | **246/246** (Desktop Chrome + Mobile Chrome Pixel 5) | Couverture fonctionnelle large. Plusieurs anciens tests utilisent encore les URLs `.php` (`/stats.php`, `/members.php`). |
| PHPStan | **0 erreur — niveau 0** (43 fichiers) | Le niveau 0 ne contrôle que les erreurs grossières. Aucune garantie de typage fort à ce stade. |
| Compilation TypeScript | 0 erreur (`tsc` strict) | OK. |
| Compilation Tailwind | « Succès » **mais CSS vide** | `build:css` avertit *« No utility classes were detected »* : aucun `tailwind.config.js` n'existe. Le rendu réel repose sur `<script src="https://cdn.tailwindcss.com">` injecté dans `layout.php` (non destiné à la production). |
| Base de données | SQLite WAL `storage/db/kanso-ya.sqlite` | Le pilote MySQL existe dans `Database.php` mais n'a **jamais été testé** en E2E. |
| Sauvegardes | 140 fichiers dans `storage/backups/` | Les tests génèrent une sauvegarde à chaque run : **aucune politique de rétention**. |

---

## 2. Ce qui est Opérationnel et Validé

### 2.1. Core & Socle Technique
- Front-controller d'API unique `public/api.php` (839 lignes), enveloppe **JSend sur HTTP 200** systématique, filet anti-crash (`sendJson` + shutdown handler).
- Singleton PDO `Core\Api\Database\Database` (SQLite WAL, `busy_timeout` 15 s), transactions `BEGIN IMMEDIATE` sur les écritures, migrations `001` (schéma), `002` (seed QA), `003` (modules).
- URLs propres sans `.php` via `public/.htaccess` (`/login`, `/stats`, `/sys-console`, `/rgpd`…).
- Session **Visiteur strict** par défaut dans `layout.php` (plus aucune session Président injectée par le gabarit).
- Thème dynamique réel via variables `--brand-*` et classes `.bg-brand-primary`, etc.

### 2.2. Authentification, Sécurité Compte & 2FA
- Login standard `/login` + Shadow Login Superadmin sur URL furtive (`/sys-admin-xyz`), étanchéité croisée testée (TC-SEC-01/02).
- Comptes Superadmin isolés (`sys_superadmins`), création de Superadmins secondaires, changement de mot de passe Superadmin, verrouillage après échecs.
- Mot de passe oublié anti-énumération, activation par code OTP 48 h, règles de complexité, double saisie.
- 2FA multi-méthode : TOTP (QR Code SVG natif 30 s, clé manuelle) et code e-mail.
- Matrice RBAC dynamique multi-colonnes (`/roles`), libellés en langage humain, filtrage de la Sidebar par permission.

### 2.3. CRM Adhérents & RGPD
- Annuaire `/members` compact (Sprint 14) : filtres combinés (statut, âge, santé, discipline/grade/niveau), tri, recherche instantanée, export CSV, renvoi d'invitation groupé.
- Multi-disciplines par adhérent (Discipline → Grade d'âge → Niveau/ceinture).
- Rattachements familiaux, co-tuteurs avec consentement de partage RGPD (`info_shared`), anonymisation stricte pour l'Entraîneur (API + UI).
- Certificats médicaux stockés hors racine web (`storage/private_uploads/` + `.htaccess Require all denied`), contrôle MIME, rejet des exécutables.

### 2.4. Inscriptions, Trésorerie & Santé
- Wizard d'inscription public adaptatif (majeur/mineur), panier familial, remises paramétrables, brouillon `localStorage` restauré automatiquement, pré-sélection `?discipline=ID`, réinscription pré-remplie.
- Questionnaire de santé / option certificat médical paramétrable (`/questionnaire-sante`).
- Trésorerie `/registrations` : inspection avec ajustement grade/niveau, validation + OTP, radiation réversible, suppression, reçu et attestation CSE.
- Bascule de saison (`POST /api/v1/tasks/rollover-season` + alias `/api/v1/rollover`).

### 2.5. Studio Éditorial (CMS Builder)
- Builder par blocs (titre, texte, encadré, liste, citation, photo, vidéo courte), colonnes symétriques et asymétriques, drag & drop barre d'outils → canevas et réordonnancement, aperçu, brouillon/relecture/publication, traçabilité auteur/éditeur.
- `CmsController::sanitizeHtml()` (liste blanche, `<video>`, largeur de colonnes), pages fixes (`/rgpd`, `/cgv`, `/mentions-legales`, `/horaires`).
- Interdiction du Studio au Superadmin (403 + masquage menu).

### 2.6. Statistiques
- `/stats` à onglets réellement exclusifs (Vue Générale, Finances [Bureau uniquement, absent du DOM pour l'Entraîneur], Démographie & Occupation, Liste d'appel), une discipline affichée à la fois, alerte de saturation, capacité `default_course_capacity`.
- Filtre `stats_tabs` exploitable par les modules.

### 2.7. Console Superadmin
- Navigation unique par Sidebar (sous-navigation horizontale supprimée au Sprint 14).
- `/sys-console` synthétique : Mode Démo (bascule `demo_mode_enabled`), santé SQLite + sauvegarde 1 clic, modules actifs, derniers événements système, rollover, gestion Superadmins.
- `/sys-branding`, `/sys-smtp` (3 onglets : configuration, modèles, historique), `/sys-modules`, `/sys-logs`.

### 2.8. SDK & Moteur de Modules
- `Core\Sdk\EventManager` (actions + filtres, priorités, `try/catch` par callback), `Core\Sdk\ModuleInterface` (`install/uninstall/boot`).
- `ModuleScanner` (manifeste, compatibilité de version → rejet 422), `ModuleManager` (cycle de vie, tables `mod_*`, Safe Mode au `boot()` → `status = crashed` + `sys_logs`).
- Modules démonstrateurs : `Boutique` (fonctionnel), `CrashTest` (Safe Mode), `IncompatibleModule` (rejet).
- Filtres Core actuellement branchés : **`admin_sidebar_menu`** et **`stats_tabs` uniquement**.

---

## 3. Points Bloquants pour une Mise en Production (Constats d'Audit)

| # | Sévérité | Constat vérifié dans le code | Risque |
|---|---|---|---|
| **S1** | 🔴 **Critique (P0)** | `POST /api/v1/auth/switch-persona` et les jetons `stub-*-token` (dont `stub-superadmin-token`) sont **actifs sans aucune condition d'environnement** dans `public/api.php` (l.106 et l.159-184). | N'importe quel visiteur anonyme peut s'octroyer les droits Superadmin en une requête. |
| **S2** | 🟠 Élevée (P1) | `public/dashboard.php`, `settings.php`, `stats.php` initialisent `$_SESSION['role_name'] ?? 'Président'` (et `user_id ?? 1` pour stats) **avant** le contrôle d'accès du gabarit. | La logique métier et les requêtes SQL s'exécutent avec un rôle Bureau fictif avant le refus d'accès (pas de fuite d'affichage grâce au buffer, mais exécution non autorisée). |
| **S3** | 🟠 Élevée (P1) | Toutes les vues PHP (avec SQL embarqué dans `stats.php`, `horaires.php`, `layout.php`) sont des fichiers directement exécutables dans `public/`. | Surface d'attaque large ; impossible d'appliquer un contrôle d'accès centralisé avant exécution. |
| **S4** | 🟠 Élevée (P1) | Aucun `.htaccess` à la racine du projet. La protection repose entièrement sur le `DocumentRoot` du vHost Laragon (`…/kanso-ya/public`). | Sur un hébergement mutualisé où la racine web = racine projet, `.env`, `storage/db/*.sqlite`, `core/`, `docs/` seraient téléchargeables. |
| **S5** | 🟡 Moyenne (P2) | Tailwind chargé depuis `cdn.tailwindcss.com` à l'exécution ; build CSS vide. | Dépendance tierce en production, pas de CSP stricte possible, performance dégradée. |
| **S6** | 🟡 Moyenne (P2) | Aucun en-tête de sécurité HTTP (CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, HSTS). | Clickjacking, MIME sniffing. |
| **S7** | 🟡 Moyenne (P2) | `public/uploads/` sans `.htaccess` interdisant l'exécution de scripts. | Défense en profondeur absente si un contrôle MIME était contourné. |

---

## 4. Fonctionnalités Simulées ou Partielles (à ne pas survendre)

- **Envoi d'e-mails :** aucun envoi SMTP réel. Les messages sont uniquement **tracés** dans `email_logs` (réponse `250 OK` simulée). Le paramétrage SMTP est stocké mais non exploité par un transport.
- **Ratio H/F** sur `/stats` : valeurs **codées en dur** (54 % / 46 %) côté SSR.
- **MySQL/MariaDB :** code de connexion présent, jamais exécuté par la suite de tests.
- **Safe Mode des filtres UI :** une exception dans un callback de filtre est journalisée via `error_log()` mais le module fautif **n'est ni identifié ni basculé en `crashed`** (seul `boot()` est couvert).
- **Hooks de surcharge UI :** seuls `admin_sidebar_menu` et `stats_tabs` existent ; pas de surcharge de texte, de page, d'onglets génériques ni de slots.
- **Assets de modules :** le module Boutique expose sa vue via `public/boutique.php` (fichier du Core) ; aucun mécanisme de publication des assets d'un module.

---

## 5. Dette Structurelle Identifiée

1. **Casse des répertoires incohérente avec les namespaces :** dossiers `core/` et `core/ui/` en minuscules, namespaces `Core\…` ; `composer.json` mappe `"Core\\": "core/"`. Sur Linux (sensible à la casse), toute future classe `Core\Ui\…` serait introuvable.
2. **`Core\Sdk` hors de la couche Api** (`core/Sdk/`), alors que le SDK est une brique backend.
3. **Monolithe `public/api.php` (839 lignes)** mêlant bootstrap, hooks QA, résolution d'authentification, table de routage et dispatch.
4. **Logique métier dans les vues** : requêtes SQL dans `public/stats.php`, `public/horaires.php` et dans `core/ui/templates/layout.php` (lecture `sys_settings`, permissions).
5. **Modules monolithiques** (`modules/Boutique/` mélange manifeste, backend et rendu), sans séparation Api/Ui.
6. **Hygiène du dépôt** : deux fichiers de tests préfixés `14-` (`14-sprint12-…` et `14-sprint13-…`), `storage/backups/` non purgé, `allure-results/` volumineux.
7. **Dérive documentaire** : `ARCHITECTURE.md` mentionne encore `kanso_ya.sqlite` (underscore) et une arborescence pré-Sprint 10.

---

## 6. Reste à Finaliser — Backlog de la Refactorisation V2

Le détail technique de la cible est décrit dans [`ARCHITECTURE-CIBLE-V2.md`](./ARCHITECTURE-CIBLE-V2.md).

| Lot | Contenu | Priorité | Dépend de |
|---|---|---|---|
| **R0 — Sécurisation immédiate** | Conditionner les hooks QA (`switch-persona`, `stub-*`) à `APP_ENV=testing` + `QA_HOOKS_ENABLED=1` ; supprimer les fallbacks `?? 'Président'` ; ajouter `.htaccess` racine + en-têtes de sécurité + `public/uploads/.htaccess`. | **P0** | — |
| **R1 — Restructuration `Core/Api` & `Core/Ui`** | Renommage sensible à la casse (`core` → `Core`, `ui` → `Ui`, `templates` → `Templates`…), déplacement de `Sdk` vers `Core/Api/Sdk`, éclatement de `api.php` en `Http/Router` + `Routes`, extraction du SQL des vues vers des Services, `composer dump-autoload --strict-psr` sans avertissement. | P1 | R0 |
| **R2 — Front-Controller unique** | `public/index.php` route vers `Core/Ui/Pages/*` via une table de routes ; contrôle d'authentification et de permissions **avant** exécution de la page ; redirections 301 des anciennes URLs `.php` ; suppression de tous les autres `.php` de `public/`. | P1 | R1 |
| **R3 — Modules `{Nom}Api` / `{Nom}Ui`** | Manifeste v2, autoload `Modules\…`, routes API/UI déclarées par les modules, publication des assets vers `public/assets/modules/{slug}/`, migration de `Boutique`, `CrashTest`, `IncompatibleModule`. | P1 | R2 |
| **R4 — Hooks de surcharge UI** | `ui_text`, `ui_tabs` (+ alias `stats_tabs`), `ui_page_override`, `ui_slot_*`, `ui_routes`, `ui_assets` ; Safe Mode étendu aux filtres (identification du module fautif). | P1 | R3 |
| **R5 — Verrouillage strict** | `.htaccess` racine + `public/` définitifs (refus des dossiers sensibles et de tout `.php` hors `index.php`/`api.php`), garde `KANSO_ENTRY` dans les gabarits, configuration Nginx équivalente, tests E2E de non-exposition. | P0/P1 | R2 |
| **R6 — Build & Qualité** | `tailwind.config.js` (contenu `Core/Ui/**`, `modules/*Ui/**`), retrait du CDN, PHPStan niveau 5, rétention des sauvegardes (ex. 10 dernières), renommage des specs dupliquées. | P2 | R1 |
| **R7 — Documentation finale** | `ARCHITECTURE.md` v2, guide développeur de module (`docs/SDK-MODULES.md`), catalogue des hooks UI, guide de déploiement (Apache/Nginx/mutualisé), procédure d'exploitation (sauvegarde/restauration). | P2 | R3, R4, R5 |

### Critère de « Done » global de la refactorisation
- 100 % des tests E2E existants au vert (après mise à jour des URLs legacy) + nouveaux tests de sécurité (accès direct refusé) et de hooks UI.
- `public/` ne contient plus que `index.php`, `api.php`, `.htaccess`, `assets/`, `uploads/` (et éventuellement `favicon.ico`, `robots.txt`).
- `composer dump-autoload -o --strict-psr` : zéro classe ignorée ; PHPStan niveau 5 : zéro erreur.
- Aucune requête HTTP directe vers `/Core/`, `/modules/`, `/storage/`, `/docs/`, `/tests/`, `/vendor/`, `/.env` n'aboutit (403/404) quel que soit le DocumentRoot.
