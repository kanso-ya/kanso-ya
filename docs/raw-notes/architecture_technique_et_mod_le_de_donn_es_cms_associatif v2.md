# Cahier des Charges et Architecture Technique Détaillée (CMS Associatif)

Ce document de référence définit les spécifications fonctionnelles, l'architecture technique, et le modèle de données du CMS dédié aux clubs et associations.

## 1. Vision et Stack Technologique

* **Objectif :** Un CMS ultra-léger, sécurisé et modulaire pour la gestion administrative et la communication des associations.
* **Backend (Cœur) :** PHP natif avec PDO (abstraction de base de données). Approche orientée API.
* **Frontend (Logique) :** TypeScript natif (compilé en JS) pour sécuriser et typer les interactions côté client.
* **Interface (Style) :** Tailwind CSS.
* **Base de données :** Support hybride SQLite (pour les petites structures) ou MySQL/MariaDB (pour les grandes).

## 2. Architecture de Sécurité et Compartimentation

La priorité absolue est l'isolation des accès et la protection des données sensibles.

### 2.1. Accès Superadmin (Niveau Système)
* **Shadow Login (URL furtive) :** L'URL de connexion du Superadmin n'est pas standard. Elle est définie dans le fichier `.env` (ex: `/gestion-sys-xyz`).
* **Rejet strict :** La page de connexion classique des utilisateurs (`/login`) refuse systématiquement toute tentative de connexion avec des identifiants Superadmin.
* **Isolation des données :** Les Superadmins possèdent leur propre table en base de données (`sys_superadmins`), totalement décorrélée de la table des membres du club (`users`).

### 2.2. Gestion des Rôles "Club" (Niveau Applicatif - RBAC)
* **Création dynamique :** L'Admin (ex: Président) dispose d'une interface pour créer des rôles sur mesure (Trésorier, Secrétaire, Entraîneur) en cochant les droits d'accès spécifiques.
* **Sécurité des fichiers sensibles :** Les documents uploadés (comme les certificats médicaux) sont stockés dans un répertoire non accessible publiquement et délivrés via un script PHP vérifiant les autorisations.

## 3. Matrice des Droits et Habilitations

Les permissions sont vérifiées par un Middleware à chaque requête.

| Fonctionnalité / Domaine | Superadmin (Système) | Admin (Président) | Rôle Spécifique (ex: Trésorier) | Rédacteur / Coach | Adhérent | Visiteur | 
| ----- | ----- | ----- | ----- | ----- | ----- | ----- | 
| **Accès URL Login dédiée** | 🟢 Oui | 🔴 Non | 🔴 Non | 🔴 Non | 🔴 Non | 🔴 Non | 
| **Gestion des comptes Superadmin** | 🟢 Oui | 🔴 Non | 🔴 Non | 🔴 Non | 🔴 Non | 🔴 Non | 
| **Configuration CMS** | 🟢 Oui | 🔴 Non | 🔴 Non | 🔴 Non | 🔴 Non | 🔴 Non | 
| **Création de rôles personnalisés** | 🔴 Non | 🟢 Oui | 🔴 Non | 🔴 Non | 🔴 Non | 🔴 Non | 
| **Gestion globale des adhérents** | 🔴 Non | 🟢 Oui | 🟡 Selon droits | 🔴 Non | 🔴 Non | 🔴 Non | 
| **Création / Édition Articles** | 🔴 Non | 🟢 Oui | 🟡 Selon droits | 🟢 Oui | 🔴 Non | 🔴 Non | 
| **Lecture des articles publics** | 🟢 Oui | 🟢 Oui | 🟢 Oui | 🟢 Oui | 🟢 Oui | 🟢 Oui | 

## 4. Architecture Modulaire Stricte

L'évolutivité repose sur un découplage total pour éviter les régressions lors des mises à jour.

* **Règle d'or :** Aucun code d'une fonctionnalité additionnelle ne doit se trouver dans le dossier `core/`.
* **Scanner et Bootstrapping :** Le CMS scanne le dossier `modules/`. Chaque module possède un `manifest.json` et un fichier d'initialisation.
* **Système de Hooks (Événements) :** Le Core orchestre des événements (ex: `onUserLogin`). Les modules s'y abonnent pour injecter leur logique sans altérer le Core.

