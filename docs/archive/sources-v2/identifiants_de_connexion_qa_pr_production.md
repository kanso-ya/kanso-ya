# Récapitulatif des Identifiants de Connexion (Jeu de Données de 58 Adhérents)

Tous les comptes du jeu de données partagent le mot de passe unique : **`password123`**

---

## A. Comptes d'Administration & Encadrement (`/login` et `/sys-admin-xyz`)

| Rôle | URL de connexion | Identifiant / Email | Mot de passe | Spécificités à tester |
| :--- | :--- | :--- | :--- | :--- |
| **🛠️ Superadmin** | `/sys-admin-xyz` | `superadmin` | `password123` | Console technique (`/sys-console.php`), Nom du site, Logo, Filigrane, Couleurs, SMTP & Logs d'emails. |
| **👑 Président** | `/login` | `president@kanso-ya.local` | `password123` | Accès total Club : Saison, Cotisation, Statistiques globales, CRM, Trésorerie, Studio CMS, Disciplines, Matrice RBAC. |
| **📋 Secrétaire** | `/login` | `secretaire@kanso-ya.local` | `password123` | Gestion Adhérents, Renvoi OTP, Statistiques globales, Rédaction & Publication d'articles, Texte d'onboarding. |
| **💶 Trésorier** | `/login` | `tresorier@kanso-ya.local` | `password123` | Cotisations & Dossiers (`/registrations.php`), Inspection avec ajustement des Grades/Niveaux, Validation OTP, Radiation/Restauration. |
| **🥋 Enseignant 1 (Judo & Karaté)** | `/login` | `tutorB@mail.com` | `password123` | Statistiques filtrées sur Judo & Karaté (`/stats.php`), Liste d'appel avec anonymisation RGPD des emails/adresses. |
| **🥋 Enseignant 2 (Aïkido)** | `/login` | `coach.aikido@kanso-ya.local` | `password123` | Statistiques filtrées uniquement sur le cours d'Aïkido & Self-Défense. |
| **✍️ Rédacteur** | `/login` | `redacteur@kanso-ya.local` | `password123` | Accès limité au Studio d'articles (`/cms.php`) avec statut Brouillon forcé. |
| **🚫 Compte Radié** | `/login` | `radie_user@mail.com` | `password123` | Doit être rejeté à la connexion (`403` Compte désactivé). |

---

## B. Comptes Parents / Familles (`/login`)

| Famille / Situation | Email de connexion | Mot de passe | Spécificités à tester |
| :--- | :--- | :--- | :--- |
| **Famille Moreau (Couple uni + 3 enfants)** | `pierre.moreau@mail.com` ou `celine.moreau@mail.com` | `password123` | **Partage RGPD activé (`info_shared = 1`)** : les deux parents voient leurs coordonnées mutuelles. 3 enfants (Lucas en Judo+Karaté, Emma, Noah) + le père Pierre lui-même adhérent en Judo+Aïkido. |
| **Famille Lefebvre (Parents séparés, 2 enfants)** | `david.lefebvre@mail.com` ou `sarah.exlefebvre@mail.com` | `password123` | **Isolation RGPD active (`info_shared = 0`)** : David ne voit pas l'adresse/email de Sarah (Bressuire) et inversement. Enfants Mathis (Judo+Aïkido) et Chloé (Karaté). |
| **Famille Garcia (Famille nombreuse, 4 enfants)** | `carlos.garcia@mail.com` | `password123` | 4 enfants inscrits (Mateo, Sofia, Diego, Elena) dans différents grades d'âge (Éveil, Poussin, Minime, Cadet) avec dossier #17 en attente. |
| **Famille Martin (Référence QA)** | `tutorA@mail.com` | `password123` | Responsable de *Enfant Test* (Judo + Karaté) et *Léo Martin*. |