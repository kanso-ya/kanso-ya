# Spécifications Fonctionnelles et Parcours Utilisateurs (User Journeys)

Ce document complète l'architecture technique en décrivant le comportement attendu du CMS, les flux d'actions des différents rôles, et la cartographie des pages.

## 1. Parcours Adhérent & CRM (Le Flux d'Adhésion)

Ce flux gère l'entrée des membres dans l'association, avec une attention particulière à la gestion des familles et des mineurs.

### 1.1. L'Initiation

* **Mode Public :** Si activé par l'Admin, un visiteur accède au formulaire d'inscription en ligne. Si désactivé, une page affiche les informations de contact pour une inscription manuelle.

* **Mode Manuel :** L'Admin ou le Secrétaire peut saisir directement les informations d'un nouvel adhérent depuis le back-office.

### 1.2. Le Formulaire d'Inscription (Multi-Étapes)

1. **Identité du pratiquant :** Informations de base (Nom, prénom, date de naissance, contact).

2. **Tuteurs Légaux :** Si l'adhérent est mineur (calculé via la date de naissance), saisie obligatoire des informations du ou des tuteurs légaux.

3. **Informations Médicales :** Affichage conditionnel (certificat médical à uploader, questionnaire de santé).

4. **Contact d'urgence :** Nom, téléphone et lien de parenté (obligatoire).

5. **Panier Familial (Affiliation) :** Possibilité d'ajouter un autre adhérent à la même inscription pour regrouper la famille.

6. **Tarification et Paiement :** Calcul du tarif global et affichage des modalités de paiement configurées par le club (ex: chèque avec option 1x, 2x, 3x).

### 1.3. Validation et Onboarding

* **Validation Manuelle :** Le dossier arrive avec le statut "En attente". Un administrateur doit le valider manuellement (vérification des pièces et du paiement). Cette action est obligatoirement historisée (qui, quoi, quand).

* **Création de Compte & OTP :** À la validation, un compte est créé. Un e-mail contenant un lien OTP (One-Time Password) valide 48h est envoyé pour configurer le mot de passe.

* **Gestion des Mineurs (Cloisonnement des Tuteurs) :**

  * L'enfant n'a pas de compte propre ; les accès sont liés aux e-mails des tuteurs.

  * Par défaut, le Tuteur A ne voit pas les informations personnelles du Tuteur B.

  * Une option de "Partage d'informations" (Opt-in) permet de fusionner les vues si les deux tuteurs valident la demande.

### 1.4. Actions Administratives Rapides

Les administrateurs peuvent, depuis la fiche adhérent :

* Renvoyer un lien OTP de configuration (valide 48h).

* Réinitialiser le statut 2FA.

* Radier un adhérent (désactivation automatique des comptes tuteurs associés s'ils n'ont pas d'autres enfants actifs ou ne sont pas eux-mêmes adhérents).

## 2. Parcours Éditorial (Le Flux CMS)

* **Rédaction :** Les rôles autorisés (ex: Entraîneurs) utilisent l'éditeur de texte pour créer des articles. À l'enregistrement, l'article est systématiquement placé en statut **"Brouillon / En attente"**.

* **Publication :** Seuls les rôles possédant le droit explicite de publication (Admin, Secrétaire, ou rôle personnalisé) peuvent passer l'article en "Publié" ou le "Dépublier".

* *Note : Les commentaires ne sont pas gérés dans le Core, mais l'architecture permet l'ajout ultérieur d'un module dédié.*

## 3. Parcours de Déploiement (L'Onboarding Superadmin)

Le premier paramétrage du système est assuré par le Superadmin.

* **Branding & Thème :** Définition du nom du club, upload du logo, choix des couleurs (surcharge Tailwind), typographie et filigrane de fond d'écran.

* **Configuration Data :** Choix du type de base de données, exécution des migrations, et import initial massif des adhérents (CSV).

* **Configuration Messagerie (SMTP) :** Paramétrage du compte mail expéditeur qui servira à l'envoi de tous les mails automatisés par le site.

* **Passation :** Le Superadmin crée le compte du "Président/Admin principal" et lui transmet l'accès.

## 4. Système de Messagerie et Modèles d'E-mails

La communication automatisée avec les adhérents repose sur un système séparant la configuration technique de la rédaction du contenu.

* **Côté Superadmin (Technique & Structure) :** 
  * Configure les identifiants SMTP du compte d'envoi.
  * Valide et définit les "types" de mails transactionnels du système et des modules (ex: Envoi OTP, Création de compte, Validation de commande, Mise à disposition de commande, Relance cotisation).

* **Côté Admin / Président (Édition de Contenu) :**
  * Dispose d'une page d'interface dédiée listant tous les types d'e-mails validés par le système.
  * Pour chaque type, l'Admin peut rédiger le modèle de l'e-mail (Template) qui sera envoyé, en utilisant un éditeur de texte et des balises dynamiques (ex: `Bonjour {{prenom}}`, `Voici votre lien : {{lien_otp}}`).

## 5. Traçabilité et Audit (Logs)

Toutes les actions sensibles sont enregistrées pour garantir la sécurité et le suivi administratif.

* **Logs Système (Superadmin exclusif) :** Journalise les actions techniques invisibles pour le club (connexions Superadmin, mises à jour, configuration BDD, imports, erreurs SMTP).

* **Logs Applicatifs (Admins & Superadmin) :** Journalise la vie de l'association (validation d'adhésion, modification de rôles, encaissement, publication d'articles).

## 6. Sécurité et Autonomie

* **Gestion des mots de passe :** Chaque utilisateur (quel que soit son rôle) possède une interface pour modifier son propre mot de passe, nécessitant la saisie du mot de passe actuel et respectant une politique de sécurité forte.

## 7. Cartographie des Pages (Front-Office)

### 7.1. Accès Publics (Sans Authentification)

Toute autre page requiert une authentification (Erreur 401 ou redirection).

* **Accueil :** Vitrine du club (articles publics).

* **Inscription / Contact :** Formulaire public d'adhésion ou informations de contact si l'inscription en ligne est fermée.

* **Login Standard :** Page d'authentification pour les adhérents, tuteurs et admins (`/login`).

* **Shadow Login :** URL furtive et modifiable dédiée aux Superadmins (`/config-url-secrete`).

* **Déconnexion :** Route universelle détruisant la session et redirigeant vers l'Accueil.

### 7.2. Pages Institutionnelles et Légales

* **Mentions Légales :** Gérées/pré-remplies par le Superadmin.

* **RGPD (Confidentialité) :** Structure imposée par le CMS (Superadmin) avec champs spécifiques personnalisables par l'Admin.

* **CGV :** Gérées par l'Admin (obligatoires si paiement géré).

* **Informations Pratiques (Horaires, Tarifs, Lieux) :** Pages dynamiques gérées entièrement par l'Admin via l'éditeur du CMS.