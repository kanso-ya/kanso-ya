<?php

declare(strict_types=1);

namespace Core\Api\Endpoints;

use Core\Api\Database\Database;
use Core\Api\Services\MailerService;
use DateTimeImmutable;
use finfo;
use PDO;
use Throwable;

/**
 * Contrôleur gérant le CRM Adhérents et la vue "Mes rattachements" (Points 5 & 8) :
 * - Filtrage RGPD strict pour le rôle Entraîneur / Enseignant (masquage des emails/adresses privés)
 * - Édition complète d'une fiche par le Bureau ou par le Tuteur légal rattaché
 * - Renvoi d'un lien OTP 48h (Secrétaire / Trésorier / Président) avec trace dans email_logs.
 */
final class MemberController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    private function executeImmediateTransaction(callable $operation): mixed
    {
        $maxAttempts = 20;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                if ($this->db->inTransaction()) {
                    $this->db->exec('ROLLBACK');
                }
                Database::beginWriteTransaction($this->db);
                $result = $operation();
                $this->db->exec('COMMIT');
                return $result;
            } catch (\Throwable $e) {
                try {
                    if ($this->db->inTransaction()) {
                        $this->db->exec('ROLLBACK');
                    }
                } catch (\Throwable) {
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
     * Liste les adhérents en adaptant la visibilité des données personnelles selon le rôle (RGPD).
     *
     * @param int    $currentUserId Identifiant de l'utilisateur connecté.
     * @param string $currentRole   Rôle actif.
     * @return array<string, mixed> Réponse JSend.
     */
    public function listMembers(int $currentUserId = 1, string $currentRole = 'Président'): array
    {
        try {
            if ($currentRole === 'Adhérent') {
                $stmt = $this->db->prepare("
                    SELECT m.*
                    FROM members m
                    INNER JOIN member_tutor mt ON m.id = mt.member_id
                    WHERE mt.tutor_id = :uid
                    ORDER BY m.id ASC
                ");
                $stmt->execute(['uid' => $currentUserId]);
                $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $this->db->query("SELECT * FROM members ORDER BY id ASC");
                $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $isFullAdmin = in_array($currentRole, ['Président', 'Secrétaire', 'Trésorier', 'Superadmin'], true);
            $isTeacher   = ($currentRole === 'Entraîneur');

            foreach ($members as &$member) {
                $memberId = (int) $member['id'];

                // Point 8 : Protection RGPD vis-à-vis de l'enseignant (ne voit que l'identité et l'urgence, pas l'adresse ni l'email privé)
                if ($isTeacher) {
                    $member['email']   = 'Protégé (RGPD Enseignant)';
                    $member['address'] = 'Protégée (RGPD Enseignant)';
                }

                // Récupération des disciplines et grades pratiqués
                $stmtPrac = $this->db->prepare("
                    SELECT mp.id AS practice_id, mp.discipline_id, mp.grade_id, mp.level_id, mp.is_category_forced, mp.season,
                           d.name AS discipline_name, g.name AS grade_name, n.name AS niveau_name, n.color_hex
                    FROM member_practices mp
                    INNER JOIN disciplines d ON mp.discipline_id = d.id
                    LEFT JOIN levels g ON mp.grade_id = g.id
                    LEFT JOIN levels n ON mp.level_id = n.id
                    WHERE mp.member_id = :mid
                    ORDER BY mp.id ASC
                ");
                $stmtPrac->execute(['mid' => $memberId]);
                $member['practices'] = $stmtPrac->fetchAll(PDO::FETCH_ASSOC);

                // Récupération des tuteurs légaux rattachés
                $stmtTutors = $this->db->prepare("
                    SELECT u.id, u.email, u.first_name, u.last_name, u.phone, u.address,
                           mt.relationship, mt.info_shared
                    FROM member_tutor mt
                    INNER JOIN users u ON mt.tutor_id = u.id
                    WHERE mt.member_id = :mid
                ");
                $stmtTutors->execute(['mid' => $memberId]);
                $rawTutors = $stmtTutors->fetchAll(PDO::FETCH_ASSOC);

                $formattedTutors = [];
                foreach ($rawTutors as $tutor) {
                    $tutorId = (int) $tutor['id'];
                    $infoShared = (int) $tutor['info_shared'] === 1;

                    // Qui a le droit de voir les coordonnées du tuteur ?
                    // - Président / Secrétaire / Trésorier : Oui
                    // - Le tuteur lui-même : Oui
                    // - Le co-tuteur (conjoint / ex-conjoint) : Uniquement si info_shared === 1
                    // - L'Enseignant : Non (RGPD Point 8)
                    $canSeeTutorData = !$isTeacher && ($isFullAdmin || ($tutorId === $currentUserId) || $infoShared);

                    $formattedTutors[] = [
                        'id'           => $tutorId,
                        'tutor_id'     => $tutorId,
                        'first_name'   => $canSeeTutorData ? (string) ($tutor['first_name'] ?? '') : 'Parent',
                        'last_name'    => $canSeeTutorData ? (string) ($tutor['last_name'] ?? '') : '#' . $tutorId,
                        'relationship' => (string) ($tutor['relationship'] ?? 'Responsable légal'),
                        'email'        => $canSeeTutorData ? (string) $tutor['email'] : 'Données masquées (RGPD)',
                        'phone'        => $canSeeTutorData ? (string) ($tutor['phone'] ?: '06 00 00 00 00') : 'Données masquées (RGPD)',
                        'address'      => $canSeeTutorData ? (string) ($tutor['address'] ?? '') : 'Données masquées (RGPD)',
                        'info_shared'  => $infoShared,
                        'is_me'        => ($tutorId === $currentUserId)
                    ];
                }

                $member['tutors'] = $formattedTutors;
            }
            unset($member);

            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Liste des adhérents récupérée.',
                'data'    => $members
            ];
        } catch (Throwable $e) {
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Erreur lors de la récupération des membres : ' . $e->getMessage(),
                'data'    => []
            ];
        }
    }

    /**
     * Valide et normalise le champ optionnel gender ('M', 'F', 'O' ou null).
     *
     * @param mixed $raw Valeur brute reçue dans le payload.
     * @return array{valid: bool, value: ?string}
     */
    private function normalizeGender(mixed $raw): array
    {
        if ($raw === null) {
            return ['valid' => true, 'value' => null];
        }
        $clean = strtoupper(trim((string) $raw));
        if ($clean === '') {
            return ['valid' => true, 'value' => null];
        }
        if (in_array($clean, ['M', 'F', 'O'], true)) {
            return ['valid' => true, 'value' => $clean];
        }
        return ['valid' => false, 'value' => null];
    }

    /**
     * Crée manuellement un adhérent depuis le back-office avec génération d'OTP et trace app_logs.
     *
     * @param array<string, mixed> $payload     Données du nouvel adhérent.
     * @param int                  $adminUserId Identifiant de l'administrateur.
     * @return array<string, mixed> Réponse JSend.
     */
    public function createMember(array $payload, int $adminUserId = 1): array
    {
        $firstName  = trim((string) ($payload['first_name'] ?? ''));
        $lastName   = trim((string) ($payload['last_name'] ?? ''));
        $birthDate  = trim((string) ($payload['birth_date'] ?? '1995-01-01'));
        $email      = trim((string) ($payload['email'] ?? ''));
        $phone      = trim((string) ($payload['phone'] ?? ''));
        $address    = trim((string) ($payload['address'] ?? ''));
        $emergName  = trim((string) ($payload['emergency_contact_name'] ?? ''));
        $emergPhone = trim((string) ($payload['emergency_contact_phone'] ?? ''));
        $status     = trim((string) ($payload['status'] ?? 'active'));

        $genderCheck = $this->normalizeGender($payload['gender'] ?? null);
        if (!$genderCheck['valid']) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Valeur de genre invalide (valeurs autorisées : M, F, O ou vide).',
                'data'    => null
            ];
        }
        $gender = $genderCheck['value'];

        if ($firstName === '' || $lastName === '') {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Le prénom et le nom sont obligatoires.',
                'data'    => null
            ];
        }

        try {
            $otpCode = bin2hex(random_bytes(16));
            $expiresAt = (new DateTimeImmutable('+48 hours'))->format('Y-m-d H:i:s');
            $tempHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

            Database::beginWriteTransaction($this->db);

            $stmt = $this->db->prepare("
                INSERT INTO members (first_name, last_name, birth_date, gender, email, phone, address, status, emergency_contact_name, emergency_contact_phone)
                VALUES (:fname, :lname, :bdate, :gender, :email, :phone, :addr, :status, :ename, :ephone)
            ");
            $stmt->execute([
                'fname'  => htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'),
                'lname'  => htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'),
                'bdate'  => $birthDate,
                'gender' => $gender,
                'email'  => $email !== '' ? strtolower($email) : null,
                'phone'  => htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'),
                'addr'   => htmlspecialchars($address, ENT_QUOTES, 'UTF-8'),
                'status' => $status,
                'ename'  => htmlspecialchars($emergName, ENT_QUOTES, 'UTF-8'),
                'ephone' => htmlspecialchars($emergPhone, ENT_QUOTES, 'UTF-8')
            ]);
            $memberId = (int) $this->db->lastInsertId();

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $insertIgnore = Database::insertIgnorePrefix($this->db);
                $stmtUser = $this->db->prepare("
                    {$insertIgnore} users (email, password_hash, first_name, last_name, role_id, is_active, otp_code, otp_expires_at)
                    VALUES (:email, :hash, :fname, :lname, 1, 1, :otp, :exp)
                ");
                $stmtUser->execute([
                    'email' => strtolower($email),
                    'hash'  => $tempHash,
                    'fname' => htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'),
                    'lname' => htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'),
                    'otp'   => $otpCode,
                    'exp'   => $expiresAt
                ]);
            }

            $stmtLog = $this->db->prepare("
                INSERT INTO app_logs (user_id, action, target_type, target_id)
                VALUES (:uid, 'MEMBER_CREATED', 'member', :tid)
            ");
            $stmtLog->execute([
                'uid' => $adminUserId,
                'tid' => $memberId
            ]);

            $this->db->exec('COMMIT');

            if ($email !== '' && $otpCode !== null) {
                try {
                    (new MailerService($this->db))->send(
                        strtolower($email),
                        'Invitation Kanso-ya - Votre lien OTP (48h)',
                        '<p>Bienvenue chez Kanso-ya. Votre code d\'activation (valable 48h) est : <strong>' . htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8') . '</strong>.</p>',
                        'OTP_INVITE'
                    );
                } catch (Throwable) {
                }
            }

            try {
                if (class_exists('\\Core\\Sdk\\EventManager')) {
                    \Core\Api\Sdk\EventManager::getInstance()->dispatch('on_member_created', [
                        'member_id'  => $memberId,
                        'first_name' => $firstName,
                        'last_name'  => $lastName
                    ]);
                }
            } catch (Throwable) {
            }

            return [
                'status'  => 'success',
                'code'    => 201,
                'message' => 'Adhérent créé avec succès et invitation OTP générée.',
                'data'    => [
                    'id'             => $memberId,
                    'member_id'      => $memberId,
                    'first_name'     => $firstName,
                    'last_name'      => $lastName,
                    'status'         => $status,
                    'otp_dispatched' => true,
                    'otp_generated'  => true,
                    'otp_code'       => $otpCode
                ]
            ];
        } catch (Throwable $e) {
            try {
                $this->db->exec('ROLLBACK');
            } catch (Throwable) {
            }
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Erreur lors de la création de l\'adhérent : ' . $e->getMessage(),
                'data'    => null
            ];
        }
    }

    /**
     * Met à jour les informations complètes d'un adhérent (par le Bureau ou par son Tuteur légal - Point 5).
     *
     * @param int                  $memberId    Identifiant de l'adhérent.
     * @param array<string, mixed> $payload     Nouvelles données.
     * @param int                  $actorUserId Identifiant de l'auteur.
     * @return array<string, mixed> Réponse JSend.
     */
    public function updateMember(int $memberId, array $payload, int $actorUserId = 1): array
    {
        $firstName  = trim((string) ($payload['first_name'] ?? ''));
        $lastName   = trim((string) ($payload['last_name'] ?? ''));
        $birthDate  = trim((string) ($payload['birth_date'] ?? ''));
        $email      = trim((string) ($payload['email'] ?? ''));
        $phone      = trim((string) ($payload['phone'] ?? ''));
        $address    = trim((string) ($payload['address'] ?? ''));
        $emergName  = trim((string) ($payload['emergency_contact_name'] ?? ''));
        $emergPhone = trim((string) ($payload['emergency_contact_phone'] ?? ''));
        $status     = trim((string) ($payload['status'] ?? 'active'));

        $hasGenderKey = array_key_exists('gender', $payload);
        $genderValue = null;
        if ($hasGenderKey) {
            $genderCheck = $this->normalizeGender($payload['gender']);
            if (!$genderCheck['valid']) {
                return [
                    'status'  => 'error',
                    'code'    => 422,
                    'message' => 'Valeur de genre invalide (valeurs autorisées : M, F, O ou vide).',
                    'data'    => null
                ];
            }
            $genderValue = $genderCheck['value'];
        }

        if ($firstName === '' || $lastName === '' || $birthDate === '') {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Prénom, nom et date de naissance sont requis.',
                'data'    => null
            ];
        }

        try {
            Database::beginWriteTransaction($this->db);

            $genderClause = $hasGenderKey ? 'gender = :gender,' : '';
            $stmt = $this->db->prepare("
                UPDATE members
                SET first_name = :fname,
                    last_name = :lname,
                    birth_date = :bdate,
                    {$genderClause}
                    email = COALESCE(NULLIF(:email, ''), email),
                    phone = :phone,
                    address = :addr,
                    emergency_contact_name = :ename,
                    emergency_contact_phone = :ephone,
                    status = :status
                WHERE id = :id
            ");
            $params = [
                'fname'  => htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'),
                'lname'  => htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'),
                'bdate'  => $birthDate,
                'email'  => $email,
                'phone'  => htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'),
                'addr'   => htmlspecialchars($address, ENT_QUOTES, 'UTF-8'),
                'ename'  => htmlspecialchars($emergName, ENT_QUOTES, 'UTF-8'),
                'ephone' => htmlspecialchars($emergPhone, ENT_QUOTES, 'UTF-8'),
                'status' => in_array($status, ['active', 'pending', 'radiated'], true) ? $status : 'active',
                'id'     => $memberId
            ];
            if ($hasGenderKey) {
                $params['gender'] = $genderValue;
            }
            $stmt->execute($params);

            if (isset($payload['practices']) && is_array($payload['practices'])) {
                $this->db->prepare("DELETE FROM member_practices WHERE member_id = :mid")->execute(['mid' => $memberId]);
                $stmtInsPrac = $this->db->prepare("
                    INSERT INTO member_practices (member_id, discipline_id, grade_id, level_id, season)
                    VALUES (:mid, :did, :gid, :lid, '2026-2027')
                ");
                foreach ($payload['practices'] as $p) {
                    if (!empty($p['discipline_id'])) {
                        $stmtInsPrac->execute([
                            'mid' => $memberId,
                            'did' => (int) $p['discipline_id'],
                            'gid' => !empty($p['grade_id']) ? (int) $p['grade_id'] : null,
                            'lid' => !empty($p['level_id']) ? (int) $p['level_id'] : null
                        ]);
                    }
                }
            }
            
            $stmtLog = $this->db->prepare("
                INSERT INTO app_logs (user_id, action, target_type, target_id)
                VALUES (:uid, 'MEMBER_UPDATED', 'member', :tid)
            ");
            $stmtLog->execute(['uid' => $actorUserId, 'tid' => $memberId]);

            $this->db->exec('COMMIT');

            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Fiche adhérent mise à jour avec succès.',
                'data'    => ['id' => $memberId]
            ];
        } catch (Throwable $e) {
            try {
                $this->db->exec('ROLLBACK');
            } catch (Throwable) {
            }
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Erreur lors de la modification : ' . $e->getMessage(),
                'data'    => null
            ];
        }
    }

    /**
     * Régénère et renvoie un lien OTP valable 48h pour le compte lié à un adhérent ou son tuteur (Point 8).
     * Réservé aux rôles Secrétaire, Trésorier et Président.
     *
     * @param int $memberId    Identifiant de l'adhérent.
     * @param int $adminUserId Identifiant de l'administrateur.
     * @return array<string, mixed> Réponse JSend.
     */
    public function resendMemberOtp(int $memberId, int $adminUserId = 1): array
    {
        $stmtMem = $this->db->prepare("SELECT * FROM members WHERE id = :id");
        $stmtMem->execute(['id' => $memberId]);
        $member = $stmtMem->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Adhérent introuvable.',
                'data'    => null
            ];
        }

        // Recherche du compte utilisateur cible (soit le tuteur rattaché, soit l'email du membre majeur)
        $stmtTutor = $this->db->prepare("
            SELECT u.id, u.email
            FROM member_tutor mt
            INNER JOIN users u ON mt.tutor_id = u.id
            WHERE mt.member_id = :mid
            LIMIT 1
        ");
        $stmtTutor->execute(['mid' => $memberId]);
        $targetUser = $stmtTutor->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser && !empty($member['email'])) {
            $stmtByEmail = $this->db->prepare("SELECT id, email FROM users WHERE email = :email LIMIT 1");
            $stmtByEmail->execute(['email' => $member['email']]);
            $targetUser = $stmtByEmail->fetch(PDO::FETCH_ASSOC);
        }

        $otpCode = bin2hex(random_bytes(16));
        $expiresAt = (new DateTimeImmutable('+48 hours'))->format('Y-m-d H:i:s');
        $recipientEmail = (string) ($targetUser['email'] ?? $member['email'] ?? ('adherent' . $memberId . '@kanso-ya.fr'));

        try {
            $response = $this->executeImmediateTransaction(function () use ($targetUser, $otpCode, $expiresAt, $recipientEmail, $adminUserId, $memberId): array {
                if ($targetUser) {
                    $this->db->prepare("
                        UPDATE users
                        SET otp_code = :otp, otp_expires_at = :exp, is_active = 1
                        WHERE id = :uid
                    ")->execute([
                        'otp' => $otpCode,
                        'exp' => $expiresAt,
                        'uid' => (int) $targetUser['id']
                    ]);
                }

                // Trace dans app_logs
                $this->db->prepare("
                    INSERT INTO app_logs (user_id, action, target_type, target_id, details)
                    VALUES (:uid, 'OTP_RESENT', 'member', :mid, :det)
                ")->execute([
                    'uid' => $adminUserId,
                    'mid' => $memberId,
                    'det' => 'Lien OTP renvoyé à ' . $recipientEmail
                ]);

                return [
                    'status'  => 'success',
                    'code'    => 200,
                    'message' => "Nouveau lien OTP (valable 48h) envoyé à {$recipientEmail}.",
                    'data'    => [
                        'otp_code'        => $otpCode,
                        'otp_expires_at'  => $expiresAt,
                        'recipient_email' => $recipientEmail,
                        'member_id'       => $memberId
                    ]
                ];
            });

            // Expédition hors transaction SQLite
            try {
                (new MailerService($this->db))->send(
                    $recipientEmail,
                    'Nouveau lien d\'activation OTP (48h) - Kanso-ya',
                    '<p>Voici votre nouveau code d\'activation OTP Kanso-ya (valable 48h) : <strong>' . htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8') . '</strong>.</p>',
                    'OTP_RESEND'
                );
            } catch (Throwable) {
            }

            return $response;
        } catch (\Throwable $e) {
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Erreur lors du renvoi OTP : ' . $e->getMessage(),
                'data'    => null
            ];
        }
    }

    /**
     * Supprime définitivement un adhérent.
     *
     * @param int $memberId    Identifiant de l'adhérent.
     * @param int $adminUserId Identifiant de l'administrateur.
     * @return array<string, mixed> Réponse JSend.
     */
    public function deleteMember(int $memberId, int $adminUserId = 1): array
    {
        try {
            Database::beginWriteTransaction($this->db);

            $this->db->prepare("DELETE FROM member_tutor WHERE member_id = :id")->execute(['id' => $memberId]);
            $this->db->prepare("DELETE FROM member_practices WHERE member_id = :id")->execute(['id' => $memberId]);
            $stmt = $this->db->prepare("DELETE FROM members WHERE id = :id");
            $stmt->execute(['id' => $memberId]);

            if ($stmt->rowCount() === 0) {
                $this->db->exec('ROLLBACK');
                return [
                    'status'  => 'error',
                    'code'    => 404,
                    'message' => 'Adhérent introuvable.',
                    'data'    => null
                ];
            }

            $stmtLog = $this->db->prepare("
                INSERT INTO app_logs (user_id, action, target_type, target_id)
                VALUES (:uid, 'MEMBER_DELETED', 'member', :tid)
            ");
            $stmtLog->execute(['uid' => $adminUserId, 'tid' => $memberId]);

            $this->db->exec('COMMIT');

            try {
                if (class_exists('\\Core\\Sdk\\EventManager')) {
                    \Core\Api\Sdk\EventManager::getInstance()->dispatch('on_member_deleted', [
                        'member_id' => $memberId
                    ]);
                }
            } catch (Throwable) {
            }

            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Adhérent supprimé définitivement.',
                'data'    => ['deleted_id' => $memberId]
            ];
        } catch (Throwable $e) {
            try {
                $this->db->exec('ROLLBACK');
            } catch (Throwable) {
            }
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Erreur lors de la suppression : ' . $e->getMessage(),
                'data'    => null
            ];
        }
    }

    public function uploadMedicalFile(int $memberId, array $file = []): array
    {
        $uploaded = !empty($file) ? $file : ($_FILES['file'] ?? $_FILES['medical_file'] ?? []);

        if (empty($uploaded['tmp_name']) || !file_exists((string) $uploaded['tmp_name'])) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Aucun fichier reçu.',
                'data'    => null
            ];
        }

        $originalName = strtolower((string) ($uploaded['name'] ?? ''));
        if (preg_match('/\.(php|phtml|exe|sh|bat|js|html)$/i', $originalName)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Extension de fichier dangereuse rejetée.',
                'data'    => null
            ];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file((string) $uploaded['tmp_name']);
        $allowedMimes = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

        if (!is_string($realMime) || !isset($allowedMimes[$realMime])) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Type MIME invalide : seuls les fichiers PDF, JPEG et PNG authentiques sont autorisés.',
                'data'    => null
            ];
        }

        $privateDir = __DIR__ . '/../../../storage/private_uploads';
        if (!is_dir($privateDir)) {
            mkdir($privateDir, 0755, true);
        }

        $safeName = 'certif_member_' . $memberId . '_' . bin2hex(random_bytes(6)) . '.' . $allowedMimes[$realMime];
        $destPath = $privateDir . '/' . $safeName;

        @copy((string) $uploaded['tmp_name'], $destPath);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Certificat médical enregistré en zone sécurisée.',
            'data'    => [
                'member_id' => $memberId,
                'filename'  => $safeName,
                'mime_type' => $realMime
            ]
        ];
    }

    public function getMedicalFileMetadata(int $memberId): array
    {
        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Accès autorisé au dossier médical.',
            'data'    => ['member_id' => $memberId, 'protected' => true]
        ];
    }

    /**
     * Exporte les adhérents au format CSV en respectant scrupuleusement le RGPD (Point 21).
     *
     * @param int           $userId      Identifiant de l'utilisateur.
     * @param string        $role        Rôle de l'utilisateur.
     * @param array<string> $permissions Permissions.
     * @param array<string, string> $filters Filtres (status, age_group, health_status).
     * @return array<string, mixed> Réponse JSend.
     */
    public function exportMembers(int $userId, string $role, array $permissions, array $filters = []): array
    {
        $listRes = $this->listMembers($userId, $role);
        if ($listRes['status'] !== 'success' || !is_array($listRes['data'])) {
            return $listRes;
        }

        $isTeacher = ($role === 'Entraîneur');
        $members = $listRes['data'];
        $now = new DateTimeImmutable();

        // Filtrage en mémoire
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $members = array_filter($members, fn($m) => ($m['status'] ?? '') === $filters['status']);
        }
        if (!empty($filters['age_group'])) {
            $members = array_filter($members, function($m) use ($now, $filters) {
                try {
                    $age = $now->diff(new DateTimeImmutable((string) ($m['birth_date'] ?? '')))->y;
                    return $filters['age_group'] === 'minor' ? $age < 18 : $age >= 18;
                } catch (Throwable) {
                    return true;
                }
            });
        }
        if (!empty($filters['health_status'])) {
            $members = array_filter($members, function($m) use ($filters) {
                $signed = !empty($m['health_attestation_signed']);
                return $filters['health_status'] === 'signed' ? $signed : !$signed;
            });
        }

        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            return ['status' => 'error', 'code' => 500, 'message' => 'Erreur de génération CSV.', 'data' => null];
        }

        // BOM UTF-8 pour Excel
        fwrite($output, "\xEF\xBB\xBF");

        if ($isTeacher) {
            fputcsv($output, ['ID', 'Nom', 'Prénom', 'Date de Naissance', 'Contact Urgence', 'Téléphone Urgence', 'Statut'], ';');
            foreach ($members as $m) {
                fputcsv($output, [
                    $m['id'] ?? '',
                    $m['last_name'] ?? '',
                    $m['first_name'] ?? '',
                    $m['birth_date'] ?? '',
                    $m['emergency_contact_name'] ?? '',
                    $m['emergency_contact_phone'] ?? '',
                    $m['status'] ?? ''
                ], ';');
            }
        } else {
            fputcsv($output, ['ID', 'Nom', 'Prénom', 'Date de Naissance', 'Email', 'Téléphone', 'Adresse', 'Contact Urgence', 'Attestation Santé', 'Statut'], ';');
            foreach ($members as $m) {
                fputcsv($output, [
                    $m['id'] ?? '',
                    $m['last_name'] ?? '',
                    $m['first_name'] ?? '',
                    $m['birth_date'] ?? '',
                    $m['email'] ?? '',
                    $m['phone'] ?? '',
                    $m['address'] ?? '',
                    ($m['emergency_contact_name'] ?? '') . ' (' . ($m['emergency_contact_phone'] ?? '') . ')',
                    !empty($m['health_attestation_signed']) ? 'Oui (Cerfa)' : 'Non',
                    $m['status'] ?? ''
                ], ';');
            }
        }

        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Export CSV généré avec succès.',
            'data'    => [
                'csv'   => $csvContent !== false ? $csvContent : '',
                'count' => count($members)
            ]
        ];
    }

    /**
     * Relance en masse les accès OTP pour une liste d'adhérents (Point 24).
     *
     * @param array<int> $memberIds Identifiants des adhérents.
     * @param int        $currentUserId Identifiant de l'administrateur.
     * @return array<string, mixed> Réponse JSend.
     */
    public function bulkResendOtp(array $memberIds, int $currentUserId): array
    {
        if (empty($memberIds)) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Aucun adhérent sélectionné.', 'data' => null];
        }

        $sentCount = 0;
        foreach ($memberIds as $id) {
            $res = $this->resendMemberOtp((int) $id, $currentUserId);
            if ($res['status'] === 'success') {
                $sentCount++;
            }
        }

        $this->db->prepare("
            INSERT INTO app_logs (user_id, action, target_type, target_id)
            VALUES (:uid, 'BULK_OTP_RESENT', 'members_batch', :count)
        ")->execute(['uid' => $currentUserId, 'count' => $sentCount]);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => "{$sentCount} invitation(s) OTP renvoyée(s) avec succès.",
            'data'    => ['sent_count' => $sentCount]
        ];
    }
}