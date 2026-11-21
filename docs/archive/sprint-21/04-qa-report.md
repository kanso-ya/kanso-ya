STATUT: VALIDATED

# Rapport de Recette QA / UAT — Lot 4 / Sprint 21 (Données Réelles Stats & Compatibilité MySQL/MariaDB)

**Rôle :** @qa-uat  
**Date :** 21 novembre 2026  
**Suite dédiée :** `tests/e2e/22-sprint21-stats-mysql.spec.ts`  
**Résultat global :** **298 / 298 Pass** (Desktop Chrome + Mobile Chrome Pixel 5 — 0 échec, 0 régression)

## 1. Couverture des Scénarios Sprint 21
| Scénario | Persona / Règles | Desktop Chrome | Mobile Chrome | Détails vérifiés |
|---|---|---:|---:|---|
| `S21-01` | Persona 1 (`RG-03`, `RG-04`) | PASS | PASS | Calculs réels `male`, `female`, `other`, `unspecified` + pourcentages sur `GET /api/v1/stats/demographics` et `/stats`, suppression de la constante `54%/46%` et de « Répartition estimée », répercussion immédiate après création (`F`) et édition (`O`) d'un adhérent. |
| `S21-02` | Persona 2 (`RG-01`, `RG-02`) | PASS | PASS | Sélecteurs optionnels `reg-member-gender`, `create-member-gender`, `edit-member-gender` non requis, création d'adhérent sans genre (`NULL`) acceptée, rejets API `422` sur `gender` invalide (`/api/v1/members` et `/api/v1/registrations`). |
| `S21-03` | Compatibilité MySQL (`RG-05`, `RG-06`) | PASS | PASS | `php Core/Api/Database/check_mysql_compat.php` → 0 syntaxe SQLite-only non abstraite dans `Core/Api/` et traduction DDL MySQL 8 / MariaDB 10.6+ (`InnoDB`, `utf8mb4`, `AUTO_INCREMENT`) validée. |

## 2. Vérifications Qualité & Non-Régression
- **Playwright E2E complet :** `npx playwright test --workers=1` → **298/298 Pass** (`3.4m`).
- **PHPStan niveau 5 :** `vendor\bin\phpstan analyse` → **0 erreur** (61 fichiers analysés).
- **Audit MySQL/MariaDB :** `npm run db:check-mysql` → **OK**.
- **Build Frontend :** `npm run build:ts` (0 erreur) & `npm run build:css` (Tailwind local sans CDN).
