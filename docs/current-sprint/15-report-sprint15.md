# Rapport de Clôture — Sprint 15 (Lot R0 : Durcissement Sécurité Immédiat & Isolation Environnement)

- **Date :** 21/11/2026
- **Jalon :** Phase V2 — Sprint 15 (Lot R0 — Priorité P0, Bloquant Production, 13 SP)
- **Statut global :** ✅ **100 % VALIDÉ EN RECETTE (254/254 E2E Pass, 0 erreur PHPStan, 0 erreur TS/CSS)**

---

## 1. Synthèse des Livrables

### US 15.1 — Confinement strict des hooks QA (`APP_ENV=testing` & `QA_HOOKS_ENABLED=1`)
- **Double garde dans `public/api.php` (`isQaHooksEnabled()`):**
  - L'endpoint de test `POST /api/v1/auth/switch-persona` et l'ensemble des jetons factices `stub-*-token` (`stub-superadmin-token`, etc.) sont désormais strictement conditionnés à la réunion simultanée des deux variables d'environnement `APP_ENV === 'testing'` **ET** `QA_HOOKS_ENABLED === '1'`.
  - Dès que `APP_ENV=production` ou `QA_HOOKS_ENABLED=0`, `POST /api/v1/auth/switch-persona` renvoie un refus JSend `404` et les jetons `stub-*` sont totalement ignorés (`403`).
- **Configuration environnement :** ajout de `.env.example` documentant `APP_ENV` et `QA_HOOKS_ENABLED` (désactivés par défaut en production).

### US 15.2 — Suppression des fallbacks de rôle privilégié (`?? 'Président'` / `?? 1`)
- **Assainissement de `public/dashboard.php`, `public/settings.php` et `public/stats.php` :**
  - Remplacement définitif des initialisations permissives `$_SESSION['role_name'] ?? 'Président'` et `$_SESSION['user_id'] ?? 1` par un statut `Visiteur` (`user_id = 0`) strict en l'absence de session authentifiée.
  - Tout visiteur anonyme accédant à `/dashboard`, `/settings` ou `/stats` est bloqué sur l'écran `Accès Protégé` sans aucune fuite de données financières, d'adhérents ni de formulaires métier.

### US 15.3 — Défense périmétrique `.htaccess`, En-têtes HTTP de Sécurité & Correctif Orphelins
- **Verrouillage racine (`/.htaccess`) :** interdiction HTTP `403` sur les répertoires internes et sensibles (`Core/`, `core/`, `modules/`, `storage/`, `docs/`, `tests/`, `vendor/`, `node_modules/`, `src/`, artefacts) ainsi que sur les fichiers cachés (`.env`, `.git`, `.htaccess`) et fichiers de configuration/build (`composer.json`, `package.json`, `playwright.config.ts`, etc.).
- **Durcissement `/public/.htaccess` :** ajout des 4 en-têtes HTTP de sécurité sur toutes les réponses (`X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`).
- **Verrouillage `/public/uploads/.htaccess` :** défense en profondeur interdisant le listing et toute exécution de scripts (`Options -Indexes -ExecCGI`, blocage `Require all denied` sur `.php`, `.phtml`, `.phar`, `.cgi`, `.sh`, etc.).
- **Correctif de sécurité détecté en recette QA (`core/Api/Endpoints/CmsController.php`) :** `cleanupOrphanMedia()` supprimait les fichiers cachés du dossier `public/uploads/` (dont `public/uploads/.htaccess`). La boucle ignore désormais systématiquement tous les fichiers commençant par `.` (`str_starts_with($file, '.')`) ainsi que les sous-répertoires (`!is_file(...)`).

---

## 2. Vérifications Qualité, Sécurité & Non-Régression

| Contrôle | Commande | Résultat |
|---|---|---|
| Analyse Statique PHP | `vendor/bin/phpstan analyse core public` | ✅ **43/43 fichiers — 0 erreur** |
| Compilation TypeScript | `npm run build:ts` | ✅ **0 erreur** |
| Compilation Tailwind CSS | `npm run build:css` | ✅ **0 erreur** |
| Suite Sécurité Sprint 15 (R0) | `npx playwright test tests/e2e/16-sprint15-security-r0.spec.ts` | ✅ **8/8 Pass** (4/4 Desktop `chromium` + 4/4 `Mobile Chrome`) |
| Suite E2E Complète (Desktop + Mobile Pixel 5) | `npm run db:reset && npm run test:e2e` | ✅ **254/254 Pass** (0 échec) |

### Détail de la suite dédiée `tests/e2e/16-sprint15-security-r0.spec.ts`
- **S15-01 :** Vérification des 4 en-têtes HTTP de sécurité sur `/` et `/login`.
- **S15-02 :** Vérification du blocage HTTP (`403` / `404`) sans fuite de contenu sur `/.env`, `/.htaccess`, `/Core/Api/Database/Database.php` et `/storage/db/kanso-ya.sqlite`.
- **S15-03 :** Vérification du blocage strict (`Accès Protégé`) pour un visiteur anonyme sur `/dashboard`, `/settings` et `/stats`.
- **S15-04 :** Vérification en sous-processus PHP isolé (`isQaHooksEnabled()`) : `switch-persona` renvoie JSend `404` hors `APP_ENV=testing` + `QA_HOOKS_ENABLED=1`, `stub-superadmin-token` est rejeté (`403`), et le contrôle positif renvoie `200`.

---

## 3. Fichiers Modifiés / Créés

- `.htaccess` (nouveau), `.env.example` (nouveau)
- `public/.htaccess`, `public/uploads/.htaccess` (nouveau)
- `public/api.php`, `public/dashboard.php`, `public/settings.php`, `public/stats.php`
- `core/Api/Endpoints/CmsController.php` (protection `.htaccess` lors du nettoyage des médias orphelins)
- `tests/e2e/16-sprint15-security-r0.spec.ts` (nouveau), `tests/e2e/14-sprint12-theme-auth-ux.spec.ts` (stabilisation timeout)
- `docs/ARCHITECTURE.md`, `docs/ARCHITECTURE-CIBLE-V2.md`, `docs/ETAT-DES-LIEUX.md`, `docs/ROADMAP.md`, `docs/current-sprint/15-report-sprint15.md`
