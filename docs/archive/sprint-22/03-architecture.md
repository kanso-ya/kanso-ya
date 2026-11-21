# Architecture Technique : Lot 5 — Sprint 22 (Module « Présences & Suivi »)

## 1. Modèle de Données (MCD) et Script SQL (`mod_presence_*`)

| Table | Colonne | Type SQL | Contraintes (PK, FK) | Description |
|---|---|---|---|---|
| `mod_presence_sessions` | `id` | `INTEGER` | PK, Auto Increment | Identifiant de la séance d'appel |
| `mod_presence_sessions` | `discipline_id` | `INTEGER` | NOT NULL | Discipline concernée (`disciplines.id`) |
| `mod_presence_sessions` | `session_date` | `DATE` | NOT NULL | Date de la séance (`YYYY-MM-DD`) |
| `mod_presence_sessions` | `time_slot` | `VARCHAR(50)` | NOT NULL | Créneau horaire (ex. `18:00-19:30`) |
| `mod_presence_sessions` | `label` | `VARCHAR(150)` | NULL | Libellé optionnel du cours |
| `mod_presence_sessions` | `created_by` | `INTEGER` | NULL | Utilisateur ayant ouvert la séance |
| `mod_presence_sessions` | `created_at` | `DATETIME` | NOT NULL, DEFAULT CURRENT_TIMESTAMP | Horodatage de création |
| `mod_presence_records` | `id` | `INTEGER` | PK, Auto Increment | Identifiant du pointage |
| `mod_presence_records` | `session_id` | `INTEGER` | NOT NULL, FK → `mod_presence_sessions(id)` ON DELETE CASCADE | Séance associée |
| `mod_presence_records` | `member_id` | `INTEGER` | NOT NULL | Adhérent pointé (`members.id`) |
| `mod_presence_records` | `status` | `VARCHAR(20)` | NOT NULL | `present`, `absent` ou `excused` |
| `mod_presence_records` | `note` | `VARCHAR(255)` | NULL | Remarque courte optionnelle |
| `mod_presence_records` | `marked_by` | `INTEGER` | NULL | Utilisateur ayant effectué le pointage |
| `mod_presence_records` | `updated_at` | `DATETIME` | NOT NULL, DEFAULT CURRENT_TIMESTAMP | Dernière mise à jour du pointage |

**Script DDL (`Modules\PresenceApi\Module::install()` via `Database::execDdlStatements`) :**
```sql
CREATE TABLE IF NOT EXISTS mod_presence_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    discipline_id INTEGER NOT NULL,
    session_date DATE NOT NULL,
    time_slot VARCHAR(50) NOT NULL DEFAULT '18:00-19:30',
    label VARCHAR(150) NULL,
    created_by INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(discipline_id, session_date, time_slot)
);

CREATE TABLE IF NOT EXISTS mod_presence_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL,
    member_id INTEGER NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'present',
    note VARCHAR(255) NULL,
    marked_by INTEGER NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(session_id, member_id),
    FOREIGN KEY(session_id) REFERENCES mod_presence_sessions(id) ON DELETE CASCADE
);
```

## 2. Arborescence Modulaire (`Modules\PresenceApi\*` & `PresenceUi`)
```text
modules/
├── PresenceApi/
│   ├── manifest.json                     # Schéma v2 (id: presence, tables_prefix: mod_presence_, ui_folder: PresenceUi)
│   ├── Module.php                        # ModuleInterface : install(), uninstall(), boot(EventManager)
│   └── Endpoints/
│       └── PresenceController.php        # Logique métier, contrôles RBAC/RGPD, requêtes PDO SQLite/MySQL
└── PresenceUi/
    ├── Pages/
    │   └── presences.php                 # Vue /presences (garde KANSO_ENTRY, layout Core, mobile-first)
    └── dist/
        ├── presence.css                  # Styles complémentaires publiés -> public/assets/modules/presence/
        └── presence.js                   # Script Vanilla ES module publié -> public/assets/modules/presence/
```

## 3. Contrats d'API (Endpoints — HTTP 200 Constant JSend sous `/api/v1/modules/presence/*`)
| ID API | Endpoint | Méthode | Payload attendu (JSON) | Réponse JSON (Status 200) |
|---|---|---|---|---|
| `API-22-01` | `/api/v1/modules/presence/sessions` | `GET` | *(query optionnelle `discipline_id`)* | `{"status":"success","success":true,"code":200,"data":{"disciplines":[...],"sessions":[...]}}` |
| `API-22-02` | `/api/v1/modules/presence/sessions` | `POST` | `{"discipline_id":1,"session_date":"2026-11-21","time_slot":"18:00-19:30","label":"Cours Ados"}` | `{"status":"success","success":true,"code":200,"data":{"session":{...},"created":true\|false}}` (ou `422` / `403`) |
| `API-22-03` | `/api/v1/modules/presence/sessions/{id}/roster` | `GET` | *(aucun)* | `{"status":"success","success":true,"code":200,"data":{"session":{...},"roster":[{"member_id":1,"first_name":"...","last_name":"...","member_status":"active","attendance_status":"present\|absent\|excused\|null","note":null}]}}` |
| `API-22-04` | `/api/v1/modules/presence/sessions/{id}/mark` | `POST` | `{"entries":[{"member_id":1,"status":"present","note":""}]}` | `{"status":"success","success":true,"code":200,"data":{"session_id":1,"saved_count":3,"summary":{"present":2,"absent":0,"excused":1}}}` |
| `API-22-05` | `/api/v1/modules/presence/members/{id}/history` | `GET` | *(aucun)* | `{"status":"success","success":true,"code":200,"data":{"member":{"id":1,"first_name":"...","last_name":"..."},"summary":{"total":4,"present":3,"absent":1,"excused":0,"attendance_rate":75},"records":[...]}}` (`403` si non autorisé) |
| `API-22-06` | `/api/v1/modules/presence/stats` | `GET` | *(aucun)* | `{"status":"success","success":true,"code":200,"data":{"total_sessions":2,"total_marks":10,"global_rate":80,"by_discipline":[...]}}` |

## 4. Instructions de passage de relais (Handoff)
- **Pour le Dev-Back :** Implémente `modules/PresenceApi/manifest.json`, `modules/PresenceApi/Module.php` et `modules/PresenceApi/Endpoints/PresenceController.php` en respectant PHP 8.5 strict, PDO préparé multi-SGBD (SQLite & MySQL), PSR-4 `Modules\PresenceApi\*`, PHPStan niveau 5 et `npm run db:check-mysql`.
