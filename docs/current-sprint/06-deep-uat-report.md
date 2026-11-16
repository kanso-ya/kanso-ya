STATUT: VALIDATED

# Rapport d'Audit E2E Approfondi, Tests Visuels & Stress UAT — Kanso-ya CMS

**Date de campagne :** 16 Novembre 2026  
**Environnement d'exécution :** Laragon Local (`https://kanso-ya.fr`, PHP 8.1+, SQLite WAL)  
**Moteurs de test :** Playwright E2E v1.42.0 (Dual-Target : Chromium Desktop 1280x800 & Mobile Chrome Pixel 5 375x812)  
**Résultat Global :** **150 / 150 tests passés avec succès (100% de réussite, durée globale : 50.6s)**  
**Analyse statique PHPStan :** `[OK] No errors` (Analyse du Core & Sdk)  
**Compilateur TypeScript :** `[OK] No errors` (`npm run build:ts`, mode strict)  

---

## 1. Synthèse Exécutive de la Campagne d'Audit Approfondi

Cette campagne d'audit E2E et de qualification UAT approfondie (`08-deep-uat-visual-stress.spec.ts`) a été conçue pour éprouver le système en conditions réelles d'utilisation extrême, sans raccourci API, et valider la conformité rigoureuse du CMS avec l'ensemble des exigences architecturales (`/docs/ARCHITECTURE.md`), de roadmap (`/docs/ROADMAP.md`) et du cahier de recette (`/docs/sources-v2/`).

