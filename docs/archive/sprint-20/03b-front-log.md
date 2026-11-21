# Journal de Livraison Frontend — Lot 4 / Sprint 20 (Transport SMTP Réel & Fiabilité Messagerie)

**Rôle :** @dev-front  
**Statut :** Terminé & Compilé (`npm run build:ts` : 0 erreur · `npm run build:css` : OK · `php -l Core/Ui/Pages/sys-smtp.php` : OK)

## 1. Modifications UI (`Core/Ui/Pages/sys-smtp.php`)
- **Onglet 1 — Configuration Serveur Mail :**
  - Ajout du sélecteur Mode de transport (`data-testid="smtp-transport-select"` : `log` vs `smtp`).
  - Ajout du champ Mot de passe SMTP (`type="password"`, `autocomplete="new-password"`, `data-testid="smtp-password-input"`), du badge d'état (`data-testid="smtp-password-status"`) et de la case d'effacement explicite (`data-testid="smtp-password-clear"`).
  - Ajout du champ Timeout réseau (`min="2"`, `max="30"`, `data-testid="smtp-timeout-input"`).
  - Ajout des attributs `data-testid="btn-save-smtp"`, `data-testid="test-smtp-recipient"` et `data-testid="btn-send-test-email"` en préservant tous les IDs existants.
- **Onglet 3 — Historique des Envois :**
  - Ajout des colonnes **Statut** et **Action** dans le tableau `#email-logs-tbody`.

## 2. Logique TypeScript (`Core/Ui/ts/sys-console.ts`)
- Enrichissement de l'interface `EmailLogItem` (`retry_count`, `last_retry_at`) avec JSDoc.
- Ajout de `updateSmtpPasswordUi(source)` : affiche « Configuré (Base chiffrée) », « Piloté par .env » ou « Non configuré », ne pré-remplit jamais le mot de passe en clair et n'affiche la case d'effacement que si un secret est stocké en base.
- Mise à jour de `initSmtpForm()` :
  - N'envoie `smtp_password` que s'il est renseigné, envoie `smtp_password_clear: "1"` si coché, et ré-hydrate immédiatement l'UI via `loadSystemSettings()`.
  - Rafraîchit systématiquement `loadEmailLogs()` après un e-mail de test, même en cas d'échec SMTP (`code: 502`).
- Mise à jour de `loadEmailLogs()` & ajout de `retryFailedEmail(logId, btn)` :
  - Rendu sécurisé (`escapeHtml`) des badges `data-testid="email-log-status-{id}"`, du compteur `data-testid="email-log-retries-{id}"` et du bouton `data-testid="btn-retry-email-{id}"` pour les lignes en statut `failed`.
  - Appel `POST /api/v1/sys/email-logs/{id}/retry`, affichage toast et rafraîchissement du tableau.

## 3. Handoff vers `@qa-uat`
- **Pour le QA/UAT :** Le front et le back sont câblés et compilés, tous les `data-testid` de `02-specs-metier.md` sont en place. Tu peux créer `tests/e2e/21-sprint20-smtp.spec.ts` et exécuter Playwright (nouvelle suite + non-régression complète).
