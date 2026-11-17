<?php

declare(strict_types=1);

/**
 * Migration 001 : Schéma relationnel complet du Core Kanso-ya.
 * Intègre : Branding/SMTP, 2FA, Profil étendu, Logs d'emails, et séparation Grade (Âge) / Niveau (Ceinture/Gant).
 */

require_once __DIR__ . '/../Database.php';

use Core\Api\Database\Database;

/**
 * Crée ou met à jour l'intégralité des tables du Core.
 *
 * @return void
 */
function runCoreSchemaMigration(): void
{
    $dbDir = __DIR__ . '/../../../../storage/db';
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }

    $db = Database::getInstance();

    $sql = <<<SQL
    -- 1. SOCLE SYSTÈME, BRANDING, SMTP & LOGS D'EMAILS
    CREATE TABLE IF NOT EXISTS sys_superadmins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username VARCHAR(100) NOT NULL UNIQUE,
        email VARCHAR(255) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        failed_login_attempts INTEGER NOT NULL DEFAULT 0,
        locked_until DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS sys_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS sys_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        superadmin_id INTEGER NULL,
        action VARCHAR(150) NOT NULL,
        details TEXT NULL,
        ip_address VARCHAR(45) NOT NULL DEFAULT '127.0.0.1',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(superadmin_id) REFERENCES sys_superadmins(id) ON DELETE SET NULL
    );

    CREATE TABLE IF NOT EXISTS email_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        recipient_email VARCHAR(255) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        template_type VARCHAR(50) NOT NULL DEFAULT 'OTP',
        status VARCHAR(30) NOT NULL DEFAULT 'sent',
        smtp_response VARCHAR(255) NULL,
        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    -- 2. RBAC, UTILISATEURS (AVEC 2FA & PROFIL DYNAMIQUE) & AUDIT APPLICATIF
    CREATE TABLE IF NOT EXISTS roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(100) NOT NULL UNIQUE,
        is_system INTEGER NOT NULL DEFAULT 0,
        permissions TEXT NOT NULL DEFAULT '[]'
    );

    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email VARCHAR(255) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        first_name VARCHAR(100) NULL,
        last_name VARCHAR(100) NULL,
        phone VARCHAR(50) NULL,
        address TEXT NULL,
        custom_fields TEXT NOT NULL DEFAULT '[]',
        role_id INTEGER NOT NULL DEFAULT 1,
        is_active INTEGER NOT NULL DEFAULT 1,
        two_factor_enabled INTEGER NOT NULL DEFAULT 0,
        two_factor_secret VARCHAR(64) NULL,
        otp_code VARCHAR(100) NULL,
        otp_expires DATETIME NULL,
        otp_expires_at DATETIME NULL,
        failed_login_attempts INTEGER NOT NULL DEFAULT 0,
        locked_until DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(role_id) REFERENCES roles(id)
    );

    CREATE TABLE IF NOT EXISTS app_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        action VARCHAR(150) NOT NULL,
        target_type VARCHAR(50) NULL,
        target_id INTEGER NULL,
        details TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
    );

    -- 3. CRM ADHÉRENTS, RATTACHEMENTS LÉGAUX & DOSSIERS FINANCIERS
    CREATE TABLE IF NOT EXISTS members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        birth_date DATE NOT NULL,
        email VARCHAR(255) NULL,
        phone VARCHAR(50) NULL,
        address TEXT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        emergency_contact_name VARCHAR(255) NULL,
        emergency_contact_phone VARCHAR(50) NULL,
        emergency_contact_relation VARCHAR(100) NULL,
        emergency_contact_secondary_phone VARCHAR(50) NULL,
        health_attestation_signed INTEGER NOT NULL DEFAULT 0,
        health_attestation_date DATETIME NULL,
        medical_file_path VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS member_tutor (
        member_id INTEGER NOT NULL,
        tutor_id INTEGER NOT NULL,
        relationship VARCHAR(50) NOT NULL DEFAULT 'Parent / Tuteur légal',
        info_shared INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (member_id, tutor_id),
        FOREIGN KEY(member_id) REFERENCES members(id) ON DELETE CASCADE,
        FOREIGN KEY(tutor_id) REFERENCES users(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS registrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        payer_user_id INTEGER NULL,
        season VARCHAR(20) NOT NULL DEFAULT '2026-2027',
        total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        discount_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        discount_description VARCHAR(255) NULL,
        payment_method VARCHAR(50) NOT NULL DEFAULT 'cheque',
        installments INTEGER NOT NULL DEFAULT 1,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        validated_by INTEGER NULL,
        validated_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(payer_user_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY(validated_by) REFERENCES users(id) ON DELETE SET NULL
    );

    -- 4. RÉFÉRENTIEL SPORTIF : DISCIPLINES, GRADES (ÂGE) & NIVEAUX (CEINTURE/GANT)
    CREATE TABLE IF NOT EXISTS disciplines (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(100) NOT NULL UNIQUE,
        teacher_user_id INTEGER NULL
    );

    CREATE TABLE IF NOT EXISTS levels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        discipline_id INTEGER NOT NULL,
        level_type VARCHAR(30) NOT NULL DEFAULT 'grade',
        name VARCHAR(100) NOT NULL,
        color_hex VARCHAR(20) NULL DEFAULT '#10b981',
        age_min INTEGER NULL,
        age_max INTEGER NULL,
        rank_order INTEGER NOT NULL DEFAULT 1,
        FOREIGN KEY(discipline_id) REFERENCES disciplines(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS member_practices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        member_id INTEGER NOT NULL,
        discipline_id INTEGER NOT NULL,
        level_id INTEGER NULL,
        grade_id INTEGER NULL,
        is_category_forced INTEGER NOT NULL DEFAULT 0,
        registration_id INTEGER NULL,
        season VARCHAR(20) NOT NULL DEFAULT '2026-2027',
        FOREIGN KEY(member_id) REFERENCES members(id) ON DELETE CASCADE,
        FOREIGN KEY(discipline_id) REFERENCES disciplines(id) ON DELETE CASCADE,
        FOREIGN KEY(registration_id) REFERENCES registrations(id) ON DELETE SET NULL
    );

    -- 5. MOTEUR ÉDITORIAL CMS
    CREATE TABLE IF NOT EXISTS article_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(100) NOT NULL UNIQUE,
        badge_color VARCHAR(80) NOT NULL DEFAULT 'bg-emerald-100 text-emerald-800'
    );

    CREATE TABLE IF NOT EXISTS articles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        category_id INTEGER NOT NULL DEFAULT 1,
        author_id INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER NULL,
        updated_by INTEGER NULL,
        visibility VARCHAR(30) NOT NULL DEFAULT 'public',
        status VARCHAR(30) NOT NULL DEFAULT 'draft',
        image_url VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(category_id) REFERENCES article_categories(id),
        FOREIGN KEY(author_id) REFERENCES users(id)
    );

    CREATE TABLE IF NOT EXISTS pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug VARCHAR(100) NOT NULL UNIQUE,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'published',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS email_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        template_key VARCHAR(100) NOT NULL UNIQUE,
        title VARCHAR(255) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        body_html TEXT NOT NULL,
        variables_available TEXT NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
