# Spécifications Fonctionnelles & Cahier d'Exigences Métier (SPECS-FINITION-V1.md)

**Projet :** Kanso-ya CMS Associatif (Core & Extensibilité)  
**Rôle :** @po (Product Owner / Spécifications Fonctionnelles & Recette Utilisateur)  
**Version :** 1.0 (Phase de Finition & Clôture Production — Sprints 7 à 10)  
**Date :** 17 novembre 2026  
**Statut :** Document de Référence Métier & Recette Fonctionnelle  

---

## 1. Introduction, Vision Produit & Périmètre

Ce document formalise les spécifications fonctionnelles détaillées issues des **35 retours de recette manuelle** capitalisés après les campagnes initiales. Il structure la trajectoire d'industrialisation du CMS Kanso-ya en 4 sprints opérationnels :

1. **Sprint 7 (Achevé & Validé) :** Socle, Ergonomie Header, Navigation sans `.php`, Auth 2FA SVG 30s, Thème dynamique & Backup BDD.
2. **Sprint 8 (Prêt à l'Exécution) :** Inscriptions intelligentes, Remises paramétrables (% ou fixe), Questionnaire de santé Cerfa & RGPD santé.
3. **Sprint 9 (Planifié) :** CRM avancé, Studio CMS traçable (`created_by`/`updated_by`), Reçus de cotisation/CSE, Exports et tris.
4. **Sprint 10 (Planifié) :** Découpage de la console Superadmin, Hook SDK `stats_tabs`, Templates de mails, Accessibilité WCAG AA & Pack SEO/Production.

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
5. **Règle S8-05 (Résilience du Formulaire / Brouillon Local) :**
   - Les étapes franchies du formulaire d'inscription sont persistées dans `localStorage.getItem('kanso_reg_draft')`.
   - En cas de fermeture inopinée du navigateur ou de rechargement de page, un bandeau informatif propose : *"Un brouillon d'inscription en cours a été détecté. Souhaitez-vous le restaurer ?"* avec boutons `Restaurer` et `Effacer`.
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
- `#btn-restore-draft` / `data-testid="btn-restore-draft"` : Bouton de restauration du brouillon local.
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
| **16** | Wizard | **S8** | Sauvegarde locale du brouillon dans `localStorage` | `#btn-restore-draft` |
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
