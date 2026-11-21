<?php

declare(strict_types=1);

namespace Modules\EvenementsApi;

use Core\Api\Database\Database;
use Core\Api\Sdk\EventManager;
use Core\Api\Sdk\ModuleInterface;
use Modules\EvenementsApi\Endpoints\EvenementsController;
use PDO;
use Throwable;

/**
 * Module Événements, Stages & Compétitions (Lot 5 — Sprint 23).
 * Enregistre :
 * - Les tables isolées mod_evenements_events & mod_evenements_registrations (SQLite & MySQL)
 * - Le menu Sidebar /evenements et la route UI /evenements
 * - Le widget d'accueil via ui_slot_home_after_articles
 * - Les 6 endpoints REST sous /api/v1/modules/evenements/*.
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
     * Installation : création des tables isolées mod_evenements_* et seed initial de 2 événements publiés.
     *
     * @return void
     */
    public function install(): void
    {
        $ddl = <<<SQL
        CREATE TABLE IF NOT EXISTS mod_evenements_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(200) NOT NULL,
            event_type VARCHAR(30) NOT NULL DEFAULT 'stage',
            discipline_id INTEGER NULL,
            event_date DATE NOT NULL,
            location VARCHAR(200) NULL,
            description TEXT NULL,
            max_capacity INTEGER NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'published',
            created_by INTEGER NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS mod_evenements_registrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            registered_by INTEGER NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(event_id, member_id),
            FOREIGN KEY(event_id) REFERENCES mod_evenements_events(id) ON DELETE CASCADE
        );
