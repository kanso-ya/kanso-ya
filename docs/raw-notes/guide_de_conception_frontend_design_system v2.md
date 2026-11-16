# Guide de Conception Frontend & Design System (UI/UX) - Core CMS

Ce document définit les règles d'interface et d'expérience utilisateur (UI/UX) pour le développement du CMS. Il est conçu pour une architecture **Multi-Pages (MPA)** servie par PHP, dynamisée par TypeScript natif pour les interactions asynchrones.

## 1. Philosophie d'Architecture Frontend

Le projet s'inspire de la composantisation de React, mais rendue côté serveur en PHP.

- **Composants Réutilisables (PHP Includes) :** Les éléments d'interface (Boutons, Modales, Cartes) sont isolés dans des fichiers PHP dédiés (ex: `ui/components/toast.php`) et inclus avec des variables.
- **TypeScript Ciblé :** Le TS gère l'interactivité des composants (fermeture de modale, requêtes API `fetch`, filtrage visuel) sans recharger la page.
- **Rétention de l'utilisateur (Soft Errors) :** En cas d'appel API refusé (erreur 403 de droits, ou 400 mauvaise requête), **l'utilisateur n'est jamais redirigé vers une page d'erreur globale**. Il reste sur sa page actuelle, et un Toast (notification) s'affiche pour expliquer l'anomalie.

## 2. Gabarit Global (Layout) et Comportement

La structure de l'application repose sur un affichage figé aux dimensions de la fenêtre de l'utilisateur (`100vh`), éliminant le scroll global au profit d'un scroll ciblé.

### 2.1. Structure Desktop (Écrans larges)

- **La Bannière Supérieure (Header) :**
  - Fixée en haut de l'écran (`sticky top-0` ou `fixed`).
  - Contient le **Nom du club**.
  - Hauteur restreinte et fixe.

