# Plan de Release Agile et Backlog Détaillé (Lot 3)

**Projet :** CMS Associatif (Core)
**Phase :** Lot 3 - Évolutivité, Automatisation et Moteur de Modules
**Méthodologie :** Scrum / Agile

## 1. Objectifs du Lot 3

Les Lots 1 et 2 ont permis de construire et de mettre en production les fonctionnalités vitales de l'association (CRM, Inscriptions, CMS public).
L'objectif de ce Lot 3 est de rendre le système autonome et pérenne :

* **Automatisation :** Gérer les transitions de saisons sans saisie manuelle (calcul des âges et des catégories).
* **Pilotage :** Fournir des tableaux de bord statistiques sur la vie du club.
* **Extensibilité (Le cœur de l'architecture) :** Implémenter le système d'orchestration d'événements (Hooks) et le scanner de modules pour permettre les développements futurs (Boutique, Pointage) sans toucher au Core.

## 2. Backlog du Sprint 5 : Automatisation et Data (Durée : 2 Semaines)

**Objectif du Sprint :** Fournir les outils de supervision au bureau et automatiser la transition annuelle des adhérents.

### US 5.1 : Moulinette de Changement de Saison (Rollover)

* **En tant que** Président (Superadmin / Admin)
* **Je veux** déclencher un recalcul massif des catégories d'âge de tous les adhérents selon le type de saison (scolaire ou civile)
* **Afin de** préparer la nouvelle saison sans avoir à éditer manuellement les 500 fiches du club.
* **Story Points :** 8
* **Critères d'acceptation :**
  * CA1 : L'API `/api/v1/tasks/rollover-season` recalcule l'âge exact de chaque membre par rapport à la date de référence.
  * CA2 : La moulinette réaffecte le `age_category_id` correspondant.
  * CA3 : **Sécurité Métier :** Si le champ `is_category_forced` est à `TRUE` pour un membre, la moulinette ignore la mise à jour de sa catégorie.
  * CA4 : L'action est historisée dans `app_logs` avec le nombre de fiches impactées.

### US 5.2 : Tableaux de Bord et Statistiques (UI/TS)

* **En tant que** Membre du Bureau
* **Je veux** visualiser des indicateurs clés (KPI) sur la santé de l'association
* **Afin de** piloter le club (assiduité globale, répartition par grades, évolution des inscriptions).
* **Story Points :** 5
* **Critères d'acceptation :**
  * CA1 : Création d'une vue "Statistiques" affichant des composants visuels (cartes de KPI générées via Tailwind).
  * CA2 : L'API expose des endpoints agrégés (ex: `COUNT`, `GROUP BY discipline`).
  * CA3 : Les calculs de pourcentages et d'assiduité sont dynamisés par TypeScript pour un affichage réactif.

## 3. Backlog du Sprint 6 : Moteur de Modules et SDK (Durée : 2 Semaines)

**Objectif du Sprint :** Mettre en place l'architecture orientée événements pour rendre le CMS 100% modulaire, avec une tolérance aux pannes stricte.

### US 6.1 : Le Scanner de Modules et le Manifeste

* **En tant que** Superadmin
* **Je veux** que le système scanne le dossier `/modules/` et lise les fichiers `manifest.json`
* **Afin de** lister les extensions disponibles et vérifier leur compatibilité avant activation.
* **Story Points :** 5
* **Critères d'acceptation :**
  * CA1 : Le backend parse les dossiers et extrait les JSON.
  * CA2 : Si le JSON est corrompu ou manquant, le dossier est ignoré silencieusement (avec log technique).
  * CA3 : Si la version du Core n'est pas dans la plage définie par le manifeste, le bouton "Activer" est grisé dans l'UI avec un avertissement.

### US 6.2 : L'EventManager (Système de Hooks)

* **En tant qu'** Architecte Logiciel (Core)
* **Je veux** un système global d'émission et d'écoute d'événements (Actions et Filtres)
* **Afin que** les futurs modules puissent interagir avec le Core sans modification du code source.
* **Story Points :** 8
* **Critères d'acceptation :**
  * CA1 : Création d'une classe `EventManager` capable d'enregistrer des *listeners* (écouteurs).
  * CA2 : Méthode `dispatch($event, $data)` opérationnelle pour les Actions (silencieuses).
  * CA3 : Méthode `applyFilters($event, $value)` opérationnelle pour intercepter et modifier une donnée (ex: ajout de liens dans le menu latéral).

### US 6.3 : Cycle de vie et "Safe Mode" des Modules

* **En tant que** Superadmin
* **Je veux** que les modules s'installent proprement et ne fassent pas crasher le site en cas d'erreur
* **Afin de** garantir la haute disponibilité de l'application.
* **Story Points :** 8
* **Critères d'acceptation :**
  * CA1 : Lors de l'activation, appel de la méthode `install()` du module (création de ses tables spécifiques).
  * CA2 : Lors de la désactivation/suppression, appel de la méthode `uninstall()` (nettoyage de la BDD).
  * CA3 (Résilience) : Le chargement des modules (`boot()`) est encadré par un `try/catch`. En cas d'Exception, le module fautif est désactivé à la volée (Safe Mode), l'erreur est loguée dans `sys_logs`, et le site continue de fonctionner.

## 4. Bilan de la Release

À l'issue de ce Lot 3, le **Core AssoCMS v1.0.0** est finalisé. 
L'association dispose d'un outil complet pour sa gestion courante, et l'équipe de développement possède l'infrastructure pour commencer la création des plugins additionnels (Pointage Mobile, Boutique, Gestion des Tournois) selon les spécifications du SDK.