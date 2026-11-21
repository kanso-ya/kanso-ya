# Plan de Release Agile et Backlog Détaillé (Lot 2)

**Projet :** CMS Associatif (Core)
**Phase :** Lot 2 - Inscriptions, Onboarding et Communication (CMS)
**Méthodologie :** Scrum / Agile

## 1. Objectifs du Lot 2

Le Lot 1 a mis en place la structure, la sécurité et l'annuaire des membres en back-office. 
L'objectif du Lot 2 est d'ouvrir le club vers l'extérieur :
* Permettre aux familles de s'inscrire en ligne de manière autonome (gestion du panier familial et des tuteurs).
* Automatiser l'accueil des nouveaux adhérents (Onboarding via OTP).
* Déployer le moteur de publication (CMS) pour la vitrine publique et les communications internes.
* Garantir la conformité légale via les pages institutionnelles (RGPD, Mentions légales).

---

## 2. Backlog du Sprint 3 : Inscription Publique et Onboarding (Durée : 2 Semaines)

**Objectif du Sprint :** Déployer le tunnel d'inscription intelligent capable de s'adapter à l'âge du pratiquant et de regrouper les fratries sous un même dossier, puis automatiser la création des accès.

### US 3.1 : Formulaire Public Interactif (TS) - Entonnoir Adhérent
* **En tant que** Visiteur / Candidat
* **Je veux** remplir un formulaire d'inscription dont les étapes s'adaptent selon ma date de naissance (Majeur vs Mineur)
* **Afin de** ne saisir les informations des tuteurs légaux que lorsque cela est nécessaire.
* **Story Points :** 8
* **Critères d'acceptation :**
  * CA1 : Affichage conditionnel (via TS) du bloc "Tuteurs Légaux" si l'âge calculé est inférieur à 18 ans.
  * CA2 : Validation TS de la présence obligatoire du "Contact d'Urgence".
  * CA3 : Le fil d'Ariane (breadcrumb) du formulaire se met à jour dynamiquement selon les étapes requises.

### US 3.2 : Soumission du Panier Familial
* **En tant que** Parent (Tuteur)
* **Je veux** pouvoir ajouter un autre enfant à mon dossier d'inscription avant de le soumettre
* **Afin de** regrouper toute ma famille sous un seul paiement et un seul dossier administratif.
* **Story Points :** 8
* **Critères d'acceptation :**
  * CA1 : Le système permet d'ajouter N adhérents au même processus avant validation finale.
  * CA2 : À la soumission, l'API enregistre une entrée dans `registrations` (statut `pending`) englobant tous les profils.
  * CA3 : Le formulaire permet de choisir un mode de règlement (Chèque, Espèces, Virement) et d'indiquer le nombre de versements souhaité.

### US 3.3 : Validation des dossiers par le Bureau (Admin)
* **En tant que** Secrétaire / Trésorier
* **Je veux** valider ou refuser les dossiers d'inscription "En attente" depuis mon tableau de bord
* **Afin de** valider l'entrée officielle des membres dans le club après réception du paiement et des certificats.
* **Story Points :** 5
* **Critères d'acceptation :**
  * CA1 : Clic sur "Valider" modifie le statut du dossier (`registrations`) à `validated`.
  * CA2 : Modifie le statut des fiches `members` concernées de `pending` à `active`.
  * CA3 : Déclenche l'insertion d'un log dans `app_logs` (Traçabilité applicative).
  * CA4 : Déclenche la création des comptes `users` (si inexistants) et la génération du code OTP en base de données.

### US 3.4 : Onboarding et Mot de passe (OTP)
* **En tant que** Nouvel Utilisateur (Adhérent adulte ou Tuteur)
* **Je veux** utiliser le lien à usage unique reçu par e-mail
* **Afin de** définir mon mot de passe de manière sécurisée et accéder à mon espace.
* **Story Points :** 5
* **Critères d'acceptation :**
  * CA1 : La route API vérifie la validité de l'OTP (Date d'expiration < 48h et existence en base).
  * CA2 : La validation d'un nouveau mot de passe détruit immédiatement l'OTP en base.
  * CA3 : Le mot de passe doit respecter les règles de sécurité (Regex : min 8 caractères, majuscule, chiffre, caractère spécial).

---

## 3. Backlog du Sprint 4 : CMS, Communication et Légal (Durée : 2 Semaines)

**Objectif du Sprint :** Fournir au club les outils pour communiquer publiquement et en interne, tout en assurant la personnalisation du site et la conformité légale.

### US 4.1 : Pages Statiques et Cadre Légal
* **En tant que** Président / Superadmin
* **Je veux** gérer le contenu des pages fixes (RGPD, CGV, Horaires)
* **Afin de** fournir les informations légales et pratiques aux visiteurs.
* **Story Points :** 5
* **Critères d'acceptation :**
  * CA1 : Le Superadmin peut définir la trame structurelle des mentions légales.
  * CA2 : L'Admin peut éditer le texte riche des pages CGV et Informations (via éditeur WYSIWYG léger).
  * CA3 : Les URLs de ces pages sont fixes (ex: `/rgpd`, `/cgv`).

### US 4.2 : Catégories et Tags Tailwind
* **En tant que** Rédacteur
* **Je veux** associer mes articles à des catégories visuelles (Tags colorés)
* **Afin de** structurer le fil d'actualités et le rendre plus lisible.
* **Story Points :** 3
* **Critères d'acceptation :**
  * CA1 : L'Admin peut créer des catégories dans la table `article_categories` (ex: "Compétition").
  * CA2 : Il peut associer une classe de couleur Tailwind (ex: `bg-red-500 text-white`) via un menu déroulant ou champ texte.
  * CA3 : Les badges s'affichent correctement sur les cartes d'articles frontend.

### US 4.3 : Workflow de Publication (Articles)
* **En tant que** Rédacteur / Admin
* **Je veux** rédiger des articles et contrôler leur visibilité
* **Afin de** cibler ma communication (Publique, Membres, ou Bureau uniquement).
* **Story Points :** 8
* **Critères d'acceptation :**
  * CA1 : Sauvegarde systématique de tout nouvel article au statut "Brouillon" (`draft`).
  * CA2 : Le passage au statut "Publié" (`published`) n'est accessible que si l'utilisateur possède le droit `articles.publish`.
  * CA3 : L'API `/api/v1/articles` filtre automatiquement les retours : les visiteurs anonymes ne reçoivent QUE les articles `visibility = 'public'`.

### US 4.4 : Éditeur de Modèles d'Emails (Templates)
* **En tant que** Président (Admin)
* **Je veux** modifier le contenu des e-mails automatiques (ex: Onboarding, Relances)
* **Afin d'** adapter le ton et les instructions spécifiques au fonctionnement de mon association.
* **Story Points :** 5
* **Critères d'acceptation :**
  * CA1 : L'interface liste les "Types" de mails configurés (ex: `otp_creation`).
  * CA2 : L'Admin peut éditer l'objet et le corps (HTML) du mail.
  * CA3 : Le système accepte et remplace les variables dynamiques (ex: `{{prenom}}`, `{{lien_otp}}`) lors de l'envoi réel.