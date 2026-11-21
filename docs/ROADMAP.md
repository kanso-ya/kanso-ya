# Feuille de Route — Kanso-ya CMS & CRM Associatif

**Rôle :** @cp (Chef de Projet)  
**Dernière mise à jour :** 21 novembre 2026  
**État courant :** **V2.4 homologuée — Lot 7 / Sprint 27 QA Intégrale validé** (35/35 matrice 7 navigateurs Desktop & Mobile + 324/324 socle V2.3 · PHPStan niveau 5 : 0 erreur · PSR-4 strict : 36 classes, 0 avertissement)  
**Dernier sprint clôturé :** **Lot 7 — Sprint 27 : QA Intégrale — Parcours Utilisateur 100 % UI, Audit Vues & E-mails Réels (Mailpit)** ([`docs/archive/sprint-27/`](./archive/sprint-27/))

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

### 1.6. Transport SMTP Réel & Fiabilité Messagerie (Lot 4 — Sprint 20)
- **Client SMTP natif PHP (`Core\Api\Services\SmtpClient`) :** support TCP clair (`none`), SSL/TLS (`ssl`) et STARTTLS (`tls`), authentification `AUTH LOGIN` / `AUTH PLAIN`, composition MIME UTF-8 base64 et dot-stuffing RFC 5321, timeout configurable (`2` à `30` s).
- **Service centralisé (`Core\Api\Services\MailerService`) :** chiffrement AES-256-GCM (`enc:v1:...`) du mot de passe SMTP en base + surcharge prioritaire `SMTP_PASSWORD` dans `.env`, masquage strict sur `GET /api/v1/settings`, mode `log/stub` et confinement sécurité sous `APP_ENV=testing` (blocage des hôtes externes).
- **Traçabilité & relance (`/sys-smtp` & `POST /api/v1/sys/email-logs/{id}/retry`) :** archivage `body_html`, `retry_count`, `last_retry_at` (`004_smtp_reliability.php`), badges de statut `Envoyé`/`Échec` et relance manuelle réservée au Superadmin ; centralisation des envois hors transaction SQLite dans `AuthController` et `MemberController`.

### 1.7. Données Réelles Stats & Compatibilité MySQL/MariaDB (Lot 4 — Sprint 21)
- **Champ optionnel `gender` (RGPD) :** colonne nullable `members.gender` (`M`, `F`, `O` ou `NULL` par défaut, migration `005_member_gender.php`), sélecteurs optionnels sur `/register` et `/members` (création/édition) et validation API `422`.
- **Statistiques démographiques réelles :** suppression définitive du ratio statique `54 % / 46 %` dans `StatsService` et `SystemController::getDemographics()`, remplacé par les effectifs/pourcentages réels (`male`, `female`, `other`, `unspecified`) affichés sur `/stats`.
- **Portabilité SQLite WAL & MySQL 8 / MariaDB 10.6+ :** abstraction dialectale centralisée dans `Core\Api\Database\Database` (`beginWriteTransaction`, `tableHasColumn`, `insertIgnorePrefix`, `upsertSettingSql`, `adaptDdlForDriver`, `execDdlStatements`) et script d'audit `npm run db:check-mysql`.

### 1.8. Module « Présences & Suivi Tatami » (Lot 5 — Sprint 22)
- **Extension normalisée SDK v2 (`modules/PresenceApi/` & `modules/PresenceUi/`) :** manifeste v2, tables isolées `mod_presence_sessions` et `mod_presence_records` (compatibles SQLite & MySQL via `Database::execDdlStatements()`), assets `presence.css`/`presence.js` publiés sous `/assets/modules/presence/` et purgés à la désinstallation.
- **Appel numérique mobile-first (`/presences`) :** création/réouverture idempotente par discipline/date/créneau, roster anonymisé RGPD (zéro e-mail/adresse), pointage `Présent` / `Absent` / `Excusé`, modale d'historique individuel protégée (Entraîneur/Bureau/Adhérent/Tuteur rattaché).
- **Intégration `/stats` sans toucher au Core :** onglet analytique « 📋 Assiduité » injecté dynamiquement via le hook `ui_tabs` (séances, présents/absents/excusés, taux par discipline).

### 1.9. Module « Événements, Stages & Compétitions » (Lot 5 — Sprint 23)
- **Extension normalisée SDK v2 (`modules/EvenementsApi/` & `modules/EvenementsUi/`) :** manifeste v2, tables isolées `mod_evenements_events` et `mod_evenements_registrations` (compatibles SQLite & MySQL), assets `evenements.css`/`evenements.js` publiés sous `/assets/modules/evenements/` et purgés à la désinstallation.
- **Calendrier & Inscriptions 1 clic (`/evenements`) :** création/publication/clôture pour les gestionnaires (`stats.view` / `articles.write`), contrôle strict de jauge (`409` Complet), inscription familiale sécurisée (`member_tutor`) et modale participants avec export CSV.
- **Widget d'accueil sans toucher au Core :** bloc « 📅 Prochains Événements, Stages & Compétitions » injecté sur `/` via `ui_slot_home_after_articles`.

