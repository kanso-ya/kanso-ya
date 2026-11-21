# Architecture Technique : Lot 6 — Sprint 24 (Module « Facturation PDF Officielle & Envoi Automatique »)

## 1. Modèle de Données (MCD) et Script SQL (`mod_facturation_*`)

| Table | Colonne | Type SQL | Contraintes (PK, FK) | Description |
|---|---|---|---|---|
| `mod_facturation_settings` | `setting_key` | `VARCHAR(100)` | PK | Clé de configuration du template PDF (`invoice_prefix`, `org_name`, `org_legal_id`, `org_address`, `org_email`, `header_title`, `footer_mentions`) |
| `mod_facturation_settings` | `setting_value` | `TEXT` | NOT NULL | Valeur du paramètre |
| `mod_facturation_settings` | `updated_at` | `DATETIME` | NOT NULL, DEFAULT CURRENT_TIMESTAMP | Date de mise à jour |
| `mod_facturation_invoices` | `id` | `INTEGER` | PK, Auto Increment | Identifiant technique |
| `mod_facturation_invoices` | `invoice_number` | `VARCHAR(40)` | NOT NULL, UNIQUE | Numéro légal séquentiel (`FAC-2026-0001`) |
| `mod_facturation_invoices` | `source_type` | `VARCHAR(40)` | NOT NULL, DEFAULT `'cotisation'` | Origine (`cotisation`, `boutique`, `autre`) |
| `mod_facturation_invoices` | `source_id` | `INTEGER` | NULL | Référence dossier/commande source (unicité idempotente sur `(source_type, source_id)` si non null) |
| `mod_facturation_invoices` | `user_id` | `INTEGER` | NULL | Compte utilisateur payeur rattaché (`users.id`) |
| `mod_facturation_invoices` | `recipient_name` | `VARCHAR(200)` | NOT NULL | Nom complet du destinataire / payeur |
| `mod_facturation_invoices` | `recipient_email` | `VARCHAR(255)` | NOT NULL | Adresse e-mail d'envoi automatique |
| `mod_facturation_invoices` | `recipient_address` | `TEXT` | NULL | Adresse postale optionnelle |
| `mod_facturation_invoices` | `items_json` | `TEXT` | NOT NULL | Détail JSON des lignes (`[{label, qty, unit_price, total}]`) |
| `mod_facturation_invoices` | `subtotal_amount` | `DECIMAL(10, 2)` | NOT NULL, DEFAULT `0.00` | Sous-total brut |
| `mod_facturation_invoices` | `discount_amount` | `DECIMAL(10, 2)` | NOT NULL, DEFAULT `0.00` | Montant de remise |
| `mod_facturation_invoices` | `total_amount` | `DECIMAL(10, 2)` | NOT NULL, DEFAULT `0.00` | Total net à payer / réglé |
| `mod_facturation_invoices` | `payment_method` | `VARCHAR(50)` | NULL | Mode de règlement hors-ligne (`cheque`, `especes`, `virement`, etc.) |
| `mod_facturation_invoices` | `pdf_filename` | `VARCHAR(255)` | NOT NULL | Nom de fichier dans `storage/private_uploads/invoices/` |
| `mod_facturation_invoices` | `email_status` | `VARCHAR(20)` | NOT NULL, DEFAULT `'pending'` | Statut d'envoi (`sent`, `failed`, `pending`) |
| `mod_facturation_invoices` | `email_log_id` | `INTEGER` | NULL | Référence éventuelle dans `email_logs` |
| `mod_facturation_invoices` | `issued_by` | `INTEGER` | NULL | Utilisateur émetteur |
| `mod_facturation_invoices` | `issued_at` | `DATETIME` | NOT NULL, DEFAULT CURRENT_TIMESTAMP | Date d'émission officielle |

