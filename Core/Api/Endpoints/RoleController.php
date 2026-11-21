<?php

declare(strict_types=1);

namespace Core\Api\Endpoints;

use Core\Api\Database\Database;
use PDO;
use Throwable;

/**
 * Contrôleur gérant la matrice RBAC complète (Point 10) :
 * - Liste des rôles
 * - Création d'un nouveau rôle (Nouvelle colonne dans la matrice)
 * - Modification des permissions ET renommage d'un rôle
 * - Suppression d'un rôle non-système.
 */
final class RoleController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function listRoles(): array
    {
        $stmt = $this->db->query("SELECT id, name, is_system, permissions FROM roles ORDER BY id ASC");
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($roles as &$role) {
            $decoded = is_string($role['permissions'])
                ? json_decode($role['permissions'], true)
                : $role['permissions'];
            $role['permissions'] = is_array($decoded) ? $decoded : [];
            $role['is_system'] = (int) $role['is_system'] === 1;
        }
        unset($role);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Rôles récupérés avec succès.',
            'data'    => $roles
        ];
    }

    public function createRole(array $payload): array
    {
        $name = htmlspecialchars(trim((string) ($payload['name'] ?? '')), ENT_QUOTES, 'UTF-8');
        if ($name === '') {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Le nom du nouveau rôle est obligatoire.',
                'data'    => null
            ];
        }

        $perms = is_array($payload['permissions'] ?? null)
            ? array_values(array_unique(array_map('strval', $payload['permissions'])))
            : ['members.view'];

        try {
            return $this->executeImmediateTransaction(function () use ($name, $perms): array {
                $stmt = $this->db->prepare("
                    INSERT INTO roles (name, is_system, permissions)
                    VALUES (:name, 0, :perms)
                ");
                $stmt->execute([
                    'name'  => $name,
                    'perms' => json_encode($perms)
                ]);

                return [
                    'status'  => 'success',
                    'code'    => 201,
                    'message' => "Colonne \"{$name}\" ajoutée à la matrice.",
                    'data'    => [
                        'id'          => (int) $this->db->lastInsertId(),
                        'name'        => $name,
                        'is_system'   => false,
                        'permissions' => $perms
                    ]
                ];
            });
        } catch (Throwable) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Un rôle portant ce nom existe déjà.',
                'data'    => null
            ];
        }
    }

    public function updatePermissions(int $roleId, array $permissions, ?string $newName = null): array
    {
        $stmt = $this->db->prepare("SELECT id, name, is_system FROM roles WHERE id = :id");
        $stmt->execute(['id' => $roleId]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Rôle introuvable.',
                'data'    => null
            ];
        }

        if ((int) $role['is_system'] === 1) {
            return [
                'status'  => 'error',
                'code'    => 403,
                'message' => 'Impossible de modifier un rôle système verrouillé.',
                'data'    => null
            ];
        }

        $cleanPerms = array_values(array_unique(array_map('strval', $permissions)));
        $finalName = ($newName !== null && trim($newName) !== '')
            ? htmlspecialchars(trim($newName), ENT_QUOTES, 'UTF-8')
            : (string) $role['name'];

        return $this->executeImmediateTransaction(function () use ($roleId, $finalName, $cleanPerms): array {
            $stmtUpdate = $this->db->prepare("
                UPDATE roles
                SET name = :name, permissions = :perms
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                'name'  => $finalName,
                'perms' => json_encode($cleanPerms),
                'id'    => $roleId
            ]);

            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Permissions mises à jour avec succès.',
                'data'    => [
                    'id'          => $roleId,
                    'name'        => $finalName,
                    'permissions' => $cleanPerms
                ]
            ];
        });
    }

    public function deleteRole(int $roleId): array
    {
        $stmt = $this->db->prepare("SELECT id, name, is_system FROM roles WHERE id = :id");
        $stmt->execute(['id' => $roleId]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Rôle introuvable.',
                'data'    => null
            ];
        }

        if ((int) $role['is_system'] === 1) {
            return [
                'status'  => 'error',
                'code'    => 403,
                'message' => 'Interdiction absolue de supprimer un rôle système.',
                'data'    => null
            ];
        }

        return $this->executeImmediateTransaction(function () use ($roleId): array {
            $this->db->prepare("UPDATE users SET role_id = 1 WHERE role_id = :id")->execute(['id' => $roleId]);
            $this->db->prepare("DELETE FROM roles WHERE id = :id")->execute(['id' => $roleId]);

            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Rôle supprimé avec succès.',
                'data'    => ['deleted_id' => $roleId]
            ];
        });
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
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
            } catch (Throwable $e) {
                try {
                    if ($this->db->inTransaction()) {
                        $this->db->exec('ROLLBACK');
                    }
                } catch (Throwable) {
                }
                $isLocked = str_contains(strtolower($e->getMessage()), 'locked')
                    || str_contains(strtolower($e->getMessage()), 'busy');
                if ($isLocked && $attempt < $maxAttempts) {
                    usleep(random_int(10000, 50000));
                    continue;
                }
                throw $e;
            }
        }
        throw new \RuntimeException('Échec de la transaction après plusieurs tentatives de verrouillage.');
    }
}