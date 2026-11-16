# Modèle Conceptuel de Données (MCD) - CMS Associatif

Ce document définit la structure de la base de données du noyau (Core) du CMS.
Il est conçu pour être compatible à la fois avec SQLite et MySQL (utilisation des types standards `INT`, `VARCHAR`, `TEXT`, `DATETIME`, `BOOLEAN`). Les structures complexes sont stockées en `TEXT` (format JSON).

---

## 1. Sécurité Système et Configuration (Superadmin & Admin)

Ces tables gèrent le cœur de la plateforme. La table `sys_superadmins` est totalement isolée des utilisateurs du club pour garantir l'étanchéité de l'administration technique.

| Table                 | Colonne                 | Type     | Description / Clé                                                      |
| :-------------------- | :---------------------- | :------- | :--------------------------------------------------------------------- |
| **`sys_superadmins`** | `id`                    | INT      | PK - Identifiant unique                                                |
|                       | `username`              | VARCHAR  | Identifiant de connexion                                               |
|                       | `email`                 | VARCHAR  | Email de notification technique                                        |
|                       | `password_hash`         | VARCHAR  | Mot de passe chiffré                                                   |
|                       | `failed_login_attempts` | INT      | Compteur d'échecs consécutifs (Anti Brute-Force)                       |
|                       | `locked_until`          | DATETIME | Verrouillage temporaire du compte en cas d'abus                        |
|                       | `created_at`            | DATETIME | Horodatage de création                                                 |
| **`sys_logs`**        | `id`                    | INT      | PK - Trace technique (Superadmin)                                      |
|                       | `superadmin_id`         | INT      | FK - Auteur de l'action                                                |
|                       | `action`                | TEXT     | Description de l'action technique                                      |
|                       | `ip_address`            | VARCHAR  | IP de connexion                                                        |
|                       | `created_at`            | DATETIME | Horodatage                                                             |
| **`sys_settings`**    | `setting_key`           | VARCHAR  | PK - Ex: `season_type`, `theme_color`                                  |
|                       | `setting_value`         | TEXT     | Valeur du paramètre                                                    |
|                       | `setting_group`         | VARCHAR  | Ex: `SYSTEM`, `BRANDING`, `CLUB`                                       |
|                       | `editable_by_admin`     | BOOLEAN  | `TRUE` = Modifiable par le Président. `FALSE` = Superadmin uniquement. |

_Note sur la saison : La clé `season_type` (civile/scolaire) déterminera le calcul automatique des catégories d'âge lors du déclenchement de la moulinette de fin de saison._

---

## 2. Utilisateurs et Matrice des Droits (RBAC)

Gestion des identités numériques et des accès à l'application.

| Table       | Colonne                 | Type     | Description / Clé                                |
| :---------- | :---------------------- | :------- | :----------------------------------------------- |
| **`roles`** | `id`                    | INT      | PK - Identifiant du rôle                         |
|             | `name`                  | VARCHAR  | Nom (Président, Secrétaire, etc.)                |
|             | `is_system`             | BOOLEAN  | Si TRUE, rôle non supprimable                    |
|             | `permissions`           | TEXT     | JSON des droits (ex: `["article.write"]`)        |
| **`users`** | `id`                    | INT      | PK - Compte de connexion                         |
|             | `email`                 | VARCHAR  | Email (identifiant)                              |
|             | `password_hash`         | VARCHAR  | Mot de passe chiffré                             |
|             | `role_id`               | INT      | FK -> `roles.id`                                 |
|             | `otp_code`              | VARCHAR  | Code pour onboarding / reset 2FA                 |
|             | `otp_expires`           | DATETIME | Expiration de l'OTP                              |
|             | `is_active`             | BOOLEAN  | Statut du compte                                 |
|             | `failed_login_attempts` | INT      | Compteur d'échecs consécutifs (Anti Brute-Force) |
|             | `locked_until`          | DATETIME | Verrouillage temporaire du compte en cas d'abus  |
|             | `created_at`            | DATETIME | Horodatage                                       |

