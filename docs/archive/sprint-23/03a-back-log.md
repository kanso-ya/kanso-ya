# Journal de Livraison Backend — Lot 5 / Sprint 23 (Module « Événements, Stages & Compétitions »)

**Rôle :** @dev-back  
**Statut :** Terminé & Vérifié (`php -l` OK · `composer dump-autoload -o --strict-psr` : 32 classes, 0 avertissement · `vendor\bin\phpstan analyse` niveau 5 : 0 erreur sur 65 fichiers)

## 1. Structure Modulaire (`modules/EvenementsApi/`)
- `modules/EvenementsApi/manifest.json` : manifeste SDK v2 (`id: evenements`, `tables_prefix: mod_evenements_`, `ui_folder: EvenementsUi`, `assets.publish_dir: EvenementsUi/dist`, `assets.public_slug: evenements`).
- `modules/EvenementsApi/Module.php` :
  - `install()` : crée `mod_evenements_events` et `mod_evenements_registrations` via `Database::execDdlStatements()` (SQLite & MySQL) et insère 2 événements publiés initiaux si la table est vide.
  - `uninstall()` : supprime proprement `mod_evenements_registrations` et `mod_evenements_events`.
  - `boot(EventManager)` : enregistre `admin_sidebar_menu` (`/evenements`), `ui_routes` (`/evenements`), le widget d'accueil `ui_slot_home_after_articles` (`data-testid="home-events-widget"`) et les 6 endpoints REST sous `/api/v1/modules/evenements/*`.
- `modules/EvenementsApi/Endpoints/EvenementsController.php` :
  - `GET /api/v1/modules/evenements/events` (calendrier + jauge + membres éligibles de la famille).
  - `POST /api/v1/modules/evenements/events` & `PATCH/PUT /api/v1/modules/evenements/events/{id}` (gestionnaires uniquement, `403`/`422`/`404`).
  - `POST /api/v1/modules/evenements/events/{id}/register` & `DELETE /api/v1/modules/evenements/events/{id}/register/{memberId}` (inscription 1 clic avec contrôle de jauge `409`, statut `409`, rattachement familial `member_tutor` `403`, authentification `401`).
  - `GET /api/v1/modules/evenements/events/{id}/participants` (liste participants + CSV téléchargeable, réservé aux gestionnaires).

## 2. Handoff vers `@dev-front`
- **Pour le Dev-Front :** Les 6 endpoints `/api/v1/modules/evenements/*` et le widget d'accueil `ui_slot_home_after_articles` sont prêts. Tu peux implémenter `modules/EvenementsUi/Pages/evenements.php`, `modules/EvenementsUi/dist/evenements.css` et `modules/EvenementsUi/dist/evenements.js` avec tous les `data-testid` de `02-specs-metier.md`, puis compiler Tailwind CSS (`npm run build:css`).
