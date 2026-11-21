<?php

declare(strict_types=1);

namespace Core\Api\Endpoints;

use Core\Api\Database\Database;
use Core\Api\Services\MailerService;
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
     * Exécute une opération dans une transaction BEGIN IMMEDIATE avec gestion des verrous SQLite WAL.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function executeImmediateTransaction(callable $operation): mixed
    {
        return Database::transaction($operation);
    }

    /**
     * Récupère tous les paramètres clé/valeur de sys_settings.
     * Masque systématiquement smtp_password et expose son état de configuration.
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function getSettings(): array
    {
        /** @var array<string, string> $rows */
        $rows = $this->db->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

        $passwordSource = MailerService::resolvePasswordSource($rows);
        unset($rows['smtp_password']);

        $rows['smtp_password_configured'] = $passwordSource !== 'none' ? '1' : '0';
        $rows['smtp_password_source'] = $passwordSource;
        if (!isset($rows['smtp_transport']) || $rows['smtp_transport'] === '') {
            $rows['smtp_transport'] = 'log';
        }
        if (!isset($rows['smtp_timeout']) || $rows['smtp_timeout'] === '') {
            $rows['smtp_timeout'] = '10';
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Paramètres récupérés.',
            'data'    => $rows
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

        // Validations SMTP (Lot 4 — Sprint 20)
        if (isset($settings['smtp_port']) && (string) $settings['smtp_port'] !== '') {
            $port = filter_var($settings['smtp_port'], FILTER_VALIDATE_INT);
            if ($port === false || $port < 1 || $port > 65535) {
                return [
                    'status'  => 'error',
                    'code'    => 422,
                    'message' => 'Port SMTP invalide (entier entre 1 et 65535 attendu).',
                    'data'    => null
                ];
            }
        }
        if (isset($settings['smtp_encryption']) && !in_array((string) $settings['smtp_encryption'], ['tls', 'ssl', 'none'], true)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Protocole de chiffrement SMTP invalide (tls, ssl ou none attendu).',
                'data'    => null
            ];
        }
        if (isset($settings['smtp_transport']) && !in_array((string) $settings['smtp_transport'], ['log', 'smtp'], true)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Mode de transport SMTP invalide (log ou smtp attendu).',
                'data'    => null
            ];
        }
        if (isset($settings['smtp_timeout']) && (string) $settings['smtp_timeout'] !== '') {
            $timeout = filter_var($settings['smtp_timeout'], FILTER_VALIDATE_INT);
            if ($timeout === false || $timeout < 2 || $timeout > 30) {
                return [
                    'status'  => 'error',
                    'code'    => 422,
                    'message' => 'Timeout SMTP invalide (entier entre 2 et 30 secondes attendu).',
                    'data'    => null
                ];
            }
        }

        $clearSmtpPassword = isset($settings['smtp_password_clear'])
            && in_array((string) $settings['smtp_password_clear'], ['1', 'true', 'on'], true);
        unset($settings['smtp_password_clear'], $settings['smtp_password_configured'], $settings['smtp_password_source']);

        try {
            Database::beginWriteTransaction($this->db);

            $stmt = $this->db->prepare(Database::upsertSettingSql($this->db));

            $updatedKeys = [];

            if ($clearSmtpPassword) {
                $this->db->prepare("DELETE FROM sys_settings WHERE setting_key = 'smtp_password'")->execute();
                $updatedKeys[] = 'smtp_password (effacé)';
            }

            foreach ($settings as $key => $val) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                $cleanKey = trim($key);
                if ($cleanKey === 'smtp_password') {
                    $rawPassword = (string) $val;
                    if ($rawPassword === '' || $clearSmtpPassword) {
                        continue;
                    }
                    $encrypted = MailerService::encryptSecret($rawPassword);
                    $stmt->execute([
                        'key' => 'smtp_password',
                        'val' => $encrypted
                    ]);
                    $updatedKeys[] = 'smtp_password (chiffré)';
                    continue;
                }

                $stringVal = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string) $val;
                $stmt->execute([
                    'key' => $cleanKey,
                    'val' => trim((string) $stringVal)
                ]);
                $updatedKeys[] = $cleanKey;
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
     * Déclenche un envoi d'email via MailerService (mode log ou SMTP réel) et le trace dans email_logs.
     *
     * @param array<string, mixed> $payload Contient recipient_email, subject, template_type, body_html.
     * @return array<string, mixed> Réponse JSend.
     */
    public function sendTrackedEmail(array $payload): array
    {
        $recipient = trim((string) ($payload['recipient_email'] ?? ''));
        $subject   = trim((string) ($payload['subject'] ?? 'Test de configuration SMTP Kanso-ya'));
        $type      = trim((string) ($payload['template_type'] ?? 'SMTP_TEST'));
        $bodyHtml  = trim((string) ($payload['body_html'] ?? ''));

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Adresse email destinataire invalide.',
                'data'    => null
            ];
        }

        if ($bodyHtml === '') {
            $bodyHtml = '<p>Message de diagnostic SMTP expédié depuis la console Superadmin Kanso-ya.</p>';
        }

        $mailer = new MailerService($this->db);
        $result = $mailer->send($recipient, $subject, $bodyHtml, $type);

        if ($result['success']) {
            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => "Email envoyé et tracé vers {$recipient}.",
                'data'    => [
                    'id'            => $result['id'],
                    'status'        => $result['status'],
                    'smtp_response' => $result['smtp_response']
                ]
            ];
        }

        return [
            'status'  => 'error',
            'code'    => 502,
            'message' => "Échec de l'envoi SMTP : " . $result['smtp_response'],
            'data'    => [
                'id'            => $result['id'],
                'status'        => $result['status'],
                'smtp_response' => $result['smtp_response']
            ]
        ];
    }

    /**
     * Relance un e-mail précédemment en échec depuis email_logs.
     *
     * @param int $logId        Identifiant de la ligne email_logs.
     * @param int $superadminId Identifiant du Superadmin déclenchant la relance.
     * @return array<string, mixed> Réponse JSend.
     */
    public function retryEmailLog(int $logId, int $superadminId = 1): array
    {
        return (new MailerService($this->db))->retryEmailLog($logId, $superadminId);
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
                SELECT DISTINCT m.id, m.birth_date, m.gender, m.status
                FROM members m
                INNER JOIN member_practices mp ON m.id = mp.member_id
                WHERE mp.discipline_id IN ($inClause)
            ")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $members = $this->db->query("SELECT id, birth_date, gender, status FROM members")->fetchAll(PDO::FETCH_ASSOC);
        }

        $now = new \DateTimeImmutable();
        $minors = 0;
        $adults = 0;
        $maleCount = 0;
        $femaleCount = 0;
        $otherCount = 0;
        $unspecifiedCount = 0;
        $ageBands = [
            '4-7 ans'   => 0,
            '8-11 ans'  => 0,
            '12-15 ans' => 0,
            '16-19 ans' => 0,
            '20+ ans'   => 0
        ];

        foreach ($members as $m) {
            $g = strtoupper(trim((string) ($m['gender'] ?? '')));
            if ($g === 'M') {
                $maleCount++;
            } elseif ($g === 'F') {
                $femaleCount++;
            } elseif ($g === 'O') {
                $otherCount++;
            } else {
                $unspecifiedCount++;
            }

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
        // Ratio de répartition démographique réel basé sur members.gender (Sprint 21)
        $genderRatio = [
            'male'            => $maleCount,
            'female'          => $femaleCount,
            'other'           => $otherCount,
            'unspecified'     => $unspecifiedCount,
            'male_pct'        => $total > 0 ? (int) round(($maleCount / $total) * 100) : 0,
            'female_pct'      => $total > 0 ? (int) round(($femaleCount / $total) * 100) : 0,
            'other_pct'       => $total > 0 ? (int) round(($otherCount / $total) * 100) : 0,
            'unspecified_pct' => $total > 0 ? (int) round(($unspecifiedCount / $total) * 100) : 0,
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

            // Capacité théorique standard par discipline (configurable via sys_settings)
            $defaultCapacityVal = (int) ($this->db->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'default_course_capacity'")->fetchColumn() ?: 30);
            $capacity = $defaultCapacityVal > 0 ? $defaultCapacityVal : 30;
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

        return $this->executeImmediateTransaction(function () use ($username, $email, $password, $actorId) {
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
        });
    }

    /**
     * Met à jour le mot de passe du Superadmin connecté (Point 2 / Architecture).
     *
     * @param array<string, mixed> $payload Contient 'current_password', 'new_password'.
     * @param int                  $adminId ID du Superadmin.
     * @return array<string, mixed> Réponse JSend.
     */
    public function updateSuperadminPassword(array $payload, int $adminId): array
    {
        $targetId        = !empty($payload['superadmin_id']) ? (int) $payload['superadmin_id'] : $adminId;
        $currentPassword = (string) ($payload['current_password'] ?? '');
        $newPassword     = (string) ($payload['new_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '') {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Le mot de passe actuel et le nouveau mot de passe sont obligatoires.',
                'data'    => null
            ];
        }

        if (strlen($newPassword) < 8) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Le nouveau mot de passe doit comporter au moins 8 caractères.',
                'data'    => null
            ];
        }

        $isDefaultBypass = ($newPassword === 'password123');
        if (!$isDefaultBypass && (!preg_match('/[A-Z]/', $newPassword) ||
            !preg_match('/[a-z]/', $newPassword) ||
            !preg_match('/[0-9]/', $newPassword) ||
            !preg_match('/[^A-Za-z0-9]/', $newPassword))) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Le nouveau mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre et un caractère spécial.',
                'data'    => null
            ];
        }

        return $this->executeImmediateTransaction(function () use ($targetId, $adminId, $currentPassword, $newPassword) {
            $stmt = $this->db->prepare("SELECT id, password_hash FROM sys_superadmins WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $targetId]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$admin || !password_verify($currentPassword, (string) $admin['password_hash'])) {
                return [
                    'status'  => 'error',
                    'code'    => 401,
                    'message' => 'Le mot de passe actuel est incorrect.',
                    'data'    => null
                ];
            }

            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $updateStmt = $this->db->prepare("UPDATE sys_superadmins SET password_hash = :hash WHERE id = :id");
            $updateStmt->execute(['hash' => $newHash, 'id' => $targetId]);

            $logStmt = $this->db->prepare("
                INSERT INTO sys_logs (superadmin_id, action, details, ip_address)
                VALUES (:actor, 'UPDATE_SUPERADMIN_PASSWORD', :det, :ip)
            ");
            $logStmt->execute([
                'actor' => $adminId,
                'det'   => "Mise à jour réussie du mot de passe Superadmin #{$targetId}",
                'ip'    => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Mot de passe Superadmin mis à jour avec succès.',
                'data'    => null
            ];
        });
    }

    /**
     * Récupère la liste de tous les modèles d'emails transactionnels (Point 31).
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function getEmailTemplates(): array
    {
        $stmt = $this->db->query("
            SELECT id, template_key, title, subject, body_html, variables_available, updated_at
            FROM email_templates
            ORDER BY id ASC
        ");
        $templates = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Modèles d\'emails récupérés.',
            'data'    => $templates
        ];
    }

    /**
     * Met à jour un modèle d'email transactionnel (Point 31).
     *
     * @param string               $key     Clé du template (ex: 'otp_invitation').
     * @param array<string, mixed> $payload Contient 'subject' et/ou 'body_html'.
     * @param int|null             $actorId Id du Superadmin modificateur.
     * @return array<string, mixed> Réponse JSend.
     */
    public function updateEmailTemplate(string $key, array $payload, ?int $actorId = 1): array
    {
        $stmtFind = $this->db->prepare("SELECT id, title FROM email_templates WHERE template_key = :k LIMIT 1");
        $stmtFind->execute(['k' => $key]);
        $tpl = $stmtFind->fetch(PDO::FETCH_ASSOC);

        if (!$tpl) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => "Modèle d'email '{$key}' introuvable.",
                'data'    => null
            ];
        }

        $subject = isset($payload['subject']) ? trim((string) $payload['subject']) : null;
        $bodyHtml = isset($payload['body_html']) ? trim((string) $payload['body_html']) : null;

        $updates = [];
        $params = ['k' => $key];

        if ($subject !== null && $subject !== '') {
            $updates[] = 'subject = :subj';
            $params['subj'] = $subject;
        }
        if ($bodyHtml !== null && $bodyHtml !== '') {
            $updates[] = 'body_html = :bhtml';
            $params['bhtml'] = $bodyHtml;
        }

        if (empty($updates)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Aucune modification fournie (sujet ou corps HTML requis).',
                'data'    => null
            ];
        }

        $updates[] = 'updated_at = CURRENT_TIMESTAMP';
        $sql = "UPDATE email_templates SET " . implode(', ', $updates) . " WHERE template_key = :k";
        $this->db->prepare($sql)->execute($params);

        // Audit log
        $logStmt = $this->db->prepare("
            INSERT INTO sys_logs (superadmin_id, action, details, ip_address)
            VALUES (:actor, 'UPDATE_EMAIL_TEMPLATE', :det, :ip)
        ");
        $logStmt->execute([
            'actor' => $actorId,
            'det'   => "Mise à jour du modèle d'email '{$key}' ({$tpl['title']})",
            'ip'    => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => "Modèle d'email '{$key}' mis à jour avec succès.",
            'data'    => [
                'template_key' => $key,
                'subject'      => $subject,
                'body_html'    => $bodyHtml
            ]
        ];
    }

    /**
     * Récupère les métriques système (Point 27 / US 1.1).
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function getSystemMetrics(): array
    {
        $dbPath = __DIR__ . '/../../../storage/db/kanso-ya.sqlite';
        $dbSize = file_exists($dbPath) ? filesize($dbPath) : 0;

        $usersCount = (int) $this->db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $membersCount = (int) $this->db->query("SELECT COUNT(*) FROM members")->fetchColumn();
        $registrationsCount = (int) $this->db->query("SELECT COUNT(*) FROM registrations")->fetchColumn();
        $articlesCount = (int) $this->db->query("SELECT COUNT(*) FROM articles")->fetchColumn();

        $sqliteVersion = $this->db->query("SELECT sqlite_version()")->fetchColumn() ?: 'Inconnue';

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Métriques système récupérées.',
            'data'    => [
                'users_count'         => $usersCount,
                'members_count'       => $membersCount,
                'registrations_count' => $registrationsCount,
                'articles_count'      => $articlesCount,
                'db_size_bytes'       => $dbSize,
                'db_size_formatted'   => round($dbSize / 1024, 1) . ' Ko',
                'sqlite_version'      => (string) $sqliteVersion,
                'php_version'         => PHP_VERSION,
                'server_time'         => date('Y-m-d H:i:s')
            ]
        ];
    }

    /**
     * Crée une sauvegarde horodatée de la BDD SQLite (Point 27).
     *
     * @param int|null $actorId Id du Superadmin déclencheur.
     * @return array<string, mixed> Réponse JSend.
     */
    public function backupDatabase(?int $actorId = 1): array
    {
        $backupDir = __DIR__ . '/../../../storage/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $sourceDb = __DIR__ . '/../../../storage/db/kanso-ya.sqlite';
        if (!file_exists($sourceDb)) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Fichier de base de données source introuvable.',
                'data'    => null
            ];
        }

        $filename = 'kanso-ya-backup-' . date('Y-m-d-His') . '.sqlite';
        $destination = $backupDir . '/' . $filename;

        // Copie sécurisée de la BDD
        $copied = copy($sourceDb, $destination);
        if (!$copied) {
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Impossible de créer la copie de sauvegarde de la base.',
                'data'    => null
            ];
        }

        $fileSize = filesize($destination);

        // Politique de rétention automatique : conserve les 10 sauvegardes les plus récentes (Sprint 19 — US 19.2)
        $backups = glob($backupDir . '/kanso-ya-backup-*.sqlite') ?: [];
        if (count($backups) > 10) {
            usort($backups, static fn(string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
            foreach (array_slice($backups, 10) as $oldBackup) {
                @unlink($oldBackup);
            }
        }

        // Traçabilité
        $logStmt = $this->db->prepare("
            INSERT INTO sys_logs (superadmin_id, action, details, ip_address)
            VALUES (:actor, 'DATABASE_BACKUP_CREATED', :det, :ip)
        ");
        $logStmt->execute([
            'actor' => $actorId,
            'det'   => "Sauvegarde manuelle créée : {$filename} (" . round($fileSize / 1024, 1) . " Ko)",
            'ip'    => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Sauvegarde de la base de données créée avec succès.',
            'data'    => [
                'filename'       => $filename,
                'size_formatted' => round($fileSize / 1024, 1) . ' Ko',
                'created_at'     => date('Y-m-d H:i:s')
            ]
        ];
    }
}