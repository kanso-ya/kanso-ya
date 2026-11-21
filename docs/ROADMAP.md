# Feuille de Route — Kanso-ya CMS & CRM Associatif

**Rôle :** @cp (Chef de Projet)  
**Dernière mise à jour :** 21 novembre 2026  
**État courant :** **V2.0 homologuée** (284/284 tests E2E Playwright Desktop & Mobile · PHPStan niveau 5 : 0 erreur · PSR-4 strict : 26 classes, 0 avertissement)  
**Sprint actif :** **Lot 4 — Sprint 20 : Transport SMTP Réel & Fiabilité Messagerie** ([`docs/current-sprint/01-scope.md`](./current-sprint/01-scope.md))

---

## 1. Existant Déjà Réalisé & Validé

### 1.1. Socle Core, Sécurité & Architecture V2 (Lots 1, 2, 3 + Sprints 15–19)
- **Arborescence PSR-4 stricte (Linux/Production) :** séparation complète `Core/Api/` (Database, Endpoints, Http, Middleware, Services, Sdk) et `Core/Ui/` (Router, Templates, Components, Pages, `ts/`).
- **Front-Controllers uniques & Verrouillage HTTP :**
  - `/public/` réduit exclusivement à `index.php` (UI via `PageRouter`), `api.php` (API via `ApiKernel`), `.htaccess`, `assets/` et `uploads/`.
  - `.htaccess` racine + `/public/.htaccess` + `/public/uploads/.htaccess` + `/storage/private_uploads/.htaccess` : blocage HTTP `403` sur tout `.php` direct hors `index.php`/`api.php`, interdiction d'accès aux dossiers internes (`Core`, `modules`, `storage`, `docs`, `tests`, `vendor`, dotfiles) et en-têtes de sécurité HTTP.
  - Garde applicative `defined('KANSO_ENTRY')` dans toutes les vues/gabarits.
  - Confinement des hooks QA (`switch-persona`, `stub-*`) à la double condition `APP_ENV=testing` + `QA_HOOKS_ENABLED=1`.
- **Build & Qualité Production :** Tailwind CSS compilé localement (`tailwind.config.js` → `public/assets/css/tailwind.css`, 44 Ko, zéro CDN), TypeScript strict (`Core/Ui/ts/`), PHPStan **niveau 5** (`phpstan.neon`, 0 erreur), rétention automatique des 10 dernières sauvegardes SQLite.

### 1.2. Authentification, Comptes, 2FA & RBAC
- Statut **Visiteur strict** par défaut, connexion standard `/login` et Shadow Login Superadmin sur URL furtive (`SUPERADMIN_URL`, `/sys-admin-xyz`) avec étanchéité croisée.
- Multi-Superadmin (`sys_superadmins`), changement de mot de passe, verrouillage anti-brute-force.
- Récupération « Mot de passe oublié » anti-énumération, activation OTP 48 h, règles de complexité et double saisie.
- **2FA multi-méthode :** TOTP (QR Code SVG vectoriel 30 s natif + clé manuelle) et code temporaire par e-mail.
- Matrice RBAC dynamique multi-colonnes (`/roles`) en langage humain et filtrage dynamique de la Sidebar.

### 1.3. CRM Adhérents, Inscriptions, Trésorerie & Santé
- Annuaire `/members` compact (sans débordement horizontal, actions icônes sur 2 lignes avec infobulles), filtres combinés (statut, âge, santé, discipline/grade/niveau), tri, recherche instantanée, export CSV filtré selon rôle, relance OTP groupée.
- Gestion multi-disciplines (`Discipline → Grade d'âge → Niveau/ceinture`) et référentiel `/disciplines` par onglets.
- Rattachements familiaux, co-tuteurs avec consentement de partage RGPD (`info_shared`), anonymisation stricte pour l'Entraîneur.
- Tunnel d'inscription public `/register` (panier familial, remises paramétrables `%`/fixe, brouillon `localStorage` auto-restauré, pré-sélection `?discipline=ID` depuis `/horaires`, réinscription pré-remplie), questionnaire de santé Cerfa `/questionnaire-sante` et coffre-fort certificats médicaux hors webroot.
- Trésorerie `/registrations` compacte : inspection + ajustement grade/niveau, validation + OTP, radiation réversible, reçus officiels et attestations CSE, bascule annuelle de saison (`POST /api/v1/tasks/rollover-season` + alias `/api/v1/rollover`).

