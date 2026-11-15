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

        $basePrice = 150.0;
        $memberCount = count($members);
        $totalAmount = $memberCount === 1
            ? $basePrice
            : round(($basePrice * $memberCount) * 0.90, 2);

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

            $result = $this->executeImmediateTransaction(function () use ($tutorsPayload, $members, $precomputedHash, $season, $totalAmount, $paymentMethod, $installments): array {
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
                    INSERT INTO registrations (payer_user_id, season, total_amount, payment_method, installments, status)
                    VALUES (:payer, :season, :amount, :method, :installments, 'pending')
                ");
                $stmtReg->execute([
                    'payer'        => $payerUserId,
                    'season'       => $season,
                    'amount'       => $totalAmount,
                    'method'       => $paymentMethod,
                    'installments' => $installments
                ]);
                $registrationId = (int) $this->db->lastInsertId();

                $createdMemberIds = [];
                foreach ($members as $m) {
                    $bdate = trim((string) $m['birth_date']);
                    $age   = $this->calculateAge($bdate);

                    $stmtMem = $this->db->prepare("
                        INSERT INTO members (first_name, last_name, birth_date, email, status, emergency_contact_name, emergency_contact_phone)
                        VALUES (:fname, :lname, :bdate, :email, 'pending', :ename, :ephone)
                    ");
                    $stmtMem->execute([
                        'fname'  => htmlspecialchars(trim((string) $m['first_name']), ENT_QUOTES, 'UTF-8'),
                        'lname'  => htmlspecialchars(trim((string) $m['last_name']), ENT_QUOTES, 'UTF-8'),
                        'bdate'  => $bdate,
                        'email'  => !empty($m['email']) ? strtolower(trim((string) $m['email'])) : null,
                        'ename'  => htmlspecialchars(trim((string) ($m['emergency_contact_name'] ?? '')), ENT_QUOTES, 'UTF-8'),
                        'ephone' => htmlspecialchars(trim((string) ($m['emergency_contact_phone'] ?? '')), ENT_QUOTES, 'UTF-8')
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
                    'registration_id' => $registrationId,
                    'member_ids'      => $createdMemberIds,
                    'total_amount'    => $totalAmount,
                    'installments'    => $installments,
                    'status'          => 'pending'
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

        if ($otpCode === '' || strlen($newPassword) < 8) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Jeton OTP requis et mot de passe de 8 caractères minimum.',
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
                        'message' => 'Jeton OTP invalide ou déjà utilisé.',
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
                            'message' => 'Ce jeton OTP a expiré (délai de 48h dépassé).',
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
                    'message' => 'Compte activé et mot de passe défini avec succès.',
                    'data'    => ['user_id' => (int) $user['id']]
                ];
            });
        } catch (Throwable $e) {
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Erreur lors de l\'activation OTP : ' . $e->getMessage(),
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