---

## 3. Paramétrage Sportif (Disciplines, Âges, Grades)

Référentiel des sports, âges et niveaux du club.

| Table                | Colonne         | Type    | Description / Clé             |
| :------------------- | :-------------- | :------ | :---------------------------- |
| **`disciplines`**    | `id`            | INT     | PK - Ex: "Judo", "Natation"   |
|                      | `name`          | VARCHAR | Nom de la discipline          |
| **`age_categories`** | `id`            | INT     | PK - Ex: "U12", "Sénior"      |
|                      | `discipline_id` | INT     | FK -> `disciplines.id`        |
|                      | `name`          | VARCHAR | Nom de la catégorie           |
|                      | `min_age`       | INT     | Âge minimum                   |
|                      | `max_age`       | INT     | Âge maximum                   |
| **`grades`**         | `id`            | INT     | PK - Ex: "Ceinture Blanche"   |
|                      | `discipline_id` | INT     | FK -> `disciplines.id`        |
|                      | `name`          | VARCHAR | Nom du niveau/grade           |
|                      | `rank_order`    | INT     | Ordre de tri pour l'affichage |

---

## 4. CRM Associatif (Fiches, Familles et Pratiques)

Gère les adhérents (sportifs), leurs pratiques et la complexité des familles.

| Table                  | Colonne              | Type    | Description / Clé                                                                   |
| :--------------------- | :------------------- | :------ | :---------------------------------------------------------------------------------- |
| **`members`**          | `id`                 | INT     | PK - Fiche du pratiquant                                                            |
|                        | `user_id`            | INT     | FK -> `users.id` (NULL si mineur sans compte)                                       |
|                        | `first_name`         | VARCHAR | Prénom                                                                              |
|                        | `last_name`          | VARCHAR | Nom                                                                                 |
|                        | `birth_date`         | DATE    | Sert au calcul automatique de catégorie                                             |
|                        | `medical_file`       | VARCHAR | Chemin vers le certificat (upload sécurisé)                                         |
|                        | `contact_urgence`    | TEXT    | JSON `{nom, tel, lien}`                                                             |
|                        | `status`             | VARCHAR | `pending`, `active`, `inactive`                                                     |
| **`member_tutor`**     | `id`                 | INT     | PK - Table pivot (Famille)                                                          |
|                        | `member_id`          | INT     | FK -> `members.id` (L'enfant/pratiquant)                                            |
|                        | `tutor_id`           | INT     | FK -> `users.id` (Le compte du parent)                                              |
|                        | `info_shared`        | BOOLEAN | Accord de partage des données entre tuteurs                                         |
| **`member_practices`** | `id`                 | INT     | PK - Table pivot (Sports)                                                           |
|                        | `member_id`          | INT     | FK -> `members.id`                                                                  |
|                        | `discipline_id`      | INT     | FK -> `disciplines.id`                                                              |
|                        | `age_category_id`    | INT     | FK -> `age_categories.id` (Calculé par le système)                                  |
|                        | `is_category_forced` | BOOLEAN | TRUE si "surclassement" (Ignore la moulinette)                                      |
|                        | `grade_id`           | INT     | FK -> `grades.id` (Attribué manuellement)                                           |
|                        | `saeson`             | VARCHAR | Saison sportive liée à cette pratique (ex: "2026-2027") pour conserver l'historique |
|                        | `registration_id`    | INT     | FK -> `registrations.id` (Lié à la facture, NULL le temps de la validation)         |

---

## 5. Finances et Inscriptions

Gestion du "Panier" d'adhésion familial.

| Table               | Colonne          | Type     | Description / Clé                                                           |
| :------------------ | :--------------- | :------- | :-------------------------------------------------------------------------- |
| **`registrations`** | `id`             | INT      | PK - Dossier d'inscription                                                  |
|                     | `payer_user_id`  | INT      | FK -> `users.id` (Le parent ou l'adulte qui paie)                           |
|                     | `total_amount`   | DECIMAL  | Tarif global du dossier                                                     |
|                     | `payment_method` | VARCHAR  | Ex: Chèque, Espèces, Virement                                               |
|                     | `installments`   | INT      | Nombre de versements (ex: 3x sans frais)                                    |
|                     | `status`         | VARCHAR  | `pending`, `validated`, `cancelled`                                         |
|                     | `validated_by`   | INT      | FK -> `users.id` (Admin/Trésorier ayant validé)                             |
|                     | `created_at`     | DATETIME | Horodatage                                                                  |
|                     | `season`         | VARCHAR  | Saison sportive comptable (ex: "2026-2027") essentielle pour les dashboards |

---

## 6. Éditorial et Communication (CMS)

| Table                    | Colonne        | Type     | Description / Clé                                                                   |
| :----------------------- | :------------- | :------- | :---------------------------------------------------------------------------------- |
| **`pages`**              | `id`           | INT      | PK - Pages institutionnelles (CGV, Accueil)                                         |
|                          | `slug`         | VARCHAR  | URL de la page                                                                      |
|                          | `title`        | VARCHAR  | Titre                                                                               |
|                          | `content`      | TEXT     | HTML contenu                                                                        |
|                          | `status`       | VARCHAR  | `draft` (défaut), `published` (Permet de préparer une page sans la rendre publique) |
| **`article_categories`** | `id`           | INT      | PK - Tags/Catégories                                                                |
|                          | `name`         | VARCHAR  | Ex: "Compétition", "Interne"                                                        |
|                          | `color_tag`    | VARCHAR  | Classe Tailwind (ex: `bg-blue-500`)                                                 |
| **`articles`**           | `id`           | INT      | PK - Actualités                                                                     |
|                          | `author_id`    | INT      | FK -> `users.id`                                                                    |
|                          | `category_id`  | INT      | FK -> `article_categories.id`                                                       |
|                          | `title`        | VARCHAR  | Titre                                                                               |
|                          | `content`      | TEXT     | HTML de l'article                                                                   |
|                          | `status`       | VARCHAR  | `draft`, `published`                                                                |
|                          | `visibility`   | VARCHAR  | `public`, `members_only`, `bureau_only`                                             |
|                          | `published_at` | DATETIME | Date de publication visible                                                         |
| **`email_templates`**    | `id`           | INT      | PK - Identifiant unique                                                             |
|                          | `type_code`    | VARCHAR  | Code unique (ex: `welcome_otp`, `password_reset`)                                   |
|                          | `subject`      | VARCHAR  | Objet de l'e-mail                                                                   |
|                          | `body_html`    | TEXT     | Corps de l'e-mail contenant les variables `{{var}}`                                 |
|                          | `updated_at`   | DATETIME | Date de dernière modification                                                       |

---

## 7. Audit (Logs Applicatifs) et Modules

| Table          | Colonne       | Type     | Description / Clé                                                            |
| :------------- | :------------ | :------- | :--------------------------------------------------------------------------- |
| **`app_logs`** | `id`          | INT      | PK - Journal de la vie du club                                               |
|                | `user_id`     | INT      | FK -> `users.id` (Admin ayant fait l'action)                                 |
|                | `action`      | VARCHAR  | Ex: `MEMBER_VALIDATED`                                                       |
|                | `target_type` | VARCHAR  | Ex: `member`, `article`                                                      |
|                | `target_id`   | INT      | ID de l'entité concernée                                                     |
|                | `created_at`  | DATETIME | Horodatage                                                                   |
| **`modules`**  | `id`          | INT      | PK - Scanner d'extensions                                                    |
|                | `folder_name` | VARCHAR  | Nom du dossier dans `/modules`                                               |
|                | `version`     | VARCHAR  | Version du module                                                            |
|                | `is_active`   | BOOLEAN  | TRUE si activé par le Superadmin                                             |
|                | `status`      | VARCHAR  | Statut d'exécution : `active`, `inactive`, `crashed` (Safe Mode automatique) |
|                | `last_error`  | TEXT     | Trace de l'exception PHP si le module est passé en statut `crashed`          |
