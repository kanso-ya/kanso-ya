# Journal de Développement Backend — Lot 7 / Sprint 27 (Correctif Surcharge SMTP `.env`)

## 1. Anomalie Identifiée & Corrigée
- [MailerService.php](file:///c:/laragon/www/kanso-ya/Core/Api/Services/MailerService.php) : `getEffectiveConfig()` ne prenait en compte que `SMTP_PASSWORD` depuis `.env`, ignorant les surcharges serveur `SMTP_TRANSPORT`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION` et `SMTP_USERNAME`.
- **Correctif minimal :** Lecture prioritaire de `SMTP_TRANSPORT`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USERNAME` et `SMTP_PASSWORD` via `self::readEnv()` avec repli intégral sur `sys_settings` lorsqu'ils ne sont pas définis dans `.env`.

## 2. Vérifications Techniques
- `php -l Core/Api/Services/MailerService.php` : OK.
- `vendor\bin\phpstan analyse --no-progress` (Niveau 5) : 0 erreur.
