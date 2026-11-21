STATUT: VALIDATED

# Rapport de Recette QA / UAT — Lot 6 / Sprint 24 (Module « Facturation PDF Officielle & Envoi Automatique »)

**Rôle :** @qa-uat  
**Date :** 21 novembre 2026  
**Suite dédiée :** `tests/e2e/25-sprint24-module-facturation.spec.ts`  
**Résultat global :** **316 / 316 Pass** (Desktop Chrome + Mobile Chrome Pixel 5 — 0 échec, 0 régression)

## 1. Couverture des Scénarios Sprint 24
| Scénario | Persona / Règles | Desktop Chrome | Mobile Chrome | Détails vérifiés |
|---|---|---:|---:|---|
| `S24-01` | Persona 1 (`RG-01`..`RG-04`) | PASS | PASS | Activation `FacturationApi`, publication assets, personnalisation template PDF (`RNA W790098765`, mentions légales) + template e-mail `invoice_issued`, émission UI `/factures`, PDF binaire `%PDF-1.4 ... %%EOF` archivé hors webroot (`storage/private_uploads/invoices/`) contenant les mentions configurées, trace `INVOICE_ISSUED` + `[PJ: FAC-...pdf]` dans `email_logs`. |
| `S24-02` | Pièce jointe MIME réelle (`RG-04`) | PASS | PASS | Envoi réel sur serveur SMTP TCP local vérifiant `Content-Type: multipart/mixed`, `Content-Type: application/pdf` et `Content-Disposition: attachment; filename="FAC-...pdf"`. |
| `S24-03` | Persona 2 — RBAC/RGPD & Désinstallation (`RG-01`, `RG-05`) | PASS | PASS | Masquage formulaires admin pour Adhérent sur `/factures`, rejets `403` sur PDF tiers, templates et émission manuelle ; désinstallation propre (purge assets, `/factures` HTTP `404`, API `404`). |

## 2. Vérifications Qualité & Non-Régression
- **Playwright E2E complet :** `npx playwright test --workers=1` → **316/316 Pass** (`3.6m`).
- **PHPStan niveau 5 :** `vendor\bin\phpstan analyse` → **0 erreur** (71 fichiers analysés).
- **Composer PSR-4 strict :** **36 classes**, 0 avertissement.
- **Audit MySQL/MariaDB :** `npm run db:check-mysql` → **OK**.
- **Build Frontend :** `npm run build:ts` (0 erreur) & `npm run build:css` (Tailwind local sans CDN).