- **La Barre Latérale (Sidebar) :**
  - Fixée à gauche (`fixed left-0`).
  - Hauteur dynamique : `calc(100vh - hauteur_du_header)`.
  - **Haut de la sidebar :** Espace réservé pour le **Logo du club** (Conteneur d'environ `100px` de hauteur maximum pour une intégration harmonieuse).
  - **Sous le logo :** Bouton proéminent de Connexion / Déconnexion.
  - **Centre :** Navigation scrollable si le menu est très long.
  - **Bas (Footer) :** Poussé tout en bas de la sidebar (Mentions légales, contacts).

- **Le Conteneur Principal (Main Workspace) :**
  - Occupe l'espace restant à droite de la sidebar et sous le header.
  - C'est le **seul élément qui possède une barre de défilement verticale** (`overflow-y-auto`). Ainsi, le menu et le header restent toujours accessibles.

### 2.2. Comportement Mobile (Responsive / Mobile-First)

Le CMS est conçu pour être 100% utilisable sur smartphone (Tailwind classes `sm:`, `md:`, `lg:`).

- **En-tête Mobile :** Le Header intègre un bouton "Menu Hamburger" (☰).
- **Sidebar Mobile :** La barre latérale est masquée par défaut sur petit écran (`hidden md:flex`). Au clic sur le hamburger, elle apparaît sous forme de tiroir coulissant (Drawer) superposé au contenu, avec un fond assombri cliquable pour la fermer.
- **Tableaux complexes :** Sur mobile, les tableaux de données (ex: liste des membres) basculent soit en défilement horizontal (`overflow-x-auto`), soit en vue "Cartes" empilées verticalement pour garantir la lisibilité.

## 3. Charte Graphique et Design Tokens

### 3.1. Typographie Duale (Configurable par le Superadmin)

Le Superadmin peut définir deux typographies distinctes dans les paramètres :

1. **Typographie du Titre (Nom du club) :** Souvent spécifique, élégante ou sportive (ex: _Bebas Neue_, _Montserrat_), appliquée uniquement dans le Header.
2. **Typographie du Site (Contenu) :** Une police classique, neutre et très lisible (ex: _Inter_, _Roboto_, _system-ui_) pour le reste de l'application (tableaux, articles, boutons).

### 3.2. Couleurs Sémantiques (Statuts et Badges)

- 🟢 **Succès / Actif / Validé :** `text-emerald-700`, `bg-emerald-50` (Ex: Cotisation payée).
- 🟡 **En attente / Brouillon :** `text-amber-700`, `bg-amber-50` (Ex: Action requise).
- 🔴 **Danger / Radié / Refusé :** `text-red-600`, `bg-red-50` (Ex: Accès bloqué).
- 🔵 **Système / Superadmin :** Isolation visuelle stricte (Dark mode `bg-slate-900` + accents rouges) pour la console racine.

## 4. UI Patterns (Composants Standards)

### 4.1. Les Tableaux de Données (Data Tables) - Le cœur du CRM

Utilisés pour l'annuaire des membres et la liste des dossiers d'inscription.

- **En-tête Fixe (Sticky Header) :** `sticky top-0 bg-slate-50` pour garder le nom des colonnes visible lors du défilement des listes longues.
- **Filtrage Instantané (TS) :** Une barre de recherche au-dessus du tableau. Le TS masque en CSS (`display: none`) les lignes (balises `<tr>`) qui ne matchent pas, offrant une recherche instantanée (0 milliseconde, pas de requête serveur).
- **Lignes Dépliables (Accordéons Famille) :** Pour gérer notre cas spécifique "Parents/Enfants", un clic sur une ligne d'adhérent mineur déploie une sous-ligne (visuellement rattachée) listant les coordonnées de ses tuteurs légaux (selon les droits RGPD vus précédemment).

### 4.2. Les Formulaires Dynamiques

Utilisés pour l'inscription et l'édition des membres.

- **Validation Inline (TS) :** Avant l'envoi, TS vérifie les champs (ex: format email, mots de passe identiques).
- **Affichage Conditionnel :** Si l'utilisateur saisit une date de naissance calculant un âge < 18 ans, le TS affiche dynamiquement le bloc HTML "Informations des Tuteurs Légaux".
- **Modales de Confirmation :** Superpositions assombries (`bg-slate-900/60`). Les actions destructrices (Radiation, Suppression) y requièrent un clic sur un bouton explicitement rouge.
- **Boutons avec état de chargement :** Lors d'un clic (ex: Soumettre formulaire), le bouton passe en `disabled`, modifie son texte ("Traitement...") et affiche un spinner CSS pour empêcher le "Double-clic / Double-POST".

### 4.3. Notifications Non Intrusives (Toasts)

Puisque le Front et l'API communiquent, nous rejetons les `alert()` natifs du navigateur.

- Lorsqu'une action API réussit (ex: "Mot de passe modifié", code 200) ou échoue (ex: "Droits insuffisants", code 403), le script TS lit l'enveloppe JSON et génère une bulle de notification temporaire (Toast) en bas à droite de l'écran, s'effaçant après 3 secondes.
- **Toasts (Notifications) :** Bulles apparaissant en bas à droite de l'écran, générées par le TypeScript suite aux réponses API. Elles s'effacent après 3 secondes (ou nécessitent un clic si c'est une erreur bloquante).

### 4.4. Gestion des Actions Destructrices (Modales)

- **Principe :** Ne jamais faire changer de page pour une demande de confirmation.
- **Exécution :** Cliquer sur "Radier un adhérent" ou "Supprimer un article" ouvre une modale HTML superposée (Overlay assombri `bg-slate-900/60`).
- **Sécurité UI :** Le bouton de validation final dans la modale doit être explicitement rouge (`bg-red-600`).

## 5. Intégration des Futurs Modules (Extensibilité UI)

Pour que l'ajout d'un futur module (Boutique, Pointage) s'intègre parfaitement au Core sans casser la charte :

1. **Le Menu Latéral (Sidebar) :** La construction du menu latéral (UI) doit prévoir une "Zone Modules". Les modules activés injecteront leurs raccourcis dans ce bloc via les _Hooks_ du système.
2. **Homogénéité :** Les développeurs de modules devront utiliser le même système de classes Tailwind (ex: `card-container`, `btn-primary`) défini dans la documentation des composants pour que leur module hérite automatiquement du style du CMS et des modifications de couleurs du Superadmin.
