<?php

declare(strict_types=1);

/**
 * Migration 002 : Jeu de données massif de Pré-Production & QA (~60 adhérents).
 * Couvre toutes les combinaisons :
 * - Familles nombreuses, parents séparés (RGPD isolé) vs couples (RGPD partagé)
 * - Adhérents majeurs autonomes, mineurs, surclassés
 * - Pratiquants mono-discipline et multi-disciplines (Judo + Karaté + Aïkido)
 * - Chaque pratique est rattachée à une Discipline + un Grade (catégorie d'âge) + un Niveau (ceinture).
 */

require_once __DIR__ . '/001_core_schema.php';

use Core\Api\Database\Database;

function runCoreSeed(): void
{
    $db = Database::getInstance();
    $hash = password_hash('password123', PASSWORD_DEFAULT);

    $db->exec('BEGIN IMMEDIATE');

    // Nettoyage préalable pour idempotence totale
    $db->exec("DELETE FROM member_practices");
    $db->exec("DELETE FROM member_tutor");
    $db->exec("DELETE FROM registrations");
    $db->exec("DELETE FROM members");
    $db->exec("DELETE FROM levels");
    $db->exec("DELETE FROM disciplines");
    $db->exec("DELETE FROM email_logs");
    $db->exec("DELETE FROM sys_logs");
    $db->exec("DELETE FROM app_logs");

    // 1. Superadmin (Mot de passe : password123)
    $db->exec("
        INSERT OR REPLACE INTO sys_superadmins (id, username, email, password_hash, failed_login_attempts, locked_until)
        VALUES (1, 'superadmin', 'superadmin@kanso-ya.local', '$hash', 0, NULL)
    ");

    // 2. Paramètres Système & Métier
    $db->exec("
        INSERT OR REPLACE INTO sys_settings (setting_key, setting_value) VALUES
            ('site_name', 'Kanso-ya - Clubs et Associations'),
            ('site_logo_url', ''),
            ('site_watermark_url', ''),
            ('site_watermark_opacity', '0.05'),
            ('theme_primary_color', '#059669'),
            ('theme_sidebar_color', '#0f172a'),
            ('theme_header_color', '#ffffff'),
            ('theme_accent_color', '#10b981'),
            ('smtp_host', 'smtp.kanso-ya.fr'),
            ('smtp_port', '587'),
            ('smtp_encryption', 'tls'),
            ('smtp_username', 'notifications@kanso-ya.fr'),
            ('smtp_from_email', 'ne-pas-repondre@kanso-ya.fr'),
            ('smtp_from_name', 'Secrétariat Kanso-ya'),
            ('season_type', 'Sept-Juin'),
            ('public_registration_enabled', '1'),
            ('membership_base_price', '150'),
            ('allowed_installment_methods', '[\"cheque\"]'),
            ('registration_confirmation_text', 'Votre dossier d''inscription a bien été transmis au bureau. Dès réception et validation de votre règlement par le Trésorier, vous recevrez un email contenant un lien d''activation sécurisé (OTP valable 48h) vous permettant de créer votre mot de passe et d''accéder à votre espace personnel.');
    ");

    // 3. Rôles RBAC
    $db->exec("
        INSERT OR REPLACE INTO roles (id, name, is_system, permissions) VALUES
            (1, 'Président', 1, '[\"members.view\",\"members.edit\",\"roles.manage\",\"finances.manage\",\"articles.write\",\"articles.publish\",\"pages.manage\",\"settings.manage\",\"logs.view\",\"stats.view\"]'),
            (2, 'Entraîneur', 0, '[\"members.view\",\"articles.write\",\"stats.view\"]'),
            (3, 'Rédacteur', 0, '[\"articles.write\"]'),
            (4, 'Trésorier', 0, '[\"members.view\",\"finances.manage\"]'),
            (5, 'Secrétaire', 0, '[\"members.view\",\"members.edit\",\"articles.write\",\"articles.publish\",\"stats.view\"]'),
            (6, 'Adhérent', 0, '[\"profile.view\"]');
    ");

    // 4. Comptes Utilisateurs (Staff + Enseignants + Tuteurs/Parents + Majeurs) — Mot de passe unique : password123
    $db->exec("
        INSERT OR REPLACE INTO users (id, email, password_hash, first_name, last_name, phone, address, custom_fields, role_id, is_active, two_factor_enabled, two_factor_secret) VALUES
            (1, 'president@kanso-ya.local', '$hash', 'Kenji', 'Takahashi', '06 01 02 03 04', '12 Rue du Dojo, 79100 Thouars', '[{\"label\":\"Licence Fédérale\",\"value\":\"FFJDA-2026-001\"}]', 1, 1, 0, 'JBSWY3DPEHPK3PXP'),
            (2, 'tutorB@mail.com', '$hash', 'Hélène', 'Martin-sensei', '06 22 33 44 55', '8 Avenue Victor Hugo, 79100 Sainte-Verge', '[{\"label\":\"Diplôme\",\"value\":\"DEJEPS Judo / Karaté\"}]', 2, 1, 0, 'JBSWY3DPEHPK3PX2'),
            (3, 'radie_user@mail.com', '$hash', 'Marc', 'Ancien', '06 00 00 00 00', 'Ancienne adresse', '[]', 3, 0, 0, NULL),
            (4, 'tresorier@kanso-ya.local', '$hash', 'Claire', 'Dubois', '06 44 55 66 77', '5 Place de la Mairie, 79100 Thouars', '[]', 4, 1, 0, 'JBSWY3DPEHPK3PX4'),
            (5, 'secretaire@kanso-ya.local', '$hash', 'Sophie', 'Laurent', '06 55 66 77 88', '14 Boulevard de la République, 79100 Thouars', '[]', 5, 1, 0, 'JBSWY3DPEHPK3PX5'),
            (6, 'redacteur@kanso-ya.local', '$hash', 'Julien', 'Plume', '06 66 77 88 99', '3 Rue des Arts, 79100 Thouars', '[]', 3, 1, 0, 'JBSWY3DPEHPK3PX6'),
            (7, 'coach.aikido@kanso-ya.local', '$hash', 'Takeshi', 'Mori', '06 77 88 99 11', '19 Rue du Château, 79100 Thouars', '[{\"label\":\"Grade\",\"value\":\"4e Dan Aïkido\"}]', 2, 1, 0, 'JBSWY3DPEHPK3PX7'),
            (10, 'tutorA@mail.com', '$hash', 'Thomas', 'Martin', '06 11 22 33 44', '24 Rue Pasteur, 79100 Thouars', '[{\"label\":\"Profession\",\"value\":\"Ostéopathe\"}]', 6, 1, 0, 'JBSWY3DPEHPK3P10'),
            (11, 'pierre.moreau@mail.com', '$hash', 'Pierre', 'Moreau', '06 12 34 56 01', '10 Rue des Vignes, 79100 Sainte-Verge', '[]', 6, 1, 0, NULL),
            (12, 'celine.moreau@mail.com', '$hash', 'Céline', 'Moreau', '06 12 34 56 02', '10 Rue des Vignes, 79100 Sainte-Verge', '[]', 6, 1, 0, NULL),
            (13, 'david.lefebvre@mail.com', '$hash', 'David', 'Lefebvre', '06 12 34 56 03', '45 Avenue de la Gare, 79100 Thouars', '[]', 6, 1, 0, NULL),
            (14, 'sarah.exlefebvre@mail.com', '$hash', 'Sarah', 'Mercier', '06 12 34 56 04', '2 Impasse du Moulin, 79300 Bressuire', '[]', 6, 1, 0, NULL),
            (15, 'carlos.garcia@mail.com', '$hash', 'Carlos', 'Garcia', '06 12 34 56 05', '8 Rue Porte au Prévost, 79100 Thouars', '[]', 6, 1, 0, NULL),
            (16, 'nadia.garcia@mail.com', '$hash', 'Nadia', 'Garcia', '06 12 34 56 06', '8 Rue Porte au Prévost, 79100 Thouars', '[]', 6, 1, 0, NULL),
            (17, 'nicolas.roux@mail.com', '$hash', 'Nicolas', 'Roux', '06 12 34 56 07', '17 Rue Saint-Médard, 79100 Thouars', '[]', 6, 1, 0, NULL),
            (18, 'emilie.leroy@mail.com', '$hash', 'Émilie', 'Leroy', '06 12 34 56 08', '31 Rue de la Trémoïlle, 79100 Thouars', '[]', 6, 1, 0, NULL),
            (19, 'antoine.girard@mail.com', '$hash', 'Antoine', 'Girard', '06 12 34 56 09', '6 Rue du Beffroi, 79100 Thouars', '[]', 6, 1, 0, NULL);
    ");

    // 5. Disciplines, Grades (Catégories d'âge) & Niveaux Techniques (Ceintures)
    $db->exec("
        INSERT INTO disciplines (id, name, teacher_user_id) VALUES
            (1, 'Judo', 2),
            (2, 'Karaté', 2),
            (3, 'Aïkido & Self-Défense', 7);

        INSERT INTO levels (id, discipline_id, level_type, name, color_hex, age_min, age_max, rank_order) VALUES
            -- JUDO : Grades (Catégories d'âge)
            (1, 1, 'grade', 'Éveil / Mini-Poussin (4-7 ans)', '#60a5fa', 4, 7, 1),
            (2, 1, 'grade', 'Poussin (8-9 ans)', '#3b82f6', 8, 9, 2),
            (3, 1, 'grade', 'Benjamin (10-11 ans)', '#8b5cf6', 10, 11, 3),
            (4, 1, 'grade', 'Minime (12-13 ans)', '#ec4899', 12, 13, 4),
            (5, 1, 'grade', 'Cadet / Junior (14-19 ans)', '#f59e0b', 14, 19, 5),
            (6, 1, 'grade', 'Senior Adultes (20 ans et +)', '#0f172a', 20, 99, 6),
            -- JUDO : Niveaux techniques (Ceintures)
            (7, 1, 'niveau', 'Ceinture Blanche', '#f8fafc', 4, 99, 1),
            (8, 1, 'niveau', 'Ceinture Blanche-Jaune à Jaune', '#facc15', 6, 99, 2),
            (9, 1, 'niveau', 'Ceinture Orange à Verte', '#16a34a', 8, 99, 3),
            (10, 1, 'niveau', 'Ceinture Bleue à Marron', '#2563eb', 12, 99, 4),
            (11, 1, 'niveau', 'Ceinture Noire (1er Dan et +)', '#090d16', 15, 99, 5),

            -- KARATÉ : Grades (Catégories d'âge)
            (12, 2, 'grade', 'Pupille / Poussin (6-9 ans)', '#3b82f6', 6, 9, 1),
            (13, 2, 'grade', 'Benjamin / Minime (10-13 ans)', '#8b5cf6', 10, 13, 2),
            (14, 2, 'grade', 'Cadet / Junior / Senior (14 ans et +)', '#dc2626', 14, 99, 3),
            -- KARATÉ : Niveaux techniques (Ceintures)
            (15, 2, 'niveau', 'Ceinture Blanche à Jaune (7e-6e Kyu)', '#facc15', 6, 99, 1),
            (16, 2, 'niveau', 'Ceinture Orange à Bleue (5e-2e Kyu)', '#2563eb', 9, 99, 2),
            (17, 2, 'niveau', 'Ceinture Marron à Noire (1er Kyu - Dan)', '#090d16', 14, 99, 3),

            -- AÏKIDO : Grades (Catégories d'âge)
            (18, 3, 'grade', 'Jeunes Ados (11-15 ans)', '#14b8a6', 11, 15, 1),
            (19, 3, 'grade', 'Adultes & Seniors (16 ans et +)', '#0f172a', 16, 99, 2),
            -- AÏKIDO : Niveaux techniques
            (20, 3, 'niveau', '5e à 3e Kyu (Débutant / Intermédiaire)', '#f8fafc', 11, 99, 1),
            (21, 3, 'niveau', '2e Kyu à Hakama / Ceinture Noire', '#090d16', 15, 99, 2);
    ");

    // 6. Les 3 Adhérents de référence pour les tests Playwright (#1, #2, #3)
    $db->exec("
        INSERT INTO members (id, first_name, last_name, birth_date, email, phone, address, status, emergency_contact_name, emergency_contact_phone) VALUES
            (1, 'Enfant', 'Test', '2015-01-01', NULL, NULL, '24 Rue Pasteur, 79100 Thouars', 'active', 'Parent Test (Père)', '06 11 22 33 44'),
            (2, 'Jean', 'Dupont', '1990-05-14', 'jean.dupont@mail.com', '06 77 88 99 00', '3 Impasse des Lilas, 79100 Thouars', 'active', 'Claire Dupont (Épouse)', '06 22 33 44 55'),
            (3, 'Léo', 'Martin', '2015-08-22', NULL, NULL, '24 Rue Pasteur, 79100 Thouars', 'pending', 'Sophie Martin (Mère)', '06 33 44 55 66');

        INSERT INTO member_tutor (member_id, tutor_id, relationship, info_shared) VALUES
            (1, 10, 'Père (Tuteur A)', 0),
            (1, 2, 'Mère (Tuteur B)', 0),
            (3, 10, 'Père (Tuteur A)', 0);
    ");

    // 7. Génération de 55 Adhérents supplémentaires réalistes (Total = 58 adhérents)
    $extraMembers = [
        // Famille Moreau (3 enfants + le père Pierre inscrit en Judo & Aïkido) - Couples avec partage RGPD actif (info_shared = 1)
        [4, 'Pierre', 'Moreau', '1982-03-19', 'pierre.moreau@mail.com', '06 12 34 56 01', '10 Rue des Vignes, 79100 Sainte-Verge', 'active', 'Céline Moreau (Épouse)', '06 12 34 56 02', [], [
            ['d' => 1, 'g' => 6, 'n' => 10],
            ['d' => 3, 'g' => 19, 'n' => 21]
        ]],
        [5, 'Lucas', 'Moreau', '2013-06-11', null, null, '10 Rue des Vignes, 79100 Sainte-Verge', 'active', 'Pierre Moreau (Père)', '06 12 34 56 01', [[11, 'Père', 1], [12, 'Mère', 1]], [
            ['d' => 1, 'g' => 4, 'n' => 9],
            ['d' => 2, 'g' => 13, 'n' => 16]
        ]],
        [6, 'Emma', 'Moreau', '2016-09-25', null, null, '10 Rue des Vignes, 79100 Sainte-Verge', 'active', 'Céline Moreau (Mère)', '06 12 34 56 02', [[11, 'Père', 1], [12, 'Mère', 1]], [
            ['d' => 1, 'g' => 3, 'n' => 8]
        ]],
        [7, 'Noah', 'Moreau', '2019-11-04', null, null, '10 Rue des Vignes, 79100 Sainte-Verge', 'active', 'Céline Moreau (Mère)', '06 12 34 56 02', [[11, 'Père', 1], [12, 'Mère', 1]], [
            ['d' => 1, 'g' => 1, 'n' => 7]
        ]],

        // Famille Lefebvre (Parents séparés : David #13 et Sarah #14 avec info_shared = 0, 2 enfants multi-disciplines)
        [8, 'Mathis', 'Lefebvre', '2012-02-14', null, null, '45 Avenue de la Gare, 79100 Thouars', 'active', 'David Lefebvre (Père)', '06 12 34 56 03', [[13, 'Père', 0], [14, 'Mère', 0]], [
            ['d' => 1, 'g' => 5, 'n' => 10],
            ['d' => 3, 'g' => 18, 'n' => 20]
        ]],
        [9, 'Chloé', 'Lefebvre', '2015-07-30', null, null, '2 Impasse du Moulin, 79300 Bressuire', 'active', 'Sarah Mercier (Mère)', '06 12 34 56 04', [[13, 'Père', 0], [14, 'Mère', 0]], [
            ['d' => 2, 'g' => 13, 'n' => 16]
        ]],

        // Famille Garcia (4 enfants inscrits : Judo + Karaté)
        [10, 'Mateo', 'Garcia', '2010-04-18', null, null, '8 Rue Porte au Prévost, 79100 Thouars', 'active', 'Carlos Garcia (Père)', '06 12 34 56 05', [[15, 'Père', 1], [16, 'Mère', 1]], [
            ['d' => 1, 'g' => 5, 'n' => 10],
            ['d' => 2, 'g' => 14, 'n' => 17]
        ]],
        [11, 'Sofia', 'Garcia', '2013-12-02', null, null, '8 Rue Porte au Prévost, 79100 Thouars', 'active', 'Nadia Garcia (Mère)', '06 12 34 56 06', [[15, 'Père', 1], [16, 'Mère', 1]], [
            ['d' => 2, 'g' => 13, 'n' => 16]
        ]],
        [12, 'Diego', 'Garcia', '2017-05-19', null, null, '8 Rue Porte au Prévost, 79100 Thouars', 'active', 'Carlos Garcia (Père)', '06 12 34 56 05', [[15, 'Père', 1], [16, 'Mère', 1]], [
            ['d' => 1, 'g' => 2, 'n' => 8]
        ]],
        [13, 'Elena', 'Garcia', '2020-08-09', null, null, '8 Rue Porte au Prévost, 79100 Thouars', 'pending', 'Nadia Garcia (Mère)', '06 12 34 56 06', [[15, 'Père', 1], [16, 'Mère', 1]], [
            ['d' => 1, 'g' => 1, 'n' => 7]
        ]],

        // Famille Roux (2 enfants)
        [14, 'Hugo', 'Roux', '2014-01-23', null, null, '17 Rue Saint-Médard, 79100 Thouars', 'active', 'Nicolas Roux (Père)', '06 12 34 56 07', [[17, 'Père', 1]], [
            ['d' => 1, 'g' => 4, 'n' => 9]
        ]],
        [15, 'Jade', 'Roux', '2018-03-14', null, null, '17 Rue Saint-Médard, 79100 Thouars', 'active', 'Nicolas Roux (Père)', '06 12 34 56 07', [[17, 'Père', 1]], [
            ['d' => 2, 'g' => 12, 'n' => 15]
        ]],

        // Famille Leroy (3 enfants dont 1 en attente)
        [16, 'Gabriel', 'Leroy', '2011-10-05', null, null, '31 Rue de la Trémoïlle, 79100 Thouars', 'active', 'Émilie Leroy (Mère)', '06 12 34 56 08', [[18, 'Mère', 1]], [
            ['d' => 1, 'g' => 5, 'n' => 10],
            ['d' => 2, 'g' => 14, 'n' => 16]
        ]],
        [17, 'Louise', 'Leroy', '2015-04-28', null, null, '31 Rue de la Trémoïlle, 79100 Thouars', 'active', 'Émilie Leroy (Mère)', '06 12 34 56 08', [[18, 'Mère', 1]], [
            ['d' => 1, 'g' => 3, 'n' => 9]
        ]],
        [18, 'Arthur', 'Leroy', '2019-01-17', null, null, '31 Rue de la Trémoïlle, 79100 Thouars', 'pending', 'Émilie Leroy (Mère)', '06 12 34 56 08', [[18, 'Mère', 1]], [
            ['d' => 1, 'g' => 1, 'n' => 7]
        ]],

        // Famille Girard (2 enfants + père Antoine inscrit en Karaté & Aïkido)
        [19, 'Antoine', 'Girard', '1980-11-12', 'antoine.girard@mail.com', '06 12 34 56 09', '6 Rue du Beffroi, 79100 Thouars', 'active', 'Sylvie Girard (Épouse)', '06 98 76 54 32', [], [
            ['d' => 2, 'g' => 14, 'n' => 17],
            ['d' => 3, 'g' => 19, 'n' => 21]
        ]],
        [20, 'Raphaël', 'Girard', '2012-08-19', null, null, '6 Rue du Beffroi, 79100 Thouars', 'active', 'Antoine Girard (Père)', '06 12 34 56 09', [[19, 'Père', 1]], [
            ['d' => 2, 'g' => 14, 'n' => 16]
        ]],
        [21, 'Alice', 'Girard', '2016-06-03', null, null, '6 Rue du Beffroi, 79100 Thouars', 'active', 'Antoine Girard (Père)', '06 12 34 56 09', [[19, 'Père', 1]], [
            ['d' => 1, 'g' => 3, 'n' => 8]
        ]]
    ];

    // Génération complémentaire d'adhérents majeurs, jeunes et seniors variés (#22 à #58)
    $firstNames = ['Maxime', 'Camille', 'Alexandre', 'Manon', 'Quentin', 'Sarah', 'Romain', ' Pauline', 'Florian', 'Laura', 'Kévin', 'Julie', 'Bastien', 'Océane', 'Valentin', 'Inès', 'Clément', 'Léa', 'Guillaume', 'Clara', 'Adrien', 'zoé', 'Vincent', 'Charlotte', 'Benoît', 'Amélie', 'Damien', 'Mélanie', 'Sébastien', 'Élise', 'Jérôme', 'Margaux', 'Xavier', 'Anaïs', 'Yannick', 'Maëlys', 'Cédric'];
    $lastNames  = ['Bernard', 'Lambert', 'Bonnet', 'François', 'Martinez', 'Legrand', 'Garnier', 'Faure', 'Rousseau', 'Blanc', 'Guérin', 'Muller', 'Henry', 'Roussel', 'Nicolas', 'Perrin', 'Morin', 'Mathieu', 'Clément', 'Gauthier', 'Dumont', 'Lopez', 'Fontaine', 'Chevalier', 'Robin', 'Masson', 'Sanchez', 'Gérard', 'Nguyen', 'Boyer', 'Denis', 'Lemaire', 'Duval', 'Joly', 'Gautier', 'Roger', 'Roche'];

    for ($idx = 0; $idx < 37; $idx++) {
        $id = 22 + $idx;
        $fn = trim($firstNames[$idx]);
        $ln = $lastNames[$idx];

        // Alternance d'âges (enfants, ados, jeunes adultes, vétérans)
        $birthYears = [1972, 1978, 1984, 1989, 1993, 1997, 2001, 2004, 2008, 2011, 2013, 2015, 2017, 2019];
        $year = $birthYears[$idx % count($birthYears)];
        $month = str_pad((string) (($idx % 12) + 1), 2, '0', STR_PAD_LEFT);
        $day   = str_pad((string) (($idx % 27) + 1), 2, '0', STR_PAD_LEFT);
        $bdate = "$year-$month-$day";
        $age   = 2026 - $year;

        $status = 'active';
        if ($idx % 9 === 0) {
            $status = 'pending';
        } elseif ($idx % 13 === 0) {
            $status = 'radiated';
        }

        $email = $age >= 18 ? strtolower($fn . '.' . $ln . $id . '@mail.com') : null;
        $phone = $age >= 18 ? ('06 ' . str_pad((string) (10 + $idx), 2, '0', STR_PAD_LEFT) . ' 45 78 ' . str_pad((string) $idx, 2, '0', STR_PAD_LEFT)) : null;
        $addr  = ($idx + 1) . ' Rue de la République, 79100 Thouars';
        $emergName  = 'Contact Famille ' . $ln;
        $emergPhone = '06 88 77 66 ' . str_pad((string) $idx, 2, '0', STR_PAD_LEFT);

        $tutors = $age < 18 ? [[11 + ($idx % 8), 'Parent référent', $idx % 2]] : [];

        // Attribution réaliste de 1 ou 2 disciplines avec Grade d'âge et Niveau cohérents
        $practices = [];
        if ($idx % 3 === 0) {
            // Judo
            $gId = $age <= 7 ? 1 : ($age <= 9 ? 2 : ($age <= 11 ? 3 : ($age <= 13 ? 4 : ($age <= 19 ? 5 : 6))));
            $nId = $age <= 7 ? 7 : ($age <= 11 ? 8 : ($age <= 15 ? 9 : ($idx % 2 === 0 ? 10 : 11)));
            $practices[] = ['d' => 1, 'g' => $gId, 'n' => $nId];
            // 1 sur 2 suit aussi l'Aïkido s'il a + de 15 ans
            if ($age >= 16 && $idx % 6 === 0) {
                $practices[] = ['d' => 3, 'g' => 19, 'n' => 20];
            }
        } elseif ($idx % 3 === 1) {
            // Karaté
            $gId = $age <= 9 ? 12 : ($age <= 13 ? 13 : 14);
            $nId = $age <= 9 ? 15 : ($age <= 14 ? 16 : 17);
            $practices[] = ['d' => 2, 'g' => $gId, 'n' => $nId];
        } else {
            // Multi-disciplines Judo + Karaté ou Aïkido
            if ($age >= 16) {
                $practices[] = ['d' => 3, 'g' => 19, 'n' => ($idx % 2 === 0 ? 20 : 21)];
                $practices[] = ['d' => 1, 'g' => 6, 'n' => 10];
            } else {
                $gId = $age <= 9 ? 2 : ($age <= 11 ? 3 : 4);
                $practices[] = ['d' => 1, 'g' => $gId, 'n' => 8];
                $practices[] = ['d' => 2, 'g' => 13, 'n' => 15];
            }
        }

        $extraMembers[] = [$id, $fn, $ln, $bdate, $email, $phone, $addr, $status, $emergName, $emergPhone, $tutors, $practices];
    }

    $stmtMem = $db->prepare("
        INSERT INTO members (id, first_name, last_name, birth_date, email, phone, address, status, emergency_contact_name, emergency_contact_phone)
        VALUES (:id, :fn, :ln, :bd, :em, :ph, :ad, :st, :en, :ep)
    ");
    $stmtTut = $db->prepare("
        INSERT OR IGNORE INTO member_tutor (member_id, tutor_id, relationship, info_shared)
        VALUES (:mid, :tid, :rel, :sh)
    ");
    $stmtPrac = $db->prepare("
        INSERT INTO member_practices (member_id, discipline_id, grade_id, level_id, registration_id, season)
        VALUES (:mid, :did, :gid, :lid, :rid, '2026-2027')
    ");

    foreach ($extraMembers as $row) {
        [$id, $fn, $ln, $bdate, $email, $phone, $addr, $status, $emergName, $emergPhone, $tutors, $practices] = $row;
        $stmtMem->execute([
            'id' => $id,
            'fn' => $fn,
            'ln' => $ln,
            'bd' => $bdate,
            'em' => $email,
            'ph' => $phone,
            'ad' => $addr,
            'st' => $status,
            'en' => $emergName,
            'ep' => $emergPhone
        ]);

        foreach ($tutors as $t) {
            $stmtTut->execute([
                'mid' => $id,
                'tid' => $t[0],
                'rel' => $t[1],
                'sh'  => $t[2]
            ]);
        }

        foreach ($practices as $p) {
            $stmtPrac->execute([
                'mid' => $id,
                'did' => $p['d'],
                'gid' => $p['g'],
                'lid' => $p['n'],
                'rid' => null
            ]);
        }
    }

    // 8. Dossiers financiers & Pratiques des 3 membres de référence (#1, #2, #3)
    $db->exec("
        INSERT INTO registrations (id, payer_user_id, season, total_amount, payment_method, installments, status, validated_by, validated_at) VALUES
            (5, 10, '2026-2027', 150.00, 'cheque', 3, 'pending', NULL, NULL),
            (12, 10, '2026-2027', 270.00, 'cheque', 2, 'pending', NULL, NULL),
            (15, 11, '2026-2027', 540.00, 'cheque', 3, 'validated', 4, CURRENT_TIMESTAMP),
            (16, 13, '2026-2027', 270.00, 'virement', 1, 'validated', 4, CURRENT_TIMESTAMP),
            (17, 15, '2026-2027', 540.00, 'cheque', 3, 'pending', NULL, NULL),
            (18, 18, '2026-2027', 405.00, 'cheque', 2, 'pending', NULL, NULL);

        INSERT INTO member_practices (member_id, discipline_id, grade_id, level_id, registration_id, season) VALUES
            (1, 1, 3, 8, 12, '2026-2027'),
            (1, 2, 13, 15, 12, '2026-2027'),
            (2, 1, 6, 11, NULL, '2026-2027'),
            (3, 2, 13, 15, 5, '2026-2027');
    ");

    // 9. Logs Système & Emails SMTP
    $db->exec("
        INSERT INTO sys_logs (superadmin_id, action, details, ip_address) VALUES
            (1, 'SYSTEM_BOOT_CHECK', 'Vérification intégrité SQLite WAL & Configuration SMTP', '127.0.0.1'),
            (1, 'SMTP_CONFIG_VERIFIED', 'Serveur relais smtp.kanso-ya.fr:587 prêt (TLS)', '127.0.0.1');

        INSERT INTO email_logs (recipient_email, subject, template_type, status, smtp_response) VALUES
            ('jean.dupont@mail.com', 'Bienvenue au club Kanso-ya - Votre lien OTP (48h)', 'OTP_ONBOARDING', 'sent', '250 2.0.0 OK: queued as 9F8A21'),
            ('pierre.moreau@mail.com', 'Validation du dossier familial #15 (4 adhérents)', 'OTP_ONBOARDING', 'sent', '250 2.0.0 OK: queued as 7B3E12'),
            ('tutorA@mail.com', 'Confirmation de dépôt du dossier familial #12', 'REGISTRATION_RECEIVED', 'sent', '250 2.0.0 OK: queued as 4C1B88');
    ");

    // 10. Catégories, Articles & Pages Légales
    $db->exec("
        INSERT OR REPLACE INTO article_categories (id, name, badge_color) VALUES
            (1, 'Vie du club', 'bg-emerald-100 text-emerald-800'),
            (2, 'Compétition', 'bg-red-100 text-red-800'),
            (3, 'Événements', 'bg-amber-100 text-amber-800');

        INSERT OR REPLACE INTO articles (id, title, content, category_id, author_id, visibility, status) VALUES
            (1, 'Ouverture des inscriptions pour la saison 2026-2027', '<p>Bienvenue au Dojo Kanso-ya ! Les inscriptions pour la nouvelle saison de <strong>Judo</strong>, <strong>Karaté</strong> et <strong>Aïkido</strong> sont officiellement ouvertes.</p><h2>Tarif familial dégressif</h2><p>Profitez de 10% de remise automatique dès le deuxième membre d''une même famille.</p>', 1, 1, 'public', 'published'),
            (2, 'Résultats du Tournoi Régional Nouvelle-Aquitaine', '<p>Félicitations à nos 14 compétiteurs engagés ce week-end à Poitiers ! Le club remporte <strong>4 médailles d''or</strong> et 3 médailles d''argent.</p>', 2, 1, 'public', 'published'),
            (3, 'Stage d''arbitrage & Passage de grades interne (Réservé Adhérents)', '<p>Le prochain passage de grades aura lieu le samedi 12 décembre à 14h00 au Dojo principal.</p>', 3, 1, 'members', 'published');

        INSERT OR REPLACE INTO pages (id, slug, title, content, status) VALUES
            (1, 'mentions-legales', 'Mentions Légales', '<h2>Éditeur du site</h2><p>Association sportive loi 1901 <strong>Kanso-ya - Clubs et Associations</strong>.</p>', 'published'),
            (2, 'rgpd', 'Politique de Confidentialité (RGPD)', '<h2>Protection des données personnelles & familiales</h2><p>Les coordonnées des responsables légaux sont strictement isolées par défaut entre co-tuteurs.</p>', 'published'),
            (3, 'cgv', 'Conditions Générales d''Adhésion (CGV)', '<h2>Cotisation annuelle 2026-2027</h2><p>La cotisation annuelle de base est fixée à 150,00 € par pratiquant.</p>', 'published'),
            (4, 'horaires', 'Horaires des Cours, Lieux & Tarifs', '<h2>Planning Hebdomadaire au Dojo Municipal</h2><p>Judo, Karaté et Aïkido du lundi au samedi.</p>', 'published');
    ");

    $db->exec('COMMIT');
    echo "[OK] Migration 002 : 58 adhérents, 3 disciplines, 21 grades/niveaux et 6 dossiers injectés.\n";
}

runCoreSeed();