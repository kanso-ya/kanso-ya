# Sprint Actif : Lot 5 — Sprint 23 : Module « Événements, Stages & Compétitions » (`EvenementsApi` / `EvenementsUi`)
**Objectif principal :** Livrer le second module métier sur le SDK V2 (`modules/EvenementsApi` + `modules/EvenementsUi`) offrant au club un calendrier unifié des stages, passages de grades et compétitions, la gestion des événements avec contrôle de jauge, l'inscription en 1 clic pour les adhérents/familles connectés et un widget automatique sur `/` via `ui_slot_home_after_articles` sans modifier le Core.

## 1. Périmètre Macro (User Stories)
| ID | Fonctionnalité ciblée | Description métier | Priorité |
|---|---|---|---|
| `US-LOT5-05` | Squelette modulaire `EvenementsApi` / `EvenementsUi` | Structurer le module selon le manifeste SDK v2 (`mod_evenements_*`, routes `/api/v1/modules/evenements/*`, route UI `/evenements`, assets publiés sous `/assets/modules/evenements/`, cycle install/activate/uninstall propre depuis `/sys-modules`). | Haute |
| `US-LOT5-06` | Gestion des événements (Bureau / Entraîneurs) | Permettre aux gestionnaires (`stats.view` / `articles.write` / Superadmin) de créer, éditer, publier et clôturer des événements (type stage/grade/compétition/autre, date, lieu, discipline cible, jauge maximale). | Haute |
| `US-LOT5-07` | Inscription en 1 clic, jauge & liste participants | Permettre à un adhérent connecté d'inscrire/désinscrire en 1 clic sa propre fiche ou un membre rattaché de sa famille (`member_tutor`) avec contrôle strict de jauge maximale, et permettre aux encadrants de consulter/exporter la liste des participants. | Haute |
| `US-LOT5-08` | Widget d'accueil via `ui_slot_home_after_articles` | Injecter automatiquement un bloc « Prochains Événements du Club » sur `/` via le hook SDK `ui_slot_home_after_articles`, qui disparaît proprement lorsque le module est désactivé/désinstallé. | Moyenne |

## 2. Hors Périmètre (Exclusions strictes)
- **Ne pas** modifier les contrôleurs, routes ou vues du Core (toute la logique vit dans `modules/EvenementsApi/` et `modules/EvenementsUi/`).
- **Ne pas** intégrer de billetterie payante ni de paiement en ligne (traité dans le Lot 6 — Sprint 24).
- **Ne pas** gérer de résultats sportifs, tableaux de poules ou synchronisation CalDAV/Google Calendar.
- **Ne pas** autoriser un adhérent à inscrire un tiers non rattaché dans `member_tutor`.

## 3. Instructions de passage de relais (Handoff)
- **Pour le PO :** À partir de ce scope, rédige les spécifications fonctionnelles détaillées, les règles de gestion RBAC/jauge/famille et la liste des `data-testid` dans `docs/current-sprint/02-specs-metier.md`.
