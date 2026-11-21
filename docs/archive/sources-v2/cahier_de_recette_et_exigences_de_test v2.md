# Master Test Plan (MTP) - Cahier de Recette Exhaustif CMS Associatif

Ce document définit la stratégie QA et les exigences granulaires permettant de générer les ~350-400 cas de tests (TC) nécessaires à la validation du Core du CMS, de sa sécurité et de son API.

## 1. Stratégie de Test et Couverture

La génération des cas de tests reposera sur les techniques suivantes pour garantir une couverture exhaustive :

- **Tests Combinatoires (RBAC Matrix) :** Chaque endpoint de l'API sera testé avec les 6 profils types (Superadmin, Président, Trésorier, Rédacteur, Adhérent, Anonyme) pour valider l'étanchéité (Génère ~150 à 200 TCs).
- **Partitions d'équivalence et Valeurs limites (BVA) :** Pour les âges, les durées de validité (OTP) et les tailles de fichiers.
- **Transitions d'États :** Pour les statuts de validation d'adhésion et de publication d'articles.
- **Tests Négatifs et Injections :** Tentatives d'élévation de privilèges, XSS, et manipulations de payloads JSON.

---

## 2. Exigences Détaillées : Sécurité et Matrice RBAC (Génère ~150+ TCs)

### 2.1. Étanchéité d'Authentification (Shadow Login)

