# Journal de Livraison Frontend — Lot 5 / Sprint 23 (Module « Événements, Stages & Compétitions »)

**Rôle :** @dev-front  
**Statut :** Terminé & Compilé (`php -l modules/EvenementsUi/Pages/evenements.php` OK · `npm run build:ts` : 0 erreur · `npm run build:css` : OK)

## 1. Livrables UI Modulaires (`modules/EvenementsUi/`)
- `modules/EvenementsUi/Pages/evenements.php` :
  - Garde applicative `KANSO_ENTRY`, consultation publique (`$requireAuth = false`).
  - Formulaire de création d'événement (`events-create-form`, `event-title-input`, `event-type-select`, `event-date-input`, `event-location-input`, `event-capacity-input`, `btn-create-event`) affiché uniquement aux gestionnaires.
  - Grille du calendrier (`events-list-grid`, cartes `event-card-{id}` avec sélecteur familial `event-member-select-{id}`, boutons `btn-register-event-{id}` / `btn-unregister-event-{id}`, gestionnaires `btn-event-participants-{id}` & `btn-toggle-event-status-{id}`).
  - Modale des participants (`event-participants-modal`) avec export CSV client (`btn-export-participants-csv`).
- `modules/EvenementsUi/dist/evenements.css` & `modules/EvenementsUi/dist/evenements.js` :
  - Assets publiés automatiquement sous `/assets/modules/evenements/` à l'activation et purgés à la désinstallation.

## 2. Handoff vers `@qa-uat`
- **Pour le QA/UAT :** Le module `EvenementsApi` / `EvenementsUi` est prêt, sans aucune modification du Core. Tu peux créer `tests/e2e/24-sprint23-module-evenements.spec.ts` et lancer Playwright.
