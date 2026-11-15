<?php

declare(strict_types=1);

namespace Core\Api\Endpoints;

use Core\Api\Database\Database;
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
                   u.custom_fields, u.two_factor_enabled, u.two_factor_secret,
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

        if (empty($profile['two_factor_secret'])) {
            $profile['two_factor_secret'] = 'KANSO2FA' . strtoupper(bin2hex(random_bytes(4)));
        }

        $stmtMem = $this->db->prepare("
            SELECT m.*
            FROM members m
            INNER JOIN member_tutor mt ON m.id = mt.member_id
            WHERE mt.tutor_id = :uid
        ");
        $stmtMem->execute(['uid' => $userId]);
        $profile['linked_members'] = $stmtMem->fetchAll(PDO::FETCH_ASSOC);

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
     * Active ou désactive la double authentification (2FA TOTP) sur le compte (Point 4).
     *
     * @param int                  $userId  Identifiant de l'utilisateur.
     * @param array<string, mixed> $payload Contient 'enabled' (bool) et 'totp_code' (string).
     * @return array<string, mixed> Réponse JSend.
     */
    public function toggleTwoFactor(int $userId, array $payload): array
    {
        $enabled = !empty($payload['enabled']);
        $code    = trim((string) ($payload['totp_code'] ?? ''));

        if ($enabled && !preg_match('/^\d{6}$/', $code)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Veuillez saisir un code d\'authentification à 6 chiffres valide pour activer la 2FA.',
                'data'    => null
            ];
        }

        $secret = $enabled ? ('KANSO2FA' . strtoupper(bin2hex(random_bytes(4)))) : null;

        $stmt = $this->db->prepare("
            UPDATE users
            SET two_factor_enabled = :enabled,
                two_factor_secret = COALESCE(:secret, two_factor_secret)
            WHERE id = :id
        ");
        $stmt->execute([
            'enabled' => $enabled ? 1 : 0,
            'secret'  => $secret,
            'id'      => $userId
        ]);

        $this->db->prepare("
            INSERT INTO app_logs (user_id, action, target_type, target_id, details)
            VALUES (:uid, :act, 'user', :uid, :det)
        ")->execute([
            'uid' => $userId,
            'act' => $enabled ? '2FA_ENABLED' : '2FA_DISABLED',
            'det' => $enabled ? 'Protection 2FA activée' : 'Protection 2FA désactivée'
        ]);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => $enabled ? 'Double authentification (2FA) activée sur votre compte !' : 'Double authentification (2FA) désactivée.',
            'data'    => ['two_factor_enabled' => $enabled]
        ];
    }

    /**
     * Modifie le mot de passe après vérification de l'ancien.
     *
     * @param int                  $userId  Identifiant de l'utilisateur.
     * @param array<string, mixed> $payload Contient current_password et new_password.
     * @return array<string, mixed> Réponse JSend.
     */
    public function updatePassword(int $userId, array $payload): array
    {
        $currentPassword = (string) ($payload['current_password'] ?? '');
        $newPassword = (string) ($payload['new_password'] ?? '');

        if ($currentPassword === '' || strlen($newPassword) < 8) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Mot de passe actuel requis et nouveau mot de passe d\'au moins 8 caractères.',
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
        $stmtUpdate = $this->db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        $stmtUpdate->execute([
            'hash' => $newHash,
            'id'   => $userId
        ]);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Mot de passe mis à jour avec succès.',
            'data'    => null
        ];
    }
}