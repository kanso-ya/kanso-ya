<?php

declare(strict_types=1);

namespace Modules\PresenceApi\Endpoints;

use Core\Api\Database\Database;
use PDO;
use Throwable;

/**
 * Contrôleur REST du module Présences & Suivi Tatami (/api/v1/modules/presence/*).
 * Implémente :
 * - Liste & création idempotente de séances d'appel par discipline
 * - Chargement du roster anonymisé RGPD (aucun e-mail/adresse)
 * - Pointage idempotent (present / absent / excused)
 * - Historique d'assiduité individuel protégé (Bureau/Entraîneur/Adhérent/Tuteur rattaché)
 * - Agrégations statistiques pour l'onglet Assiduité de /stats.
 */
final class PresenceController
{
    private const ALLOWED_STATUSES = ['present', 'absent', 'excused'];

    private PDO $db;

    /**
     * @param PDO|null $db Connexion PDO optionnelle.
     */
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Vérifie si le contexte utilisateur dispose des droits d'appel (stats.view ou Superadmin).
     *
     * @param array<string, mixed> $ctx Contexte transmis par ApiRouter.
     * @return bool
     */
    public function canManageAttendance(array $ctx): bool
    {
        if (!empty($ctx['is_superadmin'])) {
            return true;
        }
        if (empty($ctx['is_authenticated'])) {
            return false;
        }
        $perms = is_array($ctx['permissions'] ?? null) ? $ctx['permissions'] : [];
        return in_array('stats.view', $perms, true);
    }

