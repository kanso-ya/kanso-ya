# Spécifications Fonctionnelles & Cahier d'Exigences Métier (SPECS-FINITION-V1.md)

**Projet :** Kanso-ya CMS Associatif (Core & Extensibilité)  
**Rôle :** @po (Product Owner / Spécifications Fonctionnelles & Recette Utilisateur)  
**Version :** 1.1 (Phase de Finition v1.0, Correctifs Post-Recette & Finitions UX/Polissage v1.1 — Sprints 7 à 14)  
**Date :** 18 novembre 2026  
**Statut :** Document de Référence Métier & Recette Fonctionnelle  

---

## 1. Introduction, Vision Produit & Périmètre

Ce document formalise les spécifications fonctionnelles détaillées des phases de finition du CMS Kanso-ya :
- Les **35 retours initiaux** de recette manuelle (Sprints 7 à 10 — ✅ Validés) ;
- Les **17 correctifs post-recette** (Blocs 1 à 3 — ✅ Validés) ;
- Les **21 nouveaux points de finition UX & Polissage v1.1** découpés en **Sprints 12, 13 et 14** (Section 8) :
  - **Sprint 12 :** Langage humain non-technique, Toasts obligatoires sur les erreurs de validation, Thème dynamique CSS réel (`--brand-*`), Rollover harmonisé & Mode Démo/Production.
  - **Sprint 13 :** Mot de passe oublié par e-mail + code à usage unique avec double saisie, 2FA multi-méthode (QR Code 30s, Clé manuelle, Code e-mail), Inscription vierge / `?discipline=ID` & Réinscription intégralement pré-remplie.
  - **Sprint 14 :** Tableaux compacts sans débordement avec icônes d'action sur 2 lignes (`/members`, `/registrations`), Onglets fonctionnels (`/stats`, `/sys-smtp`, tableaux multi-disciplines) & verrouillage E2E v1.1.

---

## 2. Sprint 7 (Achevé & Validé) : Socle, Navigation, Auth, Thème & Backup BDD

### 2.1. Périmètre & Points de Recette Couverts
- **Points 1 & 2 :** Header épuré et Session Visiteur par défaut.
- **Point 3 :** Sauvegarde et restauration SQLite pour Playwright.
- **Point 4 :** Plan d'URLs propres sans extension `.php`.
- **Points 5 & 6 :** Refonte de la page `/login` et redirection post-connexion unifiée.
- **Points 10, 11, 15 & 35 :** 2FA TOTP QR Code SVG 30s, Toggle clé secrète, règles mot de passe et Multi-Superadmin.
- **Points 29 & 30 :** Thème dynamique global `--brand-primary` et `--brand-accent`.

### 2.2. Règles Métier & Logique Fonctionnelle
1. **Règle S7-01 (Statut Visiteur par défaut) :**
   - Tout utilisateur arrivant sur l'application sans cookie de session actif est strictement un `Visiteur` anonyme (`user_id = 0`, `role_name = 'Visiteur'`).
   - Aucune session Président ou rôle pré-authentifié n'est injectée automatiquement.
2. **Règle S7-02 (Épuration du Header) :**
   - Dans le bandeau supérieur (`#app-header`), le badge de rôle (`#active-role-pill`) et le bouton "Espace Membre" sont définitivement supprimés.
   - Si l'utilisateur est connecté, le bandeau affiche uniquement `Prénom Nom` en haut à droite, cliquable vers son profil (`/dashboard`).
   - Si l'utilisateur n'est pas connecté, la zone supérieure droite reste totalement vide. Le bouton de connexion se situe exclusivement dans la sidebar latérale.
3. **Règle S7-03 (URLs Propres) :**
   - Toutes les URLs publiques et privées sont servies sans l'extension technique `.php` (`/login`, `/register`, `/dashboard`, etc.).
   - Le serveur Apache réécrit les requêtes via `.htaccess` tout en conservant les fichiers PHP sous-jacents.
4. **Règle S7-04 (Redirection Post-Connexion) :**
   - La soumission réussie du formulaire `/login` redirige immédiatement vers l'accueil public `/` pour tous les rôles du club (Président, Trésorier, Entraîneur, Adhérent).
   - Seul le Superadmin s'authentifiant via la route furtive `/sys-admin-xyz` est redirigé vers sa console dédiée `/sys-console`.
5. **Règle S7-05 (2FA TOTP QR Code Vectoriel 30s) :**
   - Le QR Code est généré côté client en pur SVG vectoriel (TypeScript natif).
   - Un compte à rebours décrémente de 30s à 0s. À expiration, un nouveau code secret et QR Code sont régénérés sans rechargement de page.
   - Un bouton `#btn-toggle-secret` permet d'afficher ou masquer la clé secrète textuelle sous le QR Code.
6. **Règle S7-06 (Complexité Mot de Passe & Modale) :**
   - Les règles de sécurité (8+ caractères, 1 majuscule, 1 minuscule, 1 chiffre, 1 caractère spécial) sont affichées dans un encadré explicite avant les champs de saisie.
   - Toute modification de mot de passe déclenche une modale de confirmation avant envoi au serveur.
7. **Règle S7-07 (Multi-Superadmin) :**
   - Le Superadmin peut créer des comptes Superadmin secondaires (`POST /api/v1/sys/superadmins`) stockés dans la table `sys_superadmins`.

### 2.3. Cas Passants & Non-Passants
| ID Test | Scénario | Données en Entrée | Résultat Attendu |
| :--- | :--- | :--- | :--- |
| **TC-S7-01** | Visiteur sur page d'accueil | Aucune session | Header droit vide, Sidebar propose "Connexion" |
| **TC-S7-02** | Connexion utilisateur club | Identifiants valides | Redirection vers `/`, Header affiche "Prénom Nom" |
| **TC-S7-03** | Navigation URLs propres | Clic sur `/register` | Page servie sans extension `.php` |
| **TC-S7-04** | Régénération TOTP | Attente 30 secondes | QR Code SVG et clé renouvelés automatiquement |
| **TC-S7-05** | Mot de passe faible | "123456" | Blocage avec Toast explicite des critères manquants |
| **TC-S7-06** | Création Superadmin 2 | Identifiants valides | Compte créé dans `sys_superadmins` avec traçabilité |

### 2.4. Attributs `data-testid` & Sélecteurs UI
- `#app-header` : Bandeau supérieur.
- `#header-user-display` / `data-testid="header-user-display"` : Libellé "Prénom Nom" de l'utilisateur connecté.
- `#btn-sidebar-login` / `data-testid="sidebar-login-btn"` : Bouton de connexion dans la sidebar pour visiteur.
- `#totp-qr-svg` / `data-testid="totp-qr-svg"` : Élément SVG du QR Code 2FA.
- `#totp-timer` / `data-testid="totp-timer"` : Compteur décrémental 30 secondes.
- `#btn-toggle-secret` / `data-testid="btn-toggle-secret"` : Bouton afficher/masquer la clé secrète.
- `#password-requirements-card` / `data-testid="pwd-requirements-card"` : Encadré des critères de mot de passe.
- `#btn-create-secondary-admin` / `data-testid="btn-create-secondary-admin"` : Bouton d'ajout d'un Superadmin.

---

## 3. Sprint 8 (Prêt à l'Exécution) : Inscriptions Avancées, Remises Paramétrables & Santé