## 5. Arborescence du Projet (Séparation API / UI)

L'architecture abandonne le MVC classique "fourre-tout" au profit d'une séparation stricte entre la logique serveur (API) et le rendu/interface (UI).

```text
/racine-projet/
├── .env                        # Variables d'environnement
├── public/                     # Document Root (Seul dossier accessible publiquement)
│   ├── index.php               # Front-controller (Routeur principal)
│   ├── api.php                 # Point d'entrée pour les requêtes XHR/Fetch
│   └── assets/                 # Fichiers compilés (CSS, JS, images)
├── core/                       # Noyau du CMS (Fichiers protégés)
│   ├── api/                    # Logique Backend et traitement des données
│   │   ├── endpoints/          # Traitement des requêtes (ex: Auth, Users, Articles)
│   │   ├── middleware/         # Filtres de sécurité (RBAC)
│   │   ├── services/           # Logique métier, EventManager (Hooks), Scanner
│   │   └── database/           # Connexion PDO, requêtes préparées, migrations
│   └── ui/                     # Rendu Frontend et templates
│       ├── templates/          # Vues HTML/PHP globales
│       ├── components/         # Morceaux d'interface réutilisables (modales, boutons)
│       └── ts/                 # Sources TypeScript (logique UI client)
├── modules/                    # Extensions isolées
│   └── ExempleModule/          
│       ├── manifest.json       
│       ├── api/                # Logique backend propre au module
│       └── ui/                 # Vues et composants du module
├── storage/                    # Données persistantes protégées
│   ├── db/                     # Base SQLite (si utilisée)
│   └── private_uploads/        # Fichiers sensibles 
├── tailwind.config.js          
└── package.json                
```

## 6. Normes de Développement : Sécurité et API

### 6.1. Sécurité des Formulaires et Échappement
* **Prévention des Injections SQL :** Utilisation systématique et stricte des requêtes préparées via `PDO`. Aucune variable ne doit être concaténée dans une requête SQL.
* **Prévention XSS (Cross-Site Scripting) :** Toutes les données provenant des utilisateurs doivent être systématiquement échappées avant affichage dans l'UI (utilisation d'une fonction dédiée `escape()` enveloppant `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`).
* **Protection CSRF :** Tout formulaire ou requête API modifiant la donnée (POST, PUT, DELETE) doit inclure un jeton CSRF validé par l'API.

### 6.2. Gestion des Erreurs et Réponses API
Afin de faciliter le dialogue entre le frontend (TypeScript) et le backend (PHP), les requêtes asynchrones utiliseront le principe de **l'enveloppe de réponse avec statut HTTP 200**.
Le code HTTP 200 confirme que la communication avec le serveur a réussi. Le statut réel de l'opération est défini dans le corps de la réponse JSON (Standard JSend modifié).

**Format standard de réponse JSON :**
```json
{
  "success": true | false,
  "code": 200 | 400 | 403 | 404 | 500, // Code applicatif (ex: 403 pour refus de droits)
  "message": "Message clair pour l'utilisateur (ex: 'Données sauvegardées', 'Accès refusé')",
  "data": { ... } // Contenu optionnel (liste des membres, infos profil, ou détails d'erreurs de validation)
}
```
*Avantage :* Le frontend TypeScript n'a qu'à parser le JSON. Si `success` est `false`, il peut utiliser le `code` et le `message` applicatifs pour afficher directement une notification (Toast) ou marquer les champs en erreur dans le formulaire, sans déclencher les erreurs de protocole du navigateur.

## 7. Modèle Conceptuel de Données (MCD)

* **`sys_superadmins`** : `id`, `username`, `password_hash`, `created_at`
* **`sys_modules`** : `id`, `folder_name`, `is_active`
* **`roles`** : `id`, `name`, `permissions` (JSON), `is_deletable`
* **`users`** : `id`, `role_id`, `email`, `password_hash`, `firstname`, `lastname`, `membership_status`
* **`articles`** : `id`, `author_id`, `title`, `slug`, `content`, `visibility`, `status`