- **REQ-SEC-AUTH-01 :** Tentative de login Superadmin via la route standard (`/api/v1/auth/login`) -> `401 Unauthorized`.
- **REQ-SEC-AUTH-02 :** Tentative de login Adhérent via la route Superadmin (`/api/v1/sys/login`) -> `401 Unauthorized`.
- **REQ-SEC-AUTH-03 :** Attaque par force brute : blocage du compte (ou de l'IP) temporaire (15min) après 5 tentatives échouées consécutives.
- **REQ-SEC-AUTH-04 :** Déconnexion (Logout) côté serveur : l'ancien cookie de session NE DOIT PLUS permettre de requêter l'API (invalidation côté backend, pas juste côté client).

### 2.2. Exécution de la Matrice de Droits (Crucial)

Pour _chaque_ route du système (ex: `PATCH /api/v1/members/12`), le test doit affirmer le statut HTTP selon le profil :

- **REQ-SEC-RBAC-01 :** Un Adhérent tentant de modifier le membre 13 (un autre adhérent) -> `403 Forbidden`.
- **REQ-SEC-RBAC-02 :** Un Tuteur tentant de modifier le membre 13 (qui est son enfant lié) -> `200 OK`.
- **REQ-SEC-RBAC-03 :** Un Rédacteur (qui n'a que `articles.write`) tentant de lire la liste des membres (`GET /members`) -> `403 Forbidden`.

---

## 3. Exigences Détaillées : CRM, RGPD et Familles (Génère ~80 TCs)

### 3.1. Gestion des Mineurs et Tuteurs (Logique Complexe)

- **REQ-CRM-TUT-01 (Nominal) :** Création d'un panier avec 1 Parent et 2 Enfants. Vérifier en BDD que 2 fiches `members` (enfants) sont liées à 1 compte `users` (parent).
- **REQ-CRM-TUT-02 (Parents Séparés - Masquage) :** Tuteur A fait un GET sur son Enfant. Vérifier que la réponse JSON _exclut_ strictement le bloc de données (téléphone, email) du Tuteur B (si `info_shared` = false).
- **REQ-CRM-TUT-03 (Parents Séparés - Opt-in) :** Passage de `info_shared` à true par le Tuteur B. Vérifier que le Tuteur A reçoit désormais les infos de B.
- **REQ-CRM-TUT-04 (Radiation Cascade) :** Radier un Enfant. Vérifier que le compte Tuteur associé (s'il n'est pas lui-même pratiquant et n'a pas d'autres enfants actifs) passe en statut `inactive`.

### 3.2. Manipulation des Dates et Moulinette d'Âge (Valeurs Limites)

- **REQ-CRM-AGE-01 (Limite inférieure) :** Enfant ayant 12 ans le jour du lancement de la moulinette -> Assigner catégorie "U14".
- **REQ-CRM-AGE-02 (Limite supérieure) :** Enfant ayant 11 ans et 364 jours le jour de la moulinette -> Assigner catégorie "U12".
- **REQ-CRM-AGE-03 (Forçage Admin) :** Si `is_category_forced` = true, vérifier que le passage de la moulinette ignore STRICTEMENT cet ID de membre.

---

## 4. Exigences Détaillées : Flux Inscription et Paiement (Génère ~60 TCs)

### 4.1. Transitions d'États (State Machine)

- **REQ-REG-WF-01 :** Création du dossier -> Statut en BDD DOIT être `pending`.
- **REQ-REG-WF-02 :** Transition invalide : Un Adhérent tente via l'API de passer son dossier de `pending` à `validated` -> `403 Forbidden`.
- **REQ-REG-WF-03 :** Validation Admin : Passage à `validated` -> Déclenche l'OTP + Active le compte User + Insère un log applicatif.

### 4.2. Cycle de vie de l'OTP

- **REQ-REG-OTP-01 :** Utilisation d'un OTP valide (nouveau mot de passe respectant la Regex) -> `200 OK`.
- **REQ-REG-OTP-02 :** Double utilisation du même OTP -> `400 Bad Request` (Le jeton doit être détruit à la 1ère utilisation).
- **REQ-REG-OTP-03 (Valeur Limite) :** Tentative d'utilisation d'un OTP à H+48h et 1 minute -> `400 Bad Request` (Expiré).

---

## 5. Exigences Détaillées : CMS, Visibilité et Fichiers (Génère ~50 TCs)

### 5.1. Workflow Éditorial

- **REQ-CMS-WF-01 :** Rédacteur crée un article. L'API force le statut à `draft` même si le payload contenait `status: "published"`.
- **REQ-CMS-WF-02 :** Admin modifie l'article et passe le statut à `published` -> `200 OK`.
- **REQ-CMS-WF-03 (Contrôle d'accès) :** Anonyme fait un GET sur un article `draft` ou `members_only` -> `404 Not Found` (et non 403, pour masquer l'existence de la ressource).

### 5.2. Sécurité des Uploads (Certificats Médicaux)

- **REQ-CMS-FILE-01 :** Upload d'un PDF valide -> `200 OK`. Fichier stocké dans `/storage/private_uploads/`.
- **REQ-CMS-FILE-02 (Test Négatif) :** Tentative d'upload d'un fichier `.php` camouflé (ex: double extension ou mime-type spoofing) -> `422 Unprocessable Entity`.
- **REQ-CMS-FILE-03 :** Tentative d'accès public via URL directe (`/storage/private_uploads/certif.pdf`) -> présence d'un .htaccess dans `/storage/private_uploads/` empêchant l'accès aux dossiers strictement.
- **REQ-CMS-FILE-04 :** Tentative d'accès public via URL directe (`/storage/private_uploads/certif.pdf`) -> Rejet par le serveur web (`403 Forbidden` natif Apache/Nginx).

---

## 6. Exigences Détaillées : Architecture Modulaire et Système (Génère ~30 TCs)

### 6.1. Robustesse du Scanner (Résilience)

- **REQ-SYS-MOD-01 :** Scan avec un module parfait -> Enregistré en BDD, statut `inactive` par défaut.
- **REQ-SYS-MOD-02 :** Scan avec `manifest.json` manquant -> Dossier ignoré proprement, pas d'erreur 500.
- **REQ-SYS-MOD-03 :** Scan avec `manifest.json` malformé (Erreur JSON syntaxique) -> Catch de l'exception, dossier ignoré, log technique écrit dans `sys_logs`.

### 6.2. Hooks et Événements

- **REQ-SYS-HOOK-01 :** L'activation d'un module par le Superadmin DOIT déclencher l'exécution de sa méthode `install()` (création de ses propres tables avec préfixe).
- **REQ-SYS-HOOK-02 :** La désactivation DOIT empêcher le routage de toute URL commençant par `/api/v1/modules/{nom}/` (Retourne `404`).

---

## 7. Critères d'Acceptation de Validation (DoD - Definition of Done)

Pour qu'une release soit validée (Pass), le projet doit répondre aux critères suivants :

1. **0 bug critique** sur les droits (aucune fuite de données RGPD inter-famille, aucune élévation de privilège).
2. **Couverture des scénarios négatifs :** 100% des tests de payloads modifiés frauduleusement (via Postman) renvoient des erreurs de validation (422) ou des erreurs de droits (403), et jamais des erreurs 500 ou des requêtes SQL corrompues.
3. Les mots de passe et OTP sont systématiquement hashés (Bcrypt/Argon2).
