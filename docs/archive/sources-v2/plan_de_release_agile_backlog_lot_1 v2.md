# Plan de Release Agile et Backlog Détaillé (Lot 1)

**Projet :** CMS Associatif (Core)
**Phase :** Lot 1 - Fondations Techniques, Sécurité et CRM Core
**Méthodologie :** Scrum / Agile

---

## 1. Stratégie de Release (Vision Globale)

Afin de livrer de la valeur rapidement et de tester l'architecture technique, le projet est découpé en 3 Lots (Releases).

- **Lot 1 (MVP) : Fondations & CRM (Sprints 1 et 2)**
  - Mise en place de l'architecture MPA, API/UI, Base de données.
  - Système d'authentification étanche (Superadmin vs Club).
  - Moteur RBAC (Gestion des Rôles et Permissions).
  - CRM de base : Création des adhérents, isolation des tuteurs (Familles).
- **Lot 2 : Inscriptions & Communication (Sprints 3 et 4)**
  - Workflow d'inscription public et panier familial.
  - Module d'articles (CMS) et tags colorés.
  - Génération des pages statiques (RGPD, Mentions légales).
- **Lot 3 : Évolutivité & Automatisation (Sprint 5)**
  - Moteur de Modules (Scanner, EventManager, Hooks).
  - Moulinettes de fin de saison (catégories d'âge).
  - Tableaux de bord statistiques.

---

## 2. Definition of Done (DoD) - Critères de Terminaison Globaux

Pour qu'une User Story (US) soit considérée comme "Terminée" (Done) lors d'une revue de Sprint, elle doit obligatoirement valider l'ensemble de ces critères :

1.  **Architecture Respectée :** La logique métier est isolée dans l'API. Le Frontend interagit uniquement via des requêtes asynchrones (TS `fetch`).
2.  **Standard API (JSend) :** L'API retourne systématiquement un code HTTP `200 OK`, avec une enveloppe JSON contenant `status`, `code`, et `message`.
3.  **Sécurité Validée :**
    - Utilisation stricte de PDO avec requêtes préparées (Zéro concaténation SQL).
    - Échappement TS/PHP (prévention XSS) pour toute donnée affichée.
    - Token CSRF présent et validé pour toute mutation de donnée (POST/PATCH/DELETE).
4.  **UI/UX Conforme :** Les composants Tailwind CSS respectent le Design System. Les `alert()` sont proscrits et remplacés par le système de Toasts.
5.  **Tests QA :** Les cas de tests associés dans le _Master Test Plan_ (Excel) sont passés avec succès.
6.  **Code Review :** Le code a été relu et validé par un pair (ou validé via analyseur statique type PHPStan).

---

## 3. Backlog du Sprint 1 : Infrastructure & Sécurité (Durée : 2 Semaines)

**Objectif du Sprint :** Poser le socle technique, initialiser la base de données hybride, et garantir l'étanchéité absolue de l'authentification (Superadmin vs Utilisateurs).

### US 1.1 : Initialisation de l'Architecture et de la BDD

- **En tant que** Développeur Backend
- **Je veux** configurer l'architecture des dossiers (API / UI / Public) et la connexion PDO (SQLite/MySQL)
- **Afin de** disposer d'un socle technique fonctionnel pour l'ensemble du projet.
- **Story Points :** 5
- **Critères d'acceptation (CA) :**
  - CA1 : Le front-controller `public/api.php` route correctement une requête test.
  - CA2 : Le fichier `.env` permet de basculer de SQLite à MySQL.
  - CA3 : Les migrations des tables `sys_superadmins` et `users` s'exécutent sans erreur sur les deux moteurs.
  - CA4 : Routage `api.php` fonctionnel (HTTP 200)
  - CA5 : Migrations des tables initiales réussies (y compris `app_logs` et `email_templates`).

### US 1.2 : Shadow Login (Authentification Superadmin)

- **En tant que** Superadmin
- **Je veux** me connecter via une URL furtive configurée dans le fichier `.env`
- **Afin d'** accéder à la console système de manière isolée et invisible pour le public.
- **Story Points :** 8
- **Critères d'acceptation :**
  - CA1 : La route d'accès correspond à la variable d'environnement (ex: `SUPERADMIN_URL=/mon-secret`).
  - CA2 : Une tentative de connexion génère un Cookie de session sécurisé (`HttpOnly`, `Strict`).
  - CA3 : Un échec de connexion renvoie une erreur API sans indiquer si l'utilisateur existe.
  - CA4 : Le mot de passe est haché via `password_hash()` (Bcrypt/Argon2).
  - CA5 : Blocage des utilisateurs standards.

### US 1.3 : Intégration du Gabarit UI et Sidebar (MPA)

- **En tant qu'** Utilisateur connecté
- **Je veux** naviguer dans le back-office via une interface "Holy Grail Layout" (Header fixe, Sidebar fixe, Contenu scrollable)
- **Afin de** bénéficier d'une ergonomie d'application métier fluide sur PC et Mobile.
- **Story Points :** 5
- **Critères d'acceptation :**
  - CA1 : Intégration de Tailwind CSS opérationnelle (via CDN pour le dev, ou process de build).
  - CA2 : Sur Desktop, la sidebar latérale est fixe à gauche.
  - CA3 : Sur Mobile (< 1024px), la sidebar se transforme en tiroir (Drawer) ouvrant via un bouton hamburger.
  - CA4 : Un script TS global est en place pour générer les Toasts de notification (succès/erreur).
  - CA5 : Création des fichiers d'entrée physiques de l'architecture MPA (`login.php`, `dashboard.php`, et `sys-admin-login.php`) pour permettre le routage Web et inclure l'en-tête HTML global (dont la balise meta contenant le token CSRF).

### US 1.4 : Authentification Standard et Déconnexion

- **En tant qu'** Adhérent ou Admin (Membre du club)
- **Je veux** me connecter sur la page publique `/login` et me déconnecter
- **Afin d'** accéder à mon espace sécurisé sans interférer avec la table Superadmin.
- **Story Points :** 5
- **Critères d'acceptation :**
  - CA1 : Saisie d'email et mot de passe vérifiée exclusivement sur la table `users`.
  - CA2 : Impossibilité totale de se connecter sur `/login` avec un compte `sys_superadmins` (vérifier table et code erreur).
  - CA3 : Le clic sur "Déconnexion" détruit la session côté serveur et redirige vers `/`.
  - CA4 : Route API /logout fonctionnelle.
  - CA5 : Route API /me/password fonctionnelle.

---

## 4. Backlog du Sprint 2 : Cœur de Métier (RBAC & CRM Famille) (Durée : 2 Semaines)

**Objectif du Sprint :** Permettre au club de configurer ses rôles sur mesure, de créer des adhérents et de gérer la complexité des regroupements familiaux.

### US 2.1 : Moteur de Permissions (Middleware RBAC)

- **En tant que** Président (Admin)
- **Je veux** créer des rôles personnalisés (ex: "Entraîneur", "Secrétaire") et leur affecter des permissions spécifiques
- **Afin de** contrôler finement l'accès à l'API et aux vues du CMS.
- **Story Points :** 8
- **Critères d'acceptation :**
  - CA1 : Interface UI listant les rôles existants avec cases à cocher pour les permissions (stockées en JSON).
  - CA2 : L'API intègre un Middleware. Si une route exige la permission `members.edit`, tout appel sans cette permission renvoie `{ "code": 403, "message": "Accès refusé" }`.
  - CA3 : La vue Frontend masque les onglets de la Sidebar auxquels l'utilisateur n'a pas accès selon le contexte `GET /api/v1/auth/me`.

### US 2.2 : Fiche Adhérent (CRM) et Filtres TS

- **En tant que** Secrétaire
- **Je veux** lister et ajouter manuellement des adhérents (Table `members`)
- **Afin d'** alimenter l'annuaire du club.
- **Story Points :** 8
- **Critères d'acceptation :**
  - CA1 : Création d'une fiche avec Nom, Prénom, Date de Naissance, Contact Urgence.
  - CA2 : Affichage d'un tableau HTML listant les membres.
  - CA3 : Une barre de recherche utilise TypeScript pour masquer (`display: none`) instantanément les lignes du tableau ne correspondant pas à la frappe (Recherche côté client).
  - CA4 : La création d'un membre avec un e-mail déclenche la génération d'un `otp_code` _et l'envoi de l'e-mail d'activation (basé sur le template `account_created`), permettant au membre de définir son premier mot de passe._

### US 2.3 : Gestion des Familles (Liaison Pivot)

- **En tant que** Secrétaire
- **Je veux** lier la fiche d'un enfant mineur à un ou deux comptes utilisateurs (Tuteurs Légaux)
- **Afin de** regrouper les fratries et séparer les données parentales.
- **Story Points :** 13 (Complexité Élevée)
- **Critères d'acceptation :**
  - CA1 : L'UI permet d'associer la fiche `members` d'un mineur au compte `users` de "Maman" et/ou "Papa" via la table `member_tutor`.
  - CA2 : Dans l'annuaire CRM (Tableau), un clic sur un mineur déploie une ligne d'accordéon (TS) listant les coordonnées de ses tuteurs rattachés.
  - CA3 (RGPD) : L'API `/api/v1/members/:id` masque le nœud JSON des coordonnées du Tuteur B lorsque le Tuteur A fait la requête, si le champ `info_shared` est à `FALSE`.

### US 2.4 : Référentiel des Disciplines et Grades

- **En tant que** Président
- **Je veux** définir les sports (ex: Judo), les catégories d'âge associées, et l'échelle des grades
- **Afin de** structurer les pratiques du club pour le futur pointage.
- **Story Points :** 5
- **Critères d'acceptation :**
  - CA1 : CRUD API pour les tables `disciplines`, `age_categories`, et `grades`.
  - CA2 : UI permettant d'ajouter une discipline et ses grades associés (avec un champ "Ordre de tri" pour le classement).
