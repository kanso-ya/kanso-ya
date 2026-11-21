STATUT: VALIDATED

# Rapport de Recette QA / UAT — Lot 4 / Sprint 20 (Transport SMTP Réel & Fiabilité Messagerie)

**Rôle :** @qa-uat  
**Date :** 21 novembre 2026  
**Suite dédiée :** `tests/e2e/21-sprint20-smtp.spec.ts`  
**Résultat global :** **292 / 292 Pass** (Desktop Chrome + Mobile Chrome Pixel 5 — 0 échec, 0 régression)

## 1. Couverture des Scénarios Sprint 20
| Scénario | Persona / Règles | Desktop Chrome | Mobile Chrome | Détails vérifiés |
|---|---|---:|---:|---|
| `S20-01` | Persona 1 (`RG-01`, `RG-03`) | PASS | PASS | Sauvegarde `/sys-smtp`, chiffrement AES-256-GCM (`enc:v1:`) en base, masquage strict sur `GET /api/v1/settings` et DOM, conservation si champ vide, effacement via `smtp-password-clear`, rejets `422` (port, timeout, transport). |
| `S20-02` | Transport SMTP réel (`RG-01`, `RG-02`) | PASS | PASS | Dialogue TCP réel avec serveur SMTP local (`EHLO`, `AUTH LOGIN` avec identifiants déchiffrés, `MAIL FROM`, `RCPT TO`, `DATA` MIME UTF-8, `250 OK`). |
| `S20-03` | Confinement QA (`RG-04`) | PASS | PASS | Sous `APP_ENV=testing`, toute tentative d'envoi en mode `smtp` vers un hôte externe retourne `code: 502` + `550 5.7.1 Blocked external SMTP host in testing environment` et journalise `failed`. |
| `S20-04` | Persona 2 (`RG-05`, `RG-06`) | PASS | PASS | Échec SMTP réel sur port fermé (`127.0.0.1:1`), badge rouge `Échec` et bouton `Relancer` dans `/sys-smtp`, correction en mode `log`, relance UI → badge vert `Envoyé` + `Relances : 1`, gardes API `409` / `404` / `403`. |

## 2. Vérifications Qualité & Non-Régression
- **Playwright E2E complet :** `npx playwright test --workers=1` → **292/292 Pass** (`3.4m`).
- **PHPStan niveau 5 :** `vendor\bin\phpstan analyse` → **0 erreur** (59 fichiers analysés).
- **PSR-4 strict :** `composer dump-autoload -o --strict-psr` → **28 classes, 0 avertissement**.
- **Build Frontend :** `npm run build:ts` (0 erreur) & `npm run build:css` (Tailwind local sans CDN).