### 1.10. Module « Facturation PDF Officielle & Envoi Automatique » (Lot 6 — Sprint 24)
- **Extension transversale SDK v2 (`modules/FacturationApi/` & `modules/FacturationUi/`) :** manifeste v2, tables `mod_facturation_settings` et `mod_facturation_invoices` (compatibles SQLite & MySQL), numérotation séquentielle légale `FAC-YYYY-0001`, archivage PDF hors webroot (`storage/private_uploads/invoices/`).
- **Moteur PDF 1.4 natif & Templates dédiés :** `NativePdfBuilder` en PHP 8.5 pur (A4, accents français, tableau lignes, remises, total net, mentions légales), paramétrage du template PDF et du template d'e-mail dédié `invoice_issued` sur `/factures`.
- **Envoi automatique avec pièce jointe PDF :** extension rétrocompatible MIME `multipart/mixed` (`application/pdf`) dans `SmtpClient` / `MailerService`, déclenchement automatique sur `on_registration_validated` et action SDK `issue_official_invoice` réutilisable par les autres modules (Boutique, etc.).

### 1.11. Module Boutique Club — Catalogue Avancé, Variantes, Flocage & Lots (Lot 6 — Sprint 25)
- **Schéma étendu (`mod_boutique_products`, `mod_boutique_variants`, `mod_boutique_bundles`, `mod_boutique_bundle_items`) :** compatible SQLite & MySQL (`Database::execDdlStatements()`).
- **Fonctionnalités catalogue (`/boutique` & `/api/v1/modules/boutique/*`) :** options de taille avec stock par taille, statuts de disponibilité (`en_stock`, `sur_commande`, `rupture`, `masque`), remises adhérents (`percent`/`fixed`), accès « Réservé aux adhérents uniquement » (`members_only`), option flocage personnalisé avec recalcul de prix en direct, et création de lots/packs combinant articles catalogue et/ou exclusifs au lot.

### 1.12. Module Boutique Club — Commandes, Paiement Hors-Ligne, Livraison, Suivi & Facturation PDF (Lot 6 — Sprint 26)
- **Paramétrage Paiements Hors-Ligne & Livraisons (`mod_boutique_settings`) :** activation/désactivation des modes hors-ligne (`cheque`, `especes`, `virement`), option « Paiement en ligne » verrouillée **« Indisponible pour le moment »** (rejet strict API `422`), modes de livraison (`retrait_dojo`, `remise_main_propre`, `livraison_postale`) et frais postaux configurables.
- **Panier, Commandes & Suivi (`mod_boutique_orders`, `mod_boutique_order_items`) :** interface 3 onglets sur `/boutique` (`Catalogue & Panier`, `Suivi Commandes`, `Administration & Paramètres`), recalcul intégral côté serveur (remise adhérent, flocage, livraison), numérotation séquentielle `CMD-YYYY-0001`, décrémentation atomique du stock et restauration sur annulation (`annulee`).
- **Liaison Facturation PDF Officielle :** déclenchement automatique du hook SDK `issue_official_invoice` lors du passage au statut `payee` (génération PDF officielle `FacturationApi` + envoi e-mail avec pièce jointe PDF, dégradation gracieuse totale si `FacturationApi` est inactif).

---

## 2. Prochains Lots / Sprints à Venir (Backlog V2.1+)

### 2.1. Vue Synthétique des Lots & Sprints

| Lot / Sprint | Intitulé | Version cible | Priorité | Statut |
| :---: | :--- | :---: | :---: | :---: |
| **Lot 4 — Sprint 20** | **Transport SMTP Réel & Fiabilité Messagerie** | V2.1 | Haute | **Terminé** |
| **Lot 4 — Sprint 21** | **Données Réelles Stats & Compatibilité MySQL/MariaDB** | V2.1 | Moyenne | **Terminé** |
| **Lot 5 — Sprint 22** | **Module Présences & Appel Numérique Tatami** | V2.2 | Moyenne | **Terminé** |
| **Lot 5 — Sprint 23** | **Module Événements, Stages & Compétitions** | V2.2 | Basse | **Terminé** |
| **Lot 6 — Sprint 24** | **Module Facturation PDF Officielle & Envoi Automatique par E-mail** | V2.3 | Haute | **Terminé** |
| **Lot 6 — Sprint 25** | **Module Boutique Club — Catalogue Avancé, Variantes, Remises Adhérents, Flocage & Lots** | V2.3 | Haute | **Terminé** |
| **Lot 6 — Sprint 26** | **Module Boutique Club — Commandes, Paiement Hors-Ligne, Livraison, Suivi & Facturation PDF** | V2.3 | Moyenne | **Terminé** |
| **Lot 7 — Sprint 27** | **QA Intégrale — Parcours 100 % UI (Boîte Noire), Matrice RBAC, Audit Vues, Multi-Navigateurs & E-mails Réels (Mailpit)** | V2.4 | Haute | **Terminé** |
| **Lot 8 — Sprint 28** | **Mode Démo (Noyau) — Désactivation, Onboarding Premier Superadmin, Confirmation Mail, Reset & Purge Modules de Test** | V2.5 | Haute | **À faire** |
| **Lot 8 — Sprint 29** | **Gouvernance des Comptes — Superadmins Secondaires, Création Président & Gestion Membres du Bureau** | V2.5 | Haute | **À faire** |
| **Lot 9 — Sprint 30** | **Pages du Footer — Administration par Onglets, Builder Simplifié, Activation/Désactivation & Option Horaires/Tarifs** | V2.6 | Moyenne | **À faire** |
| **Lot 10 — Sprint 31** | **Multi-Corrections — Lien Événements, Pages Templates Facturation, Champs Inscription Dynamiques, Urgences Tatami & Vue Superadmin** | V2.7 | Haute | **À faire** |

