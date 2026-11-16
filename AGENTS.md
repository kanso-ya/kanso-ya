# Contexte Technique & Règles du Projet : Kanso-ya CMS

## 1. Documentation de Référence

Avant toute tâche, consulte les documents de référence dans `/docs/` :

- Architecture & MCD : `architecture_technique_et_mod_le_de_donn_es_cms_associatif v2.md` et `mod_le_conceptuel_de_donn_es_mcd v2.md`
- Contrat API : `sp_cifications_api_core v2.md`
- Moteur de modules (Lot 3) : `sp_cifications_du_moteur_de_modules_sdk v2.md`
- Recette & Tests : `cahier_de_recette_et_exigences_de_test v2.md` et `Matrice_Tests_QA_CMS_Assoc.xlsx`
- Roadmap : `plan_de_release_agile_backlog_lot_1 v2.md`, `lot_2 v2.md` et `lot_3 v2.md`

## 2. Environnement & Commandes

- Serveur local : Laragon (`https://kanso-ya.fr`, certificat auto-signé)
- Base de données : SQLite en mode WAL (`storage/db/`) via `Core\Api\Database\Database::getInstance()`
- Réinitialiser la BDD de test : `npm run db:reset`
- Compiler le TypeScript (`core/ui/ts/` -> `public/assets/ts/`) : `npm run build:ts`
- Compiler Tailwind CSS : `npm run build:css`
- Analyse statique PHP : `vendor/bin/phpstan analyse`
- Tests E2E Playwright (Desktop + Mobile Chrome) : `npm run test:e2e`

## 3. Conventions Obligatoires (Non-Régression)

1. **API JSend sur HTTP 200 :** Toute route dans `public/api.php` doit passer par `sendJson(['status' => 'success'|'error', 'code' => int, 'message' => string, 'data' => mixed])`. Ne jamais renvoyer un statut réseau 4xx/5xx.
2. **Concurrence SQLite :** Toute écriture en base (INSERT/UPDATE/DELETE) doit être encapsulée dans `BEGIN IMMEDIATE` / `COMMIT` (avec gestion des verrous `locked`/`busy`) pour supporter le parallélisme Playwright.
3. **Architecture MPA + TS :** Les pages publiques (`public/*.php`) utilisent `ob_start()` et chargent `core/ui/templates/layout.php`. La logique interactive est écrite en TypeScript strict dans `core/ui/ts/` et compilée vers `public/assets/ts/`.
4. **Ne jamais casser les hooks QA :** Conserver intactes les routes `/api/v1/auth/switch-persona` et les tokens `stub-*` dans `public/api.php`, ainsi que les attributs `data-testid` existants.
