<?php

declare(strict_types=1);

namespace Modules\EvenementsApi\Endpoints;

use Core\Api\Database\Database;
use PDO;
use Throwable;

/**
 * Contrôleur REST du module Événements, Stages & Compétitions (/api/v1/modules/evenements/*).
 * Implémente :
 * - Liste du calendrier (public sur published, complet pour gestionnaires)
 * - Création / édition / clôture d'événements (gestionnaires)
 * - Inscription / désinscription en 1 clic avec contrôle de jauge & rattachement familial RGPD
 * - Consultation & export CSV de la liste des participants (gestionnaires).
 */
final class EvenementsController
{
    private const ALLOWED_TYPES = ['stage', 'grade', 'competition', 'autre'];
    private const ALLOWED_STATUSES = ['draft', 'published', 'closed'];

    private PDO $db;

    /**
     * @param PDO|null $db Connexion PDO optionnelle.
     */
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Indique si l'utilisateur courant peut gérer les événements et voir la liste des participants.
     *
     * @param array<string, mixed> $ctx Contexte transmis par ApiRouter.
     * @return bool
     */
    public function canManageEvents(array $ctx): bool
    {
        if (!empty($ctx['is_superadmin'])) {
            return true;
        }
        if (empty($ctx['is_authenticated'])) {
            return false;
        }
        $perms = is_array($ctx['permissions'] ?? null) ? $ctx['permissions'] : [];
        return in_array('stats.view', $perms, true) || in_array('articles.write', $perms, true);
    }

