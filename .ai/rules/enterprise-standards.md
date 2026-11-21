---
trigger: always_on
---
# RÃ¨gles Strictes - Gros Projets (PHP / TS / Tailwind / MySQL)

## Standards de Code & Architecture
1. **Source de vÃ©ritÃ© :** Respecter scrupuleusement `/docs/ARCHITECTURE.md` (noms des tables, colonnes SQL, endpoints API). Ne jamais inventer une route non documentÃ©e.
2. **Non-rÃ©gression :** Avant de modifier un fichier existant, le lire en entier. Ne jamais supprimer une route API, une classe CSS ou un attribut `data-testid` utilisÃ© par un sprint prÃ©cÃ©dent.
3. **Backend PHP :** Fichiers avec `declare(strict_types=1);`. Architecture modulaire stricte (`XXApi` / `core/Api`). RequÃªtes prÃ©parÃ©es PDO obligatoires. RÃ©ponses JSON normalisÃ©es avec 3 arguments minimum (`{ success: bool, code: int, data: ..., error: ... }`). **PHPDoc obligatoire** pour chaque classe et mÃ©thode.
4. **Frontend TS/Tailwind :** Architecture UI dans `xxUi` / `core/Ui`. TypeScript strict sans `any` implicite. Compilation obligatoire via `npm run build` avant validation. **JSDoc obligatoire** pour chaque fonction et interface.
5. **Tests cumulÃ©s & isolÃ©s :** Chaque lot/sprint ajoute son propre fichier de test dans `/tests/e2e/` sans Ã©craser les anciens. **RÃ¨gle absolue :** l'Ã©tat de la base de donnÃ©es DOIT Ãªtre rÃ©initialisÃ©/isolÃ© avant chaque test.

## RÃ¨gles SpÃ©cifiques sur Code Existant (Legacy / En cours)
6. **Respect de l'existant :** Ne jamais rÃ©Ã©crire de zÃ©ro un module ou le moteur du CMS (Core) s'il est dÃ©jÃ  fonctionnel. Toujours s'adapter aux mÃ©canismes de l'infrastructure existante.
7. **SÃ©paration progressive des vues :** Dans les fichiers de vue, interdiction d'ajouter de nouvelles requÃªtes SQL ou de la logique mÃ©tier complexe. SÃ©parer clairement la prÃ©paration des donnÃ©es (Backend/PHP) et le rendu (Frontend/Tailwind).
8. **Test avant refactorisation (TDD) :** Avant de dÃ©placer, nettoyer ou refactoriser un fichier existant complexe ("fouillis"), l'agent QA doit D'ABORD Ã©crire un test Playwright qui vÃ©rifie que la fonctionnalitÃ© marche en l'Ã©tat. On ne modifie le code que lorsque ce test passe.