---

### 2.2. Lot 4 — Industrialisation Production & Données Réelles (V2.1)

**Vision du Lot :** Supprimer les derniers éléments simulés/statiques hérités de la V2.0 (envoi d'e-mails simulé `250 OK`, répartition H/F figée) et garantir la portabilité de la base de données vers **MySQL/MariaDB** en production tout en préservant l'exécution locale rapide sur SQLite WAL.

#### Sprint 20 : Transport SMTP Réel & Fiabilité Messagerie *(Terminé — archivé dans `docs/archive/sprint-20/`)*
- **Objectif métier :** Assurer l'acheminement réel et fiable des e-mails transactionnels du club (OTP, 2FA e-mail, mot de passe oublié, confirmations d'inscription, tests SMTP) via un transport SMTP natif PHP sécurisé, tout en conservant un mode `log/stub` déterministe pour les tests automatisés.
- **Périmètre macro (User Stories) :**
  - `US-LOT4-01` — **Client SMTP natif PHP sécurisé :** Remplacer la simulation `250 OK` par une implémentation SMTP native sans dépendance lourde (support STARTTLS, SSL/TLS, authentification `AUTH LOGIN`/`PLAIN`, gestion des timeouts).
  - `US-LOT4-02` — **Protection des identifiants SMTP :** Sécuriser le stockage du mot de passe SMTP (chiffrement en base `sys_settings` ou lecture via `.env`) sans jamais le ré-exposer en clair dans l'API ou l'UI `/sys-smtp`.
  - `US-LOT4-03` — **Bascule automatique `log/stub` en environnement de test :** Garantir qu'en `APP_ENV=testing` (ou mode simulation explicite), aucun e-mail réel ne quitte le serveur et que le comportement reste 100 % testable en E2E.
  - `US-LOT4-04` — **Traçabilité des erreurs & relance depuis `/sys-smtp` :** Enregistrer les statuts réels (`sent` / `failed`) et le détail d'erreur SMTP dans `email_logs`, et permettre au Superadmin de relancer manuellement un e-mail en échec.
- **Hors périmètre :** Campagnes d'e-mailing de masse / newsletters, files d'attente asynchrones avec workers/cron, intégration d'APIs tierces propriétaires (SendGrid, Mailgun, Brevo).
- **Critères de sortie :** Build TS/CSS OK, PHPStan niveau 5 (0 erreur), suite E2E existante + nouvelle suite `21-sprint20-smtp.spec.ts` 100 % verte.

#### Sprint 21 : Données Réelles Stats & Compatibilité MySQL/MariaDB *(Terminé — archivé dans `docs/archive/sprint-21/`)*
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

#### Sprint 22 : Module Présences & Appel Numérique Tatami *(Terminé — archivé dans `docs/archive/sprint-22/`)*
- **Objectif métier :** Permettre aux Entraîneurs de réaliser l'appel numérique sur smartphone ou tablette au bord du tatami, suivre l'assiduité des adhérents et enrichir `/stats` sans modifier le Core.
- **Périmètre macro (User Stories) :**
  - `US-LOT5-01` — **Squelette modulaire `PresenceApi` / `PresenceUi` :** Manifeste v2, tables préfixées `mod_presence_*`, routes `/api/v1/modules/presence/*` et route UI dédiée protégée par RBAC (Entraîneur / Bureau).
  - `US-LOT5-02` — **Feuille d'appel par séance & discipline :** Sélection d'un créneau/discipline, pointage rapide (Présent / Absent / Excusé) optimisé mobile, sauvegarde idempotente.
  - `US-LOT5-03` — **Historique d'assiduité :** Consultation des présences par adhérent (côté Entraîneur/Bureau et espace adhérent/famille dans le respect du RGPD).
  - `US-LOT5-04` — **Onglet analytique injecté dans `/stats` :** Injection d'un onglet « Assiduité » via le hook SDK `ui_tabs` (taux de présence par discipline et par période), avec tolérance totale au Safe Mode si le module est désactivé.
- **Dépendances :** SDK V2 (`EventManager`, `ui_tabs`, `ui_routes`, `api_routes`), données `disciplines` & `member_practices`.
- **Hors périmètre :** Pointage par badge NFC/QR Code matériel, notifications push mobiles natives, modification directe du code Core hors hooks existants.
- **Critères de sortie :** Activation/désactivation/désinstallation propre via `/sys-modules`, zéro régression Core, PHPStan niveau 5, nouvelle suite `23-sprint22-module-presence.spec.ts` verte (Desktop & Mobile).