**Script DDL (`Modules\FacturationApi\Module::install()` via `Database::execDdlStatements`) :**
```sql
CREATE TABLE IF NOT EXISTS mod_facturation_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mod_facturation_invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_number VARCHAR(40) NOT NULL UNIQUE,
    source_type VARCHAR(40) NOT NULL DEFAULT 'cotisation',
    source_id INTEGER NULL,
    user_id INTEGER NULL,
    recipient_name VARCHAR(200) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    recipient_address TEXT NULL,
    items_json TEXT NOT NULL,
    subtotal_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    payment_method VARCHAR(50) NULL,
    pdf_filename VARCHAR(255) NOT NULL,
    email_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    email_log_id INTEGER NULL,
    issued_by INTEGER NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

## 2. Arborescence Modulaire & Extension Pièces Jointes Core
```text
Core/Api/Services/
├── SmtpClient.php                        # Extension rétrocompatible attachments (MIME multipart/mixed)
└── MailerService.php                     # Transmission des pièces jointes + trace dans smtp_response en mode log

modules/
├── FacturationApi/
│   ├── manifest.json                     # Schéma v2 (id: facturation, tables_prefix: mod_facturation_, ui_folder: FacturationUi)
│   ├── Module.php                        # install(), uninstall(), boot(EventManager) + hook on_registration_validated & issue_official_invoice
│   ├── Services/
│   │   ├── NativePdfBuilder.php          # Générateur PDF 1.4 natif PHP 8.5 (A4, accents français, tableau lignes, mentions légales)
│   │   └── InvoiceService.php            # Service transversal multi-modules (numérotation, génération PDF, envoi e-mail template dédié)
│   └── Endpoints/
│       └── FacturationController.php     # Endpoints REST /api/v1/modules/facturation/* + contrôles RBAC/RGPD
└── FacturationUi/
    ├── Pages/
    │   └── factures.php                  # Vue /factures (liste, émission manuelle, config template PDF & e-mail dédié)
    └── dist/
        ├── facturation.css               # Styles publiés -> public/assets/modules/facturation/
        └── facturation.js                # Script Vanilla ES module publié -> public/assets/modules/facturation/
```

## 3. Contrats d'API (Endpoints — HTTP 200 Constant JSend sous `/api/v1/modules/facturation/*`)
| ID API | Endpoint | Méthode | Payload attendu (JSON) | Réponse JSON (Status 200) |
|---|---|---|---|---|
| `API-24-01` | `/api/v1/modules/facturation/invoices` | `GET` | *(aucun)* | `{"status":"success","success":true,"code":200,"data":{"can_manage":bool,"invoices":[...]}}` |
| `API-24-02` | `/api/v1/modules/facturation/invoices` | `POST` | `{"source_type":"cotisation\|boutique\|autre","source_id":null,"recipient_name":"...","recipient_email":"...","items":[{"label":"...","qty":1,"unit_price":150}],"discount_amount":0,"payment_method":"cheque","send_email":true}` | `code: 201` avec `invoice` + `email_status` (ou `403` / `422`) |
| `API-24-03` | `/api/v1/modules/facturation/invoices/{id}/pdf` | `GET` | *(aucun)* | `{"status":"success","success":true,"code":200,"data":{"filename":"FAC-2026-0001.pdf","mime_type":"application/pdf","pdf_base64":"..."}}` (`403` si facture tierce, `404` si introuvable) |
| `API-24-04` | `/api/v1/modules/facturation/invoices/{id}/send-email` | `POST` | *(aucun — gestionnaire requis)* | `code: 200` si envoyé (`502` si échec SMTP, `403` si non gestionnaire, `404` si introuvable) |
| `API-24-05` | `/api/v1/modules/facturation/templates` | `GET` / `PUT` | `{"pdf_template":{...},"email_template":{"subject":"...","body_html":"..."}}` | `code: 200` (`403` si non gestionnaire) |

## 4. Instructions de passage de relais (Handoff)
- **Pour le Dev-Back :** Ajoute le support rétrocompatible des pièces jointes MIME dans `SmtpClient` et `MailerService`, puis implémente `modules/FacturationApi/` (`manifest.json`, `NativePdfBuilder`, `InvoiceService`, `FacturationController`, `Module`). Vérifie `php -l`, `composer dump-autoload -o --strict-psr`, `vendor\bin\phpstan analyse` et `npm run db:check-mysql`.
