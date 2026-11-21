STATUT: VALIDATED

# Rapport de Recette QA / UAT — Lot 7 / Sprint 27 (QA Intégrale 100 % UI, Matrice RBAC, Audit Vues & Mailpit)

## 1. Synthèse de Validation
- **Statut global :** `VALIDATED`
- **Analyse statique & Non-régression :** `vendor\bin\phpstan analyse` niveau 5 (**0 erreur**), non-régression Sprint 20 SMTP (`21-sprint20-smtp.spec.ts`) **100 % PASS**, et socle V2.3 (`324` tests existants) préservé.
- **Matrice Playwright Complète (`npm run test:qa-full` — 7 projets, 35 tests) :** **35 / 35 PASS** :
  - **Desktop :** `Desktop Chromium` (`5/5`), `Desktop Firefox` (`5/5`), `Desktop WebKit` / Safari (`5/5`).
  - **Mobile Android :** `Mobile Pixel 5` (`5/5`), `Mobile Galaxy S9+` (`5/5`).
  - **Mobile iOS :** `Mobile iPhone 13` (`5/5`), `Mobile iPhone Pro` (`5/5`).

## 2. Anomalies Réelles Détectées & Corrigées (et Uniquement Celles-ci)
1. **[MailerService.php](file:///c:/laragon/www/kanso-ya/Core/Api/Services/MailerService.php) (`getEffectiveConfig` & `readEnv`) :**
   - `getEffectiveConfig()` ne prenait pas en compte les surcharges `.env` (`SMTP_TRANSPORT`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USERNAME`), et `parse_ini_file()` en mode par défaut convertissait `SMTP_ENCRYPTION=none` en chaîne vide `""`.
   - **Correctif :** Prise en compte prioritaire de toutes les variables `SMTP_*` depuis `.env` avec `INI_SCANNER_RAW`.
2. **[SmtpClient.php](file:///c:/laragon/www/kanso-ya/Core/Api/Services/SmtpClient.php) (`send`) :**
   - En présence d'un identifiant par défaut sans mot de passe face à un relais sans authentification (ex. Mailpit `127.0.0.1:1025` n'annonçant pas `AUTH` dans `EHLO`), le client tentait `AUTH LOGIN` puis `AUTH PLAIN` et échouait.
   - **Correctif :** Ne déclencher `authenticate()` que si le serveur annonce `AUTH` ou si un mot de passe explicite est fourni.

## 3. Résultats Détaillés par Exigence
| Scénario | Couverture | Résultat |
|---|---|---|
| `S27-01` (`RG-01`, `RG-02`) | Matrice RBAC 8 rôles 100 % UI boîte noire (Visiteur, Adhérent/Tuteur, Entraîneur, Rédacteur, Secrétaire, Trésorier, Président, Superadmin) via formulaires `/login` & `/sys-admin-xyz` | **PASS (7/7 projets)** |
| `S27-02` (`RG-04`) | Audit propreté des vues à onglets (`/sys-smtp`, `/dashboard`, `/stats`, `/disciplines`, `/boutique`, `/factures`), 0 erreur console JS, 0 exception, 0 erreur HTTP `>= 400`, 0 lien mort, 0 débordement horizontal mobile | **PASS (7/7 projets)** |
| `S27-03` (`RG-01`) | Parcours bout-en-bout 100 % UI (Public `/horaires` `/register` → Adhérent commande `/boutique` → Président suivi/annulation UI + `/members` + `/registrations`) | **PASS (7/7 projets)** |
| `S27-04` (`RG-05`) | Mailpit Phase 1 : purge `DELETE /api/v1/messages`, surcharge `.env` vers `127.0.0.1:1025`, déclenchement UI, réception vérifiée dans Mailpit API + restauration `.env` | **PASS (7/7 projets)** |
| `S27-05` (`RG-05`) | Mailpit Phase 2 : purge `DELETE`, configuration manuelle 100 % UI sur `/sys-smtp`, mail de test + flux métier réel vérifiés dans Mailpit API (destinataire, sujet, HTML, code/lien) + remise mode `log` | **PASS (7/7 projets)** |
