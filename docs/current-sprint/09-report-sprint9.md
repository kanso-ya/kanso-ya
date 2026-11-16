# STATUT: VALIDATED

## Bilan d'Exécution & Recette - Sprint 9 : Traçabilité Éditoriale CMS, Attestations Trésorerie, Export CSV & Filtres CRM

**Date d'exécution :** 17 Novembre 2026  
**Jalon :** Sprint 9 (26 SP) — Phase de Finition & Consolidation  
**Responsables :** @architect, @dev-back, @dev-front, @qa-uat  
**Résultat Global :** 100% de réussite (0 erreur PHPStan, 0 erreur TypeScript, 176/176 tests Playwright passants sur Chromium et Mobile Chrome Pixel 5).

---

### 1. Livrables Fonctionnels Réalisés (Points 18 à 26)

1. **Traçabilité Éditoriale Auteur & Éditeur (Points 18 & 19) :**
   - Colonnes `created_by` et `updated_by` ajoutées à la table `articles` dans `001_core_schema.php`.
   - Contrôleur `CmsController.php` adapté avec jointures relationnelles sur la table `users`.
   - Affichage visuel dans l'annuaire du CMS (`core/ui/ts/cms.ts`) : badges avec mentions lisibles `✍️ Rédigé par [Auteur] le [Date]` (`data-testid="article-author"`) et `✏️ Dernière modification par [Éditeur] le [Date]` (`data-testid="article-editor"`).
2. **Reçu de Cotisation & Attestation CE/CSE (Point 20) :**
   - Endpoints sécurisés `GET /api/v1/registrations/:id/receipt` et `GET /api/v1/registrations/:id/cse-attestation` dans `RegistrationController.php`.
   - Modale dédiée `#document-preview-modal` dans `public/registrations.php` et `core/ui/ts/registrations.ts` avec boutons `.btn-download-receipt` et `.btn-download-cse`.
   - Génération de documents officiels avec montant réglé, mention certifiée conforme et liste des bénéficiaires/ayants droit.
3. **Export CSV des Effectifs CRM & Cloisonnement RGPD (Point 21) :**
   - Endpoint `GET /api/v1/members/export` avec encodage universel UTF-8 BOM (`\uFEFF`) pour ouverture directe et sans défaut sous Microsoft Excel.
   - Cloisonnement strict selon rôle : le Bureau (Trésorier/Secrétaire/Président) exporte les données complètes (civilité, contact, adresse, santé), tandis que l'Entraîneur n'exporte strictement que les données terrain d'appel (nom, prénom, catégorie, contacts d'urgence) avec masquage total des adresses privées et finances.
4. **Filtres Combinés CRM & Onglets Disciplines Sans Parenthèses (Points 22 & 23) :**
   - Filtres combinés dynamiques `#filter-member-age` (mineur/majeur) et `#filter-member-health` (attestation Cerfa validée / certificat médical requis) dans `public/members.php` et `core/ui/ts/members.ts`.
   - Suppression totale des parenthèses brutes `(X)` dans les boutons au profit d'élégants badges d'effectif (`.discipline-count-badge`).
5. **Relance Groupée d'OTP en Masse (Point 24) :**
   - Bouton `#btn-bulk-resend-otp` conditionnel à la sélection de cases à cocher `.member-row-checkbox` avec compteur dynamique.
   - Endpoint transactionnel `POST /api/v1/members/bulk-resend-otp` générant et renvoyant les accès aux membres sélectionnés.
6. **Nettoyage des Médias Orphelins (Point 25) :**
   - Endpoint `POST /api/v1/articles/media/cleanup` dans `CmsController.php` analysant les balises `src` de l'ensemble des articles et purgeant les fichiers inutilisés du dossier `/uploads/`.
   - Bouton `#btn-cleanup-orphans` dans `public/cms.php` et `core/ui/ts/cms.ts` avec modale de confirmation.
7. **Tri Interactif Multi-Colonnes (Point 26) :**
   - Tri interactif instantané ascendant/descendant (`▲`/`▼`) sur les colonnes des tables `/members`, `/registrations` et dans le catalogue d'articles `/cms`.

---

### 2. Synthèse des Vérifications Qualité

| Outil de Contrôle | Commande | Statut | Résultat |
|---|---|---|---|
| Analyse statique PHP | `vendor/bin/phpstan analyse core public` | ✅ VALIDÉ | 0 erreur (37/37 fichiers analysés) |
| Compilation TypeScript | `npm run build:ts` | ✅ VALIDÉ | 0 erreur (`tsc` code 0) |
| Compilation CSS | `npm run build:css` | ✅ VALIDÉ | 0 erreur |
| Suite de tests Sprint 9 | `npx playwright test tests/e2e/10-sprint9-cms-crm.spec.ts` | ✅ VALIDÉ | 14/14 tests passants (11s) |
| Suite complète de non-régression | `npm run test:e2e` | ✅ VALIDÉ | 176/176 tests passants (Chromium + Mobile Chrome) |

---

### 3. Décision du Jalon

Le **Sprint 9** est officiellement **VALIDÉ**. Le projet est prêt pour l'exécution immédiate du **Sprint 10** (Console Superadmin multi-pages, refonte RBAC unique à Toggles sticky, Templates d'emails, page /horaires et pack production).
