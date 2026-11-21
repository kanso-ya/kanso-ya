<?php

declare(strict_types=1);

namespace Core\Api\Endpoints;

use Core\Api\Database\Database;
use Core\Api\Services\MailerService;
use PDO;
use Throwable;

/**
 * Contrôleur d'authentification et de gestion de "Mon Profil" (Point 4) :
 * - Login standard, Shadow Login Superadmin, Déconnexion
 * - Lecture & Mise à jour des informations du profil + Champs personnalisés dynamiques
 * - Activation / Désactivation de la 2FA (TOTP) et changement de mot de passe.
 */
final class AuthController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ensureTwoFactorMethodColumn();
    }

    /**
     * Garantit l'existence de la colonne two_factor_method sur la table users.
     */
    private function ensureTwoFactorMethodColumn(): void
    {
        try {
            if (!Database::tableHasColumn($this->db, 'users', 'two_factor_method')) {
                $this->db->exec("ALTER TABLE users ADD COLUMN two_factor_method VARCHAR(20) NOT NULL DEFAULT 'totp'");
            }
        } catch (Throwable) {
        }
    }

    /**
     * Exécute une opération SQLite dans une transaction IMMEDIATE avec retry sur verrou.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws Throwable
     */
    private function executeImmediateTransaction(callable $operation): mixed
    {
        $maxAttempts = 15;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                if ($this->db->inTransaction()) {
                    $this->db->exec('ROLLBACK');
                }
                Database::beginWriteTransaction($this->db);
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
                    usleep(random_int(40000, 120000) * $attempt);
                    continue;
                }
                throw $e;
            }
        }
        throw new \RuntimeException('Base de données temporairement occupée.');
    }

    /**
     * Initie une procédure "Mot de passe oublié" anti-énumération :
     * génère un code de réinitialisation si le compte existe et le journalise dans email_logs,
     * tout en renvoyant systématiquement un message neutre identique.
     *
     * @param array<string, mixed> $payload Contient 'email'.
     * @return array<string, mixed> Réponse normalisée.
     */
    public function forgotPassword(array $payload): array
    {
        $email = strtolower(trim((string) ($payload['email'] ?? '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Veuillez saisir une adresse e-mail valide.',
                'data'    => null
            ];
        }

        $neutralMessage = 'Si un compte est associé à cette adresse e-mail, un code de réinitialisation vient de vous être envoyé.';

        try {
            $dispatchData = $this->executeImmediateTransaction(function () use ($email): ?array {
                $stmt = $this->db->prepare("SELECT id, email, first_name FROM users WHERE LOWER(email) = :email AND is_active = 1 LIMIT 1");
                $stmt->execute(['email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();

                if (!$user) {
                    return null;
                }

                $resetCode = strtoupper(bin2hex(random_bytes(4)));
                $expiresAt = (new \DateTimeImmutable('+48 hours'))->format('Y-m-d H:i:s');

                $stmtUpd = $this->db->prepare("
                    UPDATE users
                    SET otp_code = :code, otp_expires_at = :exp
                    WHERE id = :id
                ");
                $stmtUpd->execute([
                    'code' => $resetCode,
                    'exp'  => $expiresAt,
                    'id'   => (int) $user['id']
                ]);

                $stmtLog = $this->db->prepare("
                    INSERT INTO app_logs (user_id, action, target_type, target_id, details)
                    VALUES (:uid, 'PASSWORD_RESET_REQUESTED', 'user', :uid, 'Demande de réinitialisation du mot de passe')
                ");
                $stmtLog->execute(['uid' => (int) $user['id']]);

                return [
                    'email' => (string) $user['email'],
                    'code'  => $resetCode,
                ];
            });

            // Expédition hors transaction SQLite pour éviter tout verrou pendant un appel réseau SMTP
            if ($dispatchData !== null) {
                (new MailerService($this->db))->send(
                    $dispatchData['email'],
                    'Réinitialisation de votre mot de passe',
                    '<p>Votre code temporaire de réinitialisation Kanso-ya est : <strong>' . htmlspecialchars($dispatchData['code'], ENT_QUOTES, 'UTF-8') . '</strong> (valable 48 heures).</p>',
                    'PASSWORD_RESET'
                );
            }
        } catch (Throwable) {
            // Maintien de la réponse neutre anti-énumération même en cas d'incident mineur
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => $neutralMessage,
            'data'    => ['dispatched' => true]
        ];
    }

    /**
     * Envoie un code de vérification à 6 chiffres par e-mail pour activer ou utiliser la double authentification par e-mail.
     *
     * @param int $userId Identifiant de l'utilisateur connecté.
     * @return array<string, mixed> Réponse normalisée.
     */
    public function sendTwoFactorEmailCode(int $userId): array
    {
        if ($userId <= 0) {
            return [
                'status'  => 'error',
                'code'    => 401,
                'message' => 'Veuillez vous connecter pour recevoir un code de vérification.',
                'data'    => null
            ];
        }

        $stmt = $this->db->prepare("SELECT id, email FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || empty($user['email'])) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Compte utilisateur introuvable.',
                'data'    => null
            ];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = (new \DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s');

        try {
            $this->executeImmediateTransaction(function () use ($userId, $code, $expiresAt): void {
                $this->db->prepare("
                    UPDATE users
                    SET otp_code = :code, otp_expires_at = :exp
                    WHERE id = :id
                ")->execute([
                    'code' => $code,
                    'exp'  => $expiresAt,
                    'id'   => $userId
                ]);
            });

            // Expédition hors transaction SQLite
            (new MailerService($this->db))->send(
                (string) $user['email'],
                'Votre code de vérification de sécurité',
                '<p>Votre code de double authentification Kanso-ya est : <strong>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</strong> (valable 10 minutes).</p>',
                '2FA_EMAIL_CODE'
            );
        } catch (Throwable $e) {
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Impossible d\'envoyer le code de vérification par e-mail pour le moment.',
                'data'    => null
            ];
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Un code de vérification à 6 chiffres a été envoyé à votre adresse e-mail.',
            'data'    => [
                'email'      => (string) $user['email'],
                'expires_in' => 600
            ]
        ];
    }

    /**
     * Authentifie un utilisateur standard.
     *
     * @param array<string, mixed> $payload Identifiants.
     * @return array<string, mixed> Réponse JSend.
     */
    public function login(array $payload): array
    {
        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($email === '' || $password === '') {
            return [
                'status'  => 'error',
                'code'    => 401,
                'message' => 'Identifiants invalides.',
                'data'    => null
            ];
        }

        $stmt = $this->db->prepare("
            SELECT u.*, r.name AS role_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.email = :email
        ");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return [
                'status'  => 'error',
                'code'    => 401,
                'message' => 'Identifiants invalides.',
                'data'    => null
            ];
        }

        if ((int) $user['is_active'] === 0) {
            return [
                'status'  => 'error',
                'code'    => 403,
                'message' => 'Compte désactivé, veuillez contacter le bureau du club.',
                'data'    => null
            ];
        }

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['role_id'] = (int) $user['role_id'];
        $_SESSION['role_name'] = (string) ($user['role_name'] ?? 'Adhérent');
        $_SESSION['is_superadmin'] = false;

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Connexion réussie.',
            'data'    => [
                'user_id' => (int) $user['id'],
                'email'   => (string) $user['email'],
                'role'    => $_SESSION['role_name']
            ]
        ];
    }

    /**
     * Authentifie un Superadmin via /sys-admin-xyz.
     *
     * @param array<string, mixed> $payload Identifiants.
     * @return array<string, mixed> Réponse JSend.
     */
    public function sysLogin(array $payload): array
    {
        $identifier = trim((string) ($payload['username'] ?? $payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($identifier === '' || $password === '') {
            return [
                'status'  => 'error',
                'code'    => 401,
                'message' => 'Identifiants système invalides.',
                'data'    => null
            ];
        }

        $stmt = $this->db->prepare("SELECT * FROM sys_superadmins WHERE username = :id OR email = :id");
        $stmt->execute(['id' => $identifier]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin || !password_verify($password, (string) $admin['password_hash'])) {
            return [
                'status'  => 'error',
                'code'    => 401,
                'message' => 'Identifiants système invalides.',
                'data'    => null
            ];
        }

        $_SESSION['user_id'] = (int) $admin['id'];
        $_SESSION['role_name'] = 'Superadmin';
        $_SESSION['is_superadmin'] = true;

        try {
            $stmtLog = $this->db->prepare("
                INSERT INTO sys_logs (superadmin_id, action, details, ip_address)
                VALUES (:id, 'SYS_LOGIN_SUCCESS', 'Authentification console Superadmin', :ip)
            ");
            $stmtLog->execute([
                'id' => (int) $admin['id'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        } catch (Throwable) {
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Accès technique autorisé.',
            'data'    => [
                'superadmin_id' => (int) $admin['id'],
                'username'      => (string) $admin['username']
            ]
        ];
    }

    /**
     * Déconnecte l'utilisateur.
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function logout(): array
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Déconnexion effectuée avec succès.',
            'data'    => null
        ];
    }

    /**
     * Récupère les informations complètes du profil de l'utilisateur connecté (Point 4).
     *
     * @param int $userId Identifiant de l'utilisateur.
     * @return array<string, mixed> Réponse JSend.
     */
    public function getMyProfile(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT u.id, u.email, u.first_name, u.last_name, u.phone, u.address,
                   u.custom_fields, u.two_factor_enabled, u.two_factor_method, u.two_factor_secret,
                   r.name AS role_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.id = :id
        ");
        $stmt->execute(['id' => $userId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$profile) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Profil introuvable.',
                'data'    => null
            ];
        }

        $custom = is_string($profile['custom_fields'] ?? null)
            ? json_decode($profile['custom_fields'], true)
            : [];
        $profile['custom_fields'] = is_array($custom) ? $custom : [];
        $profile['two_factor_enabled'] = (int) ($profile['two_factor_enabled'] ?? 0) === 1;
        $profile['two_factor_method'] = (string) ($profile['two_factor_method'] ?: 'totp');

        if (empty($profile['two_factor_secret'])) {
            $profile['two_factor_secret'] = 'KANSO2FA' . strtoupper(bin2hex(random_bytes(4)));
        }

        $stmtMem = $this->db->prepare("
            SELECT m.*
            FROM members m
            INNER JOIN member_tutor mt ON m.id = mt.member_id
            WHERE mt.tutor_id = :uid
            ORDER BY m.id ASC
        ");
        $stmtMem->execute(['uid' => $userId]);
        $linkedMembers = $stmtMem->fetchAll(PDO::FETCH_ASSOC);

        $loadPracticesForMember = function (int $memberId): array {
            $stmtPrac = $this->db->prepare("
                SELECT mp.discipline_id, mp.grade_id, mp.level_id, mp.season,
                       d.name AS discipline_name,
                       g.name AS grade_name,
                       n.name AS level_name
                FROM member_practices mp
                INNER JOIN disciplines d ON mp.discipline_id = d.id
                LEFT JOIN levels g ON mp.grade_id = g.id
                LEFT JOIN levels n ON mp.level_id = n.id
                WHERE mp.member_id = :mid
                ORDER BY mp.season DESC, mp.id DESC
            ");
            $stmtPrac->execute(['mid' => $memberId]);
            $rows = $stmtPrac->fetchAll(PDO::FETCH_ASSOC);
            // Déduplique par discipline en conservant la saison la plus récente
            $byDisc = [];
            foreach ($rows as $row) {
                $did = (int) $row['discipline_id'];
                if (!isset($byDisc[$did])) {
                    $byDisc[$did] = [
                        'discipline_id'   => $did,
                        'discipline_name' => (string) ($row['discipline_name'] ?? ''),
                        'grade_id'        => $row['grade_id'] !== null ? (int) $row['grade_id'] : null,
                        'grade_name'      => (string) ($row['grade_name'] ?? ''),
                        'level_id'        => $row['level_id'] !== null ? (int) $row['level_id'] : null,
                        'level_name'      => (string) ($row['level_name'] ?? ''),
                        'season'          => (string) ($row['season'] ?? '')
                    ];
                }
            }
            return array_values($byDisc);
        };

        $stmtTutors = $this->db->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.address,
                   mt.relationship, mt.info_shared
            FROM member_tutor mt
            INNER JOIN users u ON mt.tutor_id = u.id
            WHERE mt.member_id = :mid
            ORDER BY CASE WHEN mt.tutor_id = :uid THEN 0 ELSE 1 END, u.id ASC
        ");

        foreach ($linkedMembers as &$lm) {
            $mid = (int) $lm['id'];
            $lm['practices'] = $loadPracticesForMember($mid);

            $stmtTutors->execute(['mid' => $mid, 'uid' => $userId]);
            $rawTutors = $stmtTutors->fetchAll(PDO::FETCH_ASSOC);
            $tutorsList = [];
            foreach ($rawTutors as $t) {
                $isSelfTutor = (int) $t['id'] === $userId;
                $isShared = (int) ($t['info_shared'] ?? 0) === 1;
                $tutorsList[] = [
                    'id'           => (int) $t['id'],
                    'first_name'   => (string) ($t['first_name'] ?? ''),
                    'last_name'    => (string) ($t['last_name'] ?? ''),
                    'relationship' => (string) ($t['relationship'] ?? 'Parent / Tuteur légal'),
                    'email'        => ($isSelfTutor || $isShared) ? (string) ($t['email'] ?? '') : '',
                    'phone'        => ($isSelfTutor || $isShared) ? (string) ($t['phone'] ?? '') : '',
                    'address'      => ($isSelfTutor || $isShared) ? (string) ($t['address'] ?? '') : '',
                    'info_shared'  => $isShared
                ];
            }
            $lm['tutors'] = $tutorsList;
        }
        unset($lm);
        $profile['linked_members'] = $linkedMembers;

        // Recherche éventuelle d'une fiche pratiquant correspondant à l'utilisateur connecté lui-même
        $stmtSelf = $this->db->prepare("
            SELECT * FROM members
            WHERE LOWER(COALESCE(email, '')) = LOWER(:email)
               OR (LOWER(first_name) = LOWER(:fn) AND LOWER(last_name) = LOWER(:ln))
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtSelf->execute([
            'email' => (string) ($profile['email'] ?? ''),
            'fn'    => (string) ($profile['first_name'] ?? ''),
            'ln'    => (string) ($profile['last_name'] ?? '')
        ]);
        $selfMember = $stmtSelf->fetch(PDO::FETCH_ASSOC);
        if ($selfMember) {
            $selfMember['practices'] = $loadPracticesForMember((int) $selfMember['id']);
            $profile['self_member'] = $selfMember;
        } else {
            $profile['self_member'] = null;
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Profil récupéré.',
            'data'    => $profile
        ];
    }

    /**
     * Met à jour les informations personnelles et les champs dynamiques du profil (Point 4).
     *
     * @param int                  $userId  Identifiant de l'utilisateur.
     * @param array<string, mixed> $payload Nouvelles données du profil.
     * @return array<string, mixed> Réponse JSend.
     */
    public function updateMyProfile(int $userId, array $payload): array
    {
        $firstName = htmlspecialchars(trim((string) ($payload['first_name'] ?? '')), ENT_QUOTES, 'UTF-8');
        $lastName  = htmlspecialchars(trim((string) ($payload['last_name'] ?? '')), ENT_QUOTES, 'UTF-8');
        $email     = trim((string) ($payload['email'] ?? ''));
        $phone     = htmlspecialchars(trim((string) ($payload['phone'] ?? '')), ENT_QUOTES, 'UTF-8');
        $address   = htmlspecialchars(trim((string) ($payload['address'] ?? '')), ENT_QUOTES, 'UTF-8');

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Format d\'adresse email invalide.',
                'data'    => null
            ];
        }

        $customFields = [];
        if (isset($payload['custom_fields']) && is_array($payload['custom_fields'])) {
            foreach ($payload['custom_fields'] as $field) {
                $label = trim((string) ($field['label'] ?? ''));
                $val   = trim((string) ($field['value'] ?? ''));
                if ($label !== '') {
                    $customFields[] = [
                        'label' => htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
                        'value' => htmlspecialchars($val, ENT_QUOTES, 'UTF-8')
                    ];
                }
            }
        }

        $stmt = $this->db->prepare("
            UPDATE users
            SET first_name = :fname,
                last_name = :lname,
                email = COALESCE(NULLIF(:email, ''), email),
                phone = :phone,
                address = :addr,
                custom_fields = :cfields
            WHERE id = :id
        ");
        $stmt->execute([
            'fname'   => $firstName,
            'lname'   => $lastName,
            'email'   => $email,
            'phone'   => $phone,
            'addr'    => $address,
            'cfields' => json_encode($customFields, JSON_UNESCAPED_UNICODE),
            'id'      => $userId
        ]);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Informations de votre profil enregistrées avec succès.',
            'data'    => [
                'first_name'    => $firstName,
                'last_name'     => $lastName,
                'phone'         => $phone,
                'address'       => $address,
                'custom_fields' => $customFields
            ]
        ];
    }

    /**
     * Active ou désactive la double authentification (Application mobile / Clé manuelle / E-mail).
     *
     * @param int                  $userId  Identifiant de l'utilisateur.
     * @param array<string, mixed> $payload Contient 'enabled' (bool), 'method' ('totp'|'email') et 'totp_code'/'code'.
     * @return array<string, mixed> Réponse normalisée.
     */
    public function toggleTwoFactor(int $userId, array $payload): array
    {
        $enabled = !empty($payload['enabled']);
        $method  = strtolower(trim((string) ($payload['method'] ?? 'totp')));
        if (!in_array($method, ['totp', 'email'], true)) {
            $method = 'totp';
        }
        $code = trim((string) ($payload['totp_code'] ?? $payload['code'] ?? ''));

        if ($enabled && !preg_match('/^\d{6}$/', $code)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Veuillez saisir un code de vérification à 6 chiffres valide.',
                'data'    => null
            ];
        }

        if ($enabled && $method === 'email') {
            $stmtCheck = $this->db->prepare("SELECT otp_code, otp_expires_at FROM users WHERE id = :id LIMIT 1");
            $stmtCheck->execute(['id' => $userId]);
            $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            $storedCode = trim((string) ($row['otp_code'] ?? ''));
            $expStr     = trim((string) ($row['otp_expires_at'] ?? ''));
            $isExpired  = $expStr !== '' && strtotime($expStr) !== false && time() > strtotime($expStr);

            if ($storedCode === '' || $storedCode !== $code || $isExpired) {
                return [
                    'status'  => 'error',
                    'code'    => 422,
                    'message' => 'Le code reçu par e-mail est invalide ou expiré. Veuillez demander un nouveau code.',
                    'data'    => null
                ];
            }
        }

        $secret = ($enabled && $method === 'totp') ? ('KANSO2FA' . strtoupper(bin2hex(random_bytes(4)))) : null;

        $this->executeImmediateTransaction(function () use ($userId, $enabled, $method, $secret): void {
            $stmt = $this->db->prepare("
                UPDATE users
                SET two_factor_enabled = :enabled,
                    two_factor_method = :method,
                    two_factor_secret = COALESCE(:secret, two_factor_secret),
                    otp_code = CASE WHEN :clear_otp = 1 THEN NULL ELSE otp_code END,
                    otp_expires_at = CASE WHEN :clear_otp = 1 THEN NULL ELSE otp_expires_at END
                WHERE id = :id
            ");
            $stmt->execute([
                'enabled'   => $enabled ? 1 : 0,
                'method'    => $enabled ? $method : 'totp',
                'secret'    => $secret,
                'clear_otp' => ($enabled && $method === 'email') ? 1 : 0,
                'id'        => $userId
            ]);

            $methodLabel = $method === 'email' ? 'par e-mail' : 'par application mobile';
            $this->db->prepare("
                INSERT INTO app_logs (user_id, action, target_type, target_id, details)
                VALUES (:uid, :act, 'user', :uid, :det)
            ")->execute([
                'uid' => $userId,
                'act' => $enabled ? '2FA_ENABLED' : '2FA_DISABLED',
                'det' => $enabled ? "Double authentification activée ({$methodLabel})" : 'Double authentification désactivée'
            ]);
        });

        $methodText = $method === 'email' ? 'par e-mail' : 'par application d\'authentification';
        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => $enabled
                ? "Double authentification ({$methodText}) activée sur votre compte !"
                : 'Double authentification désactivée.',
            'data'    => [
                'two_factor_enabled' => $enabled,
                'two_factor_method'  => $enabled ? $method : 'totp'
            ]
        ];
    }

    /**
     * Modifie le mot de passe après vérification de l'ancien et de la confirmation éventuelle.
     *
     * @param int                  $userId  Identifiant de l'utilisateur.
     * @param array<string, mixed> $payload Contient current_password, new_password et optionnellement confirm_password.
     * @return array<string, mixed> Réponse normalisée.
     */
    public function updatePassword(int $userId, array $payload): array
    {
        $currentPassword = (string) ($payload['current_password'] ?? '');
        $newPassword     = (string) ($payload['new_password'] ?? '');
        $hasConfirm      = array_key_exists('confirm_password', $payload);
        $confirmPassword = (string) ($payload['confirm_password'] ?? '');

        if ($currentPassword === '' || strlen($newPassword) < 8) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Le mot de passe actuel est requis et le nouveau mot de passe doit contenir au moins 8 caractères.',
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
                'message' => 'Le nouveau mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre et un caractère spécial.',
                'data'    => null
            ];
        }

        $stmt = $this->db->prepare("SELECT password_hash FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($currentPassword, (string) $hash)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'L\'ancien mot de passe est incorrect.',
                'data'    => null
            ];
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->executeImmediateTransaction(function () use ($newHash, $userId): void {
            $stmtUpdate = $this->db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
            $stmtUpdate->execute([
                'hash' => $newHash,
                'id'   => $userId
            ]);
        });

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Mot de passe mis à jour avec succès.',
            'data'    => null
        ];
    }
}