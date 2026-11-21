<?php

declare(strict_types=1);

namespace Core\Api\Services;

use Core\Api\Database\Database;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Service gérant le basculement annuel de saison (Rollover) :
 * - Recalcul massif des catégories d'âge (grades) pour tous les membres actifs
 * - Respect strict du surclassement entraîneur (is_category_forced = 1)
 * - Archivage / clôture des dossiers d'inscription des saisons antérieures
 * - Mise à jour du paramètre système current_season
 * - Traçabilité exhaustive dans app_logs et sys_logs
 * - Concurrence SQLite garantie par transaction BEGIN IMMEDIATE.
 */
final class RolloverService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Exécute la moulinette de changement de saison.
     *
     * @param array<string, mixed> $payload Paramètres optionnels : target_season, reference_date, archive_previous.
     * @param int|null             $actorId Identifiant de l'utilisateur ayant déclenché l'action.
     * @param bool                 $isSuperadmin Indique si l'action émane du Superadmin.
     * @return array<string, mixed> Réponse normalisée JSend.
     */
    public function rollover(array $payload = [], ?int $actorId = 1, bool $isSuperadmin = false): array
    {
        // 1. Détermination de la saison cible
        $targetSeason = trim((string) ($payload['target_season'] ?? ''));
        if ($targetSeason === '') {
            $currentSeason = (string) ($this->db->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'current_season'")->fetchColumn() ?: '2026-2027');
            if (preg_match('/^(\d{4})-(\d{4})$/', $currentSeason, $matches)) {
                $startYear = (int) $matches[1] + 1;
                $endYear   = (int) $matches[2] + 1;
                $targetSeason = sprintf('%04d-%04d', $startYear, $endYear);
            } else {
                $targetSeason = '2027-2028';
            }
        }

        // 2. Détermination de la date de référence pour le calcul de l'âge
        $refDateStr = trim((string) ($payload['reference_date'] ?? ''));
        if ($refDateStr === '') {
            $seasonType = (string) ($this->db->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'season_type'")->fetchColumn() ?: 'Sept-Juin');
            $startYear = (int) substr($targetSeason, 0, 4);
            if ($startYear < 2000) {
                $startYear = (int) date('Y') + 1;
            }

            if ($seasonType === 'Jan-Dec') {
                $refDateStr = sprintf('%04d-01-01', $startYear);
            } else {
                $refDateStr = sprintf('%04d-09-01', $startYear);
            }
        }

        try {
            $referenceDate = new DateTimeImmutable($refDateStr);
        } catch (Throwable) {
            $referenceDate = new DateTimeImmutable();
        }

        $archivePrevious = isset($payload['archive_previous']) ? (bool) $payload['archive_previous'] : true;

        try {
            $data = $this->executeImmediateTransaction(function () use ($targetSeason, $referenceDate, $archivePrevious, $actorId, $isSuperadmin): array {
                // 1. Préchargement en mémoire de tous les grades configurés (0 requête dans la boucle)
                $allLevels = $this->db->query("
                    SELECT id, discipline_id, name, age_min, age_max, rank_order
                    FROM levels
                    WHERE level_type = 'grade'
                    ORDER BY rank_order ASC, id ASC
                ")->fetchAll(PDO::FETCH_ASSOC);

                // 2. Récupérer toutes les pratiques des adhérents actifs
                $stmt = $this->db->query("
                    SELECT mp.id AS practice_id, mp.member_id, mp.discipline_id, mp.grade_id, mp.level_id,
                           mp.is_category_forced, mp.season,
                           m.first_name, m.last_name, m.birth_date, m.status AS member_status
                    FROM member_practices mp
                    INNER JOIN members m ON mp.member_id = m.id
                    WHERE m.status = 'active'
                    ORDER BY m.id ASC, mp.id ASC
                ");
                $practices = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $promotedCount       = 0;
                $forcedIgnoredCount   = 0;
                $unchangedCount      = 0;
                $notes               = [];

                $stmtUpdatePractice = $this->db->prepare("
                    UPDATE member_practices
                    SET grade_id = :gid, season = :season
                    WHERE id = :pid
                ");

                foreach ($practices as $p) {
                    $pid        = (int) $p['practice_id'];
                    $mid        = (int) $p['member_id'];
                    $did        = (int) $p['discipline_id'];
                    $currentGid = $p['grade_id'] !== null ? (int) $p['grade_id'] : null;
                    $isForced   = (int) ($p['is_category_forced'] ?? 0);
                    $memberName = trim($p['first_name'] . ' ' . $p['last_name']);

                    // RÈGLE MÉTIER CA3 : Si surclassement validé (is_category_forced = 1), ignorer strictement
                    if ($isForced === 1) {
                        $forcedIgnoredCount++;
                        $notes[] = "Adhérent #{$mid} ({$memberName}) : catégorie d'âge verrouillée par l'entraîneur (is_category_forced=1) maintenue.";
                        $stmtUpdatePractice->execute([
                            'gid'    => $currentGid,
                            'season' => $targetSeason,
                            'pid'    => $pid
                        ]);
                        continue;
                    }

                    // Calcul de l'âge à la date de référence
                    try {
                        $dob = new DateTimeImmutable((string) $p['birth_date']);
                        $age = $referenceDate->diff($dob)->y;
                    } catch (Throwable) {
                        $age = 20;
                    }

                    // Résolution ultra-rapide du grade en mémoire
                    $matchedGrade = null;
                    foreach ($allLevels as $lvl) {
                        if ((int) $lvl['discipline_id'] !== $did) {
                            continue;
                        }
                        $min = $lvl['age_min'] !== null ? (int) $lvl['age_min'] : null;
                        $max = $lvl['age_max'] !== null ? (int) $lvl['age_max'] : null;
                        if (($min === null || $min <= $age) && ($max === null || $max >= $age)) {
                            $matchedGrade = $lvl;
                            break;
                        }
                    }

                    if (!$matchedGrade) {
                        foreach ($allLevels as $lvl) {
                            if ((int) $lvl['discipline_id'] === $did) {
                                $matchedGrade = $lvl;
                                break;
                            }
                        }
                    }

                    $newGid = $matchedGrade ? (int) $matchedGrade['id'] : $currentGid;
                    $newGradeName = $matchedGrade ? (string) $matchedGrade['name'] : 'Inchangé';

                    if ($newGid !== $currentGid && $newGid !== null) {
                        $promotedCount++;
                        $notes[] = "Adhérent #{$mid} ({$memberName}) : promu au grade « {$newGradeName} » ({$age} ans au {$referenceDate->format('d/m/Y')}).";
                    } else {
                        $unchangedCount++;
                    }

                    $stmtUpdatePractice->execute([
                        'gid'    => $newGid,
                        'season' => $targetSeason,
                        'pid'    => $pid
                    ]);
                }

                // 4. Clôture / archivage des dossiers validés des saisons antérieures
                $closedRegistrationsCount = 0;
                if ($archivePrevious) {
                    $stmtArchive = $this->db->prepare("
                        UPDATE registrations
                        SET status = 'archived'
                        WHERE season != :ts AND status = 'validated'
                    ");
                    $stmtArchive->execute(['ts' => $targetSeason]);
                    $closedRegistrationsCount = $stmtArchive->rowCount();
                }

                // 5. Mise à jour de la saison active dans sys_settings
                $stmtSetSeason = $this->db->prepare(Database::upsertSettingSql($this->db));
                $stmtSetSeason->execute([
                    'key' => 'current_season',
                    'val' => $targetSeason,
                ]);

                // 6. Enregistrement détaillé dans app_logs (CA4)
                $logDetailsText = sprintf(
                    "Basculement vers la saison %s (réf: %s). %d adhérent(s) promu(s), %d surclassé(s) ignoré(s), %d dossier(s) archivé(s).",
                    $targetSeason,
                    $referenceDate->format('Y-m-d'),
                    $promotedCount,
                    $forcedIgnoredCount,
                    $closedRegistrationsCount
                );

                $stmtAppLog = $this->db->prepare("
                    INSERT INTO app_logs (user_id, action, target_type, target_id, details)
                    VALUES (:uid, 'SEASON_ROLLOVER', 'system', NULL, :details)
                ");
                $stmtAppLog->execute([
                    'uid'     => $isSuperadmin ? null : $actorId,
                    'details' => $logDetailsText
                ]);

                // Traçabilité technique dans sys_logs
                $stmtSysLog = $this->db->prepare("
                    INSERT INTO sys_logs (superadmin_id, action, details, ip_address)
                    VALUES (:aid, 'SEASON_ROLLOVER', :details, :ip)
                ");
                $stmtSysLog->execute([
                    'aid'     => $isSuperadmin ? 1 : null,
                    'details' => $logDetailsText,
                    'ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);

                return [
                    'target_season'              => $targetSeason,
                    'reference_date'             => $referenceDate->format('Y-m-d'),
                    'promoted_count'             => $promotedCount,
                    'forced_ignored_count'       => $forcedIgnoredCount,
                    'unchanged_count'            => $unchangedCount,
                    'closed_registrations_count' => $closedRegistrationsCount,
                    'notes'                      => $notes
                ];
            });

            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => "Basculement vers la saison {$targetSeason} terminé avec succès.",
                'data'    => $data
            ];
        } catch (Throwable $e) {
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Échec de la moulinette de rollover : ' . $e->getMessage(),
                'data'    => null
            ];
        }
    }

    /**
     * Exécute une opération dans une transaction BEGIN IMMEDIATE avec retry et backoff progressif.
     *
     * @param callable(): mixed $operation
     * @return mixed
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

                $msg = strtolower($e->getMessage());
                if (($attempt < $maxAttempts) && (str_contains($msg, 'locked') || str_contains($msg, 'busy'))) {
                    usleep(100000 * $attempt);
                    continue;
                }
                throw $e;
            }
        }
        throw new \RuntimeException('Transaction impossible : base SQLite verrouillée.');
    }
}
