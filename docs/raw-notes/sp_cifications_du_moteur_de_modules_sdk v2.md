# Spécifications du Moteur de Modules (SDK & Hooks)

Ce document définit l'architecture technique permettant d'étendre le CMS sans altérer son code source (Core). Il sert de contrat de développement pour la création de futurs modules (Boutique, Tournois, etc.).

## 1. Structure Standard d'un Module

Tout module doit résider dans son propre dossier au sein de `/modules/` et respecter une arborescence stricte.

```
/modules/NomDuModule/
├── manifest.json       # Fichier d'identité et de compatibilité (Obligatoire)
├── Module.php          # Classe principale d'amorçage (Obligatoire)
├── api/                # Endpoints spécifiques au module
└── ui/                 # Vues PHP et composants TypeScript du module

```

## 2. Le Manifeste et la Compatibilité (`manifest.json`)

Le fichier `manifest.json` est le premier élément lu par le scanner du Superadmin. Il valide l'intégrité et la compatibilité du module avant même le chargement de son code PHP.

**Exemple de structure requise :**

```
{
  "id": "club_boutique",
  "name": "Boutique Officielle du Club",
  "version": "1.0.0",
  "author": "AssoCMS Dev",
  "description": "Gestion des stocks et commandes de produits dérivés.",
  "compatibility": {
    "core_min_version": "1.2.0",
    "core_max_version": "2.0.0",
    "php_min_version": "8.1"
  },
  "permissions_required": [
    "finances.manage"
  ]
}

```

*Règle de gestion :* Si la version actuelle du Core n'est pas comprise entre `core_min_version` et `core_max_version`, le Superadmin ne pourra pas activer le module (Bouton désactivé avec message d'avertissement).

## 3. Le Contrat d'Interface (Cycle de vie)

La classe `Module.php` doit obligatoirement implémenter l'interface `ModuleInterface` du Core, qui impose 3 méthodes :

* `install()` : Exécutée une seule fois lors de l'activation par le Superadmin. Sert à créer les tables SQL spécifiques au module (préfixées, ex: `mod_boutique_orders`) et à insérer des données initiales.

* `uninstall()` : Exécutée lors de la suppression. Doit nettoyer la base de données (DROP tables) pour ne laisser aucun résidu.

* `boot(EventManager $eventManager)` : Exécutée à **chaque requête** (si le module est actif). C'est ici que le module enregistre ses routes API et s'abonne aux Hooks du Core.

## 4. Tolérance aux Pannes et Résilience (Crash Handling)

L'appel aux fonctions des modules par le Core n'est jamais direct. Il passe par un répartiteur d'événements (Event Dispatcher) hautement sécurisé.

* **Sandboxing Logique (Try/Catch) :** L'exécution de chaque "Hook" de module est enveloppée dans un bloc `try/catch`.

* **Safe Mode automatique :** Si l'exécution de la méthode `boot()` d'un module lève une exception fatale (Fatal Error) attrapable, le Core :

  1. Bloque l'exécution du module pour la requête en cours.

  2. Insère une erreur critique dans `sys_logs`.

  3. Bascule le champ `is_active` à `FALSE` dans la table `modules` pour désactiver ce module défaillant et protéger les futures requêtes.

  4. Génère une alerte visuelle au prochain login du Superadmin.

## 5. Le Moteur de Hooks (Actions & Filtres)

### 5.1. Les Actions (Déclencheurs d'événements)

Une "Action" permet au Core d'informer les modules qu'un événement s'est produit. Le module ne renvoie rien, il exécute un traitement silencieux en tâche de fond.

* *Exemple Core :* `EventManager::dispatch('on_member_deleted', ['member_id' => 12]);`

* *Exemple Module (dans `boot()`) :*
  Le module Boutique écoute la suppression pour anonymiser l'historique d'achat.

  ```
  $eventManager->listen('on_member_deleted', function($data) {
      OrderModel::anonymizeForMember($data['member_id']);
  });
  
  ```

### 5.2. Les Filtres (Modificateurs de données)

Un "Filtre" permet à un module d'intercepter une variable (ex: un tableau, une chaîne HTML) générée par le Core, de la modifier, puis de la renvoyer au Core pour affichage.

* *Exemple Core :* `$menu = EventManager::applyFilters('admin_sidebar_menu', $defaultMenuArray);`

* *Exemple Module (dans `boot()`) :*
  Le module ajoute son lien dans le menu de gauche de l'Admin.

  ```
  $eventManager->addFilter('admin_sidebar_menu', function($menuArray) {
      $menuArray[] = [
          'label' => 'Boutique & Stocks',
          'url' => '/admin/boutique',
          'icon' => 'fa-basket-shopping',
          'permission' => 'finances.manage' // Vérifié par le composant menu du Core
      ];
      return $menuArray;
  });
  
  ```

## 6. Bonnes Pratiques de Développement Frontend (Module UI)

Pour garantir la cohérence de l'interface :

1. Les vues d'un module ne doivent **pas** inclure de balises `<html>` ou `<body>`. Elles doivent retourner le contenu du conteneur principal. Le Core s'occupe de wrapper la vue du module avec le Header et la Sidebar.

2. Les modules doivent exploiter le système de Toasts du Core en Javascript (`CoreUI.showToast()`) plutôt que d'implémenter leurs propres notifications.