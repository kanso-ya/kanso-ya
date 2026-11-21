---
description: Audite un projet existant (code + docs) pour reconstruire l'architecture et la roadmap.
---
**Interdiction formelle de modifier ou supprimer le code existant durant cette phase.**

1. **Analyse du code et de la BDD :** Appelle `@architect` pour inspecter l'arborescence et MySQL/SQLite. Il doit rÃ©diger `/docs/ARCHITECTURE.md` (Ã©tat rÃ©el, dettes, plan de rangement vers `XXApi`/`xxUi`, contrats d'API).
2. **SynthÃ¨se des documents :** Appelle `@cp` pour rÃ©diger `/docs/ROADMAP.md`. Doit distinguer : 
   - Ce qui est dÃ©jÃ  fait (Legacy fonctionnel).
   - Sprint 1 (Filet de sÃ©curitÃ© & Nettoyage) : Imposer au QA les tests Playwright AVANT de ranger.
   - Sprints 2+ : Refonte.
3. **Passage Ã  l'action :** Invite l'utilisateur Ã  lancer `/run-sprint` pour dÃ©marrer le Sprint 1.