L'audit s'est structuré autour de **4 axes fondamentaux** :
1. **Parcours Utilisateurs Complets de Bout en Bout (100% UI réels) :**
   - Parcours complet d'Onboarding Familial (1 adulte et 2 enfants dont 1 multi-disciplines, règlement chèque en 3x, remise fratrie -10%, inspection Trésorier via `/login`, ajustement des ceintures, émission et activation du jeton OTP sur `/activate-otp.php`, et connexion parent avec vérification des rattachements et du partage RGPD).
   - Parcours Éditorial complet (Création par un Rédacteur d'un article multi-colonnes avec encadré callout en statut brouillon, relecture par le Président, bascule en statut publié et consultation sur la vitrine `/` avec modale de lecture plein écran).
2. **Cas Passants & Non-Passants (Robustesse, Limites & Sécurité) :**
   - Blocage client et rejet API (422) sur soumissions incomplètes, dates de naissance futures, tuteur mineur (<18 ans) et dépassement de quota familial (>10 membres).
   - Rejet immédiat (400) des rejeux de jetons OTP expirés ou déjà consommés.
   - Rejet strict (422) des uploads malveillants (`.php`, faux `.jpg` exécutables) sur certificats médicaux et médias CMS.
   - Étanchéité absolue des points d'accès `/login` (rejet 401 du Superadmin) et `/sys-admin-xyz` (rejet 401 des utilisateurs standards).
3. **Scénarios de Charge & Planning Plein :**
   - Détection et signalement visuel d'une discipline à 100% de capacité (Judo : 40 inscrits pour 30 places) avec affichage du badge clignotant `data-testid="capacity-alert-1"` et jauge rouge sur `/stats.php`.
   - Inscription d'adhérents supplémentaires dans un créneau saturé sans crash ni dégradation de service.
   - Recherche instantanée (0ms) et filtrage combiné (Statut + Discipline) sur annuaire massif de ~60 adhérents.
4. **Audit Visuel & Ergonomie Responsive (Desktop 1280x800 vs Mobile Pixel 5 375x812) :**
   - Validation de la position du logo à cheval Header/Sidebar et centrage du nom de club sur Desktop.
   - Masquage responsive du logo fixe et fonctionnement fluide du tiroir mobile (`#mobile-menu-btn` + `#sidebar-backdrop`) sur Pixel 5.
   - Absence absolue de débordement horizontal (`document.documentElement.scrollWidth <= window.innerWidth`) sur les 8 pages clés du CMS.
   - Isolation visuelle sombre (`bg-slate-950` / accents techniques) de la console Superadmin (`/sys-console.php`).

---

## 2. Matrice Complète des Résultats par Axe d'Audit

| Axe d'Audit | Identifiant Test | Description du Scénario | Desktop Chrome | Mobile Pixel 5 | Statut |
| :--- | :--- | :--- | :---: | :---: | :---: |
| **Axe 1 : Parcours E2E** | `AXE 1.1` | **Parcours Famille & Onboarding 100% UI :** Visiteur saisit 1 adulte + 2 enfants (Judo + Karaté), Chèque 3x, remise -10%. Trésorier se connecte sur `/login`, inspecte, ajuste la ceinture, valide le dossier. Récupération OTP, activation du compte sur `/activate-otp.php`, connexion du parent, accès à "Mes rattachements" et consentement RGPD. | **PASS** (9.1s) | **PASS** (9.3s) | **VALIDÉ** |
| **Axe 1 : Parcours E2E** | `AXE 1.2` | **Parcours Éditorial Complet :** Rédacteur connecté crée article multi-colonnes (2 colonnes + callout) en brouillon. Non-apparition sur `/`. Président se connecte, relit dans l'onglet 2 du CMS, publie. Affichage immédiat sur `/` et ouverture modale de lecture. | **PASS** (4.4s) | **PASS** (4.4s) | **VALIDÉ** |
| **Axe 2 : Robustesse** | `AXE 2.1` | **Formulaires & Dates Incohérentes :** Blocage UI sur formulaire vide. Rejet 422 sur date de naissance future (`2030-01-01`) et tuteur légal mineur (< 18 ans). | **PASS** (927ms) | **PASS** (975ms) | **VALIDÉ** |
| **Axe 2 : Robustesse** | `AXE 2.2` | **Protection anti-DoS Famille :** Rejet 422 avec message explicite lors d'une tentative d'inscription de plus de 10 adhérents dans un dossier. | **PASS** (87ms) | **PASS** (33ms) | **VALIDÉ** |
| **Axe 2 : Robustesse** | `AXE 2.3` | **Sécurité OTP :** Rejet immédiat (400 "Jeton OTP invalide ou déjà utilisé") lors de la réutilisation d'un token déjà consommé. | **PASS** (300ms) | **PASS** (282ms) | **VALIDÉ** |
| **Axe 2 : Robustesse** | `AXE 2.4` | **Uploads Sécurisés :** Rejet 422 des scripts `.php`, des faux fichiers `.jpg` contenant du code bash/php exécutable et des binaires non-images (`.exe`). | **PASS** (53ms) | **PASS** (67ms) | **VALIDÉ** |
| **Axe 2 : Robustesse** | `AXE 2.5` | **Étanchéité d'Accès :** Rejet 401 du Superadmin sur `/login` standard et rejet 401 des utilisateurs classiques sur `/sys-admin-xyz`. | **PASS** (31ms) | **PASS** (39ms) | **VALIDÉ** |
| **Axe 3 : Charge & Stress** | `AXE 3.1` | **Saturation Capacité 100% :** Affichage de l'alerte `data-testid="capacity-alert-1"` (`COMPLET`) sur `/stats.php` pour le Judo (40/30) et absorption d'inscriptions supplémentaires sans crash. | **PASS** (916ms) | **PASS** (911ms) | **VALIDÉ** |
| **Axe 3 : Charge & Stress** | `AXE 3.2` | **Recherche Instantanée & Filtres :** Recherche 0ms par saisie dans `#member-search` et filtrage combiné Statut 'Actif' + Discipline 'Judo' sur ~60 adhérents. | **PASS** (1.1s) | **PASS** (1.1s) | **VALIDÉ** |
| **Axe 4 : Visuel & Responsive**| `AXE 4.1` | **Conformité Desktop 1280x800 :** Logo à cheval `#overlapping-logo-badge` visible, titre `#club-name-heading` centré, masquage du bouton hamburger mobile. | **PASS** (632ms) | **PASS** (625ms) | **VALIDÉ** |
| **Axe 4 : Visuel & Responsive**| `AXE 4.2` | **Conformité Mobile 375x812 :** Masquage propre du logo à cheval fixe, visibilité du bouton mobile, ouverture/fermeture du tiroir `#sidebar` et du fond `#sidebar-backdrop`. | **PASS** (739ms) | **PASS** (779ms) | **VALIDÉ** |
| **Axe 4 : Visuel & Responsive**| `AXE 4.3` | **Non-débordement horizontal strict :** `scrollWidth <= clientWidth` sur `/`, `/register.php`, `/members.php`, `/registrations.php`, `/cms.php`, `/roles.php`, `/stats.php`, `/sys-console.php` en Desktop et Mobile. | **PASS** (3.7s) | **PASS** (3.5s) | **VALIDÉ** |
| **Axe 4 : Visuel & Responsive**| `AXE 4.4` | **Isolation Thème Superadmin :** Vérification de la charte sombre (`bg-slate-950`, bordures rouges/ardoise) sur les contrôles système et la modale Rollover de `/sys-console.php`. | **PASS** (1.1s) | **PASS** (1.1s) | **VALIDÉ** |

---

## 3. Analyse des Anomalies Identifiées et Correctifs Appliqués

Au cours de la campagne d'audit, 3 anomalies visuelles et fonctionnelles mineures ont été détectées et corrigées immédiatement :

### 3.1. Encodage HTML Entity dans la modale de lecture d'actualités (`#reader-title`)
- **Symptôme :** Les titres d'articles contenant des apostrophes ou caractères accentués affichaient littéralement le code HTML échappé (ex: `Stage International d&#039;Automne 2026`) dans la modale de lecture d'accueil.
- **Cause :** `home.ts` utilisait `title.textContent = art.title;` alors que le titre retourné par l'API était déjà assaini avec `htmlspecialchars()`.
- **Correctif :** Modification de `core/ui/ts/home.ts` pour injecter le titre décodé via `title.innerHTML = art.title;` et recompilation avec `npm run build:ts`.

### 3.2. Signalement visuel de saturation de capacité sur `/stats.php`
- **Symptôme :** Lorsqu'une discipline dépassait 100% de son effectif théorique (30 élèves), le badge d'occupation restait vert émeraude (`bg-emerald-100`) sans mention explicite de saturation.
- **Correctif :** Ajout dans `public/stats.php` d'une alerte visuelle rouge pulsante `⚠️ COMPLET (X / 30)` avec l'attribut `data-testid="capacity-alert-<?= $ds['id'] ?>"` et barre de progression rouge (`bg-red-600`) dès que `occupancy_pct >= 100`.

### 3.3. Condition de course au chargement du wizard d'inscription (`register.ts`)
- **Symptôme :** Sous forte charge parallèle multi-workers, la saisie automatisée de la date de naissance pouvait intervenir avant l'attachement de l'événement `change` si l'appel asynchrone `loadDisciplinesCatalog()` prenait quelques dizaines de millisecondes.
- **Correctif :** Initialisation synchrone immédiate des écouteurs de formulaires dans `core/ui/ts/register.ts` dès l'événement `DOMContentLoaded`, avant la fin du chargement asynchrone des catalogues.

---

## 4. Couverture Globale de Non-Régression (Bilan des 8 Domaines)

L'exécution finale de `npm run test:e2e` a validé **100% des 150 tests automatisés** répartis sur les 8 domaines du CMS :

1. **Domaine 1 — Authentification, Shadow Login, Profil 2FA & Matrice RBAC :** 28 tests **PASS**
2. **Domaine 2 — CRM Adhérents, Co-Tuteurs, RGPD & Santé :** 22 tests **PASS**
3. **Domaine 3 — Inscriptions en Ligne, Panier Familial, Trésorerie & OTP :** 24 tests **PASS**
4. **Domaine 4 — Studio CMS Éditorial, Builder Multi-Colonnes & Sécurité :** 16 tests **PASS**
5. **Domaine 5 — Parcours Personas Utilisateurs (UAT) :** 6 tests **PASS**
6. **Domaine 6 — Rollover Saisonnier & Tableaux de Bord Analytiques :** 14 tests **PASS**
7. **Domaine 7 — Moteur de Modules & SDK Kanso-ya (Lot 3 & Safe Mode) :** 14 tests **PASS**
8. **Domaine 8 — Deep Audit E2E, Tests Visuels & Responsive Stress :** 26 tests **PASS**

---

## 5. Recommandations pour la Mise en Production

1. **Certificat SSL et Protocole HTTPS :** La configuration Playwright utilise actuellement `ignoreHTTPSErrors: true` pour le vhost Laragon auto-signé. En production, s'assurer du renouvellement automatique des certificats Let's Encrypt / ACME.
2. **Mode SQLite WAL & Synchronous NORMAL :** Les tests de charge sous 6 workers parallèles confirment la robustesse de `PRAGMA busy_timeout = 15000` et `PRAGMA synchronous = NORMAL`. Maintenir ces paramètres en production.
3. **Nettoyage automatique des fichiers temporaires :** Le mécanisme d'upload sécurisé dans `storage/private_uploads/` garantit l'étanchéité RGPD et la non-exécution de scripts via `.htaccess`.
