<?php

declare(strict_types=1);

namespace Core\Api\Endpoints;

use Core\Api\Database\Database;
use PDO;
use Throwable;

/**
 * Contrôleur gérant les Disciplines, les Grades (Catégories d'âge : Poussin, Benjamin, Minime)
 * et les Niveaux (Couleurs de ceintures, gants...) avec comptage des effectifs inscrits (Point 11).
 */
final class DisciplineController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Liste toutes les disciplines avec leurs grades, leurs niveaux techniques,
     * et le nombre d'adhérents inscrits (badge numéroté - Point 11).
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function listDisciplines(): array
    {
        $stmt = $this->db->query("SELECT id, name, teacher_user_id FROM disciplines ORDER BY id ASC");
        $disciplines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($disciplines as &$disc) {
            $did = (int) $disc['id'];

            $stmtCount = $this->db->prepare("SELECT COUNT(DISTINCT member_id) FROM member_practices WHERE discipline_id = :did");
            $stmtCount->execute(['did' => $did]);
            $disc['member_count'] = (int) $stmtCount->fetchColumn();

            $stmtLevels = $this->db->prepare("
                SELECT l.id, l.level_type, l.name, l.color_hex, l.age_min, l.age_max,
                       (SELECT COUNT(DISTINCT mp.member_id)
                        FROM member_practices mp
                        WHERE mp.discipline_id = l.discipline_id
                          AND (mp.grade_id = l.id OR mp.level_id = l.id)) AS member_count
                FROM levels l
                WHERE l.discipline_id = :did
                ORDER BY l.level_type ASC, l.id ASC
            ");
            $stmtLevels->execute(['did' => $did]);
            $allItems = $stmtLevels->fetchAll(PDO::FETCH_ASSOC);

            $disc['levels']  = $allItems;
            $disc['grades']  = array_values(array_filter($allItems, static fn($i) => ($i['level_type'] ?? 'grade') === 'grade'));
            $disc['niveaux'] = array_values(array_filter($allItems, static fn($i) => ($i['level_type'] ?? 'grade') === 'niveau'));
        }
        unset($disc);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Disciplines, grades, niveaux et effectifs récupérés.',
            'data'    => $disciplines
        ];
    }

    /**
     * Crée une discipline avec ses Grades (âge) et Niveaux (ceinture/gant).
     *
     * @param array<string, mixed> $payload Données de la discipline.
     * @return array<string, mixed> Réponse JSend.
     */
    public function createDiscipline(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Le nom de la discipline est obligatoire.',
                'data'    => null
            ];
        }

        $levels = is_array($payload['levels'] ?? null) ? $payload['levels'] : [];
        foreach ($levels as $lvl) {
            $ageMin = isset($lvl['age_min']) && $lvl['age_min'] !== '' ? (int) $lvl['age_min'] : null;
            $ageMax = isset($lvl['age_max']) && $lvl['age_max'] !== '' ? (int) $lvl['age_max'] : null;
            if ($ageMin !== null && $ageMax !== null && $ageMin > $ageMax) {
                return [
                    'status'  => 'error',
                    'code'    => 422,
                    'message' => 'Incohérence : l\'âge minimum ne peut pas être supérieur à l\'âge maximum.',
                    'data'    => null
                ];
            }
        }

        try {
            $this->db->exec('BEGIN IMMEDIATE');

            $stmtCheck = $this->db->prepare("SELECT id FROM disciplines WHERE LOWER(name) = LOWER(:name)");
            $stmtCheck->execute(['name' => $name]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                $disciplineId = (int) $existingId;
            } else {
                $stmt = $this->db->prepare("INSERT INTO disciplines (name) VALUES (:name)");
                $stmt->execute(['name' => htmlspecialchars($name, ENT_QUOTES, 'UTF-8')]);
                $disciplineId = (int) $this->db->lastInsertId();
            }

            $insertedLevels = $this->insertLevelsList($disciplineId, $levels);

            $this->db->exec('COMMIT');

            return [
                'status'  => 'success',
                'code'    => 201,
                'message' => 'Discipline créée avec succès.',
                'data'    => [
                    'id'           => $disciplineId,
                    'name'         => $name,
                    'member_count' => 0,
                    'levels'       => $insertedLevels
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
                'message' => 'Erreur lors de la création : ' . $e->getMessage(),
                'data'    => null
            ];
        }
    }

    /**
     * Met à jour une discipline et ses Grades / Niveaux.
     *
     * @param int                  $disciplineId Identifiant de la discipline.
     * @param array<string, mixed> $payload      Données mises à jour.
     * @return array<string, mixed> Réponse JSend.
     */
    public function updateDiscipline(int $disciplineId, array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Le nom de la discipline est obligatoire.',
                'data'    => null
            ];
        }

        $levels = is_array($payload['levels'] ?? null) ? $payload['levels'] : [];
        foreach ($levels as $lvl) {
            $ageMin = isset($lvl['age_min']) && $lvl['age_min'] !== '' ? (int) $lvl['age_min'] : null;
            $ageMax = isset($lvl['age_max']) && $lvl['age_max'] !== '' ? (int) $lvl['age_max'] : null;
            if ($ageMin !== null && $ageMax !== null && $ageMin > $ageMax) {
                return [
                    'status'  => 'error',
                    'code'    => 422,
                    'message' => 'Incohérence : l\'âge minimum ne peut pas être supérieur à l\'âge maximum.',
                    'data'    => null
                ];
            }
        }

        try {
            $this->db->exec('BEGIN IMMEDIATE');

            $stmt = $this->db->prepare("UPDATE disciplines SET name = :name WHERE id = :id");
            $stmt->execute([
                'name' => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                'id'   => $disciplineId
            ]);

            $this->db->prepare("DELETE FROM levels WHERE discipline_id = :did")->execute(['did' => $disciplineId]);
            $updatedLevels = $this->insertLevelsList($disciplineId, $levels);

            $this->db->exec('COMMIT');

            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Discipline, grades et niveaux mis à jour avec succès.',
                'data'    => [
                    'id'     => $disciplineId,
                    'name'   => $name,
                    'levels' => $updatedLevels
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
                'message' => 'Erreur lors de la mise à jour : ' . $e->getMessage(),
                'data'    => null
            ];
        }
    }

    /**
     * Insère la liste des grades et niveaux d'une discipline.
     *
     * @param int                             $disciplineId ID de la discipline.
     * @param array<int, array<string, mixed>> $levels       Liste des grades/niveaux.
     * @return array<int, array<string, mixed>>
     */
    private function insertLevelsList(int $disciplineId, array $levels): array
    {
        $inserted = [];
        foreach ($levels as $lvl) {
            $lvlName = trim((string) ($lvl['name'] ?? ''));
            if ($lvlName === '') {
                continue;
            }
            $type   = in_array($lvl['level_type'] ?? 'grade', ['grade', 'niveau'], true) ? (string) $lvl['level_type'] : 'grade';
            $color  = trim((string) ($lvl['color_hex'] ?? '#10b981'));
            $ageMin = isset($lvl['age_min']) && $lvl['age_min'] !== '' ? (int) $lvl['age_min'] : null;
            $ageMax = isset($lvl['age_max']) && $lvl['age_max'] !== '' ? (int) $lvl['age_max'] : null;

            $stmtLvl = $this->db->prepare("
                INSERT INTO levels (discipline_id, level_type, name, color_hex, age_min, age_max)
                VALUES (:did, :type, :name, :color, :min, :max)
            ");
            $stmtLvl->execute([
                'did'   => $disciplineId,
                'type'  => $type,
                'name'  => htmlspecialchars($lvlName, ENT_QUOTES, 'UTF-8'),
                'color' => $color,
                'min'   => $ageMin,
                'max'   => $ageMax
            ]);

            $inserted[] = [
                'id'           => (int) $this->db->lastInsertId(),
                'level_type'   => $type,
                'name'         => $lvlName,
                'color_hex'    => $color,
                'age_min'      => $ageMin,
                'age_max'      => $ageMax,
                'member_count' => 0
            ];
        }
        return $inserted;
    }
}