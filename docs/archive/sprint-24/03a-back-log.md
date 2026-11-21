# Journal de Livraison Backend — Lot 6 / Sprint 24 (Module « Facturation PDF Officielle & Envoi Automatique »)

**Rôle :** @dev-back  
**Statut :** Terminé & Vérifié (`php -l` OK · `npm run db:check-mysql` OK · `composer dump-autoload -o --strict-psr` : 36 classes, 0 avertissement · `vendor\bin\phpstan analyse` niveau 5 : 0 erreur)

## 1. Extension Rétrocompatible Pièces Jointes MIME (`Core\Api\Services\*`)
- `Core/Api/Services/SmtpClient.php` : paramètre optionnel `array $attachments = []` générant un message RFC 5322 `multipart/mixed` avec encodage base64 et `Content-Disposition: attachment; filename="..."`.
- `Core/Api/Services/MailerService.php` : transmission des pièces jointes au client SMTP et trace des noms de pièces jointes dans `smtp_response` en mode `log`.

## 2. Module Facturation (`modules/FacturationApi/`)
- `manifest.json` : schéma SDK v2 (`id: facturation`, `tables_prefix: mod_facturation_`, `ui_folder: FacturationUi`, `assets: FacturationUi/dist` → `/assets/modules/facturation/`).
- `Services/NativePdfBuilder.php` : générateur PDF 1.4 natif PHP 8.5 (format A4, polices Helvetica/Helvetica-Bold WinAnsiEncoding avec accents français, en-tête club, encadré destinataire/origine, tableau lignes, remise, total net, mentions légales configurables).
- `Services/InvoiceService.php` : numérotation séquentielle `FAC-YYYY-0001`, idempotence `(source_type, source_id)`, archivage hors webroot `storage/private_uploads/invoices/`, template PDF (`mod_facturation_settings`), template d'e-mail dédié `invoice_issued` (`email_templates`), `issueInvoice()`, `issueFromRegistration()` et `sendInvoiceEmail()`.
- `Endpoints/FacturationController.php` & `Module.php` : 6 endpoints sous `/api/v1/modules/facturation/*` (contrôles RBAC `finances.manage` et propriété adhérent `403`), écouteurs `on_registration_validated` et `issue_official_invoice`.

## 3. Handoff vers `@dev-front`
- **Pour le Dev-Front :** Les 6 endpoints `/api/v1/modules/facturation/*` sont prêts. Tu peux implémenter `modules/FacturationUi/Pages/factures.php`, `modules/FacturationUi/dist/facturation.css` et `modules/FacturationUi/dist/facturation.js` avec tous les `data-testid` de `02-specs-metier.md`, puis compiler Tailwind CSS (`npm run build:css`).
