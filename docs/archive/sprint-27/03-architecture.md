# Architecture Technique : Lot 7 — Sprint 27 (QA Intégrale 100 % UI, Audit Vues & Mailpit)

## 1. Stratégie Multi-Navigateurs & Isolation
- Pour préserver la rapidité des runs quotidiens (`playwright.config.ts` conservé intact), un fichier de configuration dédié **`playwright.sprint27.config.ts`** est introduit pour la matrice complète Sprint 27 (`testMatch: /28-sprint27-.*\.spec\.ts/`, `workers: 1`, `retries: 1`) :
  - **Desktop :** `Desktop Chromium` (`Desktop Chrome`), `Desktop Firefox` (`Desktop Firefox`), `Desktop WebKit` (`Desktop Safari`).
  - **Mobile Android :** `Mobile Pixel 5` (`Pixel 5`), `Mobile Galaxy S9+` (`Galaxy S9+`).
  - **Mobile iOS :** `Mobile iPhone 13` (`iPhone 13`), `Mobile iPhone 15 Pro` (`iPhone 15 Pro` — ou `iPhone 14 Pro` si absent de `devices`).
- Script npm ajouté dans `package.json` : `"test:qa-full": "playwright test -c playwright.sprint27.config.ts"`.

## 2. Diagnostic Initial & Correctifs Applicatifs Ciblés Autorisés
Conformément à la consigne stricte *(ne toucher au code applicatif que pour le nettoyage des vues à onglets et la correction des anomalies détectées)* :
1. **Surcharge SMTP `.env` (`Core/Api/Services/MailerService.php`) :**
   - Anomalie identifiée en audit de code : `MailerService::getEffectiveConfig()` ne lisait que `SMTP_PASSWORD` depuis `.env`, ignorant `SMTP_TRANSPORT`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION` et `SMTP_USERNAME`.
   - Correctif minimal et rétrocompatible : appliquer en priorité `SMTP_TRANSPORT`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USERNAME` et `SMTP_PASSWORD` s'ils sont définis dans `.env` / l'environnement, tout en conservant `sys_settings` comme source par défaut quand ils sont absents.
2. **Audit de propreté des vues à onglets :**
   - Inspecter les 6 vues à onglets existantes (`/stats`, `/disciplines`, `/sys-smtp`, `/profile`, `/factures`, `/boutique`) et corriger uniquement les éventuels éléments résiduels/dupliqués/orphelins ou débordements horizontaux mobiles détectés par l'audit automatisé.

## 3. Suites de Tests E2E Sprint 27 (`tests/e2e/`)
- `tests/e2e/28-sprint27-qa-ui-rbac-audit.spec.ts` :
  - `S27-01` : Matrice RBAC 100 % UI Boîte Noire (8 rôles : Visiteur, Adhérent, Entraîneur, Rédacteur, Secrétaire, Trésorier, Président, Superadmin — formulaires `/login` et `/sys-admin-xyz`, vérification Sidebar + routes autorisées/interdites).
  - `S27-02` : Audit de propreté des vues à onglets & intégrité UI (0 erreur console JS, 0 erreur réseau 4xx/5xx, 0 lien mort, 1 seul panneau d'onglet actif, 0 débordement horizontal mobile sur `/stats`, `/disciplines`, `/sys-smtp`, `/profile`, `/factures`, `/boutique`).
  - `S27-03` : Parcours métier bout-en-bout 100 % UI (inscription/navigation publique, CRM `/members`, Trésorerie `/registrations`, Boutique `/boutique` panier & commande, Factures `/factures`, Présences `/presences`, Événements `/evenements`).
- `tests/e2e/28-sprint27-qa-mailpit.spec.ts` :
  - `S27-04` : Mailpit Phase 1 — Purge `DELETE /api/v1/messages`, surcharge `.env` vers `127.0.0.1:1025`, déclenchement e-mail via UI (`forgot-password` / test SMTP), vérification réception + contenu HTML + lien cliquable dans Mailpit API, restauration propre du `.env`.
  - `S27-05` : Mailpit Phase 2 — Purge `DELETE /api/v1/messages`, connexion Superadmin 100 % UI via `/sys-admin-xyz`, saisie manuelle des paramètres Mailpit dans `/sys-smtp` (`smtp`, `127.0.0.1`, `1025`, `none`), sauvegarde, envoi mail de test + flux métier réel, vérification complète dans Mailpit API (`To`, `Subject`, `HTML`, liens), puis remise en mode `log` propre.
