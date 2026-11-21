STATUT: VALIDATED

# Rapport de Recette QA & UAT — Sprint 6 : Moteur de Modules & SDK Kanso-ya (Lot 3)

**Date d'exécution :** 16 Novembre 2026  
**Environnement de test :** Laragon (`https://kanso-ya.fr`, SQLite WAL)  
**Résultat global :** **124 tests passés / 124 exécutés (100% de succès, durée : 28.0s)**  
**Analyse statique PHPStan :** `[OK] No errors` (Level 0)  
**Compilateur TypeScript :** `[OK] No errors` (`tsc` strict)  

---

## 1. Synthèse Exécutive du Sprint 6

Le Sprint 6 pose les fondations architecturales du **Lot 3 (Moteur de Modules & SDK Kanso-ya)** conformément aux spécifications du document `spécifications_du_moteur_de_modules_sdk v2.md`. 

Toutes les exigences fonctionnelles et techniques ont été validées avec succès :
- **US 6.1 — Découverte et validation des modules (`/modules/`) :** Table `modules` créée en BDD, scanner dynamique `ModuleScanner`, contrôle sémantique des versions du Core (`1.5.0`) et de PHP (`>=8.1`), et rejet immédiat des modules incompatibles.
- **US 6.2 — Bus d'événements central (`Core\Sdk\EventManager`) :** Singleton gérant les Actions (`addAction` / `dispatch`) et les Filtres (`addFilter` / `applyFilters`), branché sur l'ensemble des contrôleurs Core (`on_member_created`, `on_member_deleted`, `on_registration_validated`, `on_article_published`) et dans la sidebar du gabarit maître (`layout.php`).
- **US 6.3 — Cycle de vie et résilience automatique (Safe Mode) :** Contrôle des transitions d'état (`install`, `activate`, `deactivate`, `uninstall`), isolation stricte des données SQL sous préfixe `mod_*`, interface d'administration dans `sys-console.php` et **Safe Mode automatique (CA3)** : capture inconditionnelle des exceptions au boot sans jamais planter le CMS, avec bascule immédiate en `status = 'crashed'` et journalisation dans `sys_logs`.
- **US 6.4 — Modules Démonstrateurs (`Boutique` & `CrashTest`) :** Module vitrine opérationnel avec injection de menu et table isolée `mod_boutique_products` sur `/boutique.php`, et module défaillant `CrashTest` validant en conditions réelles la tolérance aux pannes.

---

## 2. Matrice de Couverture des Tests E2E (Sprint 6)

| Référence Test | Description & Objectif | Résultat Desktop | Résultat Mobile |
| :--- | :--- | :---: | :---: |
| **TC-MOD-01** | Découverte des modules, validation stricte des `manifest.json` et contrôle d'accès RBAC (403 pour Adhérent/Rédacteur). | **PASS** | **PASS** |
| **TC-MOD-02** | Contrôle de compatibilité Core/PHP : blocage de l'activation (422) sur module incompatible et bouton désactivé en UI. | **PASS** | **PASS** |
| **TC-MOD-03** | Cycle de vie du module `Boutique` : installation SQL (`mod_boutique_products`), activation, affichage catalogue `/boutique.php` et injection sidebar avec filtrage des permissions. | **PASS** | **PASS** |
| **TC-MOD-04** | Cycle de vie de désactivation et désinstallation : retrait propre du menu sidebar et purge définitive de la table SQL (`DROP TABLE`). | **PASS** | **PASS** |
| **TC-MOD-SAFE-MODE** | Validation de la résilience Safe Mode : crash volontaire dans `boot()` capturé sans interruption du CMS (HTTP 200), bascule en `crashed`, traçabilité indélébile dans `sys_logs`. | **PASS** | **PASS** |
| **TC-EVENT-MANAGER-SDK** | Validation fonctionnelle du bus EventManager sur les contrôleurs Core (Member, Registration, CMS). | **PASS** | **PASS** |

---

## 3. Détail des Vérifications Techniques

### 3.1. Résilience SQLite & Parallélisme Playwright
- **Concurrence multi-workers :** Toutes les écritures en base dans `ModuleScanner`, `ModuleManager` et `Database` intègrent des boucles de retry avec backoff (5 tentatives) et `PRAGMA busy_timeout = 15000` en mode WAL.
- **Zéro blocage :** Les 124 tests s'exécutent en parallèle avec 6 workers Playwright sans aucun verrouillage `database is locked`.

### 3.2. Étanchéité et Isolation SQL (Préfixe `mod_*`)
- Vérification que le module Boutique ne crée aucune table hors de la convention `mod_boutique_products`.
- À la désinstallation, la table est purgée sans laisser de résidus en base.

### 3.3. Contrôle de Type et Qualité Statique
- `vendor/bin/phpstan analyse core/Api core/Sdk` : 0 erreur détectée.
- `npm run build:ts` : Compilation stricte réussie de tous les scripts TypeScript (`core/ui/ts/*.ts`).

---

## 4. Bilan Global des 7 Domaines de Test

- **Domaine 1 — Authentification & RBAC :** 28/28 tests validés.
- **Domaine 2 — CRM Adhérents & RGPD :** 22/22 tests validés.
- **Domaine 3 — Inscriptions & Trésorerie :** 24/24 tests validés.
- **Domaine 4 — Studio CMS Éditorial :** 16/16 tests validés.
- **Domaine 5 — UAT Personas de bout en bout :** 6/6 tests validés.
- **Domaine 6 — Rollover & Tableaux de Bord Analytiques :** 16/16 tests validés.
- **Domaine 7 — Moteur de Modules & SDK Kanso-ya :** 12/12 tests validés.
- **Total général :** **124 / 124 (100% de réussite)**.

**Conclusion :** Le Sprint 6 est formellement **VALIDÉ** et prêt pour la mise en production.
