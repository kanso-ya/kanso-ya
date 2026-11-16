# Spécifications de l'API REST (Core - v1)

Ce document est le contrat d'interface (API Contract) entre le Frontend (UI / TypeScript) et le Backend (PHP). Il liste de manière exhaustive les routes du noyau (Core) du CMS.

## 1. Standards et Conventions

### 1.1. URL Base et Routage

Toutes les routes API du noyau sont préfixées par `/api/v1/`.
Les futurs modules auront leurs propres préfixes (ex: `/api/v1/modules/boutique/`).

### 1.2. Format de Réponse (Enveloppe Standard JSend modifiée)

Le serveur renvoie toujours un statut HTTP `200 OK` (sauf crash critique du serveur 500 hors try/catch). La gestion des erreurs métier et de sécurité est gérée dans le corps JSON pour faciliter la vie du frontend.

```json
{
  "success": true,
  "code": 200, // 200, 201 (Création), 400 (Bad Request), 401 (Auth), 403 (Droits), 404, 422 (Validation)
  "message": "Texte lisible",
  "data": {} // Objet, Tableau, ou null si erreur
}
```

### 1.3. Sécurité et En-têtes (Headers)

- **Authentification :** Gérée via un Cookie de session (`HttpOnly`, `Secure`). Pas de Bearer token JWT exposé dans le JS.
- **CSRF :** Un header `X-CSRF-Token` est obligatoire pour toutes les requêtes de mutation (`POST`, `PUT`, `PATCH`, `DELETE`).
  _Mécanisme d'échange (Architecture) :_ Le backend PHP génère le token lors de la session et l'injecte dans le DOM de chaque page MPA via une balise `<meta name="csrf-token" content="...">`. Le script frontend TypeScript (`api.ts`) est chargé de lire cette balise et de l'attacher automatiquement aux en-têtes des requêtes fetch.

### 1.4. Pagination et Filtrage (Standard)

Pour les routes retournant des listes (ex: `/members`), l'API accepte les Query Parameters suivants :

- `?page=1&limit=50` (Pagination)
- `?sort=last_name&order=asc` (Tri)
- `?filter[status]=active` (Filtrage exact)
- `?search=dupont` (Recherche texte)

---

## 2. Domaine Système (Zone Superadmin)

_Toutes ces routes nécessitent une authentification sur la table `sys_superadmins`._

- **`POST /api/v1/sys/auth/login`**
  - **Description :** Connexion Superadmin (Shadow Login).
  - **Payload :** `{ "username": "", "password": "" }`
- **`POST /api/v1/sys/auth/logout`**
  - **Description :** Déconnexion Superadmin.
- **`GET /api/v1/sys/settings`**
  - **Description :** Liste la configuration globale (SMTP, type de base, debug).
  - **Permissions :** Rôle club possédant le droit `settings.view` ou Superadmin.
- **`PATCH /api/v1/sys/settings`**
  - **Description :** Met à jour une ou plusieurs clés de configuration.
  - **Permissions :** Superadmin uniquement.
  - **Payload :** `[ { "key": "smtp_host", "value": "mail.serveur.com" } ]`
- **`GET /api/v1/sys/logs`**
  - **Description :** Récupère le journal d'audit technique (Pagination supportée).
  - **Permissions :** Superadmin uniquement (Shadow Login).
  - **Retour :** Tableau paginé des entrées de la table `sys_logs`.
- **`POST /api/v1/sys/modules/scan`**
  - **Description :** Force le CMS à scanner le dossier `/modules` et met à jour la BDD (table `modules`).
- **`PATCH /api/v1/sys/modules/:id/toggle`**
  - **Description :** Active (`is_active: true`) ou désactive un module. Exécute ses scripts d'installation/désinstallation si nécessaire.

---

## 3. Domaine Authentification & Utilisateurs (App)

- **`POST /api/v1/auth/login`**
  - **Description :** Connexion standard (Adhérents, Tuteurs, Bureau).
  - **Payload :** `{ "email": "", "password": "" }`