    /**
     * Liste les événements du calendrier, les disciplines et les membres éligibles à l'inscription.
     *
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function listEvents(array $ctx): array
    {
        $canManage = $this->canManageEvents($ctx);
        $isAuth = !empty($ctx['is_authenticated']) || !empty($ctx['is_superadmin']);
        $userId = (int) ($ctx['user_id'] ?? 0);

        $where = $canManage ? '1 = 1' : "e.status IN ('published', 'closed')";
        $sql = "
            SELECT e.id, e.title, e.event_type, e.discipline_id, d.name AS discipline_name,
                   e.event_date, e.location, e.description, e.max_capacity, e.status, e.created_at,
                   COUNT(r.id) AS registered_count
            FROM mod_evenements_events e
            LEFT JOIN disciplines d ON d.id = e.discipline_id
            LEFT JOIN mod_evenements_registrations r ON r.event_id = e.id
            WHERE {$where}
            GROUP BY e.id, e.title, e.event_type, e.discipline_id, d.name,
                     e.event_date, e.location, e.description, e.max_capacity, e.status, e.created_at
            ORDER BY e.event_date ASC, e.id DESC
        ";
        $stmt = $this->db->query($sql);
        $events = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $eligibleMembers = $isAuth ? $this->getEligibleMembersForUser($userId, $canManage) : [];
        $eligibleIds = array_map(static fn (array $m): int => (int) $m['id'], $eligibleMembers);

        // Récupère les inscriptions déjà actives pour les membres éligibles
        $regMap = [];
        if (!empty($eligibleIds) && !empty($events)) {
            $inMembers = implode(',', $eligibleIds);
            $rStmt = $this->db->query("SELECT event_id, member_id FROM mod_evenements_registrations WHERE member_id IN ({$inMembers})");
            $rows = $rStmt !== false ? $rStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $row) {
                $eid = (int) $row['event_id'];
                $regMap[$eid][] = (int) $row['member_id'];
            }
        }

        $enrichedEvents = [];
        foreach ($events as $ev) {
            $eid = (int) $ev['id'];
            $cap = $ev['max_capacity'] !== null ? (int) $ev['max_capacity'] : null;
            $regCount = (int) ($ev['registered_count'] ?? 0);
            $remaining = ($cap !== null && $cap > 0) ? max(0, $cap - $regCount) : null;
            $ev['id'] = $eid;
            $ev['max_capacity'] = $cap;
            $ev['registered_count'] = $regCount;
            $ev['remaining_spots'] = $remaining;
            $ev['is_full'] = ($cap !== null && $cap > 0 && $regCount >= $cap);
            $ev['registered_member_ids'] = $regMap[$eid] ?? [];
            $enrichedEvents[] = $ev;
        }

        $discStmt = $this->db->query('SELECT id, name FROM disciplines ORDER BY id ASC');
        $disciplines = $discStmt !== false ? $discStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Calendrier des événements récupéré.',
            'data'    => [
                'can_manage'       => $canManage,
                'is_authenticated' => $isAuth,
                'disciplines'      => $disciplines,
                'eligible_members' => $eligibleMembers,
                'events'           => $enrichedEvents,
            ],
        ];
    }

    /**
     * Crée un nouvel événement (stage, passage de grade, compétition).
     *
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function createEvent(array $ctx): array
    {
        if (!$this->canManageEvents($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droits de gestion requis.', 'data' => null];
        }

        $payload = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
        $title = trim((string) ($payload['title'] ?? ''));
        $eventType = strtolower(trim((string) ($payload['event_type'] ?? 'stage')));
        $eventDate = trim((string) ($payload['event_date'] ?? ''));
        $location = trim((string) ($payload['location'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $status = strtolower(trim((string) ($payload['status'] ?? 'published')));
        $disciplineId = !empty($payload['discipline_id']) ? (int) $payload['discipline_id'] : null;

        $rawCap = $payload['max_capacity'] ?? null;
        $maxCapacity = null;
        if ($rawCap !== null && $rawCap !== '') {
            $capInt = filter_var($rawCap, FILTER_VALIDATE_INT);
            if ($capInt === false || $capInt < 0) {
                return ['status' => 'error', 'code' => 422, 'message' => 'Jauge maximale invalide (entier positif ou vide attendu).', 'data' => null];
            }
            $maxCapacity = $capInt > 0 ? $capInt : null;
        }

        if ($title === '') {
            return ['status' => 'error', 'code' => 422, 'message' => 'Le titre de l\'événement est obligatoire.', 'data' => null];
        }
        if (!in_array($eventType, self::ALLOWED_TYPES, true)) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Type d\'événement invalide (stage, grade, competition ou autre).', 'data' => null];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate) !== 1) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Format de date invalide (YYYY-MM-DD attendu).', 'data' => null];
        }
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Statut invalide (draft, published ou closed).', 'data' => null];
        }

        $actorId = (int) ($ctx['user_id'] ?? 0);
        $stmt = $this->db->prepare("
            INSERT INTO mod_evenements_events (title, event_type, discipline_id, event_date, location, description, max_capacity, status, created_by)
            VALUES (:title, :type, :did, :edate, :loc, :desc, :cap, :status, :uid)
        ");
        $stmt->execute([
            'title'  => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            'type'   => $eventType,
            'did'    => $disciplineId,
            'edate'  => $eventDate,
            'loc'    => $location !== '' ? htmlspecialchars($location, ENT_QUOTES, 'UTF-8') : null,
            'desc'   => $description !== '' ? htmlspecialchars($description, ENT_QUOTES, 'UTF-8') : null,
            'cap'    => $maxCapacity,
            'status' => $status,
            'uid'    => $actorId > 0 ? $actorId : null,
        ]);
        $newId = (int) $this->db->lastInsertId();

        return [
            'status'  => 'success',
            'code'    => 201,
            'message' => 'Événement créé avec succès.',
            'data'    => ['id' => $newId, 'status' => $status],
        ];
    }

    /**
     * Met à jour un événement existant (ex. publication ou clôture).
     *
     * @param int                  $eventId Identifiant de l'événement.
     * @param array<string, mixed> $ctx     Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function updateEvent(int $eventId, array $ctx): array
    {
        if (!$this->canManageEvents($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droits de gestion requis.', 'data' => null];
        }

        $sel = $this->db->prepare('SELECT * FROM mod_evenements_events WHERE id = :id LIMIT 1');
        $sel->execute(['id' => $eventId]);
        $existing = $sel->fetch(PDO::FETCH_ASSOC);
        if ($existing === false) {
            return ['status' => 'error', 'code' => 404, 'message' => 'Événement introuvable.', 'data' => null];
        }

        $payload = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
        $status = isset($payload['status']) ? strtolower(trim((string) $payload['status'])) : (string) $existing['status'];
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Statut invalide (draft, published ou closed).', 'data' => null];
        }

        $title = isset($payload['title']) && trim((string) $payload['title']) !== ''
            ? htmlspecialchars(trim((string) $payload['title']), ENT_QUOTES, 'UTF-8')
            : (string) $existing['title'];
        $location = array_key_exists('location', $payload)
            ? (trim((string) $payload['location']) !== '' ? htmlspecialchars(trim((string) $payload['location']), ENT_QUOTES, 'UTF-8') : null)
            : $existing['location'];

        $upd = $this->db->prepare('UPDATE mod_evenements_events SET title = :title, location = :loc, status = :status WHERE id = :id');
        $upd->execute([
            'title'  => $title,
            'loc'    => $location,
            'status' => $status,
            'id'     => $eventId,
        ]);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Événement mis à jour.',
            'data'    => ['id' => $eventId, 'status' => $status],
        ];
    }

    /**
     * Inscrit un adhérent en 1 clic sur un événement publié (contrôle famille/RGPD et jauge maximale).
     *
     * @param int                  $eventId Identifiant de l'événement.
     * @param array<string, mixed> $ctx     Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function registerMember(int $eventId, array $ctx): array
    {
        if (empty($ctx['is_authenticated']) && empty($ctx['is_superadmin'])) {
            return ['status' => 'error', 'code' => 401, 'message' => 'Veuillez vous connecter pour vous inscrire à un événement.', 'data' => null];
        }

        $payload = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
        $memberId = (int) ($payload['member_id'] ?? 0);
        if ($memberId <= 0) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Adhérent à inscrire obligatoire.', 'data' => null];
        }

        $canManage = $this->canManageEvents($ctx);
        $userId = (int) ($ctx['user_id'] ?? 0);
        if (!$this->canUserRegisterMember($userId, $memberId, $canManage)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : vous ne pouvez inscrire que votre propre fiche ou un membre rattaché.', 'data' => null];
        }

        try {
            return Database::transaction(function () use ($eventId, $memberId, $userId): array {
                $evStmt = $this->db->prepare('SELECT * FROM mod_evenements_events WHERE id = :id LIMIT 1');
                $evStmt->execute(['id' => $eventId]);
                $event = $evStmt->fetch(PDO::FETCH_ASSOC);

                if ($event === false) {
                    return ['status' => 'error', 'code' => 404, 'message' => 'Événement introuvable.', 'data' => null];
                }
                if ((string) $event['status'] !== 'published') {
                    return ['status' => 'error', 'code' => 409, 'message' => 'Les inscriptions ne sont pas ouvertes pour cet événement.', 'data' => null];
                }

                // Déjà inscrit ? (idempotent)
                $existsStmt = $this->db->prepare('SELECT id FROM mod_evenements_registrations WHERE event_id = :eid AND member_id = :mid LIMIT 1');
                $existsStmt->execute(['eid' => $eventId, 'mid' => $memberId]);
                if ($existsStmt->fetchColumn() !== false) {
                    return [
                        'status'  => 'success',
                        'code'    => 200,
                        'message' => 'Adhérent déjà inscrit à cet événement.',
                        'data'    => ['event_id' => $eventId, 'member_id' => $memberId, 'already_registered' => true],
                    ];
                }

                // Contrôle de jauge maximale
                $maxCap = $event['max_capacity'] !== null ? (int) $event['max_capacity'] : null;
                if ($maxCap !== null && $maxCap > 0) {
                    $cntStmt = $this->db->prepare('SELECT COUNT(*) FROM mod_evenements_registrations WHERE event_id = :eid');
                    $cntStmt->execute(['eid' => $eventId]);
                    $currentCount = (int) $cntStmt->fetchColumn();
                    if ($currentCount >= $maxCap) {
                        return [
                            'status'  => 'error',
                            'code'    => 409,
                            'message' => 'Événement complet : la jauge maximale est atteinte.',
                            'data'    => ['max_capacity' => $maxCap, 'registered_count' => $currentCount],
                        ];
                    }
                }

                $ins = $this->db->prepare('
                    INSERT INTO mod_evenements_registrations (event_id, member_id, registered_by)
                    VALUES (:eid, :mid, :uid)
                ');
                $ins->execute([
                    'eid' => $eventId,
                    'mid' => $memberId,
                    'uid' => $userId > 0 ? $userId : null,
                ]);

                return [
                    'status'  => 'success',
                    'code'    => 200,
                    'message' => 'Inscription enregistrée avec succès.',
                    'data'    => ['event_id' => $eventId, 'member_id' => $memberId, 'already_registered' => false],
                ];
            });
        } catch (Throwable $e) {
            return ['status' => 'error', 'code' => 500, 'message' => 'Erreur lors de l\'inscription : ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Désinscrit un adhérent d'un événement (titulaire, tuteur légal ou gestionnaire).
     *
     * @param int                  $eventId  Identifiant de l'événement.
     * @param int                  $memberId Identifiant de l'adhérent.
     * @param array<string, mixed> $ctx      Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function unregisterMember(int $eventId, int $memberId, array $ctx): array
    {
        if (empty($ctx['is_authenticated']) && empty($ctx['is_superadmin'])) {
            return ['status' => 'error', 'code' => 401, 'message' => 'Authentification requise.', 'data' => null];
        }

        $canManage = $this->canManageEvents($ctx);
        $userId = (int) ($ctx['user_id'] ?? 0);
        if (!$this->canUserRegisterMember($userId, $memberId, $canManage)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé pour désinscrire cet adhérent.', 'data' => null];
        }

        $del = $this->db->prepare('DELETE FROM mod_evenements_registrations WHERE event_id = :eid AND member_id = :mid');
        $del->execute(['eid' => $eventId, 'mid' => $memberId]);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Désinscription effectuée.',
            'data'    => ['event_id' => $eventId, 'member_id' => $memberId],
        ];
    }

    /**
     * Liste les participants inscrits à un événement et prépare l'export CSV (gestionnaires uniquement).
     *
     * @param int                  $eventId Identifiant de l'événement.
     * @param array<string, mixed> $ctx     Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function listParticipants(int $eventId, array $ctx): array
    {
        if (!$this->canManageEvents($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : liste réservée aux encadrants et au Bureau.', 'data' => null];
        }

        $evStmt = $this->db->prepare('SELECT id, title, event_date, location, max_capacity, status FROM mod_evenements_events WHERE id = :id LIMIT 1');
        $evStmt->execute(['id' => $eventId]);
        $event = $evStmt->fetch(PDO::FETCH_ASSOC);
        if ($event === false) {
            return ['status' => 'error', 'code' => 404, 'message' => 'Événement introuvable.', 'data' => null];
        }

        $pStmt = $this->db->prepare("
            SELECT m.id AS member_id, m.first_name, m.last_name, r.created_at AS registered_at
            FROM mod_evenements_registrations r
            INNER JOIN members m ON m.id = r.member_id
            WHERE r.event_id = :eid
            ORDER BY m.last_name ASC, m.first_name ASC
        ");
        $pStmt->execute(['eid' => $eventId]);
        $participants = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        $csvLines = ['ID;Prenom;Nom;DateInscription'];
        foreach ($participants as $p) {
            $csvLines[] = sprintf(
                '%d;"%s";"%s";"%s"',
                (int) $p['member_id'],
                str_replace('"', '""', (string) $p['first_name']),
                str_replace('"', '""', (string) $p['last_name']),
                (string) $p['registered_at']
            );
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Liste des participants récupérée.',
            'data'    => [
                'event'        => $event,
                'participants' => $participants,
                'csv_content'  => implode("\r\n", $csvLines),
            ],
        ];
    }

    /**
     * Retourne les 3 prochains événements publiés pour le widget de la page d'accueil.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUpcomingPublishedEvents(): array
    {
        $sql = "
            SELECT e.id, e.title, e.event_type, e.event_date, e.location, e.max_capacity,
                   COUNT(r.id) AS registered_count
            FROM mod_evenements_events e
            LEFT JOIN mod_evenements_registrations r ON r.event_id = e.id
            WHERE e.status = 'published'
            GROUP BY e.id, e.title, e.event_type, e.event_date, e.location, e.max_capacity
            ORDER BY e.event_date ASC, e.id DESC
            LIMIT 3
        ";
        $stmt = $this->db->query($sql);
        return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * Liste les membres qu'un utilisateur est autorisé à inscrire (soi + enfants rattachés, ou tous si gestionnaire).
     *
     * @param int  $userId    Identifiant utilisateur.
     * @param bool $canManage Gestionnaire ou non.
     * @return array<int, array<string, mixed>>
     */
    private function getEligibleMembersForUser(int $userId, bool $canManage): array
    {
        if ($canManage) {
            $stmt = $this->db->query("SELECT id, first_name, last_name FROM members WHERE status != 'radiated' ORDER BY last_name ASC, first_name ASC LIMIT 100");
            return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        }

        if ($userId <= 0) {
            return [];
        }

        $uStmt = $this->db->prepare('SELECT email FROM users WHERE id = :uid LIMIT 1');
        $uStmt->execute(['uid' => $userId]);
        $userEmail = strtolower(trim((string) ($uStmt->fetchColumn() ?: '')));

        $stmt = $this->db->prepare("
            SELECT DISTINCT m.id, m.first_name, m.last_name
            FROM members m
            LEFT JOIN member_tutor mt ON mt.member_id = m.id
            WHERE m.status != 'radiated'
              AND (mt.tutor_id = :uid OR (:uemail != '' AND LOWER(m.email) = :uemail2))
            ORDER BY m.last_name ASC, m.first_name ASC
        ");
        $stmt->execute([
            'uid'     => $userId,
            'uemail'  => $userEmail,
            'uemail2' => $userEmail,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Vérifie si un utilisateur a le droit d'inscrire/désinscrire un adhérent donné.
     *
     * @param int  $userId    Identifiant utilisateur.
     * @param int  $memberId  Identifiant adhérent.
     * @param bool $canManage Gestionnaire ou non.
     * @return bool
     */
    private function canUserRegisterMember(int $userId, int $memberId, bool $canManage): bool
    {
        if ($canManage) {
            $mStmt = $this->db->prepare('SELECT 1 FROM members WHERE id = :id LIMIT 1');
            $mStmt->execute(['id' => $memberId]);
            return $mStmt->fetchColumn() !== false;
        }

        $eligible = $this->getEligibleMembersForUser($userId, false);
        foreach ($eligible as $m) {
            if ((int) $m['id'] === $memberId) {
                return true;
            }
        }
        return false;
    }
}