### 1.4. Studio Éditorial CMS, Statistiques & Console Superadmin
- **Studio CMS (`/cms`) :** builder par blocs (titre, texte, encadré, liste, citation, photo/carrousel, vidéo courte HTML5), colonnes symétriques et asymétriques réglables, Drag & Drop intégral, workflow brouillon/relecture/publication, double traçabilité auteur/éditeur, médiathèque + purge orphelins, pages fixes (`/rgpd`, `/cgv`, `/mentions-legales`, `/horaires`), accès interdit au Superadmin (403).
- **Statistiques (`/stats`) :** onglets exclusifs (`Vue Générale`, `Finances` réservé Bureau, `Démographie & Occupation` à 1 discipline par onglet, `Liste d'Appel Tatami`), capacité configurable (`default_course_capacity`) et alerte saturation.
- **Console Superadmin :** navigation unique par Sidebar, `/sys-console` (Mode Démo `demo_mode_enabled`, santé SQLite + backup 1 clic, modules actifs, derniers événements, rollover, Superadmins), `/sys-branding` (thème CSS dynamique réel `--brand-*`, logo, filigrane), `/sys-smtp` (3 onglets : config, modèles, historique), `/sys-modules`, `/sys-logs`.

### 1.5. Moteur de Modules SDK & Surcharge UI
- Structure normalisée `/modules/{NomModule}Api/` + `/modules/{NomModule}Ui/`, manifeste v2, autoload PSR-4 `Modules\`.
- Routes modulaires `ui_routes` (validation `realpath()`) et `api_routes` (`/api/v1/modules/{slug}/*`), publication automatique des assets statiques vers `public/assets/modules/{slug}/` et purge à la désinstallation.
- **Surcharge UI (`EventManager` + `UiHelpers`) :** `ui_text`, `ui_tabs` (+ alias `stats_tabs`), `ui_slot_*`, `ui_page_override` (contrôle anti-path-traversal + trace `UI_PAGE_OVERRIDE_BLOCKED`), `ui_assets`.
- **Safe Mode étendu :** quarantaine automatique (`status = 'crashed'`, `is_active = 0`, purge assets, log `MODULE_SAFE_MODE_TRIGGERED`) sur crash au `boot()` **et** sur exception dans un filtre/action UI.
- Démonstrateurs validés : `BoutiqueApi` + `BoutiqueUi`, `CrashTestApi`, `IncompatibleModuleApi`.

---

## 2. Prochains Lots / Sprints à Venir (Backlog V2.1+)

### 2.1. Vue Synthétique des Lots & Sprints

| Lot / Sprint | Intitulé | Version cible | Priorité | Statut |
| :---: | :--- | :---: | :---: | :---: |
| **Lot 4 — Sprint 20** | **Transport SMTP Réel & Fiabilité Messagerie** | V2.1 | Haute | **En cours** |
| **Lot 4 — Sprint 21** | **Données Réelles Stats & Compatibilité MySQL/MariaDB** | V2.1 | Moyenne | À faire |
| **Lot 5 — Sprint 22** | **Module Présences & Appel Numérique Tatami** | V2.2 | Moyenne | À faire |
| **Lot 5 — Sprint 23** | **Module Événements, Stages & Compétitions** | V2.2 | Basse | À faire |
| **Lot 6 — Sprint 24** | **Paiement en Ligne (HelloAsso / Stripe) & Facturation** | V2.3 | Basse | À faire |

---

### 2.2. Lot 4 — Industrialisation Production & Données Réelles (V2.1)

**Vision du Lot :** Supprimer les derniers éléments simulés/statiques hérités de la V2.0 (envoi d'e-mails simulé `250 OK`, répartition H/F figée) et garantir la portabilité de la base de données vers **MySQL/MariaDB** en production tout en préservant l'exécution locale rapide sur SQLite WAL.

#### Sprint 20 : Transport SMTP Réel & Fiabilité Messagerie *(En cours)*
- **Objectif métier :** Assurer l'acheminement réel et fiable des e-mails transactionnels du club (OTP, 2FA e-mail, mot de passe oublié, confirmations d'inscription, tests SMTP) via un transport SMTP natif PHP sécurisé, tout en conservant un mode `log/stub` déterministe pour les tests automatisés.
- **Périmètre macro (User Stories) :**
  - `US-LOT4-01` — **Client SMTP natif PHP sécurisé :** Remplacer la simulation `250 OK` par une implémentation SMTP native sans dépendance lourde (support STARTTLS, SSL/TLS, authentification `AUTH LOGIN`/`PLAIN`, gestion des timeouts).
  - `US-LOT4-02` — **Protection des identifiants SMTP :** Sécuriser le stockage du mot de passe SMTP (chiffrement en base `sys_settings` ou lecture via `.env`) sans jamais le ré-exposer en clair dans l'API ou l'UI `/sys-smtp`.
  - `US-LOT4-03` — **Bascule automatique `log/stub` en environnement de test :** Garantir qu'en `APP_ENV=testing` (ou mode simulation explicite), aucun e-mail réel ne quitte le serveur et que le comportement reste 100 % testable en E2E.
  - `US-LOT4-04` — **Traçabilité des erreurs & relance depuis `/sys-smtp` :** Enregistrer les statuts réels (`sent` / `failed`) et le détail d'erreur SMTP dans `email_logs`, et permettre au Superadmin de relancer manuellement un e-mail en échec.
- **Hors périmètre :** Campagnes d'e-mailing de masse / newsletters, files d'attente asynchrones avec workers/cron, intégration d'APIs tierces propriétaires (SendGrid, Mailgun, Brevo).
- **Critères de sortie :** Build TS/CSS OK, PHPStan niveau 5 (0 erreur), suite E2E existante + nouvelle suite `21-sprint20-smtp.spec.ts` 100 % verte.

#### Sprint 21 : Données Réelles Stats & Compatibilité MySQL/MariaDB *(À faire)*
- **Objectif métier :** Fiabiliser les indicateurs démographiques de `/stats` avec de vraies données adhérents conformes au RGPD et certifier le fonctionnement complet du CMS sur un moteur **MySQL/MariaDB** en plus de SQLite WAL.
- **Périmètre macro (User Stories) :**
  - `US-LOT4-05` — **Champ `gender` optionnel (RGPD) :** Ajouter un attribut optionnel non obligatoire sur les fiches adhérents (inscription `/register`, CRM `/members`, profil) avec choix neutre / « Non renseigné » par défaut.
  - `US-LOT4-06` — **Statistiques démographiques réelles :** Remplacer le ratio H/F statique (54 % / 46 %) dans `StatsService` et `/stats` par le calcul agrégé réel (avec gestion explicite du segment « Non renseigné » et seuil d'anonymisation si nécessaire).
  - `US-LOT4-07` — **Compatibilité SQL MySQL/MariaDB :** Auditer et adapter les migrations `001`/`002`/`003`, `Database.php` et les requêtes SQL spécifiques (ex. fonctions de dates, `INSERT OR IGNORE`, `PRAGMA`, transactions) pour une exécution sans erreur sur MySQL 8 / MariaDB 10.6+.
  - `US-LOT4-08` — **Validation E2E multi-SGBD :** Permettre l'exécution et la réinitialisation propre de la suite Playwright sur un profil MySQL/MariaDB documenté sans casser le workflow SQLite WAL.
- **Dépendances :** `StatsService`, migrations Core (`001`–`003`), `Database.php`.
- **Hors périmètre :** Migration automatisée de données SQLite → MySQL avec interface graphique, clustering/réplication MySQL.
- **Critères de sortie :** Build TS/CSS OK, PHPStan niveau 5 (0 erreur), suite E2E cumulée + nouvelle suite `22-sprint21-stats-mysql.spec.ts` verte.

---

### 2.3. Lot 5 — Écosystème Métier Modulaire via le SDK V2 (V2.2)

**Vision du Lot :** Capitaliser sur le moteur de modules V2 (`modules/{NomModule}Api` + `{NomModule}Ui`, hooks UI, Safe Mode) pour livrer deux modules métier à forte valeur opérationnelle pour les entraîneurs et les adhérents, sans alourdir le Core.

#### Sprint 22 : Module Présences & Appel Numérique Tatami *(À faire)*
- **Objectif métier :** Permettre aux Entraîneurs de réaliser l'appel numérique sur smartphone ou tablette au bord du tatami, suivre l'assiduité des adhérents et enrichir `/stats` sans modifier le Core.
- **Périmètre macro (User Stories) :**
  - `US-LOT5-01` — **Squelette modulaire `PresenceApi` / `PresenceUi` :** Manifeste v2, tables préfixées `mod_presence_*`, routes `/api/v1/modules/presence/*` et route UI dédiée protégée par RBAC (Entraîneur / Bureau).
  - `US-LOT5-02` — **Feuille d'appel par séance & discipline :** Sélection d'un créneau/discipline, pointage rapide (Présent / Absent / Excusé) optimisé mobile, sauvegarde idempotente.
  - `US-LOT5-03` — **Historique d'assiduité :** Consultation des présences par adhérent (côté Entraîneur/Bureau et espace adhérent/famille dans le respect du RGPD).
  - `US-LOT5-04` — **Onglet analytique injecté dans `/stats` :** Injection d'un onglet « Assiduité » via le hook SDK `ui_tabs` (taux de présence par discipline et par période), avec tolérance totale au Safe Mode si le module est désactivé.
- **Dépendances :** SDK V2 (`EventManager`, `ui_tabs`, `ui_routes`, `api_routes`), données `disciplines` & `member_practices`.
- **Hors périmètre :** Pointage par badge NFC/QR Code matériel, notifications push mobiles natives, modification directe du code Core hors hooks existants.
- **Critères de sortie :** Activation/désactivation/désinstallation propre via `/sys-modules`, zéro régression Core, PHPStan niveau 5, nouvelle suite `23-sprint22-module-presence.spec.ts` verte (Desktop & Mobile).

#### Sprint 23 : Module Événements, Stages & Compétitions *(À faire)*
- **Objectif métier :** Offrir au club un calendrier unifié des stages, passages de grades et compétitions, avec inscription en 1 clic pour les adhérents connectés et mise en avant sur la page d'accueil.
- **Périmètre macro (User Stories) :**
  - `US-LOT5-05` — **Squelette modulaire `EvenementsApi` / `EvenementsUi` :** Manifeste v2, tables `mod_evenements_*`, routes `/api/v1/modules/evenements/*` et page publique/connectée du calendrier.
  - `US-LOT5-06` — **Gestion des événements (Bureau / Entraîneurs) :** Création, édition, publication et clôture d'événements (type stage/grade/compétition, date, lieu, discipline cible, jauge maximale).
  - `US-LOT5-07` — **Inscription en 1 clic & liste des participants :** Inscription/désinscription d'un adhérent (ou d'un membre rattaché de sa famille) avec contrôle de jauge et export de la liste des inscrits pour l'encadrant.
  - `US-LOT5-08` — **Widget d'accueil via `ui_slot_home_after_articles` :** Affichage automatique des prochains événements publiés sur `/` sans altérer le gabarit Core.
- **Dépendances :** SDK V2 (`ui_slot_home_after_articles`, `ui_routes`, `api_routes`), rattachements familiaux `member_tutor`, transport SMTP (Sprint 20) pour les confirmations optionnelles.
- **Hors périmètre :** Billetterie payante (traitée dans le Lot 6), gestion des résultats sportifs/tableaux de poules, synchronisation CalDAV/Google Calendar.
- **Critères de sortie :** Cycle de vie module complet, zéro régression sur `/`, PHPStan niveau 5, nouvelle suite `24-sprint23-module-evenements.spec.ts` verte.

---

### 2.4. Lot 6 — Paiement en Ligne & Comptabilité (V2.3)

**Vision du Lot :** Permettre aux clubs qui le souhaitent d'encaisser leurs cotisations en ligne de manière optionnelle et modulaire, tout en conservant le parcours hors-ligne (chèque, espèces, virement, Pass'Sport) 100 % fonctionnel.

#### Sprint 24 : Paiement en Ligne (HelloAsso / Stripe) & Facturation *(À faire)*
- **Objectif métier :** Connecter un module de paiement en ligne optionnel au tunnel `/register` et à la trésorerie `/registrations` pour automatiser le rapprochement des cotisations et simplifier l'export comptable.
- **Périmètre macro (User Stories) :**
  - `US-LOT6-01` — **Architecture modulaire de paiement (`PaiementApi` / `PaiementUi`) :** Configuration sécurisée des clés API et du prestataire (HelloAsso et/ou Stripe, mode sandbox/production) réservée au Superadmin/Trésorier, tables `mod_paiement_*`.
  - `US-LOT6-02` — **Option paiement en ligne dans `/register` :** Branchement conditionnel sur le tunnel d'inscription (uniquement si le module est actif) pour initier une session de paiement sécurisée et rediriger vers la page de confirmation.
  - `US-LOT6-03` — **Webhooks signés & rapprochement automatique :** Réception et vérification cryptographique des webhooks prestataire, passage automatique du statut de la cotisation dans `registrations` et déclenchement idempotent de la génération de reçu.
  - `US-LOT6-04` — **Journal des transactions & export comptable :** Vue de suivi des encaissements dans `/registrations` (ou onglet dédié) et export CSV comptable normalisé (date, référence, adhérent, montant, frais, statut).
- **Dépendances :** Transport SMTP réel (Sprint 20) pour l'envoi automatique des reçus, SDK V2, tunnel `/register` & Trésorerie `/registrations`.
- **Hors périmètre :** Stockage de données de carte bancaire (PCI-DSS délégué au prestataire), comptabilité en partie double complète (FEC/bilan), remboursements partiels complexes multi-devises.
- **Critères de sortie :** Tests E2E avec webhook simulé/signé en environnement de test (`25-sprint24-module-paiement.spec.ts`), non-régression totale du tunnel sans paiement en ligne, PHPStan niveau 5.

---

### 2.5. Règles Transverses de Pilotage (Applicable à Tous les Sprints)
1. **Non-régression stricte :** Aucune route API, classe CSS ou `data-testid` existant ne peut être supprimé ; les 284 tests E2E V2.0 doivent rester verts à chaque sprint.
2. **Qualité obligatoire :** `npm run build:ts`, `npm run build:css`, `composer dump-autoload -o --strict-psr`, `vendor\bin\phpstan analyse` (niveau 5, 0 erreur) et `npm run test:e2e`.
3. **Isolation des tests :** Chaque sprint ajoute son propre fichier `tests/e2e/XX-sprintYY-*.spec.ts` avec réinitialisation/isolation stricte de la base de données.
