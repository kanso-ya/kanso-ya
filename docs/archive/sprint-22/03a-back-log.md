# Journal de Livraison Backend — Lot 5 / Sprint 22 (Module « Présences & Suivi »)

**Rôle :** @dev-back  
**Statut :** Terminé & Vérifié (`php -l` OK · `composer dump-autoload -o --strict-psr` : 30 classes, 0 avertissement · `vendor\bin\phpstan analyse` niveau 5 : 0 erreur sur 63 fichiers)

## 1. Structure Modulaire (`modules/PresenceApi/`)
- `modules/PresenceApi/manifest.json` : manifeste SDK v2 (`id: presence`, `tables_prefix: mod_presence_`, `ui_folder: PresenceUi`, `assets.publish_dir: PresenceUi/dist`, `assets.public_slug: presence`).
- `modules/PresenceApi/Module.php` :
  - `install()` : crée `mod_presence_sessions` et `mod_presence_records` via `Database::execDdlStatements()` (SQLite & MySQL).
  - `uninstall()` : supprime proprement `mod_presence_records` et `mod_presence_sessions`.
  - `boot(EventManager)` : enregistre `admin_sidebar_menu` (`/presences`, permission `stats.view`), `ui_routes` (`/presences`), `ui_tabs` (onglet `presence` « 📋 Assiduité » injecté dans `/stats` avec `data-testid="presence-stats-table"`) et les 6 routes `api_routes` sous `/api/v1/modules/presence/*`.
- `modules/PresenceApi/Endpoints/PresenceController.php` :
  - `GET /api/v1/modules/presence/sessions` & `POST /api/v1/modules/presence/sessions` (création/réouverture idempotente).
  - `GET /api/v1/modules/presence/sessions/{id}/roster` (roster anonymisé RGPD sans e-mail/adresse).
  - `POST /api/v1/modules/presence/sessions/{id}/mark` (pointage idempotent `present` / `absent` / `excused` en transaction).
  - `GET /api/v1/modules/presence/members/{id}/history` (contrôle strict RGPD : Bureau/Entraîneur/Superadmin, adhérent lui-même ou tuteur légal rattaché dans `member_tutor` ; `403` sinon).
  - `GET /api/v1/modules/presence/stats` (statistiques agrégées par discipline).

## 2. Handoff vers `@dev-front`
- **Pour le Dev-Front :** Les 6 endpoints `/api/v1/modules/presence/*` et le hook `ui_tabs` sont prêts. Tu peux implémenter `modules/PresenceUi/Pages/presences.php`, `modules/PresenceUi/dist/presence.css` et `modules/PresenceUi/dist/presence.js` avec tous les `data-testid` de `02-specs-metier.md`, puis compiler Tailwind CSS (`npm run build:css`).