SQL;
        Database::execDdlStatements($this->db, $ddl);

        $cntStmt = $this->db->query('SELECT COUNT(*) FROM mod_evenements_events');
        $count = $cntStmt !== false ? (int) $cntStmt->fetchColumn() : 0;
        if ($count === 0) {
            $ins = $this->db->prepare("
                INSERT INTO mod_evenements_events (title, event_type, discipline_id, event_date, location, description, max_capacity, status)
                VALUES (:title, :type, :did, :edate, :loc, :desc, :cap, 'published')
            ");
            $samples = [
                [
                    'title' => 'Stage Technique Judo — Perfectionnement Ne-Waza',
                    'type'  => 'stage',
                    'did'   => 1,
                    'edate' => '2026-12-12',
                    'loc'   => 'Dojo Principal Kanso-ya',
                    'desc'  => 'Stage ouvert aux benjamins, minimes, cadets et adultes.',
                    'cap'   => 30,
                ],
                [
                    'title' => 'Passage de Grades & Ceintures — Session Hiver',
                    'type'  => 'grade',
                    'did'   => 2,
                    'edate' => '2026-12-19',
                    'loc'   => 'Salle Tatami B',
                    'desc'  => 'Évaluation technique officielle de fin de trimestre.',
                    'cap'   => 25,
                ],
            ];
            foreach ($samples as $ev) {
                $ins->execute($ev);
            }
        }
    }

    /**
     * Désinstallation : suppression propre des tables mod_evenements_*.
     *
     * @return void
     */
    public function uninstall(): void
    {
        $this->db->exec('DROP TABLE IF EXISTS mod_evenements_registrations');
        $this->db->exec('DROP TABLE IF EXISTS mod_evenements_events');
    }

    /**
     * Démarrage : enregistrement des hooks menu, route UI, widget d'accueil et endpoints REST.
     *
     * @param EventManager $em Gestionnaire d'événements SDK.
     * @return void
     */
    public function boot(EventManager $em): void
    {
        $em->addFilter('admin_sidebar_menu', static function (array $items): array {
            $items[] = [
                'id'         => 'evenements',
                'label'      => 'Événements',
                'url'        => '/evenements',
                'icon'       => '📅',
                'permission' => '',
            ];
            return $items;
        });

        $em->addFilter('ui_routes', static function (array $routes): array {
            $pagePath = dirname(__DIR__) . '/EvenementsUi/Pages/evenements.php';
            $routes['/evenements'] = ['page' => $pagePath];
            return $routes;
        });

        $em->addFilter('ui_slot_home_after_articles', function (string $html): string {
            return $html . $this->renderHomeWidgetHtml();
        });

        $em->addFilter('api_routes', static function (array $routes): array {
            $routes[] = [
                'method'  => 'GET',
                'pattern' => '/api/v1/modules/evenements/events',
                'handler' => static fn (array $ctx): array => (new EvenementsController())->listEvents($ctx),
            ];
            $routes[] = [
                'method'  => 'POST',
                'pattern' => '/api/v1/modules/evenements/events',
                'handler' => static fn (array $ctx): array => (new EvenementsController())->createEvent($ctx),
            ];
            $routes[] = [
                'method'  => ['PATCH', 'PUT'],
                'pattern' => '#^/api/v1/modules/evenements/events/(\d+)$#',
                'handler' => static fn (array $ctx, array $m): array => (new EvenementsController())->updateEvent((int) $m[1], $ctx),
            ];
            $routes[] = [
                'method'  => 'POST',
                'pattern' => '#^/api/v1/modules/evenements/events/(\d+)/register$#',
                'handler' => static fn (array $ctx, array $m): array => (new EvenementsController())->registerMember((int) $m[1], $ctx),
            ];
            $routes[] = [
                'method'  => 'DELETE',
                'pattern' => '#^/api/v1/modules/evenements/events/(\d+)/register/(\d+)$#',
                'handler' => static fn (array $ctx, array $m): array => (new EvenementsController())->unregisterMember((int) $m[1], (int) $m[2], $ctx),
            ];
            $routes[] = [
                'method'  => 'GET',
                'pattern' => '#^/api/v1/modules/evenements/events/(\d+)/participants$#',
                'handler' => static fn (array $ctx, array $m): array => (new EvenementsController())->listParticipants((int) $m[1], $ctx),
            ];
            return $routes;
        });
    }

    /**
     * Génère le widget HTML « Prochains Événements du Club » injecté sur / via ui_slot_home_after_articles.
     *
     * @return string Fragment HTML sécurisé.
     */
    private function renderHomeWidgetHtml(): string
    {
        try {
            $events = (new EvenementsController($this->db))->getUpcomingPublishedEvents();
        } catch (Throwable) {
            return '';
        }

        $typeLabels = [
            'stage'       => 'Stage',
            'grade'       => 'Passage de grade',
            'competition' => 'Compétition',
            'autre'       => 'Événement',
        ];

        $cardsHtml = '';
        foreach ($events as $ev) {
            $title = htmlspecialchars((string) $ev['title'], ENT_QUOTES, 'UTF-8');
            $typeKey = (string) ($ev['event_type'] ?? 'stage');
            $typeLabel = htmlspecialchars($typeLabels[$typeKey] ?? 'Événement', ENT_QUOTES, 'UTF-8');
            $date = htmlspecialchars((string) $ev['event_date'], ENT_QUOTES, 'UTF-8');
            $loc = htmlspecialchars((string) ($ev['location'] ?: 'Dojo du club'), ENT_QUOTES, 'UTF-8');
            $cap = $ev['max_capacity'] !== null ? (int) $ev['max_capacity'] : null;
            $reg = (int) ($ev['registered_count'] ?? 0);
            $capLabel = ($cap !== null && $cap > 0) ? sprintf('%d / %d inscrits', $reg, $cap) : sprintf('%d inscrit(s)', $reg);

            $cardsHtml .= sprintf(
                '<div class="p-4 rounded-xl border border-slate-200 bg-slate-50/70 flex flex-col justify-between gap-2">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-100 text-emerald-800">%s</span>
                            <span class="text-[11px] font-mono text-slate-500">%s</span>
                        </div>
                        <h3 class="text-sm font-bold text-slate-900 leading-snug">%s</h3>
                        <p class="text-xs text-slate-500">📍 %s</p>
                    </div>
                    <div class="pt-2 border-t border-slate-200/70 flex items-center justify-between text-[11px]">
                        <span class="font-semibold text-slate-600">%s</span>
                        <a href="/evenements" class="font-bold text-brand-primary hover:underline">S\'inscrire →</a>
                    </div>
                </div>',
                $typeLabel,
                $date,
                $title,
                $loc,
                htmlspecialchars($capLabel, ENT_QUOTES, 'UTF-8')
            );
        }

        if ($cardsHtml === '') {
            $cardsHtml = '<p class="text-xs text-slate-500 italic col-span-full">Aucun événement publié à venir pour le moment.</p>';
        }

        return sprintf(
            '<section data-testid="home-events-widget" class="bg-white rounded-2xl shadow-xs border border-slate-200 p-6 md:p-8 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-3">
                    <div>
                        <span class="text-xs font-bold uppercase text-brand-primary">Agenda du Club</span>
                        <h2 class="text-xl font-extrabold text-slate-900">📅 Prochains Événements, Stages & Compétitions</h2>
                    </div>
                    <a href="/evenements" class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold transition shrink-0">
                        Voir tout le calendrier →
                    </a>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">%s</div>
            </section>',
            $cardsHtml
        );
    }
}
