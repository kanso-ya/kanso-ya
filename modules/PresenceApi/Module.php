<?php

declare(strict_types=1);

namespace Modules\PresenceApi;

use Core\Api\Database\Database;
use Core\Api\Sdk\EventManager;
use Core\Api\Sdk\ModuleInterface;
use Modules\PresenceApi\Endpoints\PresenceController;
use PDO;
use Throwable;

/**
 * Module Présences & Suivi Tatami (Lot 5 — Sprint 22).
 * Enregistre :
 * - Les tables isolées mod_presence_sessions & mod_presence_records (SQLite & MySQL)
 * - Le menu Sidebar /presences (permission stats.view)
 * - La route UI /presences et les endpoints REST /api/v1/modules/presence/*
 * - L'onglet analytique « Assiduité » injecté dans /stats via le hook ui_tabs.
 */
final class Module implements ModuleInterface
{
    private PDO $db;

    /**
     * @param PDO|null $db Connexion PDO optionnelle.
     */
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Installation : création des tables isolées mod_presence_* compatibles SQLite et MySQL.
     *
     * @return void
     */
    public function install(): void
    {
        $ddl = <<<SQL
        CREATE TABLE IF NOT EXISTS mod_presence_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            discipline_id INTEGER NOT NULL,
            session_date DATE NOT NULL,
            time_slot VARCHAR(50) NOT NULL DEFAULT '18:00-19:30',
            label VARCHAR(150) NULL,
            created_by INTEGER NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(discipline_id, session_date, time_slot)
        );

        CREATE TABLE IF NOT EXISTS mod_presence_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'present',
            note VARCHAR(255) NULL,
            marked_by INTEGER NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(session_id, member_id),
            FOREIGN KEY(session_id) REFERENCES mod_presence_sessions(id) ON DELETE CASCADE
        );
