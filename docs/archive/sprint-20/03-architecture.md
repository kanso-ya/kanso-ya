# Architecture Technique : Lot 4 — Sprint 20 (Transport SMTP Réel & Fiabilité Messagerie)

## 1. Modèle de Données (MCD) et Script SQL

### 1.1. Évolution de la table `email_logs`
| Table | Colonne | Type SQL | Contraintes (PK, FK) | Description |
|---|---|---|---|---|
| `email_logs` | `id` | `INTEGER` | PK, Auto Increment | Identifiant unique de la trace d'envoi |
| `email_logs` | `recipient_email` | `VARCHAR(255)` | NOT NULL | Adresse e-mail destinataire |
| `email_logs` | `subject` | `VARCHAR(255)` | NOT NULL | Sujet de l'e-mail |
| `email_logs` | `body_html` | `TEXT` | NULL | Corps HTML envoyé (conservé pour permettre une relance fidèle) |
| `email_logs` | `template_type` | `VARCHAR(50)` | NOT NULL, DEFAULT `'OTP'` | Catégorie métier (`SMTP_DIAGNOSTIC`, `OTP_INVITE`, `OTP_RESEND`, `2FA_EMAIL_CODE`, `PASSWORD_RESET`) |
| `email_logs` | `status` | `VARCHAR(30)` | NOT NULL, DEFAULT `'sent'` | Statut courant : `sent` ou `failed` |
| `email_logs` | `smtp_response` | `VARCHAR(255)` | NULL | Réponse du serveur SMTP ou motif d'erreur technique |
| `email_logs` | `retry_count` | `INTEGER` | NOT NULL, DEFAULT `0` | Nombre de relances manuelles effectuées |
| `email_logs` | `last_retry_at` | `DATETIME` | NULL | Horodatage de la dernière tentative de relance |
| `email_logs` | `sent_at` | `DATETIME` | NOT NULL, DEFAULT CURRENT_TIMESTAMP | Date de création / dernier envoi réussi |

### 1.2. Nouvelles clés dans `sys_settings`
| Clé (`setting_key`) | Valeur par défaut (seed) | Description |
|---|---|---|
| `smtp_transport` | `'log'` | Mode de transport : `'log'` (journalisation locale/stub) ou `'smtp'` (envoi réseau natif PHP) |
| `smtp_timeout` | `'10'` | Timeout réseau socket SMTP en secondes (borné entre `2` et `30`) |
| `smtp_password` | `''` *(absent si non défini)* | Mot de passe SMTP chiffré (`enc:v1:<base64(iv|tag|ciphertext)>` via OpenSSL AES-256-GCM) |

### 1.3. Variables d'environnement (`.env` / `.env.example`)
- `APP_KEY` : clé secrète applicative utilisée pour dériver la clé AES-256-GCM (fallback déterministe sécurisé sur un hash SHA-256 dérivé du chemin applicatif + sel interne si absent en dev/test).
- `SMTP_PASSWORD` *(optionnel)* : surcharge prioritaire du mot de passe SMTP stocké en base (`smtp_password_source = 'env'`).

**Script de Migration (`Core/Api/Database/Migrations/001_core_schema.php` & `004_smtp_reliability.php`) :**
```sql
-- Définition complète de email_logs dans 001_core_schema.php (SQLite / MySQL compatible)
CREATE TABLE IF NOT EXISTS email_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient_email VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body_html TEXT NULL,
    template_type VARCHAR(50) NOT NULL DEFAULT 'OTP',
    status VARCHAR(30) NOT NULL DEFAULT 'sent',
    smtp_response VARCHAR(255) NULL,
    retry_count INTEGER NOT NULL DEFAULT 0,
    last_retry_at DATETIME NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Migration incrémentale idempotente (004_smtp_reliability.php) pour bases existantes
ALTER TABLE email_logs ADD COLUMN body_html TEXT NULL;
ALTER TABLE email_logs ADD COLUMN retry_count INTEGER NOT NULL DEFAULT 0;
ALTER TABLE email_logs ADD COLUMN last_retry_at DATETIME NULL;

INSERT OR IGNORE INTO sys_settings (setting_key, setting_value) VALUES
    ('smtp_transport', 'log'),
    ('smtp_timeout', '10');
```

