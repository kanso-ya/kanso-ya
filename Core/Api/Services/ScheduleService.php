<?php

declare(strict_types=1);

namespace Core\Api\Services;

use Core\Api\Database\Database;
use PDO;
use Throwable;

/**
 * Service métier de la page publique Horaires & Tarifs (extrait de horaires.php).
 */
final class ScheduleService
{
    private ?PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
    }

    /**
     * Retourne les données publiques des horaires, tarifs, capacités, disciplines et contenu CMS.
     *
     * @return array{
     *     base_price: float,
     *     default_capacity: int,
     *     disciplines: array<int, array<string, mixed>>,
     *     cms_content: array<string, mixed>|null
     * }
     */
    public function getPublicScheduleData(): array
    {
        $basePrice = 150.0;
        $defaultCapacity = 30;
        $disciplines = [];
        $cmsContent = null;

        try {
            $db = $this->db ?? Database::getInstance();

            // Récupération du tarif de base et de la capacité par cours dans sys_settings
            $stmtSettings = $db->query("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key IN ('membership_base_price', 'default_course_capacity')");
            if ($stmtSettings) {
                $settingsRows = $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
                if (isset($settingsRows['membership_base_price'])) {
                    $basePrice = (float) $settingsRows['membership_base_price'];
                }
                if (isset($settingsRows['default_course_capacity']) && (int) $settingsRows['default_course_capacity'] > 0) {
                    $defaultCapacity = (int) $settingsRows['default_course_capacity'];
                }
            }

            // 1. Récupération des disciplines avec leurs inscrits et leurs cours (grades/niveaux)
            $stmtDisc = $db->query('
                SELECT d.id, d.name,
                       COUNT(DISTINCT mp.member_id) AS enrolled_count
                FROM disciplines d
                LEFT JOIN member_practices mp ON d.id = mp.discipline_id
                GROUP BY d.id
                ORDER BY d.id ASC
            ');
            $disciplines = $stmtDisc ? $stmtDisc->fetchAll(PDO::FETCH_ASSOC) : [];

            $stmtLvl = $db->prepare('
                SELECT name, level_type, color_hex, age_min, age_max
                FROM levels
                WHERE discipline_id = :did
                ORDER BY age_min ASC, id ASC
            ');

            foreach ($disciplines as &$d) {
                $d['price'] = $basePrice;
                $d['max_capacity'] = $defaultCapacity;
                $stmtLvl->execute(['did' => $d['id']]);
                $d['levels'] = $stmtLvl->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($d);

            // 2. Vérification d'un texte personnalisé dans pages (CMS)
            $stmtPage = $db->prepare("SELECT title, content, status FROM pages WHERE slug = 'horaires' LIMIT 1");
            $stmtPage->execute();
            $cmsPage = $stmtPage->fetch(PDO::FETCH_ASSOC);
            if ($cmsPage && ($cmsPage['status'] ?? '') === 'published') {
                $cmsContent = $cmsPage;
            }
        } catch (Throwable) {
        }

        return [
            'base_price'       => $basePrice,
            'default_capacity' => $defaultCapacity,
            'disciplines'      => $disciplines,
            'cms_content'      => $cmsContent,
        ];
    }
}