- **`POST /api/v1/auth/logout`**
  - **Description :** Détruit la session active côté serveur (invalidation du cookie `HttpOnly`).
  - **Permissions :** Requis pour tout utilisateur connecté (y compris Superadmin).
  - **Retour :** Code 200 `{"status": "success", "message": "Déconnexion réussie"}`.
- **`GET /api/v1/auth/me`**
  - **Description :** Renvoie le contexte de l'utilisateur connecté (ses infos, son rôle, le tableau de ses permissions, et la liste des membres liés s'il est tuteur).
- **`POST /api/v1/auth/password-request`**
  - **Description :** Demande de réinitialisation. Génère un OTP et envoie un e-mail (Template : `reset_pwd`).
  - **Payload :** `{ "email": "" }`
- **`POST /api/v1/auth/password-reset`**
  - **Description :** Valide l'OTP et change le mot de passe.
  - **Payload :** `{ "email": "", "otp_code": "", "new_password": "" }`
- **`PATCH /api/v1/users/me/password`**
  - **Description :** Changement de mot de passe par l'utilisateur connecté.
  - **Permissions :** Utilisateur connecté.
  - **Payload :** `{ "current_password": "", "new_password": "" }`
  - **Retour :** Code 200 (Succès) ou 422 (Ancien mot de passe incorrect / Règles de complexité non respectées).

---

## 4. Domaine Référentiel Club & Droits (Admin)

_Ces routes nécessitent des permissions spécifiques, ex: `settings.manage` ou `roles.manage`._

### Rôles (RBAC)

