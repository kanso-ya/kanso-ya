STATUT: VALIDATED

# Rapport de Recette QA / UAT — Lot 5 / Sprint 22 (Module « Présences & Suivi »)

**Rôle :** @qa-uat  
**Date :** 21 novembre 2026  
**Suite dédiée :** `tests/e2e/23-sprint22-module-presence.spec.ts`  
**Résultat global :** **304 / 304 Pass** (Desktop Chrome + Mobile Chrome Pixel 5 — 0 échec, 0 régression)

## 1. Couverture des Scénarios Sprint 22
| Scénario | Persona / Règles | Desktop Chrome | Mobile Chrome | Détails vérifiés |
|---|---|---:|---:|---|
| `S22-01` | Cycle de vie SDK v2 (`RG-01`) | PASS | PASS | Scan & activation `PresenceApi`, publication automatique de `public/assets/modules/presence/presence.css` et `presence.js` (HTTP 200). |
| `S22-02` | Persona 1 — Entraîneur (`RG-02`..`RG-06`) | PASS | PASS | Ouverture/création idempotente d'une séance sur `/presences`, roster anonymisé RGPD (zéro e-mail exposé), pointage `Présent` / `Absent` / `Excusé`, correction idempotente, modale d'historique individuel (`100%`) et onglet injecté « 📋 Assiduité » dans `/stats` via `ui_tabs`. |
| `S22-03` | Persona 2 — RBAC/RGPD & Désinstallation (`RG-01`, `RG-02`, `RG-05`) | PASS | PASS | Blocage `403` pour Rédacteur sur `/api/v1/modules/presence/sessions`, autorisation `200` pour un parent sur son enfant rattaché (`#1`) vs blocage `403` sur un adhérent tiers (`#19`), désinstallation propre (purge assets, `/presences` en HTTP `404`, API en `404`, disparition de `stats-tab-presence`). |

## 2. Vérifications Qualité & Non-Régression
- **Playwright E2E complet :** `npx playwright test --workers=1` → **304/304 Pass** (`3.6m`).
- **PHPStan niveau 5 :** `vendor\bin\phpstan analyse` → **0 erreur** (63 fichiers analysés).
- **Composer PSR-4 strict :** **30 classes**, 0 avertissement.
- **Build Frontend :** `npm run build:ts` (0 erreur) & `npm run build:css` (Tailwind local sans CDN).
