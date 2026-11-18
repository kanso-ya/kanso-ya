# Rapport de Clôture — Sprint 14 (Tableaux Compacts, Onglets Fonctionnels & Épuration Superadmin)

- **Date :** 18/11/2026
- **Jalon :** Release v1.1 — Sprint 14 (Points 11, 12, 13, 14, 18, 19, 20 de recette manuelle)
- **Statut global :** ✅ **100 % VALIDÉ (246/246 E2E Pass, 0 erreur PHPStan, 0 erreur TS/CSS)**

---

## 1. Synthèse des Implémentations

### Axe 1 — Remodelage Compact des Tableaux Adhérents & Cotisations (Points 13 & 14)
- **Restauration de la largeur harmonieuse du gabarit :** suppression de la surcharge `$contentMaxWidth = 'max-w-[1600px]'` dans `public/members.php` afin de revenir au conteneur standard `max-w-6xl` de `core/ui/templates/layout.php`.
- **Tableau Adhérents (`/members`) :**
  - Resserrage des marges de cellules (`p-2.5`, typographie `text-xs`, en-têtes `text-[11px]`, troncatures élégantes sur les adresses et contacts d'urgence).
  - Regroupement des icônes d'action de la colonne finale dans une grille compacte sur 2 lignes (`grid grid-cols-3 gap-1 w-[104px] ml-auto`, `data-testid="member-actions-grid"`), chaque bouton disposant d'une infobulle `title`, d'un `aria-label` et d'un `<span class="sr-only">` accessible.
  - Correction du `colSpan` de la ligne accordéon (`colSpan = 7`).
- **Tableau Cotisations & Dossiers (`/registrations`) :**
  - Ajout de `id="registrations-table" data-testid="registrations-table"`, compactage des colonnes (`p-2.5`, troncature email/pratiquants avec `title`).
  - Grille d'icônes d'action sur 2 lignes compactes (`data-testid="registration-actions-grid"`) pour Inspecter, Valider, Reçu, Attestation CSE, Radier/Restaurer et Supprimer, avec infobulles claires et `sr-only`.
- **Bonus Correctif Mobile :** correction du contexte d'empilement `z-index` dans `core/ui/templates/layout.php` (`#sidebar-backdrop` placé au sein du conteneur `z-10` sous `#sidebar` en `z-20`) afin que les liens de la barre latérale mobile soient directement cliquables sans interception par le backdrop.

### Axe 2 — Onglets Fonctionnels sur Statistiques & Tableaux de Disciplines (Points 11 & 12)
- **Page Statistiques (`/stats`) :**
  - Mise en place d'une barre d'onglets réellement fonctionnelle (`#stats-tabs-bar`, `role="tablist"`, `aria-selected`, `initStatsTabs()` dans `core/ui/ts/stats.ts`) n'affichant **qu'un seul panneau à la fois** :
    1. `📊 Vue Générale` (`#stats-panel-kpis`)
    2. `💶 Finances` (`#stats-panel-finances`, réservé au Bureau — totalement absent du DOM pour le rôle Entraîneur conformément au RGPD)
    3. `🥋 Démographie & Occupation` (`#stats-panel-occupancy`)
    4. `📋 Liste d'Appel Tatami` (`#stats-panel-attendance`)
    5. Support dynamique des onglets injectés par modules via le filtre `stats_tabs`.
  - Lecture unique et affichage dynamique de la capacité théorique configurée (`default_course_capacity`).
- **Affichage d'une seule discipline à la fois par onglets (`role="tablist"`) :**
  - `/stats` (onglet Démographie & Occupation) : barre `#stats-discipline-tabs` (`initStatsDisciplineTabs()`).
  - `/horaires` : barre `#horaires-discipline-tabs` pilotant l'affichage d'une seule carte discipline à la fois (tout en conservant les liens directs `/register?discipline=ID`).
  - `/disciplines` : enrichissement sémantique `role="tablist"`, `role="tab"`, `aria-selected`, `data-testid="discipline-tab-item"` et `role="tabpanel"`.

### Axe 3 — Épuration Navigation Superadmin, Vue d'Ensemble & 3 Onglets SMTP (Points 18, 19 & 20)
- **Suppression complète de la double navigation :** retrait de `core/ui/templates/sys-nav.php` sur les 5 pages Superadmin (`sys-console.php`, `sys-branding.php`, `sys-smtp.php`, `sys-modules.php`, `sys-logs.php`) et correction de `$activeNav` sur chaque sous-page pour une surbrillance exacte dans la Sidebar gauche unique.
- **Refonte de la Vue d'Ensemble (`/sys-console`) :**
  - Suppression des formulaires doublonnés Identité Visuelle et SMTP (remplacés par des raccourcis directs vers `/sys-branding` et `/sys-smtp`).
  - Ajout des 4 cartes synthétiques d'état du CMS (`data-testid="sys-overview-cards"`) :
    1. **Mode Démo** (badge `Actif`/`Inactif` + bouton de bascule relié à `PATCH /api/v1/settings` sur `demo_mode_enabled`) ;
    2. **Base de Données SQLite (WAL)** (taille BDD, compteurs Comptes/Adhérents/Articles, bouton sauvegarde 1 clic) ;
    3. **Modules Actifs** (`#metric-active-modules` dynamique + lien `/sys-modules`) ;
    4. **Dernières Connexions & Événements Système** (`#sys-recent-logins` alimenté depuis `sys_logs` + lien `/sys-logs`).
- **Page Serveur SMTP (`/sys-smtp`) en 3 onglets fonctionnels (`initSmtpTabs()`) :**
  1. `⚙️ 1. Configuration Serveur Mail` (`#smtp-panel-config`, formulaire SMTP + diagnostic d'envoi de test) ;
  2. `📝 2. Modèles d'Emails` (`#smtp-panel-templates`, sélecteur + éditeur HTML) ;
  3. `📬 3. Historique des Envois` (`#smtp-panel-history`, journal `email_logs`).

---

## 2. Vérifications Qualité & Non-Régression

| Contrôle | Commande | Résultat |
|---|---|---|
| Compilation TypeScript | `npm run build:ts` | ✅ 0 erreur |
| Compilation Tailwind CSS | `npm run build:css` | ✅ 0 erreur |
| Analyse Statique PHP | `vendor/bin/phpstan analyse core public` | ✅ 43/43 fichiers — 0 erreur |
| Tests E2E Sprint 14 | `tests/e2e/15-sprint14-tables-tabs.spec.ts` | ✅ 8/8 Pass (Desktop + Mobile) |
| Suite E2E Complète | `npm run test:e2e` | ✅ **246/246 Pass** (0 échec) |

---

## 3. Fichiers Modifiés / Créés

- `core/ui/templates/layout.php`
- `public/members.php`, `core/ui/ts/members.ts`
- `public/registrations.php`, `core/ui/ts/registrations.ts`
- `public/stats.php`, `core/ui/ts/stats.ts`
- `public/horaires.php`
- `public/disciplines.php`, `core/ui/ts/disciplines.ts`
- `public/sys-console.php`, `public/sys-branding.php`, `public/sys-smtp.php`, `public/sys-modules.php`, `public/sys-logs.php`, `core/ui/ts/sys-console.ts`
- `public/assets/ts/*.js`, `public/assets/css/tailwind.css`
- `tests/e2e/15-sprint14-tables-tabs.spec.ts` (nouveau) + mise à jour des tests `02`, `05`, `06`, `08`, `11`, `14`
- `docs/ROADMAP.md`, `docs/current-sprint/14-report-sprint14.md`