    /**
     * Liste les disciplines autorisées et les séances d'appel récentes.
     *
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function listSessions(array $ctx): array
    {
        if (!$this->canManageAttendance($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droits d\'appel requis.', 'data' => null];
        }

        $userId = (int) ($ctx['user_id'] ?? 0);
        $role = (string) ($ctx['role'] ?? '');
        $isSuperadmin = !empty($ctx['is_superadmin']);
        $disciplines = $this->getAllowedDisciplines($userId, $role, $isSuperadmin);
        $allowedIds = array_map(static fn (array $d): int => (int) $d['id'], $disciplines);

        $filterDiscId = isset($ctx['query']['discipline_id']) ? (int) $ctx['query']['discipline_id'] : 0;

        if (empty($allowedIds)) {
            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Aucune discipline attribuée.',
                'data'    => ['disciplines' => [], 'sessions' => []],
            ];
        }

        $targetIds = $filterDiscId > 0 && in_array($filterDiscId, $allowedIds, true)
            ? [$filterDiscId]
            : $allowedIds;
        $inClause = implode(',', $targetIds);

        $sql = "
            SELECT s.id, s.discipline_id, d.name AS discipline_name, s.session_date, s.time_slot, s.label, s.created_at,
                   COUNT(r.id) AS total_marks,
                   SUM(CASE WHEN r.status = 'present' THEN 1 ELSE 0 END) AS present_count,
                   SUM(CASE WHEN r.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
                   SUM(CASE WHEN r.status = 'excused' THEN 1 ELSE 0 END) AS excused_count
            FROM mod_presence_sessions s
            INNER JOIN disciplines d ON d.id = s.discipline_id
            LEFT JOIN mod_presence_records r ON r.session_id = s.id
            WHERE s.discipline_id IN ({$inClause})
            GROUP BY s.id, s.discipline_id, d.name, s.session_date, s.time_slot, s.label, s.created_at
            ORDER BY s.session_date DESC, s.id DESC
            LIMIT 50
        ";
        $stmt = $this->db->query($sql);
        $sessions = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Séances de présence récupérées.',
            'data'    => [
                'disciplines' => $disciplines,
                'sessions'    => $sessions,
            ],
        ];
    }

    /**
     * Crée ou réouvre de manière idempotente une feuille d'appel sur (discipline_id, session_date, time_slot).
     *
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function openOrCreateSession(array $ctx): array
    {
        if (!$this->canManageAttendance($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droits d\'appel requis.', 'data' => null];
        }

        $payload = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
        $disciplineId = (int) ($payload['discipline_id'] ?? 0);
        $sessionDate = trim((string) ($payload['session_date'] ?? date('Y-m-d')));
        $timeSlot = trim((string) ($payload['time_slot'] ?? '18:00-19:30'));
        $label = trim((string) ($payload['label'] ?? ''));

        if ($disciplineId <= 0) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Discipline obligatoire.', 'data' => null];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate) !== 1) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Format de date invalide (YYYY-MM-DD attendu).', 'data' => null];
        }
        if ($timeSlot === '') {
            $timeSlot = '18:00-19:30';
        }

        $userId = (int) ($ctx['user_id'] ?? 0);
        $role = (string) ($ctx['role'] ?? '');
        $isSuperadmin = !empty($ctx['is_superadmin']);
        $disciplines = $this->getAllowedDisciplines($userId, $role, $isSuperadmin);
        $allowedIds = array_map(static fn (array $d): int => (int) $d['id'], $disciplines);

        if (!in_array($disciplineId, $allowedIds, true)) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Discipline introuvable ou non autorisée.', 'data' => null];
        }

        $findStmt = $this->db->prepare("
            SELECT s.*, d.name AS discipline_name
            FROM mod_presence_sessions s
            INNER JOIN disciplines d ON d.id = s.discipline_id
            WHERE s.discipline_id = :did AND s.session_date = :sdate AND s.time_slot = :slot
            LIMIT 1
        ");
        $findStmt->execute([
            'did'   => $disciplineId,
            'sdate' => $sessionDate,
            'slot'  => $timeSlot,
        ]);
        $existing = $findStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing !== false) {
            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Feuille d\'appel existante réouverte.',
                'data'    => [
                    'session' => $existing,
                    'created' => false,
                ],
            ];
        }

        $insStmt = $this->db->prepare("
            INSERT INTO mod_presence_sessions (discipline_id, session_date, time_slot, label, created_by)
            VALUES (:did, :sdate, :slot, :label, :uid)
        ");
        $insStmt->execute([
            'did'   => $disciplineId,
            'sdate' => $sessionDate,
            'slot'  => htmlspecialchars($timeSlot, ENT_QUOTES, 'UTF-8'),
            'label' => $label !== '' ? htmlspecialchars($label, ENT_QUOTES, 'UTF-8') : null,
            'uid'   => $userId > 0 ? $userId : null,
        ]);
        $newId = (int) $this->db->lastInsertId();

        $fetchNew = $this->db->prepare("
            SELECT s.*, d.name AS discipline_name
            FROM mod_presence_sessions s
            INNER JOIN disciplines d ON d.id = s.discipline_id
            WHERE s.id = :id
            LIMIT 1
        ");
        $fetchNew->execute(['id' => $newId]);
        $created = $fetchNew->fetch(PDO::FETCH_ASSOC) ?: ['id' => $newId];

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Feuille d\'appel créée avec succès.',
            'data'    => [
                'session' => $created,
                'created' => true,
            ],
        ];
    }

    /**
     * Retourne la liste anonymisée RGPD des pratiquants d'une séance avec leur pointage courant.
     *
     * @param int                  $sessionId Identifiant de la séance.
     * @param array<string, mixed> $ctx       Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function getSessionRoster(int $sessionId, array $ctx): array
    {
        if (!$this->canManageAttendance($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droits d\'appel requis.', 'data' => null];
        }

        $session = $this->findAuthorizedSession($sessionId, $ctx);
        if ($session === null) {
            return ['status' => 'error', 'code' => 404, 'message' => 'Séance introuvable ou non autorisée.', 'data' => null];
        }

        $stmt = $this->db->prepare("
            SELECT DISTINCT m.id AS member_id, m.first_name, m.last_name, m.status AS member_status,
                            r.status AS attendance_status, r.note
            FROM members m
            INNER JOIN member_practices mp ON mp.member_id = m.id
            LEFT JOIN mod_presence_records r ON r.session_id = :sid AND r.member_id = m.id
            WHERE mp.discipline_id = :did AND m.status != 'radiated'
            ORDER BY m.last_name ASC, m.first_name ASC
        ");
        $stmt->execute([
            'sid' => $sessionId,
            'did' => (int) $session['discipline_id'],
        ]);
        $roster = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Feuille d\'appel chargée.',
            'data'    => [
                'session' => $session,
                'roster'  => $roster,
            ],
        ];
    }

    /**
     * Enregistre de manière idempotente le pointage d'une séance (present / absent / excused).
     *
     * @param int                  $sessionId Identifiant de la séance.
     * @param array<string, mixed> $ctx       Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function markAttendance(int $sessionId, array $ctx): array
    {
        if (!$this->canManageAttendance($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droits d\'appel requis.', 'data' => null];
        }

        $session = $this->findAuthorizedSession($sessionId, $ctx);
        if ($session === null) {
            return ['status' => 'error', 'code' => 404, 'message' => 'Séance introuvable ou non autorisée.', 'data' => null];
        }

        $payload = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
        $entries = $payload['entries'] ?? null;
        if (!is_array($entries) || count($entries) === 0) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Aucun pointage transmis.', 'data' => null];
        }

        $validated = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $memberId = (int) ($entry['member_id'] ?? 0);
            $status = strtolower(trim((string) ($entry['status'] ?? '')));
            $note = trim((string) ($entry['note'] ?? ''));

            if ($memberId <= 0 || !in_array($status, self::ALLOWED_STATUSES, true)) {
                return [
                    'status'  => 'error',
                    'code'    => 422,
                    'message' => 'Statut de présence invalide (present, absent ou excused attendu).',
                    'data'    => null,
                ];
            }

            $validated[] = [
                'member_id' => $memberId,
                'status'    => $status,
                'note'      => $note !== '' ? htmlspecialchars(mb_substr($note, 0, 250), ENT_QUOTES, 'UTF-8') : null,
            ];
        }

        $actorId = (int) ($ctx['user_id'] ?? 0);
        $markedBy = $actorId > 0 ? $actorId : null;

        try {
            Database::transaction(function () use ($sessionId, $validated, $markedBy): void {
                $sel = $this->db->prepare('SELECT id FROM mod_presence_records WHERE session_id = :sid AND member_id = :mid LIMIT 1');
                $ins = $this->db->prepare('
                    INSERT INTO mod_presence_records (session_id, member_id, status, note, marked_by, updated_at)
                    VALUES (:sid, :mid, :status, :note, :by, CURRENT_TIMESTAMP)
                ');
                $upd = $this->db->prepare('
                    UPDATE mod_presence_records
                    SET status = :status, note = :note, marked_by = :by, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ');

                foreach ($validated as $row) {
                    $sel->execute(['sid' => $sessionId, 'mid' => $row['member_id']]);
                    $existingId = $sel->fetchColumn();
                    if ($existingId !== false) {
                        $upd->execute([
                            'status' => $row['status'],
                            'note'   => $row['note'],
                            'by'     => $markedBy,
                            'id'     => (int) $existingId,
                        ]);
                    } else {
                        $ins->execute([
                            'sid'    => $sessionId,
                            'mid'    => $row['member_id'],
                            'status' => $row['status'],
                            'note'   => $row['note'],
                            'by'     => $markedBy,
                        ]);
                    }
                }
            });
        } catch (Throwable $e) {
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Erreur lors de la sauvegarde de l\'appel : ' . $e->getMessage(),
                'data'    => null,
            ];
        }

        $sumStmt = $this->db->prepare("
            SELECT
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
                SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) AS excused_count
            FROM mod_presence_records
            WHERE session_id = :sid
        ");
        $sumStmt->execute(['sid' => $sessionId]);
        $sum = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => sprintf('Appel enregistré (%d pratiquant(s) mis à jour).', count($validated)),
            'data'    => [
                'session_id'  => $sessionId,
                'saved_count' => count($validated),
                'summary'     => [
                    'present' => (int) ($sum['present_count'] ?? 0),
                    'absent'  => (int) ($sum['absent_count'] ?? 0),
                    'excused' => (int) ($sum['excused_count'] ?? 0),
                ],
            ],
        ];
    }

    /**
     * Retourne l'historique d'assiduité d'un adhérent avec contrôle strict RGPD (Bureau/Entraîneur/Soi/Tuteur).
     *
     * @param int                  $memberId Identifiant de l'adhérent.
     * @param array<string, mixed> $ctx      Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function getMemberHistory(int $memberId, array $ctx): array
    {
        if (empty($ctx['is_authenticated']) && empty($ctx['is_superadmin'])) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Authentification requise.', 'data' => null];
        }

        $memStmt = $this->db->prepare('SELECT id, first_name, last_name, email FROM members WHERE id = :id LIMIT 1');
        $memStmt->execute(['id' => $memberId]);
        $member = $memStmt->fetch(PDO::FETCH_ASSOC);

        if ($member === false) {
            return ['status' => 'error', 'code' => 404, 'message' => 'Adhérent introuvable.', 'data' => null];
        }

        if (!$this->canViewMemberHistory($memberId, (string) ($member['email'] ?? ''), $ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé à l\'historique d\'assiduité de cet adhérent.', 'data' => null];
        }

        $recStmt = $this->db->prepare("
            SELECT r.id, r.session_id, s.session_date, s.time_slot, s.label,
                   d.name AS discipline_name, r.status, r.note, r.updated_at
            FROM mod_presence_records r
            INNER JOIN mod_presence_sessions s ON s.id = r.session_id
            INNER JOIN disciplines d ON d.id = s.discipline_id
            WHERE r.member_id = :mid
            ORDER BY s.session_date DESC, r.id DESC
            LIMIT 100
        ");
        $recStmt->execute(['mid' => $memberId]);
        $records = $recStmt->fetchAll(PDO::FETCH_ASSOC);

        $total = count($records);
        $present = 0;
        $absent = 0;
        $excused = 0;
        foreach ($records as $rec) {
            if (($rec['status'] ?? '') === 'present') {
                $present++;
            } elseif (($rec['status'] ?? '') === 'absent') {
                $absent++;
            } elseif (($rec['status'] ?? '') === 'excused') {
                $excused++;
            }
        }
        $rate = $total > 0 ? (int) round(($present / $total) * 100) : 0;

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Historique d\'assiduité récupéré.',
            'data'    => [
                'member' => [
                    'id'         => (int) $member['id'],
                    'first_name' => (string) $member['first_name'],
                    'last_name'  => (string) $member['last_name'],
                ],
                'summary' => [
                    'total'           => $total,
                    'present'         => $present,
                    'absent'          => $absent,
                    'excused'         => $excused,
                    'attendance_rate' => $rate,
                ],
                'records' => $records,
            ],
        ];
    }

    /**
     * Endpoint REST des statistiques d'assiduité (/api/v1/modules/presence/stats).
     *
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function getAttendanceStats(array $ctx): array
    {
        if (!$this->canManageAttendance($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droits d\'appel requis.', 'data' => null];
        }

        $summary = $this->buildStatsSummary(
            (int) ($ctx['user_id'] ?? 0),
            (string) ($ctx['role'] ?? ''),
            !empty($ctx['is_superadmin'])
        );

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Statistiques d\'assiduité récupérées.',
            'data'    => $summary,
        ];
    }

    /**
     * Calcule les statistiques agrégées d'assiduité par discipline (réutilisé par l'API et le hook ui_tabs de /stats).
     *
     * @param int    $userId       Identifiant de l'utilisateur connecté.
     * @param string $role         Rôle de l'utilisateur.
     * @param bool   $isSuperadmin Indique s'il s'agit du Superadmin.
     * @return array{
     *     total_sessions: int,
     *     total_marks: int,
     *     global_rate: int,
     *     by_discipline: array<int, array{
     *         discipline_id: int,
     *         discipline_name: string,
     *         sessions_count: int,
     *         total_marks: int,
     *         present_count: int,
     *         absent_count: int,
     *         excused_count: int,
     *         attendance_rate: int
     *     }>
     * }
     */
    public function buildStatsSummary(int $userId, string $role, bool $isSuperadmin): array
    {
        $disciplines = $this->getAllowedDisciplines($userId, $role, $isSuperadmin);
        $byDiscipline = [];
        $totalSessions = 0;
        $totalMarks = 0;
        $totalPresent = 0;

        $sessStmt = $this->db->prepare('SELECT COUNT(*) FROM mod_presence_sessions WHERE discipline_id = :did');
        $recStmt = $this->db->prepare("
            SELECT COUNT(r.id) AS total_marks,
                   SUM(CASE WHEN r.status = 'present' THEN 1 ELSE 0 END) AS present_count,
                   SUM(CASE WHEN r.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
                   SUM(CASE WHEN r.status = 'excused' THEN 1 ELSE 0 END) AS excused_count
            FROM mod_presence_records r
            INNER JOIN mod_presence_sessions s ON s.id = r.session_id
            WHERE s.discipline_id = :did
        ");

        foreach ($disciplines as $d) {
            $did = (int) $d['id'];
            $sessStmt->execute(['did' => $did]);
            $sCount = (int) $sessStmt->fetchColumn();

            $recStmt->execute(['did' => $did]);
            $row = $recStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $marks = (int) ($row['total_marks'] ?? 0);
            $pres = (int) ($row['present_count'] ?? 0);
            $abs = (int) ($row['absent_count'] ?? 0);
            $exc = (int) ($row['excused_count'] ?? 0);
            $rate = $marks > 0 ? (int) round(($pres / $marks) * 100) : 0;

            $totalSessions += $sCount;
            $totalMarks += $marks;
            $totalPresent += $pres;

            $byDiscipline[] = [
                'discipline_id'   => $did,
                'discipline_name' => (string) $d['name'],
                'sessions_count'  => $sCount,
                'total_marks'     => $marks,
                'present_count'   => $pres,
                'absent_count'    => $abs,
                'excused_count'   => $exc,
                'attendance_rate' => $rate,
            ];
        }

        return [
            'total_sessions' => $totalSessions,
            'total_marks'    => $totalMarks,
            'global_rate'    => $totalMarks > 0 ? (int) round(($totalPresent / $totalMarks) * 100) : 0,
            'by_discipline'  => $byDiscipline,
        ];
    }

    /**
     * Retourne les disciplines accessibles par l'utilisateur (toutes pour Bureau/Superadmin, ses cours pour Entraîneur).
     *
     * @param int    $userId       Identifiant utilisateur.
     * @param string $role         Rôle courant.
     * @param bool   $isSuperadmin Superadmin ou non.
     * @return array<int, array<string, mixed>>
     */
    public function getAllowedDisciplines(int $userId, string $role, bool $isSuperadmin): array
    {
        if (!$isSuperadmin && $role === 'Entraîneur') {
            $stmt = $this->db->prepare('SELECT id, name FROM disciplines WHERE teacher_user_id = :uid OR teacher_user_id IS NULL ORDER BY id ASC');
            $stmt->execute(['uid' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $this->db->query('SELECT id, name FROM disciplines ORDER BY id ASC');
        return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * Recherche une séance en vérifiant que l'utilisateur a accès à sa discipline.
     *
     * @param int                  $sessionId Identifiant de la séance.
     * @param array<string, mixed> $ctx       Contexte de requête.
     * @return array<string, mixed>|null
     */
    private function findAuthorizedSession(int $sessionId, array $ctx): ?array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, d.name AS discipline_name
            FROM mod_presence_sessions s
            INNER JOIN disciplines d ON d.id = s.discipline_id
            WHERE s.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($session === false) {
            return null;
        }

        $disciplines = $this->getAllowedDisciplines(
            (int) ($ctx['user_id'] ?? 0),
            (string) ($ctx['role'] ?? ''),
            !empty($ctx['is_superadmin'])
        );
        $allowedIds = array_map(static fn (array $d): int => (int) $d['id'], $disciplines);

        return in_array((int) $session['discipline_id'], $allowedIds, true) ? $session : null;
    }

    /**
     * Vérifie le droit de consultation d'historique d'assiduité individuel (RGPD).
     *
     * @param int                  $memberId    Identifiant adhérent.
     * @param string               $memberEmail E-mail de l'adhérent (pour correspondance compte propre).
     * @param array<string, mixed> $ctx         Contexte de requête.
     * @return bool
     */
    private function canViewMemberHistory(int $memberId, string $memberEmail, array $ctx): bool
    {
        if ($this->canManageAttendance($ctx)) {
            return true;
        }

        $userId = (int) ($ctx['user_id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        // 1. Tuteur légal rattaché dans member_tutor
        $tutStmt = $this->db->prepare('SELECT 1 FROM member_tutor WHERE member_id = :mid AND tutor_id = :uid LIMIT 1');
        $tutStmt->execute(['mid' => $memberId, 'uid' => $userId]);
        if ($tutStmt->fetchColumn() !== false) {
            return true;
        }

        // 2. Adhérent majeur consultant sa propre fiche (correspondance e-mail compte)
        if ($memberEmail !== '') {
            $uStmt = $this->db->prepare('SELECT email FROM users WHERE id = :uid LIMIT 1');
            $uStmt->execute(['uid' => $userId]);
            $uEmail = (string) ($uStmt->fetchColumn() ?: '');
            if ($uEmail !== '' && strtolower($uEmail) === strtolower($memberEmail)) {
                return true;
            }
        }

        return false;
    }
}
