# Sprint Actif : Lot 5 — Sprint 22 : Module « Présences & Suivi » (`PresenceApi` / `PresenceUi`)
**Objectif principal :** Concevoir et livrer le premier module métier complet sur le SDK V2 (`modules/PresenceApi` + `modules/PresenceUi`) permettant aux Entraîneurs et au Bureau de réaliser l'appel numérique par séance/discipline sur mobile ou desktop, de suivre l'historique d'assiduité par adhérent dans le respect du RGPD, et d'injecter un onglet analytique « Assiduité » dans `/stats` via `ui_tabs` sans modifier le Core.

## 1. Périmètre Macro (User Stories)
| ID | Fonctionnalité ciblée | Description métier | Priorité |
|---|---|---|---|
| `US-LOT5-01` | Squelette modulaire `PresenceApi` / `PresenceUi` | Structurer le module selon le manifeste SDK v2 (`mod_presence_*`, routes `/api/v1/modules/presence/*`, route UI `/presences`, assets publiés sous `/assets/modules/presence/`, cycle install/activate/deactivate/uninstall propre depuis `/sys-modules`). | Haute |
| `US-LOT5-02` | Feuille d'appel numérique par séance & discipline | Permettre aux Entraîneurs et au Bureau de créer/ouvrir une feuille d'appel (discipline, date, créneau) et de pointer rapidement chaque pratiquant (`Présent`, `Absent`, `Excusé`) avec sauvegarde idempotente optimisée mobile. | Haute |
| `US-LOT5-03` | Historique d'assiduité par adhérent (RGPD) | Consulter l'historique des présences et le taux d'assiduité d'un adhérent (accessible au Bureau/Entraîneur ainsi qu'au pratiquant/tuteur légal rattaché uniquement, avec blocage `403` pour tout tiers non autorisé). | Moyenne |
| `US-LOT5-04` | Onglet analytique injecté dans `/stats` (`ui_tabs`) | Injecter un onglet « 📋 Assiduité » dans `/stats` via le hook SDK `ui_tabs` affichant les séances enregistrées et le taux de présence par discipline, avec disparition propre si le module est désactivé. | Moyenne |

## 2. Hors Périmètre (Exclusions strictes)
- **Ne pas** modifier les contrôleurs, routes ou vues du Core (toute la logique vit dans `modules/PresenceApi/` et `modules/PresenceUi/`).
- **Ne pas** implémenter de pointage par badge NFC, QR Code matériel ou notification push mobile.
- **Ne pas** exposer d'e-mail, d'adresse postale ou de donnée financière dans les feuilles d'appel ou les réponses API du module (minimisation RGPD).
- **Ne pas** développer le module Événements (Sprint 23) ni le Paiement en ligne (Sprint 24).

## 3. Instructions de passage de relais (Handoff)
- **Pour le PO :** À partir de ce scope, rédige les spécifications fonctionnelles détaillées, les règles de gestion RBAC/RGPD et la liste des `data-testid` dans `docs/current-sprint/02-specs-metier.md`.
