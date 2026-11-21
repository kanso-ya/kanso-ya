# Architecture Technique : Lot 4 — Sprint 21 (Données Réelles Stats & Compatibilité MySQL/MariaDB)

## 1. Modèle de Données (MCD) et Script SQL

### 1.1. Évolution de la table `members`
| Table | Colonne | Type SQL | Contraintes | Description |
|---|---|---|---|---|
| `members` | `gender` | `VARCHAR(16)` | `NULL DEFAULT NULL` | Genre optionnel RGPD : `NULL` (Non renseigné), `'M'` (Homme), `'F'` (Femme), `'O'` (Autre / Non binaire) |

### 1.2. Migration `005_member_gender.php` & compatibilité DDL SQLite / MySQL
**Script de Migration (`Core/Api/Database/Migrations/005_member_gender.php`) :**
```sql
-- Ajout idempotent sur bases existantes (via Database::tableHasColumn)
ALTER TABLE members ADD COLUMN gender VARCHAR(16) NULL DEFAULT NULL;
```

### 1.3. Abstraction Dialectale dans `Core\Api\Database\Database`
Méthodes statiques ajoutées à `Core\Api\Database\Database` :
- `getDriver(?PDO $db = null): string` : retourne `'sqlite'` ou `'mysql'`.
- `isSqlite(?PDO $db = null): bool` / `isMysql(?PDO $db = null): bool`.
- `beginWriteTransaction(PDO $db): void` : exécute `BEGIN IMMEDIATE` sur SQLite, `START TRANSACTION` sur MySQL/MariaDB.
- `tableHasColumn(PDO $db, string $table, string $column): bool` : utilise `PRAGMA table_info(...)` sur SQLite et `SHOW COLUMNS FROM ... LIKE ...` sur MySQL/MariaDB.
- `insertIgnorePrefix(?PDO $db = null): string` : retourne `'INSERT OR IGNORE INTO'` sur SQLite, `'INSERT IGNORE INTO'` sur MySQL/MariaDB.
- `upsertSettingSql(?PDO $db = null): string` : retourne `ON CONFLICT(setting_key) DO UPDATE SET ...` sur SQLite, `ON DUPLICATE KEY UPDATE ...` sur MySQL/MariaDB.
- `adaptDdl(string $sqliteDdl, ?PDO $db = null): string` : convertit de façon déterministe un DDL SQLite (`INTEGER PRIMARY KEY AUTOINCREMENT`, `TEXT NOT NULL DEFAULT '...'`) en DDL MySQL 8 / MariaDB 10.6+ (`INT AUTO_INCREMENT PRIMARY KEY`, `TEXT NOT NULL`, `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`).
- `resetInstance(): void` : réinitialise le Singleton PDO (utile pour les tests multi-connexions).

## 2. Arborescence Modulaire (Fichiers Impactés)
```text
Core/
├── Api/
│   ├── Database/
│   │   ├── Database.php                      # Helpers dialectaux SQLite/MySQL + transaction() multi-SGBD
│   │   ├── check_mysql_compat.php            # [NOUVEAU] Script CLI de validation DDL & audit SQL MySQL/MariaDB
│   │   └── Migrations/
│   │       ├── 001_core_schema.php           # Ajout members.gender + exécution via Database::adaptDdl()
│   │       ├── 002_core_seed.php             # Seed gender réaliste (M/F/O/NULL), REPLACE INTO, Database::beginWriteTransaction()
│   │       ├── 003_modules_schema.php        # Exécution via Database::adaptDdl()
│   │       ├── 004_smtp_reliability.php      # Utilisation de Database::tableHasColumn() & insertIgnorePrefix()
│   │       └── 005_member_gender.php         # [NOUVEAU] Migration idempotente members.gender
│   ├── Services/
│   │   ├── StatsService.php                  # Calcul réel gender_ratio (male, female, other, unspecified + pct)
│   │   └── RolloverService.php               # Remplacement BEGIN IMMEDIATE -> Database::beginWriteTransaction()
│   └── Endpoints/
│       ├── SystemController.php              # getKpis() : calcul réel gender_ratio ; updateSettings() : upsertSettingSql() & beginWriteTransaction()
│       ├── MemberController.php              # Support champ optionnel gender (validation 422, create/update/list) + beginWriteTransaction() / insertIgnorePrefix()
│       ├── RegistrationController.php        # Support champ optionnel gender lors de l'inscription + beginWriteTransaction() / insertIgnorePrefix()
│       ├── AuthController.php                # tableHasColumn() & beginWriteTransaction()
│       ├── DisciplineController.php          # beginWriteTransaction()
│       └── RoleController.php                # beginWriteTransaction()
└── Ui/
    ├── Pages/
    │   ├── stats.php                         # Affichage ratio H/F réel + data-testid="kpi-gender-ratio" & "kpi-gender-details"
    │   ├── members.php                       # Sélecteurs optionnels create-member-gender & edit-member-gender
    │   └── register.php                      # Sélecteur optionnel reg-member-gender
    └── ts/
        ├── stats.ts                          # Typage & affichage gender_ratio réel + kpi-gender-details
        ├── members.ts                        # Envoi & pré-remplissage gender en création/édition
        └── register.ts                       # Lecture, brouillon local & envoi gender optionnel
```

## 3. Contrats d'API (Endpoints — HTTP 200 Constant JSend)
| ID API | Endpoint | Méthode | Payload attendu (JSON) | Réponse JSON (Status 200) |
|---|---|---|---|---|
| `API-21-01` | `/api/v1/stats/kpis` | `GET` | *(aucun)* | `{"status":"success","success":true,"code":200,"data":{...,"gender_ratio":{"male":24,"female":22,"other":2,"unspecified":10,"male_pct":41,"female_pct":38,"other_pct":3,"unspecified_pct":17}}}` |
| `API-21-02` | `/api/v1/members` | `POST` | `{...,"gender":"M\|F\|O\|\"\"\|null"}` | Succès : `code: 200` ; `gender` invalide : `code: 422` |
| `API-21-03` | `/api/v1/members/{id}` | `PUT` / `PATCH` | `{...,"gender":"M\|F\|O\|\"\"\|null"}` | Succès : `code: 200` ; `gender` invalide : `code: 422` |
| `API-21-04` | `/api/v1/registrations` | `POST` | `{"members":[{...,"gender":"M\|F\|O\|\"\"\|null"}],...}` | Succès : `code: 200` ; `gender` invalide : `code: 422` |

## 4. Instructions de passage de relais (Handoff)
- **Pour le Dev-Back :** Implémente les helpers dialectaux dans `Database.php`, les migrations `001`–`005`, le script `check_mysql_compat.php`, les calculs réels dans `StatsService` et `SystemController::getKpis()`, et le support optionnel `gender` dans `MemberController` et `RegistrationController`. Vérifie `php -l`, `composer dump-autoload -o --strict-psr`, `vendor\bin\phpstan analyse` (niveau 5, 0 erreur) et `npm run db:check-mysql`.
