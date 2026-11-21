# CONSTITUTION DU PROJET (Framework Modulaire Vanilla)

## IdentitÃ© et Philosophie
- **Nature :** Application Web / CMS modulaire sur mesure.
- **Philosophie :** LÃ©ger, autonome, sans frameworks lourds (ni cÃ´tÃ© back, ni cÃ´tÃ© front), orientÃ© performance, maintenabilitÃ© et testabilitÃ© maximale.

## Environnement Technique & Outils
- **Serveur local :** Laragon sous Windows.
- **URL de dÃ©veloppement :** L'URL locale du projet DOIT Ãªtre lue dynamiquement depuis la variable `baseURL` du fichier `playwright.config.ts`.
- **Base de donnÃ©es :** SQLite ou MySQL (utilisation stricte de PDO pur avec requÃªtes prÃ©parÃ©es).
- **Backend :** Vanilla PHP 8.5 strict (`declare(strict_types=1);`).
- **Frontend :** Vanilla TypeScript, HTML5, Tailwind CSS.
- **Assurance QualitÃ© :** Tests End-to-End stricts sous Playwright.

## Architecture Globale (RÃ¨gles d'or absolues pour tous les agents)
1. **ModularitÃ© stricte :** Le code applicatif vit dans le dossier `./module/[NomDuModule]/`. 
   - La logique mÃ©tier, les requÃªtes BDD et les endpoints vont dans le sous-dossier `XXApi`.
   - Les vues HTML, les templates et les scripts clients vont dans le sous-dossier `xxUi`.
2. **Le CÅ“ur (Core) :** L'infrastructure systÃ¨me (routeur, connexion DB PDO Singleton, helpers globaux) se trouve dans `./core/Api` et `./core/Ui`.
3. **Contrat d'API standardisÃ© :** TOUTE communication entre le front (`xxUi` / `core/Ui`) et le back (`XXApi` / `core/Api`) se fait via des API retournant obligatoirement un statut **HTTP 200**. L'Ã©tat de la requÃªte est dÃ©fini dans le JSON retournÃ© (3 arguments minimum) : 
   - SuccÃ¨s : `{ "success": true, "code": 200, "data": {...} }`
   - Erreur : `{ "success": false, "code": 400|403|404|500, "error": "Message explicite" }`

## Commandes d'exÃ©cution standard
- **Compilation Front :** `npm run build` (Compile Tailwind et valide le typage TypeScript).
- **Tests E2E (QA) :** `npm run test:allure` (ou `npm test`). Toute erreur de test bloque le workflow.

## Workflow Multi-Agents (Rappel des responsabilitÃ©s)
- Le **CP** dÃ©coupe le projet en Lots/Sprints (`/docs/current-sprint/01-scope.md`).
- Le **PO** rÃ©dige les rÃ¨gles de gestion, l'UX et liste les `data-testid` (`02-specs-metier.md`).
- L'**Architecte** fige le schÃ©ma SQL et les contrats d'API JSON (`03-architecture.md`).
- Le **Dev-Back** implÃ©mente le SQL et l'API PHP (`XXApi` / `core/Api`) sans toucher Ã  l'interface.
- Le **Dev-Front** implÃ©mente l'interface (`xxUi` / `core/Ui`) et consomme l'API sans toucher au backend.
- L'agent **QA/UAT** valide le comportement avec Playwright en isolant l'Ã©tat de la base de donnÃ©es avant chaque test.
