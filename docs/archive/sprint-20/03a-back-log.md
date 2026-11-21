# Journal de Livraison Backend — Lot 4 / Sprint 20 (Transport SMTP Réel & Fiabilité Messagerie)

**Rôle :** @dev-back  
**Statut :** Terminé & Vérifié (`php -l` OK · `composer dump-autoload -o --strict-psr` : 28 classes, 0 avertissement · `vendor\bin\phpstan analyse` niveau 5 : 0 erreur · `npm run db:reset` OK)

## 1. Migrations & Schéma BDD
- `Core/Api/Database/Migrations/001_core_schema.php` : ajout des colonnes `body_html TEXT NULL`, `retry_count INTEGER NOT NULL DEFAULT 0`, `last_retry_at DATETIME NULL` dans `email_logs`.
- `Core/Api/Database/Migrations/004_smtp_reliability.php` : migration idempotente SQLite/MySQL ajoutant ces colonnes sur bases existantes et initialisant `smtp_transport = 'log'` et `smtp_timeout = '10'` dans `sys_settings`.
- `Core/Api/Database/Migrations/002_core_seed.php` : intégration de la migration `004`, nettoyage de `smtp_password` lors du reset QA et seed de `smtp_transport = 'log'` / `smtp_timeout = '10'`.

## 2. Nouveaux Services Core (`Core\Api\Services\*`)
- `Core/Api/Services/SmtpClient.php` : client SMTP natif PHP 8.5 (`stream_socket_client`, `ssl://` / `STARTTLS` via `stream_socket_enable_crypto` / `tcp://`, `EHLO`/`HELO`, `AUTH LOGIN` avec fallback `AUTH PLAIN`, `MAIL FROM`, `RCPT TO`, `DATA` avec en-têtes RFC 5322 UTF-8 base64 et dot-stuffing RFC 5321, gestion stricte des timeouts et erreurs).
- `Core/Api/Services/MailerService.php` :
  - Chiffrement/déchiffrement AES-256-GCM (`enc:v1:...`) du secret `smtp_password` dérivé de `APP_KEY` (ou fallback déterministe) + surcharge prioritaire par `SMTP_PASSWORD` dans `.env`.
  - Bascule mode `log` (`250 2.0.0 OK [LOG/STUB] ...`) et confinement sécurité sous `APP_ENV=testing` (seuls `127.0.0.1`, `localhost`, `::1` sont autorisés en mode `smtp` pendant les tests ; les hôtes externes retournent `550 5.7.1 Blocked external SMTP host in testing environment`).
  - Centralisation de `send()` et implémentation de `retryEmailLog()` (incrément `retry_count`, mise à jour `status`, `smtp_response`, `last_retry_at`, audit `sys_logs`).

## 3. Endpoints API & Contrôleurs
- `GET /api/v1/settings` (`SystemController::getSettings`) : masque systématiquement `smtp_password` et expose `smtp_password_configured` (`"0"`/`"1"`), `smtp_password_source` (`"none"`/`"db"`/`"env"`), `smtp_transport` et `smtp_timeout`.
- `PATCH / PUT /api/v1/settings` (`SystemController::updateSettings`) : validation stricte `smtp_port` (`1..65535`), `smtp_encryption` (`tls|ssl|none`), `smtp_transport` (`log|smtp`), `smtp_timeout` (`2..30`) ; chiffrement AES-256-GCM de `smtp_password` si non vide ; conservation de l'existant si vide ; suppression explicite si `smtp_password_clear = "1"`.
- `POST /api/v1/sys/test-email` (`SystemController::sendTrackedEmail`) : délègue à `MailerService::send()`, retourne HTTP 200 JSend `code: 200` si `sent` ou `code: 502` avec le détail SMTP si `failed`.
- `POST /api/v1/sys/email-logs/{id}/retry` (`CoreApiRoutes` → `SystemController::retryEmailLog`) : réservé au Superadmin (`403` + log `UNAUTHORIZED_ACCESS_ATTEMPT` sinon), retourne `404` si introuvable, `409` si déjà `sent`, `200` si relance réussie, `502` si nouvel échec.
- `AuthController` (`forgotPassword`, `sendTwoFactorEmailCode`) et `MemberController` (`createMember`, `resendOtp`) : délégation à `MailerService::send()` **après** le `COMMIT` de la transaction SQLite.

## 4. Handoff vers `@dev-front`
- **Pour le Dev-Front :** Les API sont prêtes, respectent la norme JSend HTTP 200 et PHPStan niveau 5. Tu peux mettre à jour `Core/Ui/Pages/sys-smtp.php` et `Core/Ui/ts/sys-console.ts` avec les `data-testid` de `02-specs-metier.md`, puis compiler via `npm run build:ts` et `npm run build:css`.
