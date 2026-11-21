STATUT: VALIDATED

# Rapport de Recette QA / UAT — Lot 6 / Sprint 25 (Boutique Club — Catalogue Avancé, Variantes, Remises, Flocage & Lots)

**Rôle :** @qa-uat  
**Date :** 21 novembre 2026  
**Suite dédiée :** `tests/e2e/26-sprint25-boutique-catalogue.spec.ts`  
**Résultat global :** **320 / 320 Pass** (Desktop Chrome + Mobile Chrome Pixel 5 — 0 échec, 0 régression)

## 1. Couverture des Scénarios Sprint 25
| Scénario | Persona / Règles | Desktop Chrome | Mobile Chrome | Détails vérifiés |
|---|---|---:|---:|---|
| `S25-01` | Persona 1 (`RG-01`..`RG-04`) | PASS | PASS | Création produit avec tailles (`S:3, M:5`), disponibilité, remise adhérent `20%` (`50 €` → `40,00 €`), `members_only = 1` et flocage `+8 €` (recalcul live `48,00 €` + champ texte) ; création d'un lot mixte (`Pack Rentree Dojo` : article catalogue + article exclusif au lot). |
| `S25-02` | Persona 2 (`RG-02`, `RG-04`, `RG-05`) | PASS | PASS | Cloisonnement strict des produits `members_only` pour les visiteurs non connectés (UI + API), affichage du prix public non remisé, gardes API `403` Adhérent et `422` sur payloads invalides. |
| Non-régression | Sprint 6 (`07-*`) & Sprint 17 (`18-*`) | PASS | PASS | 100 % rétrocompatible avec `07-sprint6-modules-sdk.spec.ts` (12/12) et `18-sprint17-modules-v2.spec.ts` (6/6). |

## 2. Vérifications Qualité & Non-Régression
- **Playwright E2E complet :** `npx playwright test --workers=1` → **320/320 Pass** (`3.4m`).
- **PHPStan niveau 5 :** `vendor\bin\phpstan analyse` → **0 erreur** (72 fichiers analysés).
- **Composer PSR-4 strict :** **36 classes**, 0 avertissement.
- **Build Frontend :** `npm run build:ts` (0 erreur) & `npm run build:css` (Tailwind local sans CDN).