### 3.1. Périmètre & Points de Recette Couverts
- **Point 7 :** Paramétrage dynamique des remises de cotisation dans `sys_settings`.
- **Point 8 :** Calcul temps réel de la remise et transparence tarifaire dans le tunnel d'inscription.
- **Point 9 :** Paramétrage du mode médical (`medical_cert_mode`).
- **Point 12 :** Page publique dédiée [`/questionnaire-sante`](file:///c:/laragon/www/kanso-ya/public/health-check.php) avec auto-évaluation Cerfa réglementaire.
- **Point 13 :** Cloisonnement RGPD absolu des données médicales.
- **Point 14 :** Contacts d'urgence enrichis (numéro secondaire, lien de parenté).
- **Point 16 :** Sauvegarde locale du brouillon d'inscription (`localStorage`).
- **Point 17 :** Écran de pré-soumission avec modale de confirmation tarifaire et légale.

### 3.2. Règles Métier & Logique Fonctionnelle
1. **Règle S8-01 (Moteur de Remise de Cotisation Paramétrable) :**
   - La table `sys_settings` stocke 3 paramètres de remise :
     - `discount_threshold` (défaut : `2`) : nombre minimal d'adhérents dans le dossier pour déclencher la remise.
     - `discount_type` (défaut : `'percent'`) : `'percent'` (pourcentage appliqué au total) ou `'fixed'` (montant forfaitaire en euros déduit à partir du Nième adhérent).
     - `discount_value` (défaut : `10.0`) : valeur de la remise (ex: 10% ou 15€).
   - Formule de calcul du panier :
     - Si `nb_membres < discount_threshold` : $\text{Total} = \sum \text{cotisations}$.
     - Si `discount_type == 'percent'` : $\text{Total} = (\sum \text{cotisations}) \times (1 - \frac{\text{discount\_value}}{100})$.
     - Si `discount_type == 'fixed'` : $\text{Total} = (\sum \text{cotisations}) - ((\text{nb\_membres} - \text{discount\_threshold} + 1) \times \text{discount\_value})$.
2. **Règle S8-02 (Option Certificat Médical vs Questionnaire Cerfa) :**
   - Le paramètre `medical_cert_mode` dans `sys_settings` accepte 3 valeurs :
     - `'mandatory'` : Upload d'un certificat médical obligatoire dès l'inscription.
     - `'optional'` : Upload facultatif, pouvant être fourni ultérieurement.
     - `'health_questionnaire'` : Redirection ou complétion de l'auto-évaluation en ligne sur `/questionnaire-sante`.
3. **Règle S8-03 (RGPD Santé & Questionnaire Cerfa QS-Sport) :**
   - La page `/questionnaire-sante` présente les questions officielles du questionnaire de santé Cerfa :
     - Questionnaire Mineur (rempli conjointement avec le représentant légal).
     - Questionnaire Majeur (auto-évaluation adulte).
   - **Règle d'or de confidentialité :** Aucune réponse aux questions médicales individuelles n'est transmise ni stockée en base de données.
   - Si toutes les réponses sont négatives ("NON") : le pratiquant ou tuteur coche l'attestation sur l'honneur. Le système enregistre uniquement : `health_attestation_signed = 1` et `health_attestation_date = CURRENT_TIMESTAMP`.
   - Si au moins une réponse est positive ("OUI") : l'adhérent est averti qu'un certificat médical de non-contre-indication délivré par un médecin est obligatoire. Le système bascule le dossier en attente de certificat papier ou uploadé.
4. **Règle S8-04 (Contacts d'Urgence Approfondis) :**
   - Pour chaque pratiquant inscrit, le formulaire impose :
     - Nom et prénom du contact principal ;
     - Téléphone principal ;
     - Lien de parenté (Père, Mère, Conjoint, Autre) ;
     - Téléphone secondaire facultatif.
5. **Règle S8-05 (Résilience & Restauration Automatique Silencieuse du Brouillon) :**
   - Toutes les étapes et données saisies dans le formulaire d'inscription sont persistées en temps réel dans `localStorage.getItem('kanso_reg_draft')` à chaque modification.
   - Lors d'un retour depuis la page `/questionnaire-sante` ou lors d'un rafraîchissement/fermeture accidentelle de la page, la restauration s'opère de manière **100% automatique et transparente** sans nécessiter aucun clic sur un bouton manuel.
   - Le tunnel réhydrate l'ensemble des champs (responsable, adhérents, disciplines, contacts) et positionne immédiatement l'utilisateur sur la première étape incomplète.
6. **Règle S8-06 (Modale de Pré-Soumission) :**
   - Avant envoi final au serveur (`POST /api/v1/registrations`), une modale récapitule :
     - Les adhérents déclarés avec disciplines et tarifs ;
     - Le détail de la remise appliquée ;
     - Le mode de paiement et l'échéancier (1x, 2x, 3x) ;
     - Les cases à cocher obligatoires d'acceptation du règlement intérieur et de la politique RGPD.

### 3.3. Maquette Fonctionnelle & Attributs `data-testid`
```text
+------------------------------------------------------------------------------------+
|  INSCRIRE MA FAMILLE — RÉCAPITULATIF & RÈGLEMENT                                   |
+------------------------------------------------------------------------------------+
|  Composition du Panier (3 membres) :                                               |
|  - Lucas Martin (Judo - Poussin) ........................................ 150.00 € |
|  - Léo Martin (Judo - Benjamin) ......................................... 150.00 € |
|  - Chloé Martin (Judo + Karaté) ......................................... 150.00 € |
|                                                                                    |
|  Sous-total : 450.00 €                                                             |
|  [Remise Famille Nombreuse (-10% dès 2 inscrits)] ........................ -45.00 €|
|  TOTAL À RÉGLER : 405.00 €                                                         |
|                                                                                    |
|  Santé & Aptitude :                                                                |
|  (o) Attestation Cerfa QS-Sport validée (Tous "NON")                               |
|  ( ) Certificat médical à téléverser                                               |
|                                                                                    |
|  [ Bouton : Vérifier et Confirmer l'Adhésion ]                                     |
+------------------------------------------------------------------------------------+
```
- `#reg-discount-badge` / `data-testid="reg-discount-badge"` : Badge affichant la remise calculée.
- `#reg-discount-amount` / `data-testid="reg-discount-amount"` : Montant déduit en euros.
- `#health-mode-selector` / `data-testid="health-mode-selector"` : Sélecteur Attestation Cerfa / Certificat.
- `#cerfa-minor-form` / `data-testid="cerfa-minor-form"` : Questionnaire santé mineurs.
- `#cerfa-adult-form` / `data-testid="cerfa-adult-form"` : Questionnaire santé adultes.
- `#reg-draft-auto-restored-indicator` / `data-testid="reg-draft-auto-restored-indicator"` : Indicateur visuel discret de restauration automatique du brouillon.
- `#pre-submit-modal` / `data-testid="pre-submit-modal"` : Modale de confirmation récapitulative.
- `#btn-confirm-registration` / `data-testid="btn-confirm-registration"` : Validation définitive.

---

## 4. Sprint 9 (Planifié) : CRM, Studio CMS Traçable, Exports & Reçus

### 4.1. Périmètre & Points de Recette Couverts
- **Points 18 & 19 :** Traçabilité éditoriale (`created_by`, `updated_by`) dans la table `articles` et affichage Studio CMS.
- **Point 20 :** Reçus d'adhésion et attestations employeur/CSE générés et téléchargeables.
- **Point 21 :** Export CSV/Excel des effectifs CRM avec filtrage selon les autorisations du rôle.
- **Points 22 & 23 :** Filtres combinés CRM (âge, statut médical, paiement) et historique multi-saisons.
- **Point 24 :** Actions groupées sur l'annuaire CRM (relance OTP multiple, export ciblé).
- **Point 25 :** Gestionnaire de médiathèque CMS et suppression des fichiers orphelins.
- **Point 26 :** Tri interactif multi-colonnes persistant sur la gestion des cotisations (`registrations`).

### 4.2. Règles Métier & Logique Fonctionnelle
1. **Règle S9-01 (Traçabilité Éditoriale Double) :**
   - Chaque article consigne `created_by` (ID de l'auteur original, immuable) et `updated_by` (ID du dernier utilisateur ayant modifié le contenu ou changé le statut de publication).
   - Dans l'onglet "Publications" du Studio CMS (`cms.php`), chaque carte d'article affiche :
     - *"Rédigé par [Auteur] le [Date]"*
     - *"Dernière révision / Publication par [Éditeur] le [Date]"*
2. **Règle S9-02 (Reçus de Cotisation & Attestation CSE) :**
   - Dès qu'un dossier d'adhésion est validé (`status = 'validated'`), un reçu officiel horodaté est accessible :
     - Téléchargeable par le payeur/tuteur depuis son profil (`/dashboard`).
     - Téléchargeable par le Trésorier ou Secrétaire depuis la liste des cotisations (`/registrations`).
   - Le document contient le numéro d'agrément club, le cachet de l'association, la décomposition des montants par adhérent et la mention légale pour prise en charge CSE / CE / Pass'Sport.
3. **Règle S9-03 (Exportation Sécurisée des Données CRM) :**
   - L'export CSV/Excel généré respecte strictement la matrice de visibilité :
     - Pour le Président/Secrétaire : export intégral avec adresses, téléphones et contacts d'urgence.
     - Pour l'Entraîneur : export restreint aux noms, prénoms, catégories d'âge et ceintures (numéros de téléphone et adresses masqués selon le standard RGPD Kanso-ya).
4. **Règle S9-04 (Historique Multi-Saisons sur Fiche Adhérent) :**
   - Le déploiement de la fiche d'un adhérent (`members.php`) intègre un onglet ou volet *"Historique des saisons"* répertoriant les saisons antérieures, les disciplines pratiquées, les grades obtenus et les cotisations acquittées.

### 4.3. Attributs `data-testid` & Sélecteurs UI
- `#article-author-info` / `data-testid="article-author-info"` : Mention de l'auteur d'origine.
- `#article-editor-info` / `data-testid="article-editor-info"` : Mention du dernier éditeur/relecteur.
- `#btn-download-receipt` / `data-testid="btn-download-receipt"` : Bouton de téléchargement du reçu d'adhésion.
- `#btn-export-crm-csv` / `data-testid="btn-export-crm-csv"` : Bouton d'export CSV des adhérents filtrés.
- `#crm-filter-age-band` / `data-testid="crm-filter-age-band"` : Menu déroulant de filtre par tranche d'âge.
- `#crm-filter-medical-status` / `data-testid="crm-filter-medical-status"` : Filtre de statut médical.
- `#btn-bulk-resend-otp` / `data-testid="btn-bulk-resend-otp"` : Action groupée de renvoi OTP.
- `#table-registrations-th-sort` / `data-testid="sort-column-header"` : En-têtes cliquables de tri multi-colonnes.

---

## 5. Sprint 10 (Planifié) : Console Découpée, SDK & Clôture Production

### 5.1. Périmètre & Points de Recette Couverts
- **Point 27 :** Découpage de la console Superadmin en sous-pages dédiées (`/sys-branding`, `/sys-smtp`, `/sys-modules`, `/sys-logs`).
- **Point 28 :** Extension du hook `stats_tabs` dans l'`EventManager` et `stats.php`.
- **Point 31 :** Gestionnaire de templates d'e-mails transactionnels personnalisables avec balises dynamiques.
- **Point 32 :** Page dynamique institutionnelle [`/horaires`](file:///c:/laragon/www/kanso-ya/public/schedule.php) administrable dans le CMS.
- **Point 33 :** Audit d'accessibilité (a11y) WCAG 2.1 niveau AA (contrastes, focus traps, navigation clavier, ARIA).
- **Point 34 :** Pack de clôture de production (SEO OpenGraph, Favicon dynamique, `robots.txt`, `sitemap.xml`).

### 5.2. Règles Métier & Logique Fonctionnelle
1. **Règle S10-01 (Découpage de la Console Superadmin) :**
   - La page unique `/sys-console` est subdivisée en 4 sous-vues claires et compartimentées :
     - `/sys-branding` : Gestion du nom du site, logo à cheval, filigrane et couleurs Tailwind.
     - `/sys-smtp` : Configuration des identifiants SMTP, port, chiffrement TLS/SSL et simulateur de test d'envoi.
     - `/sys-modules` : Scanner de répertoire, activation/désactivation et monitoring des alertes Safe Mode.
     - `/sys-logs` : Consultation et filtrage des logs d'audit système et d'e-mails.
2. **Règle S10-02 (Hook Analytique Modulaire `stats_tabs`) :**
   - La page [`/stats`](file:///c:/laragon/www/kanso-ya/public/stats.php) appelle le filtre `applyFilters('stats_tabs', $defaultTabs, $context)`.
   - Tout module actif (ex: Boutique) peut déclarer un nouvel onglet avec son propre callback de rendu :
     ```php
     $eventManager->addFilter('stats_tabs', function(array $tabs) {
         $tabs[] = [
             'id' => 'boutique_sales',
             'title' => 'Ventes Boutique Club',
             'icon' => 'shopping-bag',
             'render_endpoint' => '/api/v1/boutique/stats'
         ];
         return $tabs;
     });
     ```
3. **Règle S10-03 (Éditeur de Modèles d'E-mails) :**
   - Le bureau associatif (Président/Secrétaire) dispose d'une interface pour personnaliser les messages automatiques envoyés par le club.
   - Balises dynamiques supportées et remplacées à l'envoi : `{{prenom}}`, `{{nom}}`, `{{nom_club}}`, `{{montant_cotisation}}`, `{{lien_otp}}`, `{{saison}}`.
4. **Règle S10-04 (Conformité Accessibilité a11y WCAG 2.1 AA) :**
   - Ratio de contraste minimal de 4.5:1 sur tous les textes et boutons.
   - Toutes les modales interceptent et confinent le focus clavier (`focus trap`). L'appui sur `Echap` ferme la modale.
   - Les boutons d'icônes disposent obligatoirement d'un attribut `aria-label` explicite.
5. **Règle S10-05 (Pack SEO & Intégrité Web) :**
   - Balises méta OpenGraph (`og:title`, `og:description`, `og:image`) synchronisées avec le branding du club.
   - Génération dynamique de `robots.txt` bloquant les chemins privés (`/sys-*`, `/storage/`, `/api/`) et autorisant les pages publiques (`/`, `/register`, `/horaires`, `/rgpd`, etc.).

### 5.3. Attributs `data-testid` & Sélecteurs UI
- `#sys-nav-branding` / `data-testid="sys-nav-branding"` : Lien vers la page Branding.
- `#sys-nav-smtp` / `data-testid="sys-nav-smtp"` : Lien vers la page SMTP.
- `#sys-nav-modules` / `data-testid="sys-nav-modules"` : Lien vers la page Modules.
- `#sys-nav-logs` / `data-testid="sys-nav-logs"` : Lien vers la page Logs.
- `#stats-custom-tab-boutique` / `data-testid="stats-custom-tab"` : Onglet de statistiques injecté par module.
- `#email-template-editor` / `data-testid="email-template-editor"` : Zone d'édition du modèle d'e-mail.
- `#schedule-table-view` / `data-testid="schedule-table-view"` : Tableau interactif des cours sur `/horaires`.

---

## 6. Matrice Récapitulative des 35 Points de Recette

| Point | Domaine | Sprint | Description & Exigence Fonctionnelle | Attribut Clé / Route API |
| :---: | :--- | :---: | :--- | :--- |
| **1** | Auth | **S7** | Suppression session Président par défaut (Visiteur strict) | `$_SESSION['user_id'] = 0` |
| **2** | UI/UX | **S7** | Épuration du Header (suppression rôle/bouton membre, Prénom Nom seul) | `#header-user-display` |
| **3** | QA | **S7** | Backup et restauration SQLite bit-à-bit Playwright | `tests/setup/global-setup.ts` |
| **4** | Routage | **S7** | Plan complet d'URLs propres sans `.php` | Public `.htaccess` / `/login` |
| **5** | UI/UX | **S7** | Refonte visuelle de `/login` aux couleurs dynamiques | `#form-login-card` |
| **6** | Auth | **S7** | Redirection post-connexion unifiée vers `/` (et `/sys-console`) | `core/ui/ts/login.ts` |
| **7** | Finances | **S8** | Paramétrage des remises de cotisation (% ou montant fixe) | `PATCH /api/v1/settings` |
| **8** | Wizard | **S8** | Calcul transparent de la remise en direct sur le panier | `#reg-discount-badge` |
| **9** | Santé | **S8** | Paramétrage du mode médical (`medical_cert_mode`) | `sys_settings.medical_cert_mode` |
| **10** | Sécurité | **S7** | 2FA TOTP QR Code SVG vectoriel 30s régénéré | `#totp-qr-svg`, `#totp-timer` |
| **11** | Sécurité | **S7** | Bouton toggle afficher/masquer la clé secrète textuelle | `#btn-toggle-secret` |
| **12** | Santé | **S8** | Page publique `/questionnaire-sante` (Cerfa mineurs/majeurs) | `/questionnaire-sante` |
| **13** | RGPD | **S8** | Cloisonnement données santé (seule l'attestation est stockée) | `health_attestation_signed` |
| **14** | Inscriptions| **S8** | Contacts d'urgence enrichis (tél secondaire, parenté) | `#reg-emerg-phone-secondary` |
| **15** | Sécurité | **S7** | Bloc explicite des règles de mot de passe & modale confirmation | `#password-requirements-card` |
| **16** | Wizard | **S8** | Sauvegarde & restauration automatique silencieuse du brouillon | `localStorage`, saut étape auto |
| **17** | Inscriptions| **S8** | Modale récapitulative et consentements pré-soumission | `#pre-submit-modal` |
| **18** | CMS | **S9** | Traçabilité `created_by` et `updated_by` sur la table `articles` | `articles.created_by` |
| **19** | CMS | **S9** | Affichage auteur original et dernier éditeur dans Studio CMS | `#article-editor-info` |
| **20** | Finances | **S9** | Génération de reçus d'adhésion et attestations employeur/CSE | `#btn-download-receipt` |
| **21** | CRM | **S9** | Export CSV/Excel avec filtrage selon permissions du rôle | `#btn-export-crm-csv` |
| **22** | CRM | **S9** | Filtres combinés annuaire (âge, certificat médical, paiement) | `#crm-filter-age-band` |
| **23** | CRM | **S9** | Historique des saisons et cotisations antérieures sur fiche | `#member-history-tab` |
| **24** | CRM | **S9** | Barre d'actions groupées (relance OTP groupée, export) | `#btn-bulk-resend-otp` |
| **25** | CMS | **S9** | Médiathèque CMS avec nettoyage des médias orphelins | `#btn-purge-unused-media` |
| **26** | Finances | **S9** | Tri multi-colonnes persistant sur la liste des cotisations | `#sort-column-header` |
| **27** | Système | **S10** | Découpage sous-pages console (`/sys-branding`, `/sys-smtp`, etc.) | `/sys-branding`, `/sys-smtp` |
| **28** | SDK | **S10** | Hook `stats_tabs` pour injecter des onglets analytiques | `applyFilters('stats_tabs')` |
| **29** | Thème | **S7** | Application globale de la couleur primaire `--brand-primary` | `.btn-primary`, liens actifs |
| **30** | Thème | **S7** | Application globale de la couleur accent `--brand-accent` | Badges, liens inscription |
| **31** | Messagerie | **S10** | Templates d'e-mails transactionnels personnalisables | `#email-template-editor` |
| **32** | Contenu | **S10** | Page dynamique institutionnelle administrable `/horaires` | `/horaires`, `#schedule-table` |
| **33** | Accessibilité| **S10**| Conformité WCAG 2.1 niveau AA, Focus traps et ARIA | `aria-label`, `.focus-trap` |
| **34** | SEO/Prod | **S10**| Métadonnées OpenGraph, Favicon dynamique, robots.txt | `<meta property="og:image">` |
| **35** | Système | **S7** | Multi-Superadmin (`sys_superadmins`, `POST /api/v1/sys/superadmins`) | `POST /api/v1/sys/superadmins` |

---

## 7. Spécifications Détaillées des 17 Correctifs Post-Recette (Blocs 1, 2 et 3)

### 7.1. Bloc 1 : Console Superadmin, Sécurité & Thème (Points 1 à 4)

#### Point 1 : Interdiction Stricte de `/cms` pour le Rôle Superadmin
- **Exigence Métier :** Le compte Superadmin assure exclusivement l'administration technique, l'intégrité de la BDD, le monitoring et les réglages système. Il n'a aucun droit de regard, d'écriture ou de publication sur la vie éditoriale du club (`/cms`).
- **Comportement Attendu :**
  - Navigation directe vers `/cms` : refus immédiat avec code HTTP 200 JSend 403 (`Droits insuffisants : l'administration technique n'a pas accès au CMS`) et redirection ou affichage d'un écran d'accès refusé.
  - Sidebar : le lien "Articles & Pages" est masqué dès lors que le persona actif est Superadmin.
  - Endpoints d'API `/api/v1/articles*` : rejet 403 systématique si la requête émane d'une session ou d'un token Superadmin.

#### Point 2 : Gestion du Mot de Passe Superadmin & Comptes Secondaires
- **Exigence Métier :** Offrir au Superadmin en place la possibilité de modifier son mot de passe technique et de créer de façon robuste des Superadmins secondaires.
- **Règles & Endpoints :**
  - `PATCH /api/v1/sys/password` : vérifie `current_password`, valide la complexité (8+ caractères, majuscule, minuscule, chiffre, symbole) de `new_password`, hache avec `password_hash(PASSWORD_BCRYPT)` et met à jour `sys_superadmins`.
  - `POST /api/v1/sys/superadmins` : accepte obligatoirement `username`, `email` et `password`. Vérifie l'unicité du `username` et du format e-mail (rejet 409 si déjà existant). Insère le compte avec traçabilité dans `sys_logs`.

#### Point 3 : Vue d'Ensemble Synthétique, Tableau des Modules Réel & Audit
- **Exigence Métier :** La console `/sys-console` doit présenter un tableau de bord moderne et opérationnel :
  - **4 KPIs Synthétiques :**
    - `#kpi-active-modules` : Nombre de modules actifs vs installés (ex: `1 / 3`).
    - `#kpi-total-superadmins` : Nombre de comptes Superadmin enregistrés.
    - `#kpi-recent-logs` : Nombre d'événements consignés dans `sys_logs` sur les dernières 24h.
    - `#kpi-db-size` : Taille du fichier SQLite `kanso_ya.sqlite` en kilo-octets/méga-octets.
  - **Registre des Modules Réel :** Le tableau des modules doit refléter les modules effectivement présents dans `/modules/` (`Boutique`, `CrashTest`, `IncompatibleModule`). Un bouton `#btn-scan-modules` déclenche le scan et met à jour dynamiquement un compteur `#module-scan-counter`.
  - **Traces d'Audit Récents :** Affichage paginé des 10 dernières actions consignées dans `sys_logs` avec horodatage, action et adresse IP.

#### Point 4 : Application Universelle du Thème Dynamique
- **Exigence Métier :** Les variables CSS `--brand-primary` et `--brand-accent` définies dans les paramètres de branding doivent s'appliquer sur 100% des éléments interactifs :
  - Tous les boutons d'action principale (`.btn-primary`, validation, sauvegarde).
  - Les liens textuels actifs, bordures d'onglets sélectionnés, boutons d'ajout au panier.
  - Les badges de mise en avant et le fil d'Ariane du tunnel d'inscription.

---

### 7.2. Bloc 2 : Séparation Profil/Club, Inscription Résiliente & Ergonomie Tableaux (Points 5 à 11)

#### Point 5 : Séparation Stricte entre `/dashboard` et `/settings`
- **Exigence Métier :** Cloisonnement rigoureux entre l'espace individuel de l'adhérent et l'espace institutionnel de gestion du club :
  - **`/dashboard` ("Mon Profil") :** Vue réservée à l'adhérent / tuteur pour consulter ses données personnelles, gérer les fiches de ses enfants/ayants droit rattachés, téléverser les pièces médicales et télécharger ses attestations/reçus. **Retrait absolu** du Rollover, des paramètres du club et des journaux d'audit de cette page.
  - **`/settings` ("Paramètres du Club") :** Vue d'administration réservée au Président et Secrétaire (`settings.manage`). Regroupe les coordonnées du club, la gestion du Rollover de saison avec bouton sécurisé, les journaux d'audit de la vie du club (`app_logs`) et les configurations globales.

#### Point 6 : Capacité Théorique par Discipline dans `/settings`
- **Exigence Métier :** Permettre au Bureau de définir une capacité d'accueil maximale par discipline/cours via le paramètre `default_course_capacity` dans `sys_settings`.
- **Impact Analytique :** Cette valeur sert d'étalon de référence pour alimenter les jauges de remplissage et les taux d'occupation présentés sur `/stats` et sur la page publique `/horaires`.

#### Point 7 : Gestion et Édition des Pages Institutionnelles dans `/settings`
- **Exigence Métier :** Donner la main au Bureau associatif dans `/settings` pour modifier directement le contenu rédactionnel des pages fixes du pied de page :
  - `/horaires` : Présentation des créneaux et modalités d'accueil.
  - `/mentions-legales` : Informations juridiques de l'association (SIRET, RNA, siège social).
  - `/rgpd` : Politique de protection des données personnelles.
  - `/cgv` : Conditions générales de vente et d'adhésion.
- **Interface :** Formulaire avec sélection de la page via menu déroulant, éditeur de contenu et sauvegarde via `PUT /api/v1/pages/:slug`.

#### Point 8 : Sauvegarde & Restauration Automatique Silencieuse du Brouillon d'Inscription
- **Exigence Métier :** Éliminer tout risque de perte de données lors du tunnel d'inscription en ligne sans imposer de friction cognitive à l'utilisateur :
  - Sauvegarde continue et transparente dans `localStorage` à chaque modification de champ ou validation d'étape.
  - **Restauration 100% automatique et silencieuse :** au retour de la page `/questionnaire-sante` ou lors d'un rafraîchissement/fermeture intempestive, le tunnel réinjecte automatiquement les données sans afficher de bandeau préalable ni nécessiter de clic sur un bouton "Restaurer".
  - L'écran s'ouvre directement sur la **première étape incomplète** avec affichage d'un toast ou badge discret `#reg-draft-auto-restored-indicator` notifiant l'utilisateur que sa progression a été conservée.

#### Point 9 : Optimisation Pleine Largeur de l'Annuaire `/members`
- **Exigence Métier :** Maximiser la surface de visualisation des adhérents pour supprimer le défilement horizontal superflu :
  - Tableau prenant 100% de la largeur du conteneur (`w-full`).
  - Colonnes optimisées (Photo/Avatar, Nom & Prénom, Date de naissance / Catégorie, Disciplines & Ceintures, Tuteurs rattachés, Statut médical, Statut d'adhésion).
  - Actions compactes regroupées dans la dernière colonne sous forme de boutons icônes avec infobulles (Consulter, Modifier, Certificat, Renvoi OTP, Radier).

#### Point 10 : Trésorerie `/registrations` avec Boutons Icônes & Tooltips
- **Exigence Métier :** Remplacer les boutons textuels encombrants par une barre d'actions compacte et élégante :
  - Boutons icônes SVG :
    - `Inspecter` : icône œil (`data-testid="btn-inspect-registration"`).
    - `Valider le règlement` : icône coche verte (`data-testid="btn-validate-registration"`).
    - `Télécharger le Reçu` : icône reçu/document (`data-testid="btn-download-receipt"`).
    - `Télécharger l'Attestation` : icône diplôme/badge (`data-testid="btn-download-attestation"`).
    - `Radier / Restaurer` : icône poubelle/recyclage (`data-testid="btn-radiate-registration"`).
  - Chaque bouton dispose obligatoirement d'un attribut `title` et `aria-label` déclenchant une infobulle native claire au survol.

#### Point 11 : Nettoyage Ergonomique de `/disciplines` & `/roles`
- **Exigence Métier :**
  - **`/disciplines` :** Suppression définitive des parenthèses superflues autour des noms de disciplines dans les onglets. Les badges numériques indiquant le nombre d'adhérents inscrits deviennent des liens interactifs cliquables qui redirigent vers `/members` avec le filtre de discipline pré-activé.
  - **`/roles` :** Suppression définitive des cartes redondantes affichées sous le tableau de la matrice des permissions. Seule la matrice unique multi-colonnes avec colonnes figées (`sticky`) subsiste, évitant la duplication d'informations.

---

### 7.3. Bloc 3 : Studio CMS Révolutionné (Points 12 à 17)

#### Point 12 : Drag & Drop Universel dans le Builder CMS
- **Exigence Métier :** Expérience de mise en page moderne basée sur l'API HTML5 Drag and Drop :
  - **Palette vers Conteneur :** Les blocs de composants (Texte riche, Titre, Image/Galerie, Vidéo, Encadré d'appel) peuvent être glissés depuis la barre d'outils `#cms-component-palette` directement dans l'une des colonnes de l'éditeur.
  - **Réordonnancement Dynamique :** Tout bloc déjà inséré dans une colonne peut être déplacé par glisser-déposer au sein de la même colonne (intra-colonne) ou transféré vers l'autre colonne (inter-colonnes) avec un indicateur visuel de zone de largage (`drop-zone-active`).

#### Point 13 : Colonnes Asymétriques à Proportions Réglables Figées
- **Exigence Métier :** Le gabarit à 2 colonnes ne doit plus être limité à un ratio rigide 50/50 :
  - Un sélecteur de proportions visuel permet de choisir entre plusieurs configurations : 50/50, 70/30 (colonne principale + barre latérale) ou 30/70.
  - Le ratio sélectionné est encodé sous forme d'attribut de style en ligne sécurisé (ex: `style="flex: 0 0 70%;"` et `style="flex: 0 0 30%;"`).
  - L'assainisseur HTML backend (`CmsController::sanitizeHtml()`) préserve scrupuleusement ces propriétés CSS de mise en page pour garantir un rendu public strictement identique à la prévisualisation dans l'éditeur.

#### Point 14 : Lecteur Vidéo Léger Intégré
- **Exigence Métier :** Support direct des formats vidéo web (`video/mp4`, `video/webm`) jusqu'à une limite de 25 Mo via `POST /api/v1/articles/media`.
- **Fonctionnalités du Lecteur :**
  - Balise HTML5 `<video>` stylisée avec contrôles complets intégrés : Lecture (`play`), Pause, Arrêt (`stop`), Curseur de volume et barre de progression temporelle.
  - Téléchargement asynchrone des médias avec prévisualisation immédiate dans le bloc d'édition.
  - Préservation intégrale des balises `<video>` et `<source>` lors de la publication de l'article.

#### Point 15 : Composant Multimédia Polyvalent (Photo Unique vs Carrousel)
- **Exigence Métier :** Dans le Studio Builder, le bloc multimédia offre une bascule interactive entre deux modes d'affichage :
  - **Mode Photo Unique :** Affichage d'une image plein cadre avec légende et effet de zoom léger au survol.
  - **Mode Carrousel :** Permet d'associer plusieurs images au sein d'un même bloc avec choix du défilement :
    - Carrousel Horizontal fluide avec glissement tactile (`scroll-snap-x mandatory`, boutons précédent/suivant et indicateurs de pagination).
    - Carrousel Vertical compact pour formats de colonnes étroites.

#### Point 16 : Filtres Combinés Multi-Critères dans la Liste des Publications
- **Exigence Métier :** Faciliter la recherche et la gestion des contenus éditoriaux au sein de l'onglet Publications de `/cms` :
  - Trois sélecteurs de filtrage combinables en temps réel :
    - `#cms-filter-status` : Tous, Brouillons (`draft`), Publiés (`published`).
    - `#cms-filter-category` : Filtrage par catégorie thématique (Actualités, Stages, Compétitions, Vie du Club).
    - `#cms-filter-author` : Filtrage par auteur / rédacteur.
  - L'application des filtres met à jour le tableau instantanément côté client sans latence réseau.

#### Point 17 : Double Traçabilité Éditoriale Visible
- **Exigence Métier :** Transparence et responsabilité éditoriale totale dans la gestion des articles :
  - Chaque ligne d'article dans le tableau des publications affiche deux métadonnées distinctes :
    - **Auteur Initial :** Prénom et Nom de l'utilisateur ayant créé le brouillon (`created_by`), accompagné de la date et l'heure de création.
    - **Dernier Éditeur / Relecteur :** Prénom et Nom du dernier utilisateur ayant modifié le contenu ou changé le statut de publication (`updated_by`), accompagné du timestamp de révision.
  - Si l'article n'a jamais été modifié après sa création, la mention "Aucune modification ultérieure" est affichée sobrement.

---

## 8. Spécifications Détaillées — Finitions UX & Polissage v1.1 (Sprints 12, 13 et 14 — 21 Points)

### 8.1. Sprint 12 : Langage Humain, Toasts Universels, Thème Dynamique & Exploitation (Points 1 à 7)

#### Règle S12-01 — Langage Humain Non-Technique Partout dans l'Interface (Points 1 & 2)
- **Exigence Métier :** Aucune clé interne, nom de table SQL ou acronyme informatique brut ne doit apparaître dans un libellé visible par les adhérents, bénévoles du bureau ou administrateurs (titres, en-têtes de colonnes, badges, infobulles, messages d'erreur ou onglets). Les attributs techniques (`data-*`, `name`, valeurs d'API) restent inchangés côté code.
- **Table de correspondance obligatoire :**

| Jargon / Clé technique proscrit à l'affichage | Libellé humain obligatoire dans l'UI |
| :--- | :--- |
| `members.view` / `members.edit` | *Consulter l'annuaire* / *Gérer les fiches adhérents* |
| `finances.manage` | *Gérer les inscriptions et la trésorerie* |
| `articles.write` / `articles.publish` | *Rédiger des articles* / *Publier des articles* |
| `pages.manage` / `settings.manage` | *Gérer les pages du site* / *Paramétrer le club* |
| `roles.manage` / `stats.view` / `logs.view` | *Gérer les rôles et accès* / *Consulter les statistiques* / *Consulter le journal d'activité* |
| `rbac` / `Matrice RBAC` | *Rôles et permissions* / *Droits d'accès* |
| `app_logs` | *Journal d'activité du club* |
| `sys_logs` | *Journal d'audit technique* |
| `email_logs` | *Historique des e-mails envoyés* |
| `otp` / `Jeton OTP` / `Renvoi OTP` | *Code de sécurité à usage unique* / *Renvoyer le lien d'activation* |
| `totp` | *Application d'authentification* |
| `smtp` (hors titre technique Superadmin) | *Serveur d'envoi d'e-mails* |

#### Règle S12-02 — Toasts Obligatoires sur Toutes les Erreurs de Validation de Formulaire (Point 3)
- **Exigence Métier :** Tout échec de validation — qu'il survienne avant l'envoi (validation client : champ obligatoire vide, e-mail invalide, mot de passe trop faible, confirmation différente, aucune discipline choisie) ou en retour d'API (`status: "error"`) — **doit impérativement déclencher un Toast d'erreur explicite** (`showToast(message, 'error')`).
- **Comportement Attendu :**
  - Les attributs HTML natifs bloquants silencieux (`novalidate` géré en TS sur les formulaires pilotés par JS) ne doivent pas masquer le retour Toast.
  - Les champs en erreur reçoivent une mise en évidence visuelle (`aria-invalid="true"`, bordure rouge) tout en conservant les valeurs saisies par l'utilisateur.

#### Règle S12-03 — Thème Dynamique CSS Réel & États Interactifs (Points 4 & 7)
- **Exigence Métier :** Les couleurs configurées dans `/sys-branding` doivent recolorer instantanément et intégralement l'interface, sans résidu vert `emerald-*` figé.
- **Règles d'implémentation :**
  - Injection dans `<head>` des 5 variables CSS maîtresses : `--brand-primary`, `--brand-primary-hover`, `--brand-sidebar`, `--brand-header`, `--brand-accent`.
  - Remplacement des utilitaires figés (`bg-emerald-*`, `text-emerald-*`, `border-emerald-*`) par les classes dédiées (`.bg-brand-primary`, `.text-brand-primary`, `.border-brand-primary`) et règles CSS `!important` centralisées.
  - Conservation d'un contraste lisible (WCAG AA) sur tous les boutons primaires, états `:hover`, onglets actifs et indicateurs de focus.

#### Règle S12-04 — Harmonisation Rollover & Mode Démo / Production (Points 5 & 6)
- **Rollover :** Les appels UI depuis `/settings` et `/sys-console` ciblent `POST /api/v1/tasks/rollover-season`, tandis que `POST /api/v1/rollover` reste accepté comme alias officiel sur le backend.
- **Mode Démo / Production :** Sur `/sys-console`, la vue d'ensemble affiche une carte/badge d'état piloté par `sys_settings.demo_mode_enabled` (*Mode Démonstration* en ambre ou *Mode Production* en vert/marque) avec contrôle de bascule réservé au Superadmin.

#### Sélecteurs `data-testid` — Sprint 12
- `data-testid="toast-container"` / `data-testid="toast-error"` : Conteneur et notification Toast d'erreur de validation.
- `data-testid="kanso-dynamic-theme"` (`#kanso-dynamic-theme`) : Balise `<style>` injectant les variables `--brand-*`.
- `data-testid="sys-demo-mode-badge"` : Badge d'état Mode Démo / Production dans `/sys-console`.
- `data-testid="sys-demo-mode-toggle"` : Interrupteur de bascule `demo_mode_enabled` dans `/sys-console`.
- `data-testid="btn-trigger-rollover"` : Bouton de lancement du basculement de saison sur `/settings`.

---

### 8.2. Sprint 13 : Mot de Passe Oublié, 2FA Multi-Méthode & Inscription / Réinscription (Points 8 à 14)

#### Règle S13-01 — Parcours Complet "Mot de Passe Oublié" avec Double Saisie (Points 8 & 9)
- **Étape 1 — Demande de réinitialisation :**
  - Sur `/login`, un lien explicite *"Mot de passe oublié ?"* ouvre le formulaire dédié (modale ou vue `/forgot-password`).
  - L'utilisateur saisit son adresse e-mail et soumet vers `POST /api/v1/auth/forgot-password`.
  - **Anti-énumération stricte :** le message de retour (Toast + encadré d'information) est strictement identique que l'adresse existe ou non en base.
- **Étape 2 — Saisie du code à usage unique & Nouveau mot de passe avec double saisie :**
  - L'utilisateur saisit le code de sécurité reçu par e-mail, son nouveau mot de passe (affiché sous l'encadré pédagogique des règles de complexité) et une **seconde saisie de confirmation** (`confirm_password`).
  - Si les deux mots de passe ne concordent pas ou ne respectent pas les critères de sécurité, la soumission est bloquée avec un **Toast d'erreur explicite**.
  - En cas de succès (`POST /api/v1/auth/activate-otp`), le code est immédiatement invalidé (usage unique) et l'utilisateur est redirigé vers `/login` avec un Toast de confirmation.

#### Règle S13-02 — Double Authentification (2FA) au Choix : QR Code (30s), Clé Manuelle ou Code E-mail (Points 10 & 11)
- **Exigence Métier :** Dans l'onglet Sécurité du profil (`/dashboard`), tout utilisateur connecté peut choisir sa méthode de double authentification :
  1. **Application d'authentification (QR Code 30s) :** Affichage d'un QR Code SVG vectoriel renouvelé automatiquement toutes les 30 secondes avec compte à rebours visible.
  2. **Clé secrète manuelle :** Option d'affichage/masquage (`#btn-toggle-secret`) et bouton de copie rapide de la clé secrète textuelle pour saisie manuelle dans l'application d'authentification.
  3. **Code par E-mail :** Bouton permettant d'envoyer un code temporaire à 6 chiffres sur l'adresse e-mail du compte via `POST /api/v1/users/me/2fa/send-email-code`, puis validation pour activer la méthode `email` via `POST /api/v1/users/me/2fa`.
- **Défi à la connexion :** Lors d'une connexion ultérieure sur `/login` pour un compte protégé par 2FA, l'interface adapte le défi selon la méthode active (`totp` ou `email`, avec bouton *"Renvoyer un code par e-mail"* si méthode e-mail).

#### Règle S13-03 — Inscription Vierge par Défaut & Pré-Sélection via `?discipline=ID` (Points 12 & 13)
- **Absence de pré-sélection par défaut :** Lors d'un accès direct à `/register` sans paramètre d'URL, **aucune discipline n'est cochée par défaut**. Si l'utilisateur tente de passer à l'étape suivante sans avoir sélectionné au moins une discipline, un Toast d'erreur l'en informe clairement.
- **Pré-sélection depuis `/horaires` :** Chaque bloc discipline sur `/horaires` expose un bouton *"S'inscrire"* redirigeant vers `/register?discipline=<ID>`. Le tunnel d'inscription lit le paramètre `discipline` de l'URL et pré-coche exclusivement la discipline correspondante (si l'ID est valide et actif).

#### Règle S13-04 — Pré-Remplissage Intégral lors de la Réinscription (Point 14)
- **Exigence Métier :** Lorsqu'un adhérent ou tuteur connecté accède à `/register` (mode réinscription), le formulaire pré-remplit automatiquement l'intégralité des informations connues de la saison précédente afin de limiter la ressaisie :
  - Coordonnées et identité du ou des pratiquants rattachés ;
  - **Niveau technique actuel** (ceinture / grade technique en cours) ;
  - **Contact d'urgence complet** (nom, téléphone principal, téléphone secondaire, lien de parenté) ;
  - **Tuteurs légaux** (pour les pratiquants mineurs : Tuteur 1 et éventuel Co-tuteur 2 avec leurs coordonnées).
- Les champs restent intégralement modifiables avant soumission si une donnée a évolué (changement d'adresse, nouveau numéro d'urgence, etc.).

#### Sélecteurs `data-testid` — Sprint 13
- `data-testid="link-forgot-password"` : Lien *"Mot de passe oublié ?"* sur `/login`.
- `data-testid="forgot-password-form"` / `data-testid="forgot-email-input"` / `data-testid="btn-send-reset-code"` : Formulaire de demande de code.
- `data-testid="reset-password-form"` / `data-testid="reset-otp-input"` / `data-testid="reset-new-password"` / `data-testid="reset-confirm-password"` / `data-testid="btn-confirm-reset-password"` : Formulaire de réinitialisation avec double saisie.
- `data-testid="2fa-method-totp"` / `data-testid="2fa-method-email"` : Sélecteurs de méthode 2FA (Application vs E-mail).
- `data-testid="btn-copy-totp-secret"` : Bouton de copie de la clé secrète manuelle.
- `data-testid="btn-send-2fa-email-code"` / `data-testid="input-2fa-email-code"` : Envoi et saisie du code 2FA par e-mail.
- `data-testid="horaires-register-discipline-btn"` (avec `data-discipline-id`) : Bouton *"S'inscrire"* sur `/horaires` redirigeant vers `/register?discipline=ID`.
- `data-testid="reg-discipline-checkbox"` (avec `data-discipline-id`) : Cases de sélection de discipline sur `/register`.
- `data-testid="reg-reenroll-prefilled-banner"` : Indicateur discret confirmant le pré-remplissage des données de réinscription.

---

### 8.3. Sprint 14 : Tableaux Compacts, Actions sur 2 Lignes, Onglets & Verrouillage E2E v1.1 (Points 15 à 21)

#### Règle S14-01 — Colonnes Compactes Sans Élargir le Conteneur & Actions sur 2 Lignes (Points 15, 16 & 17)
- **Contrainte de mise en page stricte :** Les tableaux de gestion de `/members` et `/registrations` doivent tenir dans 100 % de la largeur disponible de `#main-workspace` sur écran Desktop (≥ 1280px) sans jamais forcer l'élargissement du conteneur parent ni imposer de défilement horizontal parasite (`table.scrollWidth <= container.clientWidth`).
- **Disposition des icônes d'action sur 2 lignes :**
  - Dans la colonne finale `Actions` de `/members` et `/registrations`, les boutons d'action icônes sont agencés en **grille compacte sur 2 lignes** (`grid grid-cols-3 gap-1 w-max`, ou `grid-cols-2` selon le nombre d'actions visibles pour le rôle).
  - Chaque bouton conserve une zone cliquable confortable (28×28 px minimum), une icône SVG lisible, ainsi que les attributs `title` et `aria-label` en français naturel.

#### Règle S14-02 — Onglets Fonctionnels sur `/stats`, `/sys-smtp` et Tableaux Multi-Disciplines (Points 18, 19 & 20)
- **Standard d'accessibilité et d'interaction des onglets :**
  - Chaque barre d'onglets utilise `role="tablist"`, chaque bouton `role="tab"` + `aria-selected="true|false"` + `aria-controls`, et chaque panneau `role="tabpanel"`.
  - L'onglet actif utilise les classes de thème dynamique (`.border-brand-primary`, `.text-brand-primary`).
- **Couverture par vue :**
  - **`/stats` :** Onglets fonctionnels (*Vue d'ensemble*, *Démographie*, *Occupation des cours*, *Finances* masqué pour Entraîneur/RGPD, plus les onglets dynamiques injectés via le hook `stats_tabs`).
  - **`/sys-smtp` :** Onglets fonctionnels (*Configuration du serveur*, *Modèles d'e-mails*, *Test d'envoi & Historique*).
  - **Tableaux multi-disciplines (`/disciplines`, `/horaires`, vues filtrées) :** Bascule fluide entre *"Toutes les disciplines"* et chaque discipline active avec compteurs d'effectifs synchronisés et affichage instantané des données filtrées.

#### Règle S14-03 — Verrouillage E2E v1.1 (Point 21)
- **Exigence Qualité :** Création d'une suite de tests Playwright dédiée (`tests/e2e/14-v11-ux-polish.spec.ts`) validant à 100 % sur Desktop Chrome et Mobile Chrome Pixel 5 les 21 points des Sprints 12, 13 et 14, sans aucune régression sur les 222 tests existants.

#### Sélecteurs `data-testid` — Sprint 14
- `data-testid="members-table"` / `data-testid="member-actions-grid"` : Tableau compact de l'annuaire et grille d'icônes d'action sur 2 lignes.
- `data-testid="registrations-table"` / `data-testid="registration-actions-grid"` : Tableau compact de trésorerie et grille d'icônes d'action sur 2 lignes.
- `data-testid="stats-tabs"` / `data-testid="stats-tab-overview"` / `data-testid="stats-tab-demographics"` / `data-testid="stats-tab-occupancy"` / `data-testid="stats-tab-finances"` : Barre et onglets de `/stats`.
- `data-testid="smtp-tabs"` / `data-testid="smtp-tab-config"` / `data-testid="smtp-tab-templates"` / `data-testid="smtp-tab-history"` : Barre et onglets de `/sys-smtp`.
- `data-testid="discipline-tabs"` / `data-testid="discipline-tab-item"` : Barre d'onglets standardisée des tableaux multi-disciplines.

---

### 8.4. Matrice Récapitulative des 21 Points de Finition v1.1 (Sprints 12 à 14)

| Point | Domaine | Sprint | Description & Exigence Fonctionnelle | Attribut Clé / Route API |
| :---: | :--- | :---: | :--- | :--- |
| **1** | UX / RBAC | **S12** | Langage humain non-technique sur les rôles & permissions (zéro clé `members.view`, `rbac`) | `/roles`, libellés français |
| **2** | UX / Libellés | **S12** | Langage humain sur journaux, messagerie & sécurité (zéro `app_logs`, `sys_logs`, `otp` brut) | `/settings`, `/sys-logs`, `/dashboard` |
| **3** | UX / Forms | **S12** | Toasts obligatoires sur toutes les erreurs de validation de formulaire | `data-testid="toast-error"` |
| **4** | Thème CSS | **S12** | Injection `--brand-*` dans `<head>` + classes `.bg-brand-primary` remplaçant `emerald-*` | `#kanso-dynamic-theme` |
| **5** | API / Saison | **S12** | Harmonisation endpoint Rollover (`/api/v1/tasks/rollover-season` + alias `/api/v1/rollover`) | `POST /api/v1/rollover` |
| **6** | Exploitation | **S12** | Suivi Mode Démo / Production (`demo_mode_enabled`) dans la vue d'ensemble Superadmin | `data-testid="sys-demo-mode-badge"` |
| **7** | UI / Contrastes | **S12** | Harmonisation `--brand-primary-hover`, focus rings et badges d'accentuation | `.border-brand-primary` |
| **8** | Auth | **S13** | Demande "Mot de passe oublié" par e-mail avec garantie anti-énumération | `POST /api/v1/auth/forgot-password` |
| **9** | Auth | **S13** | Réinitialisation par code temporaire avec double saisie de confirmation | `data-testid="reset-confirm-password"` |
| **10** | Sécurité 2FA | **S13** | 2FA Application : QR Code SVG (30s) + affichage/copie clé secrète manuelle | `data-testid="2fa-method-totp"` |
| **11** | Sécurité 2FA | **S13** | 2FA par Code E-mail (activation, envoi temporaire et défi connexion) | `POST /api/v1/users/me/2fa/send-email-code` |
| **12** | Inscription | **S13** | Aucune discipline pré-cochée par défaut sur `/register` | `data-testid="reg-discipline-checkbox"` |
| **13** | Inscription | **S13** | Pré-sélection contextuelle via `?discipline=ID` depuis `/horaires` | `/register?discipline=ID` |
| **14** | Réinscription | **S13** | Pré-remplissage intégral (niveau technique, contact d'urgence, tuteurs légaux) | `data-testid="reg-reenroll-prefilled-banner"` |
| **15** | Tableaux | **S14** | Colonnes compactes sur `/members` sans élargir le conteneur parent | `data-testid="members-table"` |
| **16** | Tableaux | **S14** | Grille d'icônes d'action sur 2 lignes dans `/members` | `data-testid="member-actions-grid"` |
| **17** | Trésorerie | **S14** | Tableau compact et grille d'icônes d'action sur 2 lignes dans `/registrations` | `data-testid="registration-actions-grid"` |
| **18** | Statistiques | **S14** | Onglets fonctionnels sur `/stats` avec respect strict RGPD Entraîneur | `data-testid="stats-tabs"` |
| **19** | Messagerie | **S14** | Onglets fonctionnels sur `/sys-smtp` (Configuration, Modèles, Test & Historique) | `data-testid="smtp-tabs"` |
| **20** | Multi-vues | **S14** | Onglets fonctionnels standardisés sur tous les tableaux multi-disciplines | `data-testid="discipline-tabs"` |
| **21** | QA / E2E | **S14** | Suite Playwright v1.1 Desktop & Mobile Pixel 5 (100 % non-régression) | `tests/e2e/14-v11-ux-polish.spec.ts` |
