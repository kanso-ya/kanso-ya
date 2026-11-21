---
description: ExÃ©cute ou reprend un Sprint complet pour gros projet (CP -> PO -> Architect -> Back -> Front -> QA).
---
Inspecte `/docs/current-sprint/` pour reprendre Ã  la bonne Ã©tape :

1. **Planification & Pilotage :** Si `01-scope.md` est absent, appelle `@cp` avec la demande.
2. **SpÃ©cifications MÃ©tier :** Si `02-specs-metier.md` est absent, appelle `@po`.
3. **Architecture Technique :** Si `03-architecture.md` est absent, appelle `@architect`.
4. **Boucle RÃ©alisation & Recette (Max 5 itÃ©rations) :**
   - VÃ©rifie `04-qa-report.md`. S'il commence par `STATUT: VALIDATED`, passe Ã  l'Ã©tape 5.
   - Si `03a-back-log.md` est absent OU rapport = erreur Backend, appelle `@dev-back`.
   - Si `03b-front-log.md` est absent OU rapport = erreur Frontend, appelle `@dev-front`.
   - Appelle `@qa-uat` pour lancer les tests isolÃ©s. 
   - Si `STATUT: FAILED`, la boucle recommence.
5. **ClÃ´ture du Sprint :** Une fois `STATUT: VALIDATED` obtenu, archive les fichiers dans `/docs/archive/sprint-X/`, appelle `@cp` pour MAJ Roadmap/README, et propose un `git commit`.