SQL;

    $db->exec($sql);

    // Ajout idempotent des nouvelles colonnes si la BDD existait déjà
    $alters = [
        "ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL",
        "ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NULL",
        "ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL",
        "ALTER TABLE users ADD COLUMN address TEXT NULL",
        "ALTER TABLE users ADD COLUMN custom_fields TEXT NOT NULL DEFAULT '[]'",
        "ALTER TABLE users ADD COLUMN two_factor_enabled INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN two_factor_secret VARCHAR(64) NULL",
        "ALTER TABLE members ADD COLUMN email VARCHAR(255) NULL",
        "ALTER TABLE members ADD COLUMN phone VARCHAR(50) NULL",
        "ALTER TABLE members ADD COLUMN address TEXT NULL",
        "ALTER TABLE member_tutor ADD COLUMN relationship VARCHAR(50) NOT NULL DEFAULT 'Parent / Tuteur légal'",
        "ALTER TABLE disciplines ADD COLUMN teacher_user_id INTEGER NULL",
        "ALTER TABLE levels ADD COLUMN level_type VARCHAR(30) NOT NULL DEFAULT 'grade'",
        "ALTER TABLE levels ADD COLUMN color_hex VARCHAR(20) NULL DEFAULT '#10b981'",
        "ALTER TABLE members ADD COLUMN emergency_contact_relation VARCHAR(100) NULL",
        "ALTER TABLE members ADD COLUMN emergency_contact_secondary_phone VARCHAR(50) NULL",
        "ALTER TABLE members ADD COLUMN health_attestation_signed INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE members ADD COLUMN health_attestation_date DATETIME NULL",
        "ALTER TABLE registrations ADD COLUMN discount_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00",
        "ALTER TABLE registrations ADD COLUMN discount_description VARCHAR(255) NULL",
        "ALTER TABLE articles ADD COLUMN created_by INTEGER NULL",
        "ALTER TABLE articles ADD COLUMN updated_by INTEGER NULL"
    ];
    foreach ($alters as $q) {
        try {
            $db->exec($q);
        } catch (Throwable) {
        }
    }

    // Peuplement des modèles d'emails transactionnels (Point 31)
    $stmtCount = $db->query("SELECT COUNT(*) FROM email_templates");
    if ($stmtCount && (int) $stmtCount->fetchColumn() === 0) {
        $templates = [
            [
                'key'       => 'otp_invitation',
                'title'     => 'Invitation d\'activation de compte (OTP 48h)',
                'subject'   => 'Activez votre espace personnel - {{club_name}}',
                'body_html' => "<p>Bonjour {{first_name}},</p><p>Votre dossier d'adhésion a été validé. Cliquez sur le lien sécurisé suivant pour définir votre mot de passe et accéder à votre espace (lien valable 48h) :</p><p><a href=\"{{activation_link}}\">Activer mon compte</a></p><p>À très bientôt sur les tatamis !</p>",
                'vars'      => '{{first_name}}, {{last_name}}, {{club_name}}, {{activation_link}}, {{expires_in}}'
            ],
            [
                'key'       => 'registration_received',
                'title'     => 'Accusé de réception de pré-inscription',
                'subject'   => 'Confirmation de réception de votre dossier - {{club_name}}',
                'body_html' => "<p>Bonjour {{payer_name}},</p><p>Nous avons bien reçu votre dossier d'adhésion pour la saison {{season}} (Montant : {{total_amount}} €).</p><p>Notre équipe de trésorerie procède actuellement à sa vérification.</p>",
                'vars'      => '{{payer_name}}, {{total_amount}}, {{season}}, {{club_name}}'
            ],
            [
                'key'       => 'registration_validated',
                'title'     => 'Validation du dossier & Reçu de cotisation',
                'subject'   => 'Votre inscription est validée - Reçu officiel {{club_name}}',
                'body_html' => "<p>Bonjour {{payer_name}},</p><p>Votre règlement pour le dossier #{{registration_id}} a été validé avec succès.</p><p>Votre reçu de cotisation officiel et votre attestation pour le CSE sont disponibles au téléchargement dans votre espace adhérent.</p>",
                'vars'      => '{{payer_name}}, {{registration_id}}, {{total_amount}}, {{club_name}}'
            ]
        ];

        $insTpl = $db->prepare("
            INSERT INTO email_templates (template_key, title, subject, body_html, variables_available)
            VALUES (:key, :title, :subject, :body, :vars)
        ");
        foreach ($templates as $t) {
            $insTpl->execute([
                'key'     => $t['key'],
                'title'   => $t['title'],
                'subject' => $t['subject'],
                'body'    => $t['body_html'],
                'vars'    => $t['vars']
            ]);
        }
    }

    echo "[OK] Migration 001 : Schéma enrichi créé avec succès.\n";
}

runCoreSchemaMigration();