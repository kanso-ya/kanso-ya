# Rapport de Fin de Sprint 12 — Thème CSS Réel, Sécurité Multi-Choix, Mot de Passe Oublié & Langage Humain

**Projet :** Kanso-ya CMS Associatif (v1.1)  
**Sprint :** Sprint 12 (Phase Finitions UX & Polissage v1.1)  
**Date :** 18 novembre 2026  
**Statut :** ✅ **100 % VALIDÉ & HOMOLOGUÉ QA (230/230 E2E Pass, 0 erreur PHPStan, 0 erreur TS/CSS)**

---

## 1. Synthèse des Réalisations

### 1.1. Thème Dynamique CSS Réel & Encadré Logo Adaptatif (Points 7 & 21)
- **Variables CSS maîtresses dans `<head>` (`#kanso-dynamic-theme`) :**
  - Injection centralisée dans `core/ui/templates/layout.php` et `public/login.php` des 5 variables dynamiques `--brand-primary`, `--brand-primary-hover` (calculée automatiquement par assombrissement -14 %), `--brand-sidebar`, `--brand-header` et `--brand-accent`.
  - Exposition des classes dédiées (`.bg-brand-primary`, `.text-brand-primary`, `.border-brand-primary`, `.bg-brand-accent`, `.text-brand-accent`) et surcharge `!important` des utilitaires Tailwind primaires (`bg-emerald-600`, `hover:bg-emerald-700`, `text-emerald-600`, `border-emerald-500`, `peer-checked:bg-emerald-600`, `focus:ring-emerald-500`) pour garantir qu'un changement de palette dans `/sys-branding` colore immédiatement l'ensemble du site.
  - Ajout du sélecteur de couleur d'accentuation (`#sys-color-accent`, `theme_accent_color`) et mise à jour des préréglages dans `public/sys-branding.php` et `core/ui/ts/sys-console.ts`.
- **Encadré du logo adaptatif (`#overlapping-logo-badge`) :**
  - Conteneur adaptatif (`w-auto min-w-[88px] max-w-[224px] h-[88px] p-0 rounded-2xl overflow-hidden`) avec image `object-cover rounded-2xl` afin d'épouser la géométrie du logo téléversé tout en rognant proprement les coins arrondis.

### 1.2. Page Login : Logo Club & Parcours Complet "Mot de passe oublié" (Points 5 & 6)
- **Suppression du sigle `"KY"` sur `public/login.php` :**
  - Affichage du logo du club téléversé dans un cadre arrondi propre (`object-cover overflow-hidden rounded-2xl`), ou du nom du club stylisé aux couleurs de la marque si aucun logo n'est configuré.
- **Parcours "Mot de passe oublié ?" anti-énumération :**
  - Ajout du lien `Mot de passe oublié ?` (`data-testid="link-forgot-password"`) et du formulaire dédié (`data-testid="forgot-password-form"`) sur `public/login.php` et `core/ui/ts/login.ts`.
  - Nouvel endpoint `POST /api/v1/auth/forgot-password` (`AuthController::forgotPassword()` dans `core/Api/Endpoints/AuthController.php`, routé dans `public/api.php`) : génère un code à usage unique dans `users.otp_code` (transaction SQLite `BEGIN IMMEDIATE`), trace l'envoi dans `email_logs` (`template_type = 'PASSWORD_RESET'`) et dans `app_logs`, tout en renvoyant invariablement un message neutre identique que le compte existe ou non.
  - Redirection vers `/activate-otp?mode=reset` (`public/activate-otp.php`) enrichi d'un champ obligatoire de **confirmation du nouveau mot de passe** (`#otp-confirm-password`, `data-testid="reset-confirm-password"`), avec validation de complexité et concordance par Toast côté client et vérification `confirm_password` côté backend (`RegistrationController::activateAccountWithOtp()`).

### 1.3. Profil Utilisateur : Modale Confirmation Double Saisie & 2FA Multi-Choix (Points 8, 9 & 10)
- **Modale dédiée de confirmation de changement de mot de passe (`/dashboard`) :**
  - Remplacement de la modale destructrice générique par une modale dédiée (`#password-confirm-modal`, `data-testid="password-confirm-modal"`) demandant une **seconde saisie du nouveau mot de passe** (`#modal-confirm-new-password`).
  - Bouton de validation libellé exactement **"Confirmer la modification"** (`data-testid="btn-confirm-password-change"`) — plus jamais *"Confirmer la suppression"*.
  - Validation côté client et backend (`AuthController::updatePassword()`) des règles de complexité et de concordance des deux saisies.
