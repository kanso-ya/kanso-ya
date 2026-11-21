# Journal de Livraison Frontend — Lot 4 / Sprint 21 (Données Réelles Stats & Compatibilité MySQL/MariaDB)

**Rôle :** @dev-front  
**Statut :** Terminé & Compilé (`npm run build:ts` : 0 erreur · `npm run build:css` : OK · `php -l` UI : OK)

## 1. Modifications UI & TypeScript
- `Core/Ui/Pages/stats.php` & `Core/Ui/ts/stats.ts` :
  - Ajout de `data-testid="kpi-gender-ratio"` sur `#kpi-gender-ratio` et `data-testid="kpi-gender-details"` sur `#kpi-gender-details`.
  - Suppression définitive de la mention « Répartition estimée », remplacée par l'affichage réel (`Autre X% · Non renseigné Y%` ou `Données réelles déclarées`) synchronisé entre le rendu serveur PHP et `loadDemographicsStats()`.
- `Core/Ui/Pages/members.php` & `Core/Ui/ts/members.ts` :
  - Ajout des sélecteurs optionnels « Genre (optionnel) » dans la modale de création (`data-testid="create-member-gender"`) et d'édition (`data-testid="edit-member-gender"`).
  - Typage `gender?: string | null` sur `MemberItem`, hydratation à l'ouverture de la modale d'édition et transmission dans `PUT /api/v1/members/{id}`.
- `Core/Ui/Pages/register.php` & `Core/Ui/ts/register.ts` :
  - Ajout du sélecteur optionnel « Genre (optionnel) » à l'étape 1 (`data-testid="reg-member-gender"`), transmission dans `POST /api/v1/registrations` et réinitialisation lors de l'ajout d'un second membre de la famille.

## 2. Handoff vers `@qa-uat`
- **Pour le QA/UAT :** Le front et le back sont câblés et compilés, tous les `data-testid` de `02-specs-metier.md` sont en place. Tu peux créer `tests/e2e/22-sprint21-stats-mysql.spec.ts` et lancer Playwright (nouvelle suite + non-régression complète).
