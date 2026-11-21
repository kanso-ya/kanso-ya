# Architecture Technique : Lot 5 — Sprint 23 (Module « Événements, Stages & Compétitions »)

## 1. Modèle de Données (MCD) et Script SQL (`mod_evenements_*`)

| Table | Colonne | Type SQL | Contraintes (PK, FK) | Description |
|---|---|---|---|---|
| `mod_evenements_events` | `id` | `INTEGER` | PK, Auto Increment | Identifiant de l'événement |
| `mod_evenements_events` | `title` | `VARCHAR(200)` | NOT NULL | Intitulé du stage / passage de grade / compétition |
| `mod_evenements_events` | `event_type` | `VARCHAR(30)` | NOT NULL, DEFAULT `'stage'` | `stage`, `grade`, `competition` ou `autre` |
| `mod_evenements_events` | `discipline_id` | `INTEGER` | NULL | Discipline cible optionnelle (`NULL` = toutes) |
| `mod_evenements_events` | `event_date` | `DATE` | NOT NULL | Date de l'événement (`YYYY-MM-DD`) |
| `mod_evenements_events` | `location` | `VARCHAR(200)` | NULL | Lieu / dojo |
| `mod_evenements_events` | `description` | `TEXT` | NULL | Descriptif court |
| `mod_evenements_events` | `max_capacity` | `INTEGER` | NULL | Jauge maximale (`NULL` ou `0` = illimitée) |
| `mod_evenements_events` | `status` | `VARCHAR(20)` | NOT NULL, DEFAULT `'published'` | `draft`, `published` ou `closed` |
| `mod_evenements_events` | `created_by` | `INTEGER` | NULL | Auteur |
| `mod_evenements_events` | `created_at` | `DATETIME` | NOT NULL, DEFAULT CURRENT_TIMESTAMP | Date de création |
| `mod_evenements_registrations` | `id` | `INTEGER` | PK, Auto Increment | Identifiant de l'inscription |
| `mod_evenements_registrations` | `event_id` | `INTEGER` | NOT NULL, FK → `mod_evenements_events(id)` ON DELETE CASCADE | Événement cible |
| `mod_evenements_registrations` | `member_id` | `INTEGER` | NOT NULL | Adhérent inscrit (`members.id`) |
| `mod_evenements_registrations` | `registered_by` | `INTEGER` | NULL | Utilisateur ayant effectué l'inscription |
| `mod_evenements_registrations` | `created_at` | `DATETIME` | NOT NULL, DEFAULT CURRENT_TIMESTAMP | Horodatage d'inscription |

**Script DDL (`Modules\EvenementsApi\Module::install()` via `Database::execDdlStatements`) :**
```sql
CREATE TABLE IF NOT EXISTS mod_evenements_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(200) NOT NULL,
    event_type VARCHAR(30) NOT NULL DEFAULT 'stage',
    discipline_id INTEGER NULL,
    event_date DATE NOT NULL,
    location VARCHAR(200) NULL,
    description TEXT NULL,
    max_capacity INTEGER NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'published',
    created_by INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mod_evenements_registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL,
    member_id INTEGER NOT NULL,
    registered_by INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(event_id, member_id),
    FOREIGN KEY(event_id) REFERENCES mod_evenements_events(id) ON DELETE CASCADE
);
```

## 2. Arborescence Modulaire (`Modules\EvenementsApi\*` & `EvenementsUi`)
```text
modules/
├── EvenementsApi/
│   ├── manifest.json                     # Schéma v2 (id: evenements, tables_prefix: mod_evenements_, ui_folder: EvenementsUi)
│   ├── Module.php                        # ModuleInterface : install(), uninstall(), boot(EventManager) + ui_slot_home_after_articles
│   └── Endpoints/
│       └── EvenementsController.php      # Logique métier, jauge, famille/RGPD, participants CSV, PDO SQLite/MySQL
└── EvenementsUi/
    ├── Pages/
    │   └── evenements.php                # Vue /evenements (garde KANSO_ENTRY, layout Core, calendrier + gestion + modale inscrits)
    └── dist/
        ├── evenements.css                # Styles publiés -> public/assets/modules/evenements/
        └── evenements.js                 # Script Vanilla ES module publié -> public/assets/modules/evenements/
```

## 3. Contrats d'API (Endpoints — HTTP 200 Constant JSend sous `/api/v1/modules/evenements/*`)
| ID API | Endpoint | Méthode | Payload attendu (JSON) | Réponse JSON (Status 200) |
|---|---|---|---|---|
| `API-23-01` | `/api/v1/modules/evenements/events` | `GET` | *(aucun)* | `{"status":"success","success":true,"code":200,"data":{"can_manage":bool,"is_authenticated":bool,"disciplines":[...],"eligible_members":[...],"events":[...]}}` |
| `API-23-02` | `/api/v1/modules/evenements/events` | `POST` | `{"title":"...","event_type":"stage\|grade\|competition\|autre","discipline_id":1\|null,"event_date":"2026-12-12","location":"...","description":"...","max_capacity":20,"status":"published"}` | `code: 201` (ou `403` / `422`) |
| `API-23-03` | `/api/v1/modules/evenements/events/{id}` | `PATCH` / `PUT` | `{"status":"closed\|published\|draft", ...}` | `code: 200` (ou `403` / `404` / `422`) |
| `API-23-04` | `/api/v1/modules/evenements/events/{id}/register` | `POST` | `{"member_id":1}` | `code: 200` (ou `401` / `403` / `404` / `409`) |
| `API-23-05` | `/api/v1/modules/evenements/events/{id}/register/{memberId}` | `DELETE` | *(aucun)* | `code: 200` (ou `401` / `403` / `404`) |
| `API-23-06` | `/api/v1/modules/evenements/events/{id}/participants` | `GET` | *(aucun — gestionnaire requis)* | `{"status":"success","success":true,"code":200,"data":{"event":{...},"participants":[...],"csv_content":"..."}}` (`403` si non gestionnaire) |

## 4. Instructions de passage de relais (Handoff)
- **Pour le Dev-Back :** Implémente `modules/EvenementsApi/manifest.json`, `modules/EvenementsApi/Module.php` et `modules/EvenementsApi/Endpoints/EvenementsController.php` en respectant PHP 8.5 strict, PDO préparé multi-SGBD (SQLite & MySQL), PSR-4 `Modules\EvenementsApi\*` et PHPStan niveau 5.