- **Double authentification (2FA) au choix parmi 3 options (`/dashboard`, onglet Sécurité) :**
  1. **Application mobile (QR Code 30s)** (`data-testid="2fa-method-totp"`) avec renouvellement automatique toutes les 30 secondes.
  2. **Clé secrète manuelle** (`data-testid="2fa-method-manual"`) avec affichage direct et bouton de copie rapide (`data-testid="btn-copy-totp-secret"`).
  3. **Code reçu par e-mail** (`data-testid="2fa-method-email"`) avec bouton d'envoi (`data-testid="btn-send-2fa-email-code"`) appelant le nouvel endpoint `POST /api/v1/users/me/2fa/send-email-code` (`AuthController::sendTwoFactorEmailCode()`), génération d'un code à 6 chiffres valable 10 min tracé dans `email_logs`, et validation via `POST /api/v1/users/me/2fa` avec `method: 'email'` (colonne `two_factor_method` ajoutée dans `001_core_schema.php` avec migration transparente `PRAGMA table_info`).

### 1.4. Langage Humain Non-Technique, Harmonisation Rollover & Toasts Systématiques (Points 2, 15 & 16)
- **Élimination du jargon technique visible :**
  - `public/roles.php` & `core/ui/ts/roles.ts` : suppression des mentions `(RBAC)` et des codes `members.view`, `finances.manage`, `articles.publish`, etc., au profit d'intitulés métier clairs.
  - `public/settings.php`, `public/sys-logs.php`, `public/sys-smtp.php`, `public/members.php`, `public/registrations.php`, `public/register.php`, `public/activate-otp.php`, `public/cms.php` : suppression des noms de tables bruts (`app_logs`, `sys_logs`, `email_logs`, `is_category_forced = 1`, `OTP` brut) dans les libellés visibles et humanisation des actions du journal dans `core/ui/ts/settings.ts`.
  - `public/api.php` & `core/Api/Endpoints/CmsController.php` : messages d'erreur 403/422 rédigés en français courant, et ajout de l'alias officiel `/api/v1/rollover` en complément de `/api/v1/tasks/rollover-season`.
- **Toasts systématiques sur toutes les erreurs de validation :**
  - Attributs `novalidate` + validations explicites avec `showToast(..., 'error')` (marqués `data-testid="toast-error"` / `role="alert"`) sur `login.ts`, `activate-otp.php`, `dashboard.ts`, `settings.ts`, `cms.ts`, `register.ts`, `roles.ts`, `members.ts` et `main.ts`.

---

## 2. Vérifications Qualité & Tests Automatisés

| Contrôle | Commande | Résultat |
| :--- | :--- | :--- |
| **Compilation TypeScript** | `npm run build:ts` | ✅ **0 erreur** |
| **Compilation Tailwind CSS** | `npm run build:css` | ✅ **0 erreur** |
| **Analyse Statique PHP** | `vendor/bin/phpstan analyse core public` | ✅ **43/43 fichiers OK (0 erreur)** |
| **Suite Dédiée Sprint 12** | `npx playwright test tests/e2e/14-sprint12-theme-auth-ux.spec.ts` | ✅ **8/8 Pass (Desktop Chrome & Mobile Pixel 5)** |
| **Suite Complète E2E (Non-Régression)** | `npm run test:e2e` | ✅ **230/230 Pass (0 échec, 14 suites)** |

---

## 3. Fichiers Principaux Modifiés / Créés

- **Backend & BDD :** `core/Api/Database/migrations/001_core_schema.php`, `core/Api/Database/migrations/002_core_seed.php`, `core/Api/Endpoints/AuthController.php`, `core/Api/Endpoints/RegistrationController.php`, `core/Api/Endpoints/CmsController.php`, `public/api.php`.
- **Vues & Gabarits :** `core/ui/templates/layout.php`, `core/ui/components/feedback.php`, `public/login.php`, `public/activate-otp.php`, `public/dashboard.php`, `public/roles.php`, `public/settings.php`, `public/sys-branding.php`, `public/sys-logs.php`, `public/sys-smtp.php`, `public/members.php`, `public/registrations.php`, `public/register.php`, `public/cms.php`.
- **Frontend TypeScript :** `core/ui/ts/main.ts`, `core/ui/ts/login.ts`, `core/ui/ts/dashboard.ts`, `core/ui/ts/roles.ts`, `core/ui/ts/settings.ts`, `core/ui/ts/sys-console.ts`, `core/ui/ts/members.ts`, `core/ui/ts/registrations.ts`, `core/ui/ts/register.ts`, `core/ui/ts/cms.ts` (+ JS compilés dans `public/assets/ts/`).
- **Tests E2E :** `tests/e2e/14-sprint12-theme-auth-ux.spec.ts` (nouveau), `tests/e2e/05-uat-personas.spec.ts`, `tests/e2e/08-deep-uat-visual-stress.spec.ts`.