SQL;
        Database::execDdlStatements($this->db, $ddl);
    }

    /**
     * Désinstallation : suppression propre des tables mod_presence_*.
     *
     * @return void
     */
    public function uninstall(): void
    {
        $this->db->exec('DROP TABLE IF EXISTS mod_presence_records');
        $this->db->exec('DROP TABLE IF EXISTS mod_presence_sessions');
    }

    /**
     * Démarrage : enregistrement des hooks menu, routes UI/API et onglet /stats.
     *
     * @param EventManager $em Gestionnaire d'événements SDK.
     * @return void
     */
    public function boot(EventManager $em): void
    {
        $em->addFilter('admin_sidebar_menu', static function (array $items): array {
            $items[] = [
                'id'         => 'presence',
                'label'      => 'Présences & Suivi',
                'url'        => '/presences',
                'icon'       => '📋',
                'permission' => 'stats.view',
            ];
            return $items;
        });

        $em->addFilter('ui_routes', static function (array $routes): array {
            $pagePath = dirname(__DIR__) . '/PresenceUi/Pages/presences.php';
            $routes['/presences'] = ['page' => $pagePath];
            return $routes;
        });

        $em->addFilter('ui_tabs', function (array $tabs, array $context): array {
            if (($context['page'] ?? '') !== 'stats') {
                return $tabs;
            }

            $role = (string) ($context['role'] ?? '');
            $userId = (int) ($context['user_id'] ?? 0);
            $isSuperadmin = ($role === 'Superadmin');

            $contentHtml = $this->renderStatsTabHtml($userId, $role, $isSuperadmin);

            $tabs[] = [
                'id'      => 'presence',
                'label'   => '📋 Assiduité',
                'active'  => false,
                'order'   => 50,
                'content' => $contentHtml,
            ];
            return $tabs;
        });

        $em->addFilter('api_routes', static function (array $routes): array {
            $routes[] = [
                'method'  => 'GET',
                'pattern' => '/api/v1/modules/presence/sessions',
                'handler' => static fn (array $ctx): array => (new PresenceController())->listSessions($ctx),
            ];
            $routes[] = [
                'method'  => 'POST',
                'pattern' => '/api/v1/modules/presence/sessions',
                'handler' => static fn (array $ctx): array => (new PresenceController())->openOrCreateSession($ctx),
            ];
            $routes[] = [
                'method'  => 'GET',
                'pattern' => '#^/api/v1/modules/presence/sessions/(\d+)/roster$#',
                'handler' => static fn (array $ctx, array $m): array => (new PresenceController())->getSessionRoster((int) $m[1], $ctx),
            ];
            $routes[] = [
                'method'  => 'POST',
                'pattern' => '#^/api/v1/modules/presence/sessions/(\d+)/mark$#',
                'handler' => static fn (array $ctx, array $m): array => (new PresenceController())->markAttendance((int) $m[1], $ctx),
            ];
            $routes[] = [
                'method'  => 'GET',
                'pattern' => '#^/api/v1/modules/presence/members/(\d+)/history$#',
                'handler' => static fn (array $ctx, array $m): array => (new PresenceController())->getMemberHistory((int) $m[1], $ctx),
            ];
            $routes[] = [
                'method'  => 'GET',
                'pattern' => '/api/v1/modules/presence/stats',
                'handler' => static fn (array $ctx): array => (new PresenceController())->getAttendanceStats($ctx),
            ];
            return $routes;
        });
    }

    /**
     * Génère le contenu HTML sécurisé de l'onglet « Assiduité » injecté dans /stats.
     *
     * @param int    $userId       Identifiant utilisateur.
     * @param string $role         Rôle utilisateur.
     * @param bool   $isSuperadmin Superadmin ou non.
     * @return string Fragment HTML prêt à afficher.
     */
    private function renderStatsTabHtml(int $userId, string $role, bool $isSuperadmin): string
    {
        try {
            $summary = (new PresenceController($this->db))->buildStatsSummary($userId, $role, $isSuperadmin);
        } catch (Throwable) {
            return '<p class="text-slate-500">Aucune donnée de présence disponible pour le moment.</p>';
        }

        $rowsHtml = '';
        foreach ($summary['by_discipline'] as $row) {
            $name = htmlspecialchars($row['discipline_name'], ENT_QUOTES, 'UTF-8');
            $rowsHtml .= sprintf(
                '<tr class="hover:bg-slate-50"><td class="p-3 font-bold text-slate-900">%s</td><td class="p-3">%d</td><td class="p-3 text-emerald-700 font-semibold">%d</td><td class="p-3 text-red-600">%d</td><td class="p-3 text-amber-600">%d</td><td class="p-3 font-extrabold text-slate-900">%d%%</td></tr>',
                $name,
                $row['sessions_count'],
                $row['present_count'],
                $row['absent_count'],
                $row['excused_count'],
                $row['attendance_rate']
            );
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="6" class="p-4 text-center text-slate-400">Aucune discipline configurée.</td></tr>';
        }

        return sprintf(
            '<div class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-4 text-xs">
                        <span class="px-3 py-1.5 rounded-xl bg-slate-100 font-bold text-slate-800">Séances : %d</span>
                        <span class="px-3 py-1.5 rounded-xl bg-emerald-50 font-bold text-emerald-700">Taux global : %d%%</span>
                        <span class="px-3 py-1.5 rounded-xl bg-blue-50 font-bold text-blue-700">Pointages : %d</span>
                    </div>
                    <a href="/presences" class="px-3.5 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold transition">Ouvrir l\'appel numérique →</a>
                </div>
                <div class="overflow-x-auto rounded-xl border border-slate-200">
                    <table data-testid="presence-stats-table" class="w-full text-left border-collapse text-xs">
                        <thead class="bg-slate-50 text-slate-500 uppercase border-b border-slate-200">
                            <tr>
                                <th class="p-3">Discipline</th>
                                <th class="p-3">Séances</th>
                                <th class="p-3">Présents</th>
                                <th class="p-3">Absents</th>
                                <th class="p-3">Excusés</th>
                                <th class="p-3">Taux d\'assiduité</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">%s</tbody>
                    </table>
                </div>
            </div>',
            $summary['total_sessions'],
            $summary['global_rate'],
            $summary['total_marks'],
            $rowsHtml
        );
    }
}
