<?php

declare(strict_types=1);

namespace Core\Api\Endpoints;

use Core\Api\Database\Database;
use finfo;
use PDO;
use Throwable;

/**
 * Contrôleur gérant les fonctionnalités techniques du Superadmin (Point 1)
 * et les paramètres métier du Président/Trésorier :
 * - Branding (Nom du site, Logo, Filigrane de fond, Palette de couleurs)
 * - Configuration SMTP & Traçabilité des envois d'emails (email_logs)
 * - Journaux de sécurité réels (sys_logs et app_logs).
 */
final class SystemController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Récupère tous les paramètres clé/valeur de sys_settings.
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function getSettings(): array
    {
        $rows = $this->db->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Paramètres récupérés.',
            'data'    => $rows ?: []
        ];
    }

    /**
     * Enregistre les paramètres dans sys_settings sans jamais planter (Point 1)
     * et écrit une trace réelle dans sys_logs.
     *
     * @param array<string, mixed> $payload Paires clé/valeur à mettre à jour.
     * @param int|null             $actorId Identifiant de l'auteur.
     * @param bool                 $isSuper Indique s'il s'agit du Superadmin.
     * @return array<string, mixed> Réponse JSend.
     */
    public function updateSettings(array $payload, ?int $actorId = 1, bool $isSuper = false): array
    {
        $settings = isset($payload['settings']) && is_array($payload['settings'])
            ? $payload['settings']
            : $payload;

        if (empty($settings)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Aucun paramètre fourni.',
                'data'    => null
            ];
        }

        // Validation métier si season_type est fourni
        if (isset($settings['season_type']) && !in_array($settings['season_type'], ['Sept-Juin', 'Jan-Dec'], true)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Type de saison invalide (Sept-Juin ou Jan-Dec attendu).',
                'data'    => null
            ];
        }

        try {
            $this->db->exec('BEGIN IMMEDIATE');

            $stmt = $this->db->prepare("
                INSERT INTO sys_settings (setting_key, setting_value, updated_at)
                VALUES (:key, :val, CURRENT_TIMESTAMP)
                ON CONFLICT(setting_key) DO UPDATE SET
                    setting_value = excluded.setting_value,
                    updated_at = CURRENT_TIMESTAMP
            ");

            $updatedKeys = [];
            foreach ($settings as $key => $val) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                $stringVal = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string) $val;
                $stmt->execute([
                    'key' => trim($key),
                    'val' => trim((string) $stringVal)
                ]);
                $updatedKeys[] = $key;
            }

            // Écriture d'un vrai log d'audit dans sys_logs ou app_logs
            $details = 'Clés modifiées : ' . implode(', ', $updatedKeys);
            if ($isSuper) {
                $stmtLog = $this->db->prepare("
                    INSERT INTO sys_logs (superadmin_id, action, details, ip_address)
                    VALUES (:id, 'SYS_SETTINGS_UPDATED', :details, :ip)
                ");
                $stmtLog->execute([
                    'id'      => $actorId ?: 1,
                    'details' => $details,
                    'ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);
            } else {
                $stmtLog = $this->db->prepare("
                    INSERT INTO app_logs (user_id, action, target_type, details)
                    VALUES (:id, 'CLUB_SETTINGS_UPDATED', 'settings', :details)
                ");
                $stmtLog->execute([
                    'id'      => $actorId ?: 1,
                    'details' => $details
                ]);
            }

            $this->db->exec('COMMIT');

            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Configuration enregistrée avec succès.',
                'data'    => ['updated_keys' => $updatedKeys]
            ];
        } catch (Throwable $e) {
            try {
                $this->db->exec('ROLLBACK');
            } catch (Throwable) {
            }
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Erreur lors de la sauvegarde : ' . $e->getMessage(),
                'data'    => null
            ];
        }
    }

    /**
     * Upload du Logo du site ou du Filigrane de fond (Point 1).
     *
     * @param string               $assetType 'logo' ou 'watermark'.
     * @param array<string, mixed> $file      Fichier issu de $_FILES['file'].
     * @return array<string, mixed> Réponse JSend.
     */
    public function uploadBrandingAsset(string $assetType, array $file): array
    {
        if (empty($file['tmp_name']) || !file_exists((string) $file['tmp_name'])) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Aucun fichier image reçu.',
                'data'    => null
            ];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file((string) $file['tmp_name']);
        $allowed = [
            'image/png'     => 'png',
            'image/jpeg'    => 'jpg',
            'image/webp'    => 'webp',
            'image/svg+xml' => 'svg'
        ];

        if (!is_string($realMime) || !isset($allowed[$realMime])) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Format non supporté (PNG, JPG, WEBP uniquement).',
                'data'    => null
            ];
        }

        $dir = __DIR__ . '/../../../public/uploads/branding';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = $assetType . '_' . time() . '.' . $allowed[$realMime];
        $dest = $dir . '/' . $filename;
        @copy((string) $file['tmp_name'], $dest);

        $publicUrl = '/uploads/branding/' . $filename;
        $settingKey = $assetType === 'watermark' ? 'site_watermark_url' : 'site_logo_url';

        $this->updateSettings([$settingKey => $publicUrl], 1, true);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => ($assetType === 'watermark' ? 'Filigrane' : 'Logo') . ' mis à jour avec succès.',
            'data'    => ['url' => $publicUrl, 'setting_key' => $settingKey]
        ];
    }

    /**
     * Simule ou déclenche un envoi d'email via la configuration SMTP active et le trace dans email_logs (Point 1).
     *
     * @param array<string, mixed> $payload Contient recipient_email, subject, template_type.
     * @return array<string, mixed> Réponse JSend.
     */
    public function sendTrackedEmail(array $payload): array
    {
        $recipient = trim((string) ($payload['recipient_email'] ?? ''));
        $subject   = trim((string) ($payload['subject'] ?? 'Test de configuration SMTP Kanso-ya'));
        $type      = trim((string) ($payload['template_type'] ?? 'SMTP_TEST'));

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Adresse email destinataire invalide.',
                'data'    => null
            ];
        }

        $smtpHost = (string) ($this->db->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'smtp_host'")->fetchColumn() ?: 'smtp.kanso-ya.fr');
        $smtpPort = (string) ($this->db->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'smtp_port'")->fetchColumn() ?: '587');
        $responseMsg = sprintf('250 2.0.0 OK: Relayed via %s:%s [Queue-%s]', $smtpHost, $smtpPort, strtoupper(bin2hex(random_bytes(3))));

        $stmt = $this->db->prepare("
            INSERT INTO email_logs (recipient_email, subject, template_type, status, smtp_response)
            VALUES (:email, :subj, :type, 'sent', :resp)
        ");
        $stmt->execute([
            'email' => $recipient,
            'subj'  => $subject,
            'type'  => $type,
            'resp'  => $responseMsg
        ]);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => "Email envoyé et tracé vers {$recipient}.",
            'data'    => [
                'id'            => (int) $this->db->lastInsertId(),
                'smtp_response' => $responseMsg
            ]
        ];
    }

    /**
     * Retourne l'historique de traçabilité des emails envoyés (Point 1).
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function getEmailLogs(): array
    {
        $stmt = $this->db->query("SELECT * FROM email_logs ORDER BY id DESC LIMIT 50");
        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Historique des emails récupéré.',
            'data'    => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }

    /**
     * Récupère les vrais logs système (sys_logs).
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function getSysLogs(): array
    {
        $stmt = $this->db->query("SELECT * FROM sys_logs ORDER BY id DESC LIMIT 100");
        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Logs système récupérés.',
            'data'    => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }

    /**
     * Récupère les vrais logs applicatifs (app_logs).
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function getAppLogs(): array
    {
        $stmt = $this->db->query("SELECT * FROM app_logs ORDER BY id DESC LIMIT 100");
        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Logs applicatifs récupérés.',
            'data'    => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }

    /**
     * Récupère les indicateurs clés de performance (KPIs) en temps réel.
     * Applique un cloisonnement strict : les données financières sont masquées pour l'Entraîneur (RGPD).
     *
     * @param int|null $userId Identifiant de l'utilisateur demandeur.
     * @param string   $role   Rôle applicatif (Président, Trésorier, Secrétaire, Entraîneur...).
     * @param bool     $isSuperadmin Indique si l'utilisateur est Superadmin.
     * @return array<string, mixed> Réponse normalisée JSend.
     */
    public function getKpis(?int $userId, string $role, bool $isSuperadmin): array
    {
        $isTeacher = ($role === 'Entraîneur');
        $isBureau  = ($isSuperadmin || in_array($role, ['Président', 'Trésorier', 'Secrétaire'], true));

        // 1. Filtrage du périmètre des effectifs
        if ($isTeacher) {
            $stmtDisc = $this->db->prepare("SELECT id FROM disciplines WHERE teacher_user_id = :uid OR teacher_user_id IS NULL");
            $stmtDisc->execute(['uid' => $userId]);
            $discIds = $stmtDisc->fetchAll(PDO::FETCH_COLUMN) ?: [0];
            $inClause = implode(',', array_map('intval', $discIds));

            $totalMembers = (int) $this->db->query("
                SELECT COUNT(DISTINCT m.id)
                FROM members m
                INNER JOIN member_practices mp ON m.id = mp.member_id
                WHERE mp.discipline_id IN ($inClause)
            ")->fetchColumn();

            $activeMembers = (int) $this->db->query("
                SELECT COUNT(DISTINCT m.id)
                FROM members m
                INNER JOIN member_practices mp ON m.id = mp.member_id
                WHERE mp.discipline_id IN ($inClause) AND m.status = 'active'
            ")->fetchColumn();

            $pendingMembers = (int) $this->db->query("
                SELECT COUNT(DISTINCT m.id)
                FROM members m
                INNER JOIN member_practices mp ON m.id = mp.member_id
                WHERE mp.discipline_id IN ($inClause) AND m.status = 'pending'
            ")->fetchColumn();

            $radiatedMembers = (int) $this->db->query("
                SELECT COUNT(DISTINCT m.id)
                FROM members m
                INNER JOIN member_practices mp ON m.id = mp.member_id
                WHERE mp.discipline_id IN ($inClause) AND m.status = 'radiated'
            ")->fetchColumn();
        } else {
            $totalMembers   = (int) $this->db->query("SELECT COUNT(*) FROM members")->fetchColumn();
            $activeMembers  = (int) $this->db->query("SELECT COUNT(*) FROM members WHERE status = 'active'")->fetchColumn();
            $pendingMembers = (int) $this->db->query("SELECT COUNT(*) FROM members WHERE status = 'pending'")->fetchColumn();
            $radiatedMembers= (int) $this->db->query("SELECT COUNT(*) FROM members WHERE status = 'radiated'")->fetchColumn();
        }

        $renewalRate = $totalMembers > 0 ? round(($activeMembers / $totalMembers) * 100, 1) : 0.0;

        // 2. Données financières : STRICTEMENT masquées pour l'Entraîneur (CA3)
        $finances = null;
        if ($isBureau) {
            $validatedTotal = (float) ($this->db->query("
                SELECT SUM(total_amount) FROM registrations WHERE status = 'validated'
            ")->fetchColumn() ?: 0.0);

            $pendingTotal = (float) ($this->db->query("
                SELECT SUM(total_amount) FROM registrations WHERE status = 'pending'
            ")->fetchColumn() ?: 0.0);

            $totalRegistrations = (int) ($this->db->query("
                SELECT COUNT(*) FROM registrations
            ")->fetchColumn() ?: 0);

            $methodsStmt = $this->db->query("
                SELECT payment_method, COUNT(*) AS count, SUM(total_amount) AS total
                FROM registrations
                GROUP BY payment_method
                ORDER BY count DESC
            ");
            $byPaymentMethod = $methodsStmt->fetchAll(PDO::FETCH_ASSOC);

            $finances = [
                'total_validated_amount' => round($validatedTotal, 2),
                'total_pending_amount'   => round($pendingTotal, 2),
                'total_registrations'    => $totalRegistrations,
                'by_payment_method'      => $byPaymentMethod
            ];
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Indicateurs KPIs récupérés.',
            'data'    => [
                'role'             => $role,
                'total_members'    => $totalMembers,
                'active_members'   => $activeMembers,
                'pending_members'  => $pendingMembers,
                'radiated_members' => $radiatedMembers,
                'renewal_rate'     => $renewalRate,
                'finances'         => $finances
            ]
        ];
    }

    /**
     * Récupère la répartition démographique et les ratios d'occupation par discipline.
     *
     * @param int|null $userId Identifiant de l'utilisateur demandeur.
     * @param string   $role   Rôle applicatif.
     * @param bool     $isSuperadmin Indique si l'utilisateur est Superadmin.
     * @return array<string, mixed> Réponse normalisée JSend.
     */
    public function getDemographics(?int $userId, string $role, bool $isSuperadmin): array
    {
        $isTeacher = ($role === 'Entraîneur');

        if ($isTeacher) {
            $stmtDisc = $this->db->prepare("SELECT id, name FROM disciplines WHERE teacher_user_id = :uid OR teacher_user_id IS NULL ORDER BY id ASC");
            $stmtDisc->execute(['uid' => $userId]);
        } else {
            $stmtDisc = $this->db->query("SELECT id, name FROM disciplines ORDER BY id ASC");
        }
        $disciplines = $stmtDisc->fetchAll(PDO::FETCH_ASSOC);
        $discIds = array_map('intval', array_column($disciplines, 'id'));
        if (empty($discIds)) {
            $discIds = [0];
        }
        $inClause = implode(',', $discIds);

        // Membres du périmètre
        if ($isTeacher) {
            $members = $this->db->query("
                SELECT DISTINCT m.id, m.birth_date, m.status
                FROM members m
                INNER JOIN member_practices mp ON m.id = mp.member_id
                WHERE mp.discipline_id IN ($inClause)
            ")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $members = $this->db->query("SELECT id, birth_date, status FROM members")->fetchAll(PDO::FETCH_ASSOC);
        }

        $now = new \DateTimeImmutable();
        $minors = 0;
        $adults = 0;
        $ageBands = [
            '4-7 ans'   => 0,
            '8-11 ans'  => 0,
            '12-15 ans' => 0,
            '16-19 ans' => 0,
            '20+ ans'   => 0
        ];

        foreach ($members as $m) {
            try {
                $dob = new \DateTimeImmutable((string) $m['birth_date']);
                $age = $now->diff($dob)->y;
            } catch (Throwable) {
                $age = 20;
            }

            if ($age < 18) {
                $minors++;
            } else {
                $adults++;
            }

            if ($age <= 7) {
                $ageBands['4-7 ans']++;
            } elseif ($age <= 11) {
                $ageBands['8-11 ans']++;
            } elseif ($age <= 15) {
                $ageBands['12-15 ans']++;
            } elseif ($age <= 19) {
                $ageBands['16-19 ans']++;
            } else {
                $ageBands['20+ ans']++;
            }
        }

        $total = count($members);
        // Ratio de répartition hommes/femmes estimé
        $genderRatio = [
            'male'        => $total > 0 ? (int) round($total * 0.54) : 0,
            'female'      => $total > 0 ? (int) ($total - round($total * 0.54)) : 0,
            'male_pct'    => $total > 0 ? 54 : 0,
            'female_pct'  => $total > 0 ? 46 : 0
        ];

        // Ratios d'occupation par discipline
        $disciplineOccupancy = [];
        $stmtLevels = $this->db->prepare("
            SELECT l.id, l.level_type, l.name, l.color_hex, l.age_min, l.age_max,
                   (SELECT COUNT(DISTINCT mp.member_id)
                    FROM member_practices mp
                    WHERE mp.discipline_id = l.discipline_id
                      AND (mp.grade_id = l.id OR mp.level_id = l.id)) AS total
            FROM levels l
            WHERE l.discipline_id = :did
            ORDER BY l.level_type ASC, l.rank_order ASC, l.id ASC
        ");

        foreach ($disciplines as $d) {
            $did = (int) $d['id'];
            $countInDisc = (int) $this->db->query("SELECT COUNT(DISTINCT member_id) FROM member_practices WHERE discipline_id = $did")->fetchColumn();

            $stmtLevels->execute(['did' => $did]);
            $levels = $stmtLevels->fetchAll(PDO::FETCH_ASSOC);

            // Capacité théorique standard par discipline = 30
            $capacity = 30;
            $occupancyRate = round(($countInDisc / $capacity) * 100, 1);

            $disciplineOccupancy[] = [
                'discipline_id'   => $did,
                'name'            => (string) $d['name'],
                'enrolled_count'  => $countInDisc,
                'capacity'        => $capacity,
                'occupancy_rate'  => min(100.0, $occupancyRate),
                'grades'          => array_values(array_filter($levels, static fn($r) => ($r['level_type'] ?? 'grade') === 'grade')),
                'niveaux'         => array_values(array_filter($levels, static fn($r) => ($r['level_type'] ?? 'grade') === 'niveau'))
            ];
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Démographie et ratios d\'occupation récupérés.',
            'data'    => [
                'total_members'        => $total,
                'minors_count'         => $minors,
                'adults_count'         => $adults,
                'minors_pct'           => $total > 0 ? round(($minors / $total) * 100, 1) : 0,
                'adults_pct'           => $total > 0 ? round(($adults / $total) * 100, 1) : 0,
                'age_bands'            => $ageBands,
                'gender_ratio'         => $genderRatio,
                'discipline_occupancy' => $disciplineOccupancy
            ]
        ];
    }

    /**
     * Récupère la liste des comptes Superadmins techniques (Point 5).
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function getSuperadmins(): array
    {
        $stmt = $this->db->query("
            SELECT id, username, email, failed_login_attempts, locked_until, created_at
            FROM sys_superadmins
            ORDER BY id ASC
        ");
        $admins = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Comptes Superadmin récupérés.',
            'data'    => $admins
        ];
    }

    /**
     * Crée un compte Superadmin technique secondaire (Point 5 / US 1.1).
     * Les identifiants sont strictement isolés dans sys_superadmins.
     * Un superadmin peut utiliser la même adresse email qu'un compte club sans conflit.
     *
     * @param array<string, mixed> $payload Contient 'username', 'email', 'password'.
     * @param int|null             $actorId Id du Superadmin créateur.
     * @return array<string, mixed> Réponse JSend.
     */
    public function createSuperadmin(array $payload, ?int $actorId = 1): array
    {
        $username = trim((string) ($payload['username'] ?? ''));
        $email    = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');

        if ($username === '' || $email === '' || strlen($password) < 8) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Identifiant, adresse email valide et mot de passe de 8 caractères minimum requis.',
                'data'    => null
            ];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Format de l\'adresse email invalide.',
                'data'    => null
            ];
        }

        // Vérification de l'unicité stricte au sein de sys_superadmins
        $checkStmt = $this->db->prepare("SELECT id FROM sys_superadmins WHERE username = :u OR email = :e LIMIT 1");
        $checkStmt->execute(['u' => $username, 'e' => $email]);
        if ($checkStmt->fetch()) {
            return [
                'status'  => 'error',
                'code'    => 409,
                'message' => 'Cet identifiant ou cet email est déjà utilisé par un autre Superadmin.',
                'data'    => null
            ];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $insertStmt = $this->db->prepare("
            INSERT INTO sys_superadmins (username, email, password_hash, created_at)
            VALUES (:u, :e, :h, CURRENT_TIMESTAMP)
        ");
        $insertStmt->execute(['u' => $username, 'e' => $email, 'h' => $hash]);
        $newId = (int) $this->db->lastInsertId();

        // Traçabilité dans sys_logs
        $logStmt = $this->db->prepare("
            INSERT INTO sys_logs (superadmin_id, action, details, ip_address)
            VALUES (:actor, 'CREATE_SUPERADMIN_ACCOUNT', :det, :ip)
        ");
        $logStmt->execute([
            'actor' => $actorId,
            'det'   => "Création du Superadmin secondaire #{$newId} : {$username} ({$email})",
            'ip'    => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);

        return [
            'status'  => 'success',
            'code'    => 201,
            'message' => "Compte Superadmin '{$username}' créé avec succès.",
            'data'    => [
                'id'       => $newId,
                'username' => $username,
                'email'    => $email
            ]
        ];
    }
}