- **`GET /api/v1/roles`** : Liste les rôles.
- **`POST /api/v1/roles`** : Crée un rôle sur mesure (Payload: `{ "name": "", "permissions": ["..."] }`).
- **`PUT /api/v1/roles/:id`** : Modifie un rôle.
- **`DELETE /api/v1/roles/:id`** : Supprime un rôle (Erreur 403 si `is_system` est true ou s'il y a des utilisateurs attachés).

### Sports, Catégories et Grades

- **`GET /api/v1/disciplines`** : Liste toutes les disciplines avec leurs catégories d'âge et grades liés.
- **`POST /api/v1/disciplines`** / **`PUT`** / **`DELETE`** : CRUD complet.
- **`POST /api/v1/disciplines/:id/age-categories`** : Ajoute une catégorie (Payload: `{ "name": "U12", "min_age": 10, "max_age": 11 }`).
- **`POST /api/v1/disciplines/:id/grades`** : Ajoute un grade (Payload: `{ "name": "Ceinture Verte", "rank_order": 3 }`).

---

## 5. Domaine CRM (Adhérents & Familles)

### Adhérents (Membres)

- **`GET /api/v1/members`**
  - **Permissions :** `members.view` (Bureau) ou accès limité à ses propres membres liés (Tuteur).
- **`GET /api/v1/members/:id`**
  - **Description :** Fiche complète avec informations d'urgence, tuteurs liés, et historiques de pratiques.
- **`GET /api/v1/members/:id/medical-file`**
  - **Description :** Téléchargement ou lecture du certificat médical de l'adhérent (permet de contourner le blocage Apache du dossier `private_uploads`).
  - **Permissions :** `members.view` (Bureau) ou restreint au compte du tuteur concerné.
  - **Action :** Le backend vérifie les droits RBAC, va lire le fichier binaire sur le disque et le renvoie avec le header `Content-Type` approprié.
- **`POST /api/v1/members`**
  - **Description :** Création manuelle d'une fiche par un Admin (hors flux de paiement).
- **`PUT /api/v1/members/:id`**
  - **Description :** Mise à jour d'une fiche.
- **`POST /api/v1/members/:id/medical-file`**
  - **Description :** Upload du certificat (Reçoit du `multipart/form-data`). Renvoie le nouveau nom du fichier sécurisé.
- **`PATCH /api/v1/members/:id/practices`**
  - **Description :** Affecte un membre à une discipline, force sa catégorie ou modifie son grade.
  - **Payload :** `{ "discipline_id": 1, "grade_id": 5, "is_category_forced": false, "age_category_id": 2 }`

### Liaisons Familiales

- **`POST /api/v1/members/:id/tutors`**
  - **Description :** Lie un compte utilisateur (`users.id`) comme tuteur d'un membre.
- **`PATCH /api/v1/members/:id/tutors/:tutor_id`**
  - **Description :** Modifie la liaison (ex: bascule `info_shared` à true/false).
- **`DELETE /api/v1/members/:id/tutors/:tutor_id`**
  - **Description :** Retire l'autorité parentale d'un compte sur une fiche.

---

## 6. Domaine Inscriptions & Finances

- **`POST /api/v1/registrations`** (La route clé du flux public/famille)
  - **Description :** Soumission d'un panier d'adhésion complet.
  - **Payload :** Objet complexe incluant les tuteurs à créer/lier, les membres, leurs pratiques, et le choix du paiement.
  - **Action :** Génère un dossier au statut `pending`.
- **`GET /api/v1/registrations`**
  - **Description :** Liste les dossiers (Permissions: `finances.view` ou restreint au `payer_user_id` de l'utilisateur connecté).
- **`PATCH /api/v1/registrations/:id/status`**
  - **Description :** Validation manuelle par le bureau.
  - **Payload :** `{ "status": "validated" }`
  - **Action :** Bascule le statut, génère l'historique (`app_logs`), crée les comptes manquants et déclenche l'envoi de l'e-mail OTP.

---

## 7. Domaine CMS (Contenu & Emails)

### Pages, Articles et Catégories

- **`GET /api/v1/pages`** & **`GET /api/v1/pages/:slug`** : Lecture des pages.
- **`PUT /api/v1/pages/:id`** : Édition d'une page statique.
- **`GET /api/v1/article-categories`** : Liste les tags colorés.
- **`POST /api/v1/article-categories`** : CRUD des tags.
- **`GET /api/v1/articles`**
  - **Description :** Liste des articles. Si requête anonyme, le filtre `visibility = 'public'` est forcé par le backend.
- **`POST /api/v1/articles`**
  - **Description :** Crée un article. Le `status` par défaut est forcé à `draft` si l'utilisateur n'a pas la permission `articles.publish`.
- **`PUT /api/v1/articles/:id`** / **`DELETE /api/v1/articles/:id`** : Modification/Suppression.
- **`PATCH /api/v1/articles/:id/status`** : Publie ou dépublie un article (Permission `articles.publish` requise).
- **`POST /api/v1/articles/media`**
  - **Description :** Upload d'une image d'illustration pour le CMS. Stocké dans `/public/uploads/media/`.
  - **Permissions :** `articles.write`
  - **Action :** Le backend vérifie strictement le type MIME (JPEG, PNG, WEBP). Rejette tout exécutable (prévention XSS/Shell). Renvoie l'URL publique de l'image.

### Modèles d'e-mails (Templates)

- **`GET /api/v1/email-templates`**
  - **Description :** Liste les modèles modifiables par le Bureau.
- **`PUT /api/v1/email-templates/:id`**
  - **Description :** Met à jour l'objet et le corps (HTML) d'un type d'e-mail. (Payload: `{ "subject": "", "body": "HTML..." }`).

---

## 8. Tâches d'Administration (Moulinettes)

- **`POST /api/v1/tasks/rollover-season`**
  - **Permissions :** `system.admin` (Président).
  - **Description :** Recalcule l'âge de tous les `members` selon le paramétrage `season_type` et met à jour `member_practices.age_category_id` (sauf si `is_category_forced` = true).
  - **Retour :** Nombre de fiches impactées.

- **`GET /api/v1/app-logs`**
  - **Description :** Récupère l'historique des actions métier du club.
  - **Permissions :** Rôle club possédant le droit `logs.view` (ex: Président).
  - **Retour :** Tableau paginé des entrées de la table `app_logs`.