## 2. Arborescence Modulaire (PSR-4 `Core\Api\*` & `Core\Ui\*`)
```text
Core/
├── Api/
│   ├── Database/
│   │   └── Migrations/
│   │       ├── 001_core_schema.php       # MAJ DDL email_logs (body_html, retry_count, last_retry_at)
│   │       ├── 002_core_seed.php         # MAJ seed sys_settings (smtp_transport='log', smtp_timeout='10')
│   │       └── 004_smtp_reliability.php  # Migration idempotente pour bases déjà initialisées
│   ├── Services/
│   │   ├── SmtpClient.php                # [NOUVEAU] Client SMTP natif PHP (sockets TCP/SSL, STARTTLS, AUTH LOGIN/PLAIN, DATA)
│   │   └── MailerService.php             # [NOUVEAU] Orchestration config SMTP, chiffrement AES-256-GCM, garde APP_ENV=testing, logs & retry
│   ├── Endpoints/
│   │   ├── SystemController.php          # MAJ getSettings (masquage), updateSettings (validation + chiffrement), sendTrackedEmail, retryEmailLog
│   │   ├── AuthController.php            # Délégation forgotPassword & sendTwoFactorEmailCode à MailerService (hors transaction SQLite)
│   │   └── MemberController.php          # Délégation createMember & resendOtp à MailerService (hors transaction SQLite)
│   └── Http/
│       └── Routes/
│           └── CoreApiRoutes.php         # Ajout POST /api/v1/sys/email-logs/{id}/retry (Superadmin uniquement)
└── Ui/
    ├── Pages/
    │   └── sys-smtp.php                  # MAJ formulaire config (transport, password, badge état, clear, timeout) + tableau historique (statut, relance)
    └── ts/
        └── sys-console.ts                # MAJ hydratation SMTP, sauvegarde sécurisée, rendu badges/relances & handler POST retry
```

### Règle d'architecture SQLite WAL
- **Aucun appel réseau SMTP ne doit être exécuté à l'intérieur d'une transaction SQLite `BEGIN IMMEDIATE`**. Dans `AuthController` et `MemberController`, la transaction métier (mise à jour `users` / `members` / `app_logs`) est d'abord validée (`COMMIT`), puis `MailerService::send()` est appelé pour expédier et journaliser dans `email_logs`.

## 3. Contrats d'API (Endpoints — HTTP 200 Constant JSend)
| ID API | Endpoint | Méthode | Payload attendu (JSON) | Réponse JSON (Status 200) |
|---|---|---|---|---|
| `API-20-01` | `/api/v1/settings` | `GET` | *(aucun)* | `{"status":"success","success":true,"code":200,"message":"...","data":{"smtp_host":"...","smtp_port":"587","smtp_encryption":"tls","smtp_transport":"log","smtp_timeout":"10","smtp_username":"...","smtp_from_email":"...","smtp_from_name":"...","smtp_password_configured":"0\|1","smtp_password_source":"none\|db\|env"}}` *(clé `smtp_password` jamais retournée)* |
| `API-20-02` | `/api/v1/settings` | `PATCH` / `PUT` | `{"smtp_host":"...","smtp_port":"587","smtp_encryption":"tls\|ssl\|none","smtp_transport":"log\|smtp","smtp_timeout":"10","smtp_username":"...","smtp_password":"(optionnel)","smtp_password_clear":"0\|1","smtp_from_email":"...","smtp_from_name":"..."}` | Succès : `{"status":"success","success":true,"code":200,"message":"Configuration enregistrée avec succès.","data":{"updated_keys":[...]}}`<br>Erreur validation : `{"status":"error","success":false,"code":422,"message":"...","data":null}` |
| `API-20-03` | `/api/v1/sys/test-email` | `POST` | `{"recipient_email":"a@b.fr","subject":"...","template_type":"SMTP_DIAGNOSTIC","body_html":"(optionnel)"}` | Succès : `{"status":"success","success":true,"code":200,"message":"...","data":{"id":12,"status":"sent","smtp_response":"..."}}`<br>Échec SMTP : `{"status":"error","success":false,"code":502,"message":"Échec de l'envoi SMTP : ...","data":{"id":12,"status":"failed","smtp_response":"..."}}` |
| `API-20-04` | `/api/v1/sys/email-logs` | `GET` | *(aucun — Superadmin requis)* | `{"status":"success","success":true,"code":200,"message":"...","data":[{"id":12,"recipient_email":"...","subject":"...","template_type":"...","status":"sent\|failed","smtp_response":"...","retry_count":0,"last_retry_at":null,"sent_at":"..."}]}` |
| `API-20-05` | `/api/v1/sys/email-logs/{id}/retry` | `POST` | *(aucun — Superadmin requis)* | Succès : `{"status":"success","success":true,"code":200,"message":"E-mail relancé avec succès.","data":{"id":12,"status":"sent","retry_count":1,"smtp_response":"...","last_retry_at":"..."}}`<br>Échec SMTP : `code: 502` avec `status: "failed"`, `retry_count` incrémenté<br>Déjà envoyé : `code: 409`<br>Introuvable : `code: 404`<br>Non Superadmin : `code: 403` |

## 4. Instructions de passage de relais (Handoff)
- **Pour le Dev-Back :** Implémente les migrations (`001_core_schema.php`, `002_core_seed.php`, `004_smtp_reliability.php`), `SmtpClient`, `MailerService`, les mises à jour de `SystemController`, `AuthController`, `MemberController` et la route `POST /api/v1/sys/email-logs/{id}/retry` dans `CoreApiRoutes.php` en respectant strictement PHP 8.5, PDO préparé, PSR-4 et PHPStan niveau 5. Documente ton travail dans `/docs/current-sprint/03a-back-log.md`.
