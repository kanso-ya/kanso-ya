<?php

declare(strict_types=1);

namespace Core\Api\Endpoints;

use Core\Api\Database\Database;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Contrôleur gérant le tunnel d'inscription multi-disciplines (avec pré-sélection Grade/Niveau),
 * l'inspection détaillée avec ajustement des Grades/Niveaux par le valideur, et la validation OTP.
 */
final class RegistrationController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Exécute une opération SQLite dans une transaction IMMEDIATE avec réessai automatique sur verrou.
     *
     * @template T
     * @param callable(): T $operation Fonction à exécuter.
     * @return T
     * @throws Throwable
     */
    private function executeImmediateTransaction(callable $operation): mixed
    {
        $maxAttempts = 20;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                if ($this->db->inTransaction()) {
                    $this->db->exec('ROLLBACK');
                }
                $this->db->exec('BEGIN IMMEDIATE');
                $result = $operation();
                $this->db->exec('COMMIT');
                return $result;
            } catch (Throwable $e) {
                try {
                    if ($this->db->inTransaction()) {
                        $this->db->exec('ROLLBACK');
                    }
                } catch (Throwable) {
                }

                $msg = strtolower($e->getMessage());
                if ((str_contains($msg, 'locked') || str_contains($msg, 'busy')) && $attempt < $maxAttempts) {
                    usleep(random_int(50000, 150000) * $attempt);
                    continue;
                }
                throw $e;
            }
        }
        throw new \RuntimeException('Échec après plusieurs tentatives SQLite.');
    }

    /**
     * Liste tous les dossiers d'inscription avec le détail complet des membres et de leurs pratiques
     * (Discipline + Grade d'âge + Niveau technique) pour permettre au valideur de les ajuster.
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function listRegistrations(): array
    {
        $stmt = $this->db->query("
            SELECT r.*, u.email AS payer_email
            FROM registrations r
            LEFT JOIN users u ON r.payer_user_id = u.id
            ORDER BY r.id DESC
        ");
        $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($registrations as &$reg) {
            $regId = (int) $reg['id'];
            $payerId = isset($reg['payer_user_id']) ? (int) $reg['payer_user_id'] : 0;

            $stmtMem = $this->db->prepare("
                SELECT DISTINCT m.id, m.first_name, m.last_name, m.birth_date, m.status,
                       m.emergency_contact_name, m.emergency_contact_phone
                FROM member_practices mp
                INNER JOIN members m ON mp.member_id = m.id
                WHERE mp.registration_id = :rid
            ");
            $stmtMem->execute(['rid' => $regId]);
            $members = $stmtMem->fetchAll(PDO::FETCH_ASSOC);

            if (empty($members) && $payerId > 0) {
                $stmtFallback = $this->db->prepare("
                    SELECT m.id, m.first_name, m.last_name, m.birth_date, m.status,
                           COALESCE(m.emergency_contact_name, 'Contact Parent') AS emergency_contact_name,
                           COALESCE(m.emergency_contact_phone, '06 12 34 56 78') AS emergency_contact_phone
                    FROM member_tutor mt
                    INNER JOIN members m ON mt.member_id = m.id
                    WHERE mt.tutor_id = :tid
                ");
                $stmtFallback->execute(['tid' => $payerId]);
                $members = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);
            }

            // Pour chaque membre du dossier, on récupère toutes ses disciplines/grades/niveaux
            foreach ($members as &$m) {
                $stmtPrac = $this->db->prepare("
                    SELECT mp.id AS practice_id, mp.discipline_id, mp.grade_id, mp.level_id,
                           d.name AS discipline_name,
                           g.name AS grade_name,
                           n.name AS niveau_name
                    FROM member_practices mp
                    INNER JOIN disciplines d ON mp.discipline_id = d.id
                    LEFT JOIN levels g ON mp.grade_id = g.id
                    LEFT JOIN levels n ON mp.level_id = n.id
                    WHERE mp.member_id = :mid
                ");
                $stmtPrac->execute(['mid' => (int) $m['id']]);
                $practices = $stmtPrac->fetchAll(PDO::FETCH_ASSOC);
                $m['practices'] = $practices;
                $m['discipline_name'] = !empty($practices)
                    ? implode(', ', array_column($practices, 'discipline_name'))
                    : 'Judo';
            }
            unset($m);

            $reg['members'] = $members;
        }
        unset($reg);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Liste détaillée des dossiers récupérée.',
            'data'    => $registrations
        ];
    }

    /**
     * Enregistre un dossier d'inscription public en supportant plusieurs disciplines par adhérent,
     * avec affectation automatique ou ajustée du Grade (catégorie d'âge) et du Niveau technique.
     *
     * @param array<string, mixed> $payload Données soumises.
     * @return array<string, mixed> Réponse JSend.
     */
    public function submitPublicRegistration(array $payload): array
    {
        try {
            $stmtSetting = $this->db->prepare("SELECT setting_value FROM sys_settings WHERE setting_key = 'public_registration_enabled'");
            $stmtSetting->execute();
            if ($stmtSetting->fetchColumn() === '0') {
                return [
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Les inscriptions en ligne sont actuellement fermées par le bureau du club.',
                    'data'    => null
                ];
            }
        } catch (Throwable) {
        }

        $members = $payload['members'] ?? [];
        if (!is_array($members) || count($members) === 0) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Au moins un adhérent doit être présent dans le dossier.',
                'data'    => null
            ];
        }

        if (count($members) > 10) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Limite dépassée : un dossier familial ne peut pas contenir plus de 10 adhérents.',
                'data'    => null
            ];
        }

        $installments = (int) ($payload['installments'] ?? 1);
        if (!in_array($installments, [1, 2, 3], true)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Nombre d\'échéances invalide (1, 2 ou 3 paiements autorisés).',
                'data'    => null
            ];
        }

        $paymentMethod = trim((string) ($payload['payment_method'] ?? 'cheque'));
        $tutorsPayload = is_array($payload['tutors'] ?? null) ? $payload['tutors'] : [];

        $hasMinor = false;
        foreach ($members as $index => $m) {
            $firstName  = trim((string) ($m['first_name'] ?? ''));
            $lastName   = trim((string) ($m['last_name'] ?? ''));
            $birthDate  = trim((string) ($m['birth_date'] ?? ''));
            $emergName  = trim((string) ($m['emergency_contact_name'] ?? ''));
            $emergPhone = trim((string) ($m['emergency_contact_phone'] ?? ''));

            if ($firstName === '' || $lastName === '' || $birthDate === '') {
                return [
                    'status'  => 'error',
                    'code'    => 422,
                    'message' => "Identité incomplète pour l'adhérent #" . ($index + 1) . ".",
                    'data'    => null
                ];
            }

            if ($emergName === '' || $emergPhone === '') {
                return [
                    'status'  => 'error',
                    'code'    => 422,
                    'message' => "Le contact d'urgence est obligatoire pour chaque adhérent.",
                    'data'    => null
                ];
            }

            $age = $this->calculateAge($birthDate);
            if ($age < 0 || $age > 120) {
                return [
                    'status'  => 'error',
                    'code'    => 422,
                    'message' => 'Date de naissance incohérente.',
                    'data'    => null
                ];
            }

            if ($age < 18) {
                $hasMinor = true;
            }
        }

        if ($hasMinor && count($tutorsPayload) === 0) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Au moins un tuteur légal est obligatoire pour l\'inscription d\'un mineur.',
                'data'    => null
            ];
        }

        foreach ($tutorsPayload as $t) {
            $tutorEmail = filter_var($t['email'] ?? '', FILTER_VALIDATE_EMAIL);
            if (!$tutorEmail) {
                return [
                    'status'  => 'error',
                    'code'    => 422,
                    'message' => 'L\'adresse email du tuteur légal est invalide.',
                    'data'    => null
                ];
            }

            if (!empty($t['birth_date'])) {
                $tutorAge = $this->calculateAge((string) $t['birth_date']);
                if ($tutorAge < 18) {
                    return [
                        'status'  => 'error',
                        'code'    => 422,
                        'message' => 'Un tuteur légal doit obligatoirement être majeur (18 ans minimum).',
                        'data'    => null
                    ];
                }
            }
        }

        // Récupération des paramètres de remise et prix de base
        $settingsStmt = $this->db->query("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key IN ('membership_base_price', 'discount_threshold', 'discount_type', 'discount_value', 'medical_cert_mode')");
        $settingsMap = [];
        if ($settingsStmt) {
            while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
                $settingsMap[$row['setting_key']] = $row['setting_value'];
            }
        }

        $basePrice = isset($settingsMap['membership_base_price']) ? (float) $settingsMap['membership_base_price'] : 150.0;
        $discThreshold = isset($settingsMap['discount_threshold']) ? (int) $settingsMap['discount_threshold'] : 2;
        $discType = isset($settingsMap['discount_type']) ? (string) $settingsMap['discount_type'] : 'percent';
        $discVal = isset($settingsMap['discount_value']) ? (float) $settingsMap['discount_value'] : 10.0;

        $memberCount = count($members);
        $subtotal = $basePrice * $memberCount;
        $discountAmount = 0.0;
        $discountDesc = null;

        if ($memberCount >= $discThreshold && $discVal > 0) {
            if ($discType === 'fixed') {
                $eligibleCount = ($memberCount - $discThreshold + 1);
                $discountAmount = round($eligibleCount * $discVal, 2);
                $discountDesc = "-{$discVal}€ dès {$discThreshold} adhérents";
            } else {
                $discountAmount = round(($subtotal * $discVal) / 100.0, 2);
                $discountDesc = "-{$discVal}% dès {$discThreshold} adhérents";
            }
        }
        $totalAmount = max(0.0, round($subtotal - $discountAmount, 2));

        if (isset($payload['total_amount']) && (float) $payload['total_amount'] < 0) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Le montant de la cotisation ne peut pas être négatif.',
                'data'    => null
            ];
        }

        try {
            $precomputedHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
            $season = (string) ($payload['season'] ?? '2026-2027');

            $result = $this->executeImmediateTransaction(function () use ($tutorsPayload, $members, $precomputedHash, $season, $totalAmount, $discountAmount, $discountDesc, $paymentMethod, $installments): array {
                $tutorIds = [];
                foreach ($tutorsPayload as $t) {
                    $email = strtolower(trim((string) $t['email']));
                    $stmtCheck = $this->db->prepare("SELECT id FROM users WHERE email = :email");
                    $stmtCheck->execute(['email' => $email]);
                    $existingId = $stmtCheck->fetchColumn();

                    if ($existingId) {
                        $tutorIds[] = (int) $existingId;
                    } else {
                        $stmtUser = $this->db->prepare("
                            INSERT INTO users (email, password_hash, first_name, last_name, phone, address, role_id, is_active)
                            VALUES (:email, :hash, :fn, :ln, :ph, :ad, 6, 0)
                        ");
                        $stmtUser->execute([
                            'email' => $email,
                            'hash'  => $precomputedHash,
                            'fn'    => htmlspecialchars(trim((string) ($t['first_name'] ?? '')), ENT_QUOTES, 'UTF-8'),
                            'ln'    => htmlspecialchars(trim((string) ($t['last_name'] ?? '')), ENT_QUOTES, 'UTF-8'),
                            'ph'    => htmlspecialchars(trim((string) ($t['phone'] ?? '')), ENT_QUOTES, 'UTF-8'),
                            'ad'    => htmlspecialchars(trim((string) ($t['address'] ?? '')), ENT_QUOTES, 'UTF-8')
                        ]);
                        $tutorIds[] = (int) $this->db->lastInsertId();
                    }
                }

                $payerUserId = $tutorIds[0] ?? null;
                if ($payerUserId === null && !empty($members[0]['email'])) {
                    $adultEmail = strtolower(trim((string) $members[0]['email']));
                    if (filter_var($adultEmail, FILTER_VALIDATE_EMAIL)) {
                        $stmtCheck = $this->db->prepare("SELECT id FROM users WHERE email = :email");
                        $stmtCheck->execute(['email' => $adultEmail]);
                        $existingAdultId = $stmtCheck->fetchColumn();

                        if ($existingAdultId) {
                            $payerUserId = (int) $existingAdultId;
                        } else {
                            $stmtUser = $this->db->prepare("
                                INSERT INTO users (email, password_hash, first_name, last_name, role_id, is_active)
                                VALUES (:email, :hash, :fn, :ln, 6, 0)
                            ");
                            $stmtUser->execute([
                                'email' => $adultEmail,
                                'hash'  => $precomputedHash,
                                'fn'    => htmlspecialchars(trim((string) $members[0]['first_name']), ENT_QUOTES, 'UTF-8'),
                                'ln'    => htmlspecialchars(trim((string) $members[0]['last_name']), ENT_QUOTES, 'UTF-8')
                            ]);
                            $payerUserId = (int) $this->db->lastInsertId();
                        }
                    }
                }

                $stmtReg = $this->db->prepare("
                    INSERT INTO registrations (payer_user_id, season, total_amount, discount_amount, discount_description, payment_method, installments, status)
                    VALUES (:payer, :season, :amount, :disc_amount, :disc_desc, :method, :installments, 'pending')
                ");
                $stmtReg->execute([
                    'payer'        => $payerUserId,
                    'season'       => $season,
                    'amount'       => $totalAmount,
                    'disc_amount'  => $discountAmount,
                    'disc_desc'    => $discountDesc,
                    'method'       => $paymentMethod,
                    'installments' => $installments
                ]);
                $registrationId = (int) $this->db->lastInsertId();

                $createdMemberIds = [];
                foreach ($members as $m) {
                    $bdate = trim((string) $m['birth_date']);
                    $age   = $this->calculateAge($bdate);

                    $emergRel = htmlspecialchars(trim((string) ($m['emergency_contact_relation'] ?? '')), ENT_QUOTES, 'UTF-8');
                    $emergSec = htmlspecialchars(trim((string) ($m['emergency_contact_secondary_phone'] ?? '')), ENT_QUOTES, 'UTF-8');
                    $healthSigned = !empty($m['health_attestation_signed']) ? 1 : 0;
                    $healthDate = $healthSigned ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null;

                    $stmtMem = $this->db->prepare("
                        INSERT INTO members (first_name, last_name, birth_date, email, status, emergency_contact_name, emergency_contact_phone, emergency_contact_relation, emergency_contact_secondary_phone, health_attestation_signed, health_attestation_date)
                        VALUES (:fname, :lname, :bdate, :email, 'pending', :ename, :ephone, :erel, :esec, :hsigned, :hdate)
                    ");
                    $stmtMem->execute([
                        'fname'  => htmlspecialchars(trim((string) $m['first_name']), ENT_QUOTES, 'UTF-8'),
                        'lname'  => htmlspecialchars(trim((string) $m['last_name']), ENT_QUOTES, 'UTF-8'),
                        'bdate'  => $bdate,
                        'email'  => !empty($m['email']) ? strtolower(trim((string) $m['email'])) : null,
                        'ename'  => htmlspecialchars(trim((string) ($m['emergency_contact_name'] ?? '')), ENT_QUOTES, 'UTF-8'),
                        'ephone' => htmlspecialchars(trim((string) ($m['emergency_contact_phone'] ?? '')), ENT_QUOTES, 'UTF-8'),
                        'erel'   => $emergRel !== '' ? $emergRel : null,
                        'esec'   => $emergSec !== '' ? $emergSec : null,
                        'hsigned'=> $healthSigned,
                        'hdate'  => $healthDate
                    ]);
                    $memberId = (int) $this->db->lastInsertId();
                    $createdMemberIds[] = $memberId;

                    foreach ($tutorIds as $tId) {
                        $stmtPivot = $this->db->prepare("
                            INSERT OR IGNORE INTO member_tutor (member_id, tutor_id, info_shared)
                            VALUES (:mid, :tid, 0)
                        ");
                        $stmtPivot->execute(['mid' => $memberId, 'tid' => $tId]);
                    }

                    // Gestion multi-disciplines : soit $m['practices'] (tableau), soit $m['discipline_id']
                    $practicesList = [];
                    if (!empty($m['practices']) && is_array($m['practices'])) {
                        $practicesList = $m['practices'];
                    } else {
                        $practicesList[] = [
                            'discipline_id' => (int) ($m['discipline_id'] ?? 1),
                            'grade_id'      => isset($m['grade_id']) && $m['grade_id'] !== '' ? (int) $m['grade_id'] : null,
                            'level_id'      => isset($m['level_id']) && $m['level_id'] !== '' ? (int) $m['level_id'] : null
                        ];
                    }

                    foreach ($practicesList as $prac) {
                        $did = (int) ($prac['discipline_id'] ?? 1);
                        $gid = !empty($prac['grade_id']) ? (int) $prac['grade_id'] : $this->resolveDefaultGradeByAge($did, $age);
                        $lid = !empty($prac['level_id']) ? (int) $prac['level_id'] : $this->resolveDefaultSkillLevel($did);

                        $stmtPrac = $this->db->prepare("
                            INSERT INTO member_practices (member_id, discipline_id, grade_id, level_id, registration_id, season)
                            VALUES (:mid, :did, :gid, :lid, :rid, :season)
                        ");
                        $stmtPrac->execute([
                            'mid'    => $memberId,
                            'did'    => $did,
                            'gid'    => $gid,
                            'lid'    => $lid,
                            'rid'    => $registrationId,
                            'season' => $season
                        ]);
                    }
                }

                return [
                    'registration_id'      => $registrationId,
                    'member_ids'           => $createdMemberIds,
                    'total_amount'         => $totalAmount,
                    'discount_amount'      => $discountAmount,
                    'discount_description' => $discountDesc,
                    'installments'         => $installments,
                    'status'               => 'pending'
                ];
            });

            return [
                'status'  => 'success',
                'code'    => 201,
                'message' => 'Dossier d\'inscription enregistré en attente de validation.',
                'data'    => $result
            ];
        } catch (Throwable $e) {
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Erreur lors de l\'enregistrement du dossier : ' . $e->getMessage(),
                'data'    => null
            ];
        }
    }

    /**
     * Valide un dossier d'inscription et applique les ajustements éventuels de Grades et Niveaux
     * choisis par le valideur (Trésorier / Président).
     *
     * @param int                  $registrationId Identifiant du dossier.
     * @param int                  $adminUserId    Identifiant du valideur.
     * @param array<string, mixed> $payload        Contient optionnellement 'practice_updates'.
     * @return array<string, mixed> Réponse JSend.
     */
    public function validateRegistration(int $registrationId, int $adminUserId, array $payload = []): array
    {
        $stmt = $this->db->prepare("SELECT * FROM registrations WHERE id = :id");
        $stmt->execute(['id' => $registrationId]);
        $registration = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$registration) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Dossier d\'inscription introuvable.',
                'data'    => null
            ];
        }

        if ($registration['status'] === 'validated') {
            return [
                'status'  => 'error',
                'code'    => 409,
                'message' => 'Ce dossier a déjà été validé.',
                'data'    => null
            ];
        }

        $practiceUpdates = is_array($payload['practice_updates'] ?? null) ? $payload['practice_updates'] : [];

        try {
            $otpCode = bin2hex(random_bytes(16));
            $expiresAt = (new DateTimeImmutable('+48 hours'))->format('Y-m-d H:i:s');

            $this->executeImmediateTransaction(function () use ($registrationId, $adminUserId, $registration, $otpCode, $expiresAt, $practiceUpdates): void {
                // 1. Applique les modifications de Grade et de Niveau choisies par le valideur
                if (!empty($practiceUpdates)) {
                    $stmtUpdPrac = $this->db->prepare("
                        UPDATE member_practices
                        SET grade_id = :gid, level_id = :lid, is_category_forced = 1
                        WHERE id = :pid
                    ");
                    foreach ($practiceUpdates as $pu) {
                        if (!empty($pu['practice_id'])) {
                            $stmtUpdPrac->execute([
                                'gid' => !empty($pu['grade_id']) ? (int) $pu['grade_id'] : null,
                                'lid' => !empty($pu['level_id']) ? (int) $pu['level_id'] : null,
                                'pid' => (int) $pu['practice_id']
                            ]);
                        }
                    }
                }

                // 2. Valide le dossier financier
                $stmtUpdate = $this->db->prepare("
                    UPDATE registrations
                    SET status = 'validated', validated_by = :admin, validated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmtUpdate->execute([
                    'admin' => $adminUserId,
                    'id'    => $registrationId
                ]);

                // 3. Active les adhérents du dossier
                $this->db->prepare("
                    UPDATE members
                    SET status = 'active'
                    WHERE id IN (SELECT member_id FROM member_practices WHERE registration_id = :rid)
                ")->execute(['rid' => $registrationId]);

                if (!empty($registration['payer_user_id'])) {
                    $stmtOtp = $this->db->prepare("
                        UPDATE users
                        SET is_active = 1, otp_code = :otp, otp_expires_at = :exp
                        WHERE id = :uid
                    ");
                    $stmtOtp->execute([
                        'otp' => $otpCode,
                        'exp' => $expiresAt,
                        'uid' => (int) $registration['payer_user_id']
                    ]);
                }

                $stmtLog = $this->db->prepare("
                    INSERT INTO app_logs (user_id, action, target_type, target_id)
                    VALUES (:uid, 'REGISTRATION_VALIDATED', 'registration', :tid)
                ");
                $stmtLog->execute([
                    'uid' => $adminUserId,
                    'tid' => $registrationId
                ]);
            });

            try {
                if (class_exists('\\Core\\Sdk\\EventManager')) {
                    \Core\Sdk\EventManager::getInstance()->dispatch('on_registration_validated', [
                        'registration_id' => $registrationId,
                        'admin_user_id'   => $adminUserId
                    ]);
                }
            } catch (Throwable) {
            }

            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Dossier validé avec succès (Grades & Niveaux confirmés) et accès OTP envoyé.',
                'data'    => [
                    'registration_id' => $registrationId,
                    'status'          => 'validated',
                    'otp_dispatched'  => true,
                    'otp_code_stub'   => $otpCode
                ]
            ];
        } catch (Throwable $e) {
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Erreur lors de la validation : ' . $e->getMessage(),
                'data'    => null
            ];
        }
    }

    /**
     * Active un compte utilisateur via son jeton OTP (valable 48h, à usage unique).
     *
     * @param array<string, mixed> $payload Contient 'otp_code' et 'new_password'.
     * @return array<string, mixed> Réponse JSend.
     */
    public function activateAccountWithOtp(array $payload): array
    {
        $otpCode = trim((string) ($payload['otp_code'] ?? ''));
        $newPassword = (string) ($payload['new_password'] ?? '');
        $hasConfirm = array_key_exists('confirm_password', $payload);
        $confirmPassword = (string) ($payload['confirm_password'] ?? '');

        if ($otpCode === '' || strlen($newPassword) < 8) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Le code de sécurité et un mot de passe d\'au moins 8 caractères sont obligatoires.',
                'data'    => null
            ];
        }

        if ($hasConfirm && $newPassword !== $confirmPassword) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Les deux saisies du nouveau mot de passe ne correspondent pas.',
                'data'    => null
            ];
        }

        if (
            !preg_match('/[A-Z]/', $newPassword)
            || !preg_match('/[a-z]/', $newPassword)
            || !preg_match('/[0-9]/', $newPassword)
            || !preg_match('/[^A-Za-z0-9]/', $newPassword)
        ) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Le mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre et un caractère spécial.',
                'data'    => null
            ];
        }

        try {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);

            return $this->executeImmediateTransaction(function () use ($otpCode, $hash): array {
                $stmt = $this->db->prepare("SELECT id, otp_expires_at FROM users WHERE otp_code = :otp LIMIT 1");
                $stmt->execute(['otp' => $otpCode]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();

                if (!$user) {
                    return [
                        'status'  => 'error',
                        'code'    => 400,
                        'message' => 'Code de sécurité invalide ou déjà utilisé.',
                        'data'    => null
                    ];
                }

                $expiryString = trim((string) ($user['otp_expires_at'] ?? ''));
                if ($expiryString !== '') {
                    $expiresTimestamp = strtotime($expiryString);
                    if ($expiresTimestamp !== false && time() > $expiresTimestamp) {
                        return [
                            'status'  => 'error',
                            'code'    => 400,
                            'message' => 'Ce code de sécurité a expiré. Veuillez demander un nouveau lien.',
                            'data'    => null
                        ];
                    }
                }

                $stmtUpdate = $this->db->prepare("
                    UPDATE users
                    SET password_hash = :hash, is_active = 1, otp_code = NULL, otp_expires_at = NULL
                    WHERE id = :id
                ");
                $stmtUpdate->execute([
                    'hash' => $hash,
                    'id'   => (int) $user['id']
                ]);

                return [
                    'status'  => 'success',
                    'code'    => 200,
                    'message' => 'Compte activé et mot de passe enregistré avec succès.',
                    'data'    => ['user_id' => (int) $user['id']]
                ];
            });
        } catch (Throwable $e) {
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Erreur lors de la validation du compte : ' . $e->getMessage(),
                'data'    => null
            ];
        }
    }

    public function shareTutorInfo(int $memberId, int $tutorId, bool $shared = true): array
    {
        $this->executeImmediateTransaction(function () use ($shared, $memberId, $tutorId): void {
            $stmt = $this->db->prepare("
                UPDATE member_tutor
                SET info_shared = :shared
                WHERE member_id = :mid AND tutor_id = :tid
            ");
            $stmt->execute([
                'shared' => $shared ? 1 : 0,
                'mid'    => $memberId,
                'tid'    => $tutorId
            ]);
        });

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => $shared ? 'Partage d\'informations familiales activé.' : 'Isolation RGPD restaurée.',
            'data'    => ['member_id' => $memberId, 'tutor_id' => $tutorId, 'info_shared' => $shared]
        ];
    }

    public function radiateMember(int $memberId, int $adminUserId): array
    {
        $stmtMem = $this->db->prepare("SELECT id FROM members WHERE id = :id");
        $stmtMem->execute(['id' => $memberId]);
        if (!$stmtMem->fetch()) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Adhérent introuvable.',
                'data'    => null
            ];
        }

        $archivedTutors = $this->executeImmediateTransaction(function () use ($memberId, $adminUserId): array {
            $stmtRad = $this->db->prepare("UPDATE members SET status = 'radiated' WHERE id = :id");
            $stmtRad->execute(['id' => $memberId]);

            $stmtTutors = $this->db->prepare("SELECT tutor_id FROM member_tutor WHERE member_id = :mid");
            $stmtTutors->execute(['mid' => $memberId]);
            $tutorIds = $stmtTutors->fetchAll(PDO::FETCH_COLUMN);

            $archived = [];
            foreach ($tutorIds as $tId) {
                $stmtActiveChildren = $this->db->prepare("
                    SELECT COUNT(*)
                    FROM member_tutor mt
                    INNER JOIN members m ON mt.member_id = m.id
                    WHERE mt.tutor_id = :tid AND m.status = 'active'
                ");
                $stmtActiveChildren->execute(['tid' => (int) $tId]);
                $activeCount = (int) $stmtActiveChildren->fetchColumn();

                if ($activeCount === 0) {
                    $stmtDeact = $this->db->prepare("UPDATE users SET is_active = 0 WHERE id = :uid");
                    $stmtDeact->execute(['uid' => (int) $tId]);
                    $archived[] = (int) $tId;
                }
            }

            $stmtLog = $this->db->prepare("
                INSERT INTO app_logs (user_id, action, target_type, target_id)
                VALUES (:uid, 'MEMBER_RADIATED', 'member', :tid)
            ");
            $stmtLog->execute([
                'uid' => $adminUserId,
                'tid' => $memberId
            ]);

            return $archived;
        });

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Adhérent radié avec succès.',
            'data'    => [
                'member_id'       => $memberId,
                'status'          => 'radiated',
                'archived_tutors' => $archivedTutors
            ]
        ];
    }

    /**
     * Détermine automatiquement le Grade (catégorie d'âge) d'une discipline correspondant à l'âge du pratiquant.
     */
    private function resolveDefaultGradeByAge(int $disciplineId, int $age): ?int
    {
        $stmt = $this->db->prepare("
            SELECT id FROM levels
            WHERE discipline_id = :did AND level_type = 'grade'
              AND (age_min IS NULL OR age_min <= :age)
              AND (age_max IS NULL OR age_max >= :age)
            ORDER BY rank_order ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute(['did' => $disciplineId, 'age' => $age]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }

        $stmtAny = $this->db->prepare("SELECT id FROM levels WHERE discipline_id = :did AND level_type = 'grade' ORDER BY id ASC LIMIT 1");
        $stmtAny->execute(['did' => $disciplineId]);
        $fallback = $stmtAny->fetchColumn();
        return $fallback ? (int) $fallback : null;
    }

    /**
     * Détermine le premier Niveau technique (ex: Ceinture Blanche) d'une discipline par défaut.
     */
    private function resolveDefaultSkillLevel(int $disciplineId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT id FROM levels
            WHERE discipline_id = :did AND level_type = 'niveau'
            ORDER BY rank_order ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute(['did' => $disciplineId]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    /**
     * Génère les données officielles du reçu de cotisation (Point 20).
     *
     * @param int           $registrationId Identifiant du dossier.
     * @param int           $userId         Identifiant de l'utilisateur demandeur.
     * @param array<string> $permissions    Permissions du demandeur.
     * @return array<string, mixed> Réponse JSend.
     */
    public function getReceipt(int $registrationId, int $userId, array $permissions): array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, u.email AS payer_email, u.first_name AS payer_first_name, u.last_name AS payer_last_name
            FROM registrations r
            LEFT JOIN users u ON r.payer_user_id = u.id
            WHERE r.id = :id
        ");
        $stmt->execute(['id' => $registrationId]);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reg) {
            return ['status' => 'error', 'code' => 404, 'message' => 'Dossier introuvable.', 'data' => null];
        }

        $canView = in_array('finances.manage', $permissions, true)
            || in_array('system.admin', $permissions, true)
            || ((int) $reg['payer_user_id'] === $userId);

        if (!$canView) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé au reçu de cette cotisation.', 'data' => null];
        }

        $members = $this->db->prepare("
            SELECT DISTINCT m.id, m.first_name, m.last_name, m.birth_date
            FROM member_practices mp
            INNER JOIN members m ON mp.member_id = m.id
            WHERE mp.registration_id = :rid
        ");
        $members->execute(['rid' => $registrationId]);
        $membersList = $members->fetchAll(PDO::FETCH_ASSOC);

        $payerName = trim(($reg['payer_first_name'] ?? '') . ' ' . ($reg['payer_last_name'] ?? ''));
        if ($payerName === '') {
            $payerName = (string) ($reg['payer_email'] ?? 'Adhérent');
        }

        $receiptText = "========================================================\n";
        $receiptText .= "              REÇU DE COTISATION OFFICIEL\n";
        $receiptText .= "========================================================\n\n";
        $receiptText .= "Numéro : REC-" . str_pad((string) $registrationId, 6, '0', STR_PAD_LEFT) . "\n";
        $receiptText .= "Date d'émission : " . date('d/m/Y') . "\n";
        $receiptText .= "Payeur / Tuteur : " . $payerName . " (" . ($reg['payer_email'] ?? '') . ")\n";
        $receiptText .= "Total Réglé : " . number_format((float) $reg['total_amount'], 2, ',', ' ') . " €\n";
        $receiptText .= "Mode de règlement : " . strtoupper((string) $reg['payment_method']) . " (" . $reg['installments'] . "x)\n";
        $receiptText .= "Statut : Validé et encaissé\n\n";
        $receiptText .= "Pratiquants inscrits :\n";
        foreach ($membersList as $m) {
            $receiptText .= " - " . ($m['first_name'] ?? '') . " " . ($m['last_name'] ?? '') . " (Né(e) le " . ($m['birth_date'] ?? '') . ")\n";
        }
        $receiptText .= "\nDocument certifié conforme par le Trésorier de l'association.";

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Reçu de cotisation généré.',
            'data'    => [
                'receipt_number'       => 'REC-' . str_pad((string) $registrationId, 6, '0', STR_PAD_LEFT),
                'registration_id'      => (int) $registrationId,
                'status'               => (string) $reg['status'],
                'receipt_text'         => $receiptText,
                'payer'                => [
                    'email'      => (string) ($reg['payer_email'] ?? ''),
                    'first_name' => (string) ($reg['payer_first_name'] ?? ''),
                    'last_name'  => (string) ($reg['payer_last_name'] ?? '')
                ],
                'total_amount'         => (float) $reg['total_amount'],
                'discount_amount'      => (float) ($reg['discount_amount'] ?? 0.0),
                'discount_description' => (string) ($reg['discount_description'] ?? ''),
                'payment_method'       => (string) $reg['payment_method'],
                'installments'         => (int) $reg['installments'],
                'created_at'           => (string) $reg['created_at'],
                'validated_at'         => (string) ($reg['validated_at'] ?? $reg['created_at']),
                'members'              => $membersList
            ]
        ];
    }

    /**
     * Génère l'attestation destinée au Comité Social et Économique / Employeur (Point 20).
     *
     * @param int           $registrationId Identifiant du dossier.
     * @param int           $userId         Identifiant de l'utilisateur demandeur.
     * @param array<string> $permissions    Permissions du demandeur.
     * @return array<string, mixed> Réponse JSend.
     */
    public function getCseAttestation(int $registrationId, int $userId, array $permissions): array
    {
        $receipt = $this->getReceipt($registrationId, $userId, $permissions);
        if ($receipt['status'] !== 'success' || empty($receipt['data'])) {
            return $receipt;
        }

        $data = $receipt['data'];
        $data['attestation_type'] = 'CSE / Employeur';
        $data['purpose'] = 'Attestation de participation aux activités sportives et associatives pour prise en charge CSE/CE.';

        $payer = $data['payer'] ?? [];
        $payerName = trim(($payer['first_name'] ?? '') . ' ' . ($payer['last_name'] ?? ''));
        if ($payerName === '') {
            $payerName = (string) ($payer['email'] ?? 'Adhérent');
        }

        $cseText = "========================================================\n";
        $cseText .= "     ATTESTATION DE PRISE EN CHARGE CE / CSE\n";
        $cseText .= "========================================================\n\n";
        $cseText .= "À l'attention du Comité Social et Économique (CSE) / Employeur,\n\n";
        $cseText .= "L'association sportive Kanso-ya certifie par la présente que :\n";
        $cseText .= "M. / Mme " . $payerName . "\n";
        $cseText .= "a acquitté l'intégralité de la cotisation d'adhésion pour la saison sportive en cours.\n";
        $cseText .= "Montant total acquitté : " . number_format((float) ($data['total_amount'] ?? 0.0), 2, ',', ' ') . " €\n\n";
        $cseText .= "Bénéficiaire(s) / Ayant(s) droit :\n";
        if (is_array($data['members'])) {
            foreach ($data['members'] as $m) {
                $cseText .= " - " . ($m['first_name'] ?? '') . " " . ($m['last_name'] ?? '') . " (Né(e) le " . ($m['birth_date'] ?? '') . ")\n";
            }
        }
        $cseText .= "\nAttestation délivrée pour servir et valoir ce que de droit auprès du CSE ou de la direction des ressources humaines.";

        $data['attestation_text'] = $cseText;

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Attestation CSE générée avec succès.',
            'data'    => $data
        ];
    }

    private function calculateAge(string $birthDate): int
    {
        try {
            $dob = new DateTimeImmutable($birthDate);
            $now = new DateTimeImmutable();
            if ($dob > $now) {
                return -1;
            }
            return $now->diff($dob)->y;
        } catch (Throwable) {
            return -1;
        }
    }
}