#### Sprint 23 : Module Événements, Stages & Compétitions *(Terminé — archivé dans `docs/archive/sprint-23/`)*
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

### 2.4. Lot 6 — Facturation PDF Officielle & Boutique Club Complète (V2.3)

**Vision du Lot :** Doter le CMS d'un moteur modulaire de **facturation PDF officielle** (multi-sources : cotisations Core et modules tiers comme la Boutique) avec envoi automatique par e-mail dédié, puis transformer la Boutique Club en un véritable **module e-commerce associatif hors-ligne** (tailles, remises adhérents, flocage, lots/packs, livraison et suivi de commandes).

> **Décision de cadrage (Hors Périmètre Projet) :** Le **paiement en ligne** (carte bancaire, Stripe, HelloAsso ou virement direct initié depuis le CMS) est **définitivement sorti du périmètre de Kanso-ya** et fera l'objet d'un projet distinct. Dans les paramétrages Boutique, l'option « Paiement en ligne » reste visible mais explicitement verrouillée sur **« Indisponible pour le moment »**.

#### Sprint 24 : Module Facturation PDF Officielle & Envoi Automatique par E-mail *(Terminé — archivé dans `docs/archive/sprint-24/`)*
- **Objectif métier :** Permettre au club de générer, archiver et expédier automatiquement par e-mail des factures **PDF officielles** (numérotation légale, template PDF spécifique personnalisable aux couleurs du club et template d'e-mail dédié) pour les cotisations d'adhésion ainsi que pour les autres modules (Boutique, etc.).
- **Périmètre macro (User Stories) :**
  - `US-LOT6-01` — **Squelette modulaire `FacturationApi` / `FacturationUi` :** Manifeste SDK v2, tables isolées `mod_facturation_*` (factures, lignes, séquences annuelles `FAC-YYYY-0001`, paramètres de template PDF), stockage sécurisé hors webroot (`storage/private_uploads/invoices/`).
  - `US-LOT6-02` — **Moteur d'édition PDF & Template spécifique :** Génération native d'un PDF officiel valide à partir d'un gabarit dédié configurable (logo, raison sociale/SIRET/RNA du club, coordonnées adhérent/payeur, détail des lignes, remises, total net, mentions légales de pied de page et prévisualisation).
  - `US-LOT6-03` — **Envoi automatique par e-mail avec pièce jointe PDF :** Template d'e-mail dédié (`invoice_issued` personnalisable), extension du transport SMTP (`MailerService` / `SmtpClient`) pour supporter les pièces jointes MIME (`application/pdf`), déclenchement automatique sur `on_registration_validated` et relance manuelle en cas d'échec.
  - `US-LOT6-04` — **Service transversal multi-modules & Espace Factures :** Interface/hook SDK permettant à d'autres modules (ex. `BoutiqueApi`) d'émettre une facture officielle standardisée ; écran de gestion/historique pour le Bureau (`finances.manage`) et téléchargement sécurisé pour l'adhérent/payeur concerné.
- **Dépendances :** Transport SMTP natif (Sprint 20), SDK V2 (`on_registration_validated`, `ui_routes`, `api_routes`), dossiers `registrations`.
- **Hors périmètre :** Paiement en ligne / prélèvement SEPA, comptabilité en partie double (FEC/bilan), facturation électronique Factur-X B2B.
- **Critères de sortie :** Génération PDF binaire valide (`%PDF-1.x`), pièce jointe vérifiée sur serveur SMTP local en E2E, contrôle d'accès strict (`403` sur facture tierce), PHPStan niveau 5, nouvelle suite `25-sprint24-module-facturation.spec.ts` verte.

#### Sprint 25 : Module Boutique Club — Catalogue Avancé, Variantes, Remises Adhérents, Flocage & Lots *(Terminé — archivé dans `docs/archive/sprint-25/`)*
- **Objectif métier :** Faire évoluer le module `BoutiqueApi` / `BoutiqueUi` (au-delà du démonstrateur V2) en un catalogue associatif complet gérant les variantes de taille, la disponibilité, les tarifs préférentiels / accès réservé aux adhérents, l'option de flocage personnalisé et la composition de lots/packs.
- **Périmètre macro (User Stories) :**
  - `US-LOT6-05` — **Modèle catalogue étendu & gestion back-office (`mod_boutique_*`) :** CRUD complet des produits pour les gestionnaires (`finances.manage`) avec gestion des **options de taille** (ex. XS/S/M/L/XL ou 120cm..190cm avec stock individuel par taille) et de la **disponibilité** (`en_stock`, `sur_commande`, `rupture`, `masque`).
  - `US-LOT6-06` — **Tarification & visibilité adhérents :** Paramétrage par article d'un prix public, d'une **remise / tarif préférentiel adhérent** (montant fixe ou pourcentage) et d'une option **« Réservé aux adhérents connectés uniquement »** (masqué ou verrouillé aux visiteurs).
  - `US-LOT6-07` — **Option de flocage personnalisé :** Activation du flocage par article avec supplément tarifaire paramétrable, saisie du texte à floquer (ex. nom / initiales, limite de caractères) et prévisualisation du surcoût.
  - `US-LOT6-08` — **Création de Lots / Packs :** Composition d'un lot promotionnel regroupant des **articles existants du catalogue** et/ou des **articles exclusifs au lot**, avec sélection des tailles/flocages par composant et prix de lot dédié.
- **Dépendances :** SDK V2 (`BoutiqueApi` / `BoutiqueUi`), contexte d'authentification adhérent Core.
- **Hors périmètre :** Paiement en ligne CB/Stripe/HelloAsso, gestion multi-entrepôts ou synchronisation fournisseur externe.
- **Critères de sortie :** Rétrocompatibilité des routes `/boutique` et `/api/v1/modules/boutique/products` (zéro régression Sprint 17), PHPStan niveau 5, nouvelle suite `26-sprint25-boutique-catalogue.spec.ts` verte.

#### Sprint 26 : Module Boutique Club — Commandes, Paiement Hors-Ligne, Livraison, Suivi & Liaison Facturation PDF *(Terminé — archivé dans `docs/archive/sprint-26/`)*
- **Objectif métier :** Compléter la Boutique Club avec le tunnel de commande, le paramétrage des modes de paiement hors-ligne (avec paiement en ligne explicitement marqué « Indisponible pour le moment ») et de livraison, le suivi d'avancement des commandes et l'émission automatique de la facture PDF officielle.
- **Périmètre macro (User Stories) :**
  - `US-LOT6-09` — **Paramétrages Paiement & Livraison Boutique :** Écran de configuration (`finances.manage`) permettant d'activer/désactiver les modes de paiement hors-ligne (Chèque, Espèces, Virement manuel club), d'afficher l'option **« Paiement en ligne »** en état désactivé **« Indisponible pour le moment »**, et de configurer les **modes de livraison** (ex. Retrait au dojo gratuit, Remise en main propre, Livraison postale avec frais).
  - `US-LOT6-10` — **Panier & Passage de commande :** Sélection des variantes (taille, flocage, composition de lot), calcul temps réel des remises adhérents et frais de livraison, choix du mode de paiement hors-ligne et de livraison, décrémentation/réservation cohérente du stock.
  - `US-LOT6-11` — **Suivi des commandes (Bureau & Adhérent) :** Workflow complet de suivi (`en_attente_paiement`, `payee`, `en_preparation`, `prete_ou_expediee`, `livree`, `annulee`) avec historique des étapes, gestion côté Bureau et suivi en temps réel dans l'espace de l'acheteur.
  - `US-LOT6-12` — **Liaison Facturation PDF Officielle :** Déclenchement automatique de la génération de facture PDF officielle et de son envoi par e-mail dédié via `FacturationApi` (Sprint 24) lors du passage d'une commande au statut `payee` (avec dégradation gracieuse si le module Facturation est inactif).
- **Dépendances :** Sprint 24 (`FacturationApi`), Sprint 25 (Catalogue `BoutiqueApi`), transport SMTP (Sprint 20).
- **Hors périmètre :** Paiement en ligne (CB, Stripe, HelloAsso, virement automatisé — projet séparé), génération d'étiquettes transporteurs (Colissimo/Mondial Relay).
- **Critères de sortie :** Tunnel complet panier → commande → suivi → facture PDF, vérification du verrouillage « Indisponible pour le moment » sur le paiement en ligne, zéro régression sur les 310+ tests existants, PHPStan niveau 5, nouvelle suite `27-sprint26-boutique-commandes.spec.ts` verte.

---

### 2.5. Lot 7 — Assurance Qualité Intégrale, Audit UI & Messagerie Réelle (V2.4)

**Vision du Lot :** Homologuer l'intégralité de la plateforme Kanso-ya (Core + Modules V2.3) en conditions réelles d'utilisation **100 % boîte noire via l'interface utilisateur**, sur une matrice complète de navigateurs Desktop et d'émulations Mobile, en auditant la propreté visuelle/structurelle de chaque vue et en certifiant l'envoi réel des e-mails via **Mailpit** (Laragon).

#### Sprint 27 : QA Intégrale — Parcours Utilisateur 100 % UI, Audit Vues & E-mails Réels (Mailpit) *(Terminé — archivé dans `docs/archive/sprint-27/`)*
- **Objectif métier :** Garantir qu'aucun parcours utilisateur, contrôle RBAC, écran à onglets ou envoi d'e-mail réel ne présente de défaut caché par des raccourcis API ou des mocks, et fournir une couverture multi-navigateurs Desktop & Mobile sans compromis.
- **Périmètre macro (User Stories) :**
  - `US-LOT7-01` — **Parcours Utilisateur 100 % UI (Boîte Noire) :** Rejouer l'ensemble des scénarios fonctionnels existants (Core + modules actifs `PresenceApi`, `EvenementsApi`, `FacturationApi`, `BoutiqueApi`) exclusivement via l'interface utilisateur : connexion par formulaire (`/login` et `/sys-admin-xyz`), saisie, clics et navigation réelle — **aucun raccourci API (`switch-persona`, appels directs) ni injection de session**.
  - `US-LOT7-02` — **Couverture Exhaustive des Rôles (Matrice RBAC UI) :** Tester systématiquement chaque profil utilisateur existant (**Visiteur anonyme**, **Adhérent / Tuteur**, **Entraîneur**, **Rédacteur**, **Secrétaire**, **Trésorier**, **Président**, **Superadmin**) afin de vérifier strictement ce qu'il doit voir (menus Sidebar, onglets, boutons d'action, données autorisées) et ce qui doit lui être masqué ou interdit (accès URL directe, boutons absents, cloisonnement RGPD).
  - `US-LOT7-03` — **Matrice Playwright Complète (Desktop & Mobile, sans compromis sur le temps d'exécution) :** Exécuter la campagne QA sur l'ensemble des moteurs et terminaux cibles :
    - **Desktop :** `Chromium` (Chrome / Edge / Opera), `Firefox`, `WebKit` (Safari).
    - **Mobile (émulation Playwright) :** profils Android (`Pixel`, `Samsung Galaxy`) et iOS (`iPhone`, `iPhone Pro`).
  - `US-LOT7-04` — **Audit de Propreté des Vues & Intégrité UI :** Auditer systématiquement chaque page — en particulier les vues ayant reçu des onglets (`/stats`, `/disciplines`, `/sys-smtp`, `/profile`, `/factures`, `/boutique`, etc.) — pour détecter et corriger tout élément résiduel/superflu (blocs dupliqués, contenu orphelin hors onglet, liens ou contenus manquants/superflus), tout lien mort, toute erreur console JS, toute erreur réseau (`4xx`/`5xx` inattendues) et tout débordement horizontal (`scrollWidth > clientWidth`) sur mobile.
  - `US-LOT7-05` — **Tests d'Envoi Réel d'E-mails via Mailpit (Laragon — SMTP `127.0.0.1:1025`, API `http://127.0.0.1:8025/api/v1/messages`) en 2 temps :**
    1. **Phase 1 — Surcharge SMTP (niveau serveur / `.env`) :** Vérifier que la surcharge SMTP pointant vers Mailpit (`127.0.0.1:1025`) est effectivement prise en compte et que les e-mails déclenchés par l'application sont reçus et lisibles via l'API Mailpit.
    2. **Phase 2 — Configuration Utilisateur (100 % UI) :** Connecté en tant qu'administrateur via l'interface, renseigner manuellement les paramètres SMTP de Mailpit depuis l'écran `/sys-smtp`, sauvegarder, puis vérifier que les envois réels (mail de test et flux métier : OTP, mot de passe oublié, facturation PDF avec pièce jointe, etc.) aboutissent bien dans Mailpit (destinataire, sujet, contenu HTML, liens cliquables).
    - **Isolation stricte :** Purge automatique de la boîte Mailpit (`DELETE http://127.0.0.1:8025/api/v1/messages`) avant chaque scénario et chaque phase afin d'éliminer tout faux positif.
- **Dépendances :** Socle V2.3 complet (Sprints 1–26), service Mailpit actif sous Laragon (`127.0.0.1:1025` / `127.0.0.1:8025`), navigateurs Playwright (`chromium`, `firefox`, `webkit`).
- **Hors périmètre :** Développement de nouvelles fonctionnalités métier hors correctifs d'anomalies UI/RBAC/SMTP découvertes par l'audit, tests sur fermes de terminaux physiques cloud (BrowserStack/SauceLabs), envoi d'e-mails vers des boîtes externes sur Internet.
- **Critères de sortie :** Zéro erreur console JS / réseau / lien mort / débordement horizontal mobile, audit complet des vues à onglets validé, 100 % des rôles vérifiés en boîte noire UI, phases 1 & 2 Mailpit validées avec purge isolée, matrice Playwright complète (Desktop Chromium/Firefox/WebKit + Mobile Android/iOS) verte, et zéro régression sur les 324 tests E2E existants.

---

### 2.6. Lot 8 — Cycle de Vie « Mode Démo » (Noyau) & Gouvernance des Comptes (V2.5)

**Vision du Lot :** Faire du Mode Démo un mécanisme natif du noyau Kanso-ya (et non un module) capable de basculer proprement et irréversiblement une instance de démonstration vers une instance de production vierge (premier Superadmin + URL furtive personnalisée + validation par e-mail + purge BDD/réglages + suppression des modules de test préfixés), puis doter le Superadmin et le Président d'écrans dédiés de gestion des comptes.

#### Sprint 28 : Désactivation du Mode Démo, Onboarding Premier Superadmin, Reset & Purge des Modules de Test *(À faire)*
- **Objectif métier :** Sécuriser le passage de l'environnement de démonstration à la production réelle en remplaçant les identifiants et l'URL par défaut par le premier Superadmin validé par e-mail, en réinitialisant les données de démo et en purgeant les fichiers des modules de test.
- **Périmètre macro (User Stories) :**
  - `US-LOT8-01` — **Mode Démo natif au Noyau (Core) :** Ancrer la gestion du Mode Démo directement dans le Core Kanso-ya (hors moteur de modules), avec avertissements explicites sur la suppression définitive des données de démo et le changement d'URL de la console.
  - `US-LOT8-02` — **Onboarding Premier Superadmin & Confirmation par E-mail :** Lors de la désactivation du Mode Démo, afficher une page dédiée permettant de créer le premier compte Superadmin (identifiant, e-mail, mot de passe fort) et de définir la nouvelle URL furtive d'accès à la console de réglages (supplantant `SUPERADMIN_URL` par défaut). Envoyer un e-mail contenant un lien de confirmation sécurisé pour vérifier l'adresse/identifiant avant de valider définitivement la suppression du Mode Démo.
  - `US-LOT8-03` — **Réinitialisation propre (Reset) à la création du premier Superadmin :** Dès confirmation du premier Superadmin, purger les tables de données de démonstration et les réglages par défaut (architecture de service `ResetService` réutilisable pour une future fonction globale de réinitialisation).
  - `US-LOT8-04` — **Convention de préfixe & Purge des modules de test :** Normaliser les modules de test du moteur SDK sous un préfixe dédié (ex. `Test*` — hors `BoutiqueApi` qui est un module métier à part entière et n'est plus considéré comme un module de test) afin que la désactivation du Mode Démo supprime leurs fichiers proprement sans jamais risquer de régression sur les modules métier.
- **Dépendances :** Transport SMTP réel (Sprint 20 / Mailpit Sprint 27), gestion `.env` / `sys_superadmins`, `ModuleManager`.
- **Hors périmètre :** Fonctionnalité complète de « Reset Usine » à la demande depuis la console (préparée par le service interne mais exposée ultérieurement).
- **Critères de sortie :** Parcours complet désactivation démo → création 1er Superadmin + URL personnalisée → confirmation lien e-mail (Mailpit) → purge BDD/réglages + suppression fichiers modules `Test*` (avec `BoutiqueApi` préservé), PHPStan niveau 5, nouvelle suite `29-sprint28-demo-offboarding.spec.ts` verte.

#### Sprint 29 : Gouvernance des Comptes — Superadmins Secondaires, Président & Membres du Bureau *(À faire)*
- **Objectif métier :** Doter le Superadmin et le Président d'interfaces dédiées et cloisonnées pour administrer la chaîne de gouvernance des comptes (Superadmins secondaires, Président, membres du Bureau).
- **Périmètre macro (User Stories) :**
  - `US-LOT8-05` — **Gestion des Superadmins secondaires & Création du Président (Espace Superadmin) :** Page dédiée au Superadmin pour créer, modifier et supprimer des comptes Superadmin secondaires (avec garde-fou interdisant la suppression du dernier Superadmin actif) et créer le compte **Président** du club.
  - `US-LOT8-06` — **Gestion des Comptes Membres du Bureau (Espace Président) :** Page dédiée au Président pour créer, modifier et supprimer les comptes des membres du Bureau (Secrétaire, Trésorier, Entraîneur, Rédacteur, ou tout rôle non-Superadmin) en respectant strictement la matrice RBAC.
- **Dépendances :** Sprint 28, tables `sys_superadmins`, `users`, `roles`.
- **Hors périmètre :** Création/édition de comptes Superadmin par le Président (`403` strict), gestion des fiches sportives adhérents (déjà couverte par `/members`).
- **Critères de sortie :** CRUD complet Superadmins secondaires + création Président côté Superadmin, CRUD complet membres du Bureau côté Président, gardes RBAC stricts (`403`), PHPStan niveau 5, nouvelle suite `30-sprint29-accounts-governance.spec.ts` verte.

---

### 2.7. Lot 9 — Gestion des Pages du Footer (Builder Simplifié & Visibilité) (V2.6)

**Vision du Lot :** Permettre au Président d'administrer de façon autonome et ergonomique l'ensemble des pages du footer (Mentions légales, RGPD, CGV, Horaires et Tarifs, etc.) depuis une interface unifiée par onglets avec builder simplifié et contrôle d'activation par page.

#### Sprint 30 : Éditeur des Pages du Footer (Onglets, Builder Simplifié, Désactivation & Horaires/Tarifs) *(À faire)*
- **Objectif métier :** Centraliser l'édition et la visibilité des pages du footer pour le Président tout en offrant un mode simplifié sans composants lourds et une option dynamique pour « Horaires et Tarifs ».
- **Périmètre macro (User Stories) :**
  - `US-LOT9-01` — **Page dédiée d'administration & Navigation par onglets (Président) :** Créer une page dédiée réservée au Président regroupant l'ensemble des pages du footer avec une navigation fluide par onglets entre chacune d'elles.
  - `US-LOT9-02` — **Builder de contenu simplifié :** Permettre la modification de chaque page du footer via un builder par blocs simplifié (titres, textes, encadrés, listes, citations, images simples — **sans bloc vidéo et sans carrousel**).
  - `US-LOT9-03` — **Activation / Désactivation par page :** Permettre au Président de désactiver individuellement chaque page du footer ; dès désactivation, son lien disparaît de **toutes les vues** (footer et navigation) et son URL devient inaccessible (`404`).
  - `US-LOT9-04` — **Option Disciplines & Grades sur « Horaires et Tarifs » :** Ajouter dans l'onglet de configuration de la page « Horaires et Tarifs » une option permettant d'activer ou désactiver l'affichage automatique des disciplines et grades du club.
- **Dépendances :** Pages légales & `/horaires` existantes (`PageRouter`, table `pages`, `disciplines`/`levels`).
- **Hors périmètre :** Création arbitraire de nouvelles routes CMS hors pages du footer, blocs vidéo ou carrousel dans cet éditeur.
- **Critères de sortie :** Édition complète par onglets, absence vérifiée des blocs vidéo/carrousel, masquage global + `404` sur page désactivée, toggle Disciplines/Grades opérationnel sur `/horaires`, PHPStan niveau 5, nouvelle suite `31-sprint30-footer-pages.spec.ts` verte.

---

### 2.8. Lot 10 — Multi-Corrections & Finitions Transverses (V2.7)

**Vision du Lot :** Corriger l'ensemble des irritants fonctionnels et incohérences d'interface relevés en recette manuelle sur les modules (Événements, Facturation), le formulaire d'inscription, les statistiques et la console Superadmin.

#### Sprint 31 : Correctifs Sidebar Événements, Templates Facturation, Formulaire d'Inscription Dynamique, Urgences Tatami & Console Superadmin *(À faire)*
- **Objectif métier :** Assurer la cohérence fonctionnelle et ergonomique de bout en bout entre la Sidebar, le module Facturation, le formulaire d'inscription, les vues Statistiques et la Console Superadmin.
- **Périmètre macro (User Stories) :**
  - `US-LOT10-01` — **Affichage du lien « Événements » dans la Sidebar :** Corriger l'injection/affichage du lien du module `EvenementsApi` dans la barre latérale UI afin qu'il soit bien visible et cliquable dès que le module est actif.
  - `US-LOT10-02` — **Pages dédiées Templates E-mail & PDF dans Facturation + Envoi Auto fiable :** Séparer la configuration dans `FacturationUi` en **une page/vue dédiée au template d'e-mail** et **une page/vue dédiée au template PDF**, et vérifier que toute émission de facture génère le PDF et envoie automatiquement l'e-mail avec pièce jointe PDF et l'ensemble des renseignements exacts (validé en réel via Mailpit).
  - `US-LOT10-03` — **Gestion complète des champs du formulaire d'inscription (`/register`) :** Permettre au Président/Bureau d'ajouter, modifier, ordonner et supprimer des champs du formulaire d'inscription public, et de définir pour chaque champ s'il est **obligatoire ou facultatif** (avec rendu dynamique sur `/register` et validation côté serveur).
  - `US-LOT10-04` — **Renommage cohérent de « Liste d'Appel Tatami » :** Renommer l'onglet et les libellés de `/stats` affichant les contacts d'urgence des adhérents (ex. **« Contacts d'Urgence Tatami »**) afin d'éviter toute confusion avec le module « Présences & Suivi » (`PresenceApi`).
  - `US-LOT10-05` — **Épuration stricte de la Vue d'Ensemble Superadmin & Cloisonnement Modules :** Retirer de `/sys-console` tout contenu résiduel issu des autres onglets/pages (Branding, SMTP, Modules, Logs) pour ne conserver que la véritable vue d'ensemble synthétique, et retirer au Superadmin l'accès aux vues métier des modules (réservées au Bureau au maximum, sauf spécification explicite déclarée dans le manifeste du module).
- **Dépendances :** `EvenementsApi`, `FacturationApi`/`FacturationUi`, `/register`, `/stats`, `/sys-console`, `layout.php`, `ModuleManager`.
- **Hors périmètre :** Ajout de nouveaux modules métier, refonte graphique globale du thème.
- **Critères de sortie :** Lien `/evenements` visible en Sidebar, 2 vues distinctes Templates E-mail & PDF + envoi auto PDF vérifié dans Mailpit, champs dynamiques obligatoires/optionnels opérationnels sur `/register`, onglet renommé sans ambiguïté sur `/stats`, `/sys-console` épuré et accès modules Superadmin cloisonné (`403` sauf opt-in manifeste), PHPStan niveau 5, nouvelle suite `32-sprint31-multi-corrections.spec.ts` + non-régression complète vertes.

---

### 2.9. Règles Transverses de Pilotage (Applicable à Tous les Sprints)
1. **Non-régression stricte :** Aucune route API, classe CSS ou `data-testid` existant ne peut être supprimé ; les 324 tests E2E V2.3 et la matrice QA Sprint 27 doivent rester verts à chaque sprint (hors adaptations explicitement spécifiées par les Lots 8 à 10).
2. **Qualité obligatoire :** `npm run build:ts`, `npm run build:css`, `composer dump-autoload -o --strict-psr`, `vendor\bin\phpstan analyse` (niveau 5, 0 erreur) et `npm run test:e2e`.
3. **Isolation des tests :** Chaque sprint ajoute son propre fichier `tests/e2e/XX-sprintYY-*.spec.ts` avec réinitialisation/isolation stricte de la base de données.
