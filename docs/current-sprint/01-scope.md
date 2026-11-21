# Sprint Actif : Lot 4 — Sprint 20 : Transport SMTP Réel & Fiabilité Messagerie
**Objectif principal :** Remplacer l'envoi d'e-mails simulé (`250 OK`) par un véritable client SMTP natif PHP sécurisé (STARTTLS/SSL, authentification, protection du mot de passe) avec bascule automatique en mode `log/stub` sous `APP_ENV=testing`, gestion fine des erreurs et relance manuelle depuis `/sys-smtp`.

## 1. Périmètre Macro (User Stories)
| ID | Fonctionnalité ciblée | Description métier | Priorité |
|---|---|---|---|
| `US-LOT4-01` | Client SMTP natif PHP sécurisé | Permettre l'envoi réel des e-mails transactionnels du CMS (OTP, 2FA e-mail, mot de passe oublié, notifications d'inscription, e-mail de test) via un transport SMTP natif PHP sans dépendance lourde, prenant en charge STARTTLS, SSL/TLS, l'authentification SMTP et la gestion des timeouts. | Haute |
| `US-LOT4-02` | Sécurisation des identifiants SMTP | Protéger le mot de passe SMTP configuré par le Superadmin (chiffrement en base `sys_settings` ou surcharge via `.env`) et garantir qu'il n'est jamais renvoyé en clair dans les réponses API ni pré-rempli en clair dans `/sys-smtp`. | Haute |
| `US-LOT4-03` | Bascule `log/stub` en environnement de test | Garantir qu'en `APP_ENV=testing` (ou lorsque le mode simulation/log est actif), aucun e-mail réel n'est transmis sur le réseau : l'envoi est journalisé de façon déterministe dans `email_logs` afin de préserver la fiabilité et la rapidité des 284+ tests E2E Playwright. | Haute |
| `US-LOT4-04` | Suivi des échecs & relance depuis `/sys-smtp` | Tracer précisément le statut de chaque tentative d'envoi (`sent` / `failed`, code/message d'erreur SMTP) dans `email_logs` et permettre au Superadmin de relancer manuellement un e-mail en échec depuis l'onglet Historique de `/sys-smtp`. | Moyenne |

## 2. Hors Périmètre (Exclusions strictes)
- **Ne pas** installer de framework ou de librairie externe lourde si le client SMTP natif PHP suffit (respect de la philosophie Vanilla PHP 8.5 strict du projet).
- **Ne pas** développer de système de newsletter, d'envoi de masse ou de campagnes marketing.
- **Ne pas** mettre en place de file d'attente asynchrone par cron/worker ou daemon externe dans ce sprint.
- **Ne pas** intégrer d'API HTTP propriétaire de fournisseurs tiers (SendGrid, Brevo, Mailgun, AWS SES API).
- **Ne pas** modifier les parcours fonctionnels des autres lots (pas de champ `gender`, pas de migration MySQL, pas de nouveaux modules métier).
- **Ne pas** casser ni supprimer les endpoints existants (`/api/v1/sys/email-logs`, `/api/v1/sys/test-email`, `/api/v1/sys/email-templates`) ni les `data-testid` de `/sys-smtp`.

## 3. Instructions de passage de relais (Handoff)
- **Pour le PO :** À partir de ce scope, rédige les spécifications fonctionnelles détaillées, les règles de gestion métier (modes de transport, comportements en cas d'échec, masquage du mot de passe, UX de relance dans `/sys-smtp`) et la liste des `data-testid` dans `docs/current-sprint/02-specs-metier.md`.
