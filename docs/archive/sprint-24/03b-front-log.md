# Journal de Livraison Frontend — Lot 6 / Sprint 24 (Module « Facturation PDF Officielle & Envoi Automatique »)

**Rôle :** @dev-front  
**Statut :** Terminé & Compilé (`php -l modules/FacturationUi/Pages/factures.php` OK · `npm run build:ts` : 0 erreur · `npm run build:css` : OK)

## 1. Livrables UI Modulaires (`modules/FacturationUi/`)
- `modules/FacturationUi/Pages/factures.php` :
  - Garde applicative `KANSO_ENTRY`, accès connecté (`$requireAuth = true`).
  - Onglet 1 (`facturation-tab-list`) : formulaire d'émission manuelle réservé au Bureau (`facturation-create-form`, `invoice-recipient-name`, `invoice-recipient-email`, `invoice-source-type`, `invoice-line-label`, `invoice-line-amount`, `btn-issue-invoice`) + tableau des factures (`facturation-invoices-table`) avec boutons `btn-download-invoice-{id}` et `btn-resend-invoice-{id}`.
  - Onglet 2 (`facturation-tab-templates`, Bureau uniquement) : personnalisation du template PDF spécifique (`pdf-tpl-org-name`, `pdf-tpl-legal-id`, `pdf-tpl-footer`) et du template d'e-mail dédié `invoice_issued` (`email-tpl-subject`, `email-tpl-body`, `btn-save-invoice-templates`).
- `modules/FacturationUi/dist/facturation.css` & `modules/FacturationUi/dist/facturation.js` :
  - Assets publiés automatiquement sous `/assets/modules/facturation/` à l'activation et purgés à la désinstallation.

## 2. Handoff vers `@qa-uat`
- **Pour le QA/UAT :** Le module `FacturationApi` / `FacturationUi` est prêt. Tu peux créer `tests/e2e/25-sprint24-module-facturation.spec.ts` couvrant la génération PDF `%PDF-1.4`, la personnalisation des templates PDF/e-mail, l'envoi réel de pièce jointe MIME `application/pdf` sur serveur SMTP local, l'isolation RGPD `403` et la désinstallation propre `404`.
