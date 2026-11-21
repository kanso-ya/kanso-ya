# Sprint Actif : Lot 6 — Sprint 24 : Module « Facturation PDF Officielle & Envoi Automatique par E-mail » (`FacturationApi` / `FacturationUi`)
**Objectif principal :** Livrer un module transversal SDK v2 (`modules/FacturationApi` + `modules/FacturationUi`) capable d'éditer des factures **PDF officielles** du club à partir d'un **template PDF spécifique personnalisable**, de les archiver hors webroot et de les expédier automatiquement par e-mail avec pièce jointe PDF via un **template d'e-mail dédié**, aussi bien pour les cotisations Core (`on_registration_validated`) que pour les futurs modules tiers (Boutique, etc.).

## 1. Périmètre Macro (User Stories)
| ID | Fonctionnalité ciblée | Description métier | Priorité |
|---|---|---|---|
| `US-LOT6-01` | Squelette modulaire `FacturationApi` / `FacturationUi` | Manifeste SDK v2, tables isolées `mod_facturation_invoices` et `mod_facturation_settings` (SQLite & MySQL), numérotation séquentielle légale `FAC-YYYY-0001`, stockage PDF hors webroot (`storage/private_uploads/invoices/`). | Haute |
| `US-LOT6-02` | Moteur d'édition PDF natif & Template PDF spécifique | Générateur PDF 1.4 natif PHP (format A4, encodage français, tableau des lignes, remises, total net) piloté par un **template PDF configurable** (raison sociale, RNA/SIRET, adresse, titre, mentions légales, préfixe de numérotation). | Haute |
| `US-LOT6-03` | Envoi automatique par e-mail avec pièce jointe PDF | Extension rétrocompatible de `MailerService` / `SmtpClient` pour pièces jointes MIME (`multipart/mixed`, `application/pdf`), template d'e-mail dédié `invoice_issued` personnalisable, déclenchement automatique sur `on_registration_validated` et relance manuelle depuis `/factures`. | Haute |
| `US-LOT6-04` | Service transversal multi-modules & Espace Factures (`/factures`) | Service réutilisable `InvoiceService::issueInvoice()` (+ action SDK `issue_official_invoice`) utilisable par les cotisations Core et par d'autres modules (ex. Boutique), interface `/factures` pour le Bureau (`finances.manage` : config template PDF + template mail, émission manuelle, liste, téléchargement, renvoi mail) et accès restreint aux propres factures pour les adhérents. | Haute |

## 2. Hors Périmètre (Exclusions strictes)
- **Ne pas** implémenter de paiement en ligne (CB, Stripe, HelloAsso, virement direct — hors périmètre projet).
- **Ne pas** installer de librairie PDF tierce lourde (générateur PDF 1.4 natif Vanilla PHP 8.5 strict).
- **Ne pas** implémenter la refonte de la Boutique (traitée dans les Sprints 25 et 26).
- **Ne pas** exposer les fichiers PDF directement dans `public/` (stockage strictement dans `storage/private_uploads/invoices/` servi via contrôle RBAC).

## 3. Instructions de passage de relais (Handoff)
- **Pour le PO :** À partir de ce scope, rédige les spécifications fonctionnelles détaillées, les règles de gestion PDF/e-mail/RGPD et la liste des `data-testid` dans `docs/current-sprint/02-specs-metier.md`.
