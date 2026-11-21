# Journal de Livraison Backend — Lot 4 / Sprint 21 (Données Réelles Stats & Compatibilité MySQL/MariaDB)

**Rôle :** @dev-back  
**Statut :** Terminé & Vérifié (`npm run db:reset` OK · `npm run db:check-mysql` OK · `composer dump-autoload -o --strict-psr` : 28 classes, 0 avertissement · `vendor\bin\phpstan analyse` niveau 5 : 0 erreur sur 61 fichiers)

## 1. Abstraction Dialectale SQLite / MySQL (`Core\Api\Database\Database`)
- Ajout des helpers dialectaux multi-SGBD : `getDriver()`, `isSqlite()`, `isMysql()`, `beginWriteTransaction()`, `tableHasColumn()`, `insertIgnorePrefix()`, `upsertSettingSql()`, `adaptDdlForDriver()`, `execDdlStatements()`, `resetInstance()`.
- `Database::getInstance()` accepte désormais la surcharge par variables d'environnement OS (`DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).
- Remplacement intégral des syntaxes SQLite-only (`BEGIN IMMEDIATE`, `PRAGMA table_info`, `INSERT OR IGNORE`, `INSERT OR REPLACE`, `ON CONFLICT(...) DO UPDATE`) dans `AuthController`, `DisciplineController`, `MemberController`, `RegistrationController`, `RoleController`, `SystemController`, `RolloverService` et les migrations `001`–`005`.
- Ajout de `Core/Api/Database/check_mysql_compat.php` (commande `npm run db:check-mysql`) auditant `Core/Api/` et validant la traduction DDL MySQL 8 / MariaDB 10.6+ (`InnoDB`, `utf8mb4`, `AUTO_INCREMENT`).

## 2. Champ Optionnel `gender` (RGPD) & Statistiques Réelles
- `Core/Api/Database/Migrations/001_core_schema.php` & `005_member_gender.php` : ajout idempotent de `members.gender VARCHAR(16) NULL DEFAULT NULL`.
- `Core/Api/Database/Migrations/002_core_seed.php` : injection d'une répartition démographique réaliste (`M`, `F`, `O`, `NULL`) sur les 58 adhérents QA.
- `MemberController` (`createMember`, `updateMember`) & `RegistrationController` (`createRegistration`) : validation stricte du champ optionnel `gender` (`M`, `F`, `O` ou vide/null ; rejet `422` sinon) et persistance PDO.
- `StatsService::getDashboardData()` & `SystemController::getDemographics()` : suppression définitive des constantes statiques `54 % / 46 %`, remplacées par les agrégations réelles (`male`, `female`, `other`, `unspecified` et `*_pct`).

## 3. Handoff vers `@dev-front`
- **Pour le Dev-Front :** Les API et `StatsService` retournent désormais les données réelles `gender_ratio` et acceptent le champ optionnel `gender`. Tu peux mettre à jour `stats.php` / `stats.ts`, `members.php` / `members.ts` et `register.php` / `register.ts` avec les `data-testid` de `02-specs-metier.md`, puis compiler via `npm run build:ts` et `npm run build:css`.
