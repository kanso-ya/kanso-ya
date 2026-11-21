# Sprint Actif : Lot 7 — Sprint 27 (QA Intégrale — Parcours 100 % UI, Audit Vues & E-mails Réels Mailpit)
**Objectif principal :** Certifier la plateforme Kanso-ya V2.3 en boîte noire 100 % UI sur une matrice Playwright complète Desktop & Mobile, auditer et nettoyer les vues à onglets, vérifier l'intégralité de la matrice RBAC et valider l'envoi réel d'e-mails via Mailpit en 2 temps (surcharge `.env` puis paramétrage 100 % UI).

## 1. Périmètre Macro (User Stories)
| ID | Fonctionnalité ciblée | Description métier | Priorité |
|---|---|---|---|
| `US-LOT7-01` | Parcours Utilisateur 100 % UI (Boîte Noire) | Rejouer tous les parcours métier clés (Core + modules actifs) exclusivement par l'UI (formulaires `/login` et `/sys-admin-xyz`, clics, navigation — zéro `switch-persona`, zéro raccourci API). | Haute |
| `US-LOT7-02` | Couverture Complète RBAC UI | Vérifier pour chaque rôle (Visiteur, Adhérent/Tuteur, Entraîneur, Rédacteur, Secrétaire, Trésorier, Président, Superadmin) la visibilité stricte des menus, onglets, actions et l'interdiction des accès non autorisés. | Haute |
| `US-LOT7-03` | Matrice Playwright Complète (Desktop & Mobile) | Exécuter la suite Sprint 27 sur Desktop (`Chromium`, `Firefox`, `WebKit`) et émulations Mobile Android (`Pixel 5`, `Galaxy S9+`) & iOS (`iPhone 13`, `iPhone 15 Pro`). | Haute |
| `US-LOT7-04` | Audit de Propreté des Vues & Intégrité UI | Auditer les vues à onglets (`/stats`, `/disciplines`, `/sys-smtp`, `/profile`, `/factures`, `/boutique`), corriger tout bloc dupliqué/orphelin/superflu, vérifier 0 lien mort, 0 erreur console JS, 0 erreur réseau 4xx/5xx et 0 débordement horizontal mobile. | Haute |
| `US-LOT7-05` | Envoi Réel d'E-mails via Mailpit (2 temps + Purge) | Valider (1) la surcharge SMTP `.env` vers `127.0.0.1:1025` et (2) la configuration manuelle 100 % UI depuis `/sys-smtp` avec réception vérifiée dans l'API Mailpit (`http://127.0.0.1:8025/api/v1/messages`) et purge `DELETE` avant chaque phase/scénario. | Haute |

## 2. Hors Périmètre (Exclusions strictes)
- **Aucune nouvelle fonctionnalité métier :** ne toucher au code applicatif **que** pour le nettoyage des vues à onglets et la correction des anomalies détectées par les tests.
- Aucune modification des 324 tests E2E existants (non-régression stricte).
- Aucun envoi d'e-mail vers un serveur externe sur Internet.

## 3. Instructions de passage de relais (Handoff)
- **Pour le PO :** Détailler les règles de vérification boîte noire, la matrice complète des 8 rôles, la liste des vues à onglets à auditer et les assertions Mailpit dans `02-specs-metier.md`.
