# Journal de Livraison Frontend — Lot 5 / Sprint 22 (Module « Présences & Suivi »)

**Rôle :** @dev-front  
**Statut :** Terminé & Compilé (`php -l modules/PresenceUi/Pages/presences.php` OK · `npm run build:ts` : 0 erreur · `npm run build:css` : OK)

## 1. Livrables UI Modulaires (`modules/PresenceUi/`)
- `modules/PresenceUi/Pages/presences.php` :
  - Garde applicative `KANSO_ENTRY`, contrôle `$requireAuth = true` & `$requiredPerms = ['stats.view']`.
  - Formulaire d'ouverture/création de séance (`presence-discipline-select`, `presence-date-input`, `presence-slot-input`, `btn-open-presence-session`).
  - Feuille d'appel mobile-first (`presence-roster-list`, boutons tactiles `presence-btn-present-{id}` / `absent` / `excused`, bouton « Tous présents », `btn-save-presence-roll`).
  - Modale d'historique d'assiduité individuel (`presence-history-modal`, `presence-history-rate`, compteurs Présent/Absent/Excusé et liste des séances).
- `modules/PresenceUi/dist/presence.css` & `modules/PresenceUi/dist/presence.js` :
  - Assets statiques automatiquement publiés sous `/assets/modules/presence/` lors de l'activation du module et purgés à la désinstallation.
  - Script Vanilla ES module (JSDoc complet, échappement XSS `escapeHtml`, consommation JSend HTTP 200).

## 2. Handoff vers `@qa-uat`
- **Pour le QA/UAT :** Le module `PresenceApi` / `PresenceUi` est prêt, sans aucune modification du Core. Tu peux créer `tests/e2e/23-sprint22-module-presence.spec.ts` couvrant le cycle de vie complet (scan, activation, appel numérique, idempotence, historique RGPD, onglet `/stats`, RBAC `403`, désinstallation propre) et lancer Playwright.
