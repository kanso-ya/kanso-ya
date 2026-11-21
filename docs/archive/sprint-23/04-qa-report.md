STATUT: VALIDATED

# Rapport de Recette QA / UAT — Lot 5 / Sprint 23 (Module « Événements, Stages & Compétitions »)

**Rôle :** @qa-uat  
**Date :** 21 novembre 2026  
**Suite dédiée :** `tests/e2e/24-sprint23-module-evenements.spec.ts`  
**Résultat global :** **310 / 310 Pass** (Desktop Chrome + Mobile Chrome Pixel 5 — 0 échec, 0 régression)

## 1. Couverture des Scénarios Sprint 23
| Scénario | Persona / Règles | Desktop Chrome | Mobile Chrome | Détails vérifiés |
|---|---|---:|---:|---|
| `S23-01` | Cycle de vie SDK v2 & Widget `/` (`RG-01`, `RG-05`) | PASS | PASS | Scan & activation `EvenementsApi`, publication `public/assets/modules/evenements/{evenements.css, evenements.js}` (HTTP 200), widget `home-events-widget` injecté sur `/` via `ui_slot_home_after_articles`. |
| `S23-02` | Persona 1 — Création, Jauge & Participants (`RG-02`, `RG-03`, `RG-04`) | PASS | PASS | Création d'un stage à `max_capacity = 1` par le Président, inscription en 1 clic par un Parent pour son enfant rattaché (`1 / 1 inscrits` + badge `Complet`), refus `409` sur dépassement de jauge, modale `event-participants-modal` et export CSV vérifiés. |
| `S23-03` | Persona 2 — RBAC/RGPD & Désinstallation (`RG-01`, `RG-02`, `RG-03`) | PASS | PASS | Blocage `403` Adhérent sur création, participants et inscription tiers (`#19`), `401` Visiteur sur inscription, désinstallation propre (purge assets, `/evenements` HTTP `404`, API `404`, disparition de `home-events-widget` sur `/`). |

## 2. Vérifications Qualité & Non-Régression
- **Playwright E2E complet :** `npx playwright test --workers=1` → **310/310 Pass** (`3.6m`).
- **PHPStan niveau 5 :** `vendor\bin\phpstan analyse` → **0 erreur** (66 fichiers analysés).
- **Composer PSR-4 strict :** **32 classes**, 0 avertissement.
- **Build Frontend :** `npm run build:ts` (0 erreur) & `npm run build:css` (Tailwind local sans CDN).
