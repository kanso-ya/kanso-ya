# Sprint Actif : Lot 4 — Sprint 21 : Données Réelles Stats & Compatibilité MySQL/MariaDB
**Objectif principal :** Remplacer définitivement le ratio démographique H/F statique (54 % / 46 %) sur `/stats` et `GET /api/v1/stats/kpis` par de vraies données agrégées issues d'un champ optionnel `gender` conforme RGPD, et certifier la portabilité du schéma SQL, des migrations et des transactions Core sur **MySQL/MariaDB** en plus de SQLite WAL.

## 1. Périmètre Macro (User Stories)
| ID | Fonctionnalité ciblée | Description métier | Priorité |
|---|---|---|---|
| `US-LOT4-05` | Champ optionnel `gender` (RGPD) | Ajouter une colonne facultative `gender` (`M`, `F`, `O`, ou `NULL` « Non renseigné » par défaut) sur la table `members`, saisissable de manière optionnelle sur `/register` et `/members` et protégée par l'anonymisation RGPD pour l'Entraîneur. | Haute |
| `US-LOT4-06` | Statistiques démographiques réelles | Remplacer les constantes statiques `54 % / 46 %` dans `StatsService` et `SystemController::getKpis()` par les effectifs et pourcentages réels (Hommes, Femmes, Autre, Non renseigné) affichés dynamiquement sur `/stats`. | Haute |
| `US-LOT4-07` | Compatibilité SQL MySQL/MariaDB | Auditer et abstraire toutes les syntaxes SQLite-only (`BEGIN IMMEDIATE`, `PRAGMA`, `AUTOINCREMENT`, `INSERT OR IGNORE`, `INSERT OR REPLACE`, `ON CONFLICT`) dans `Database.php`, les migrations `001`–`005` et les contrôleurs Core afin qu'elles s'exécutent nativement sur SQLite 3 WAL et MySQL 8 / MariaDB 10.6+. | Haute |
| `US-LOT4-08` | Validation & outillage multi-SGBD | Fournir un vérificateur de compatibilité MySQL/MariaDB (`db:check-mysql`) et une suite Playwright dédiée (`22-sprint21-stats-mysql.spec.ts`) validant les stats réelles, le RGPD et l'absence de régression SQLite/MySQL. | Moyenne |

## 2. Hors Périmètre (Exclusions strictes)
- **Ne pas** rendre le champ `gender` obligatoire dans aucun formulaire (respect strict de la minimisation RGPD).
- **Ne pas** développer d'assistant graphique de migration de données SQLite → MySQL.
- **Ne pas** introduire d'ORM ou de Query Builder externe (conservation stricte de PDO pur et Vanilla PHP 8.5).
- **Ne pas** toucher aux fonctionnalités des Lots 5 et 6 (modules Présences, Événements, Paiement en ligne).
- **Ne pas** supprimer les clés existantes de `GET /api/v1/stats/kpis` (`gender_ratio.male`, `female`, `male_pct`, `female_pct`) ni les IDs/sélecteurs existants de `/stats`, `/members` et `/register`.

## 3. Instructions de passage de relais (Handoff)
- **Pour le PO :** À partir de ce scope, rédige les spécifications fonctionnelles détaillées, les règles de gestion RGPD/démographie/multi-SGBD et la liste des `data-testid` dans `docs/current-sprint/02-specs-metier.md`.
