<?php

declare(strict_types=1);

namespace Core\Api\Middleware;

use Core\Api\Database\Database;
use PDO;

/**
 * Middleware gérant l'authentification basée sur les sessions et le contrôle des accès (RBAC).
 * Exige les permissions strictes définies dans la matrice des droits[cite: 5].
 */
final readonly class AuthMiddleware
{
    public function __construct(private PDO $db) {}

    /**
     * Vérifie si la requête provient d'un Superadmin authentifié.
     * Arrête l'exécution et renvoie une erreur 401/403 en cas d'échec[cite: 1, 7].
     * 
     * @return void
     */
    public function requireSuperadmin(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['sys_admin_id'])) {
            $this->sendForbiddenResponse('Accès Superadmin requis. Identifiants invalides.');
        }
    }

    /**
     * Vérifie si l'utilisateur possède une permission spécifique via la table roles.
     * 
     * @param string $requiredPermission La permission requise (ex: 'members.view').
     * @return void
     */
    public function requirePermission(string $requiredPermission): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id'])) {
            $this->sendForbiddenResponse('Session expirée ou invalide. Veuillez vous connecter.', 401);
        }

        $stmt = $this->db->prepare(<<<SQL
            SELECT r.permissions 
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            WHERE u.id = :user_id AND u.is_active = 1
        SQL);
        
        $stmt->execute(['user_id' => $_SESSION['user_id']]);
        $role = $stmt->fetch();

        if (!$role) {
            $this->sendForbiddenResponse('Compte inactif ou rôle introuvable.', 403);
        }

        $permissions = json_decode($role['permissions'], true, 512, JSON_THROW_ON_ERROR);

        if (!in_array($requiredPermission, $permissions, true)) {
            $this->sendForbiddenResponse("Privilèges insuffisants. Requiert: {$requiredPermission}", 403);
        }
    }

    /**
     * Formate le rejet selon l'enveloppe JSend standardisée (HTTP 200 réseau).
     */
    private function sendForbiddenResponse(string $message, int $logicalCode = 403): never
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => 'error',
            'code'    => $logicalCode,
            'message' => $message,
            'data'    => null
        ]);
        exit;
    }
}