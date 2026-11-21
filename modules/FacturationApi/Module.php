<?php

declare(strict_types=1);

namespace Modules\FacturationApi;

use Core\Api\Database\Database;
use Core\Api\Sdk\EventManager;
use Core\Api\Sdk\ModuleInterface;
use Modules\FacturationApi\Endpoints\FacturationController;
use Modules\FacturationApi\Services\InvoiceService;
use PDO;
use Throwable;

/**
 * Module Facturation PDF Officielle & Envoi Automatique par E-mail (Lot 6 — Sprint 24).
 * Enregistre :
 * - Les tables isolées mod_facturation_settings & mod_facturation_invoices (SQLite & MySQL)
 * - Le template d'e-mail dédié 'invoice_issued'
 * - Le déclenchement automatique sur 'on_registration_validated' et le hook transversal 'issue_official_invoice'
 * - Le menu Sidebar /factures, la route UI /factures et les endpoints REST /api/v1/modules/facturation/*.
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
     * Installation : création des tables isolées mod_facturation_* et du template e-mail dédié 'invoice_issued'.
     *
     * @return void
     */
    public function install(): void
    {
        $ddl = <<<SQL
        CREATE TABLE IF NOT EXISTS mod_facturation_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS mod_facturation_invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_number VARCHAR(40) NOT NULL UNIQUE,
            source_type VARCHAR(40) NOT NULL DEFAULT 'cotisation',
            source_id INTEGER NULL,
            user_id INTEGER NULL,
            recipient_name VARCHAR(200) NOT NULL,
            recipient_email VARCHAR(255) NOT NULL,
            recipient_address TEXT NULL,
            items_json TEXT NOT NULL,
            subtotal_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            payment_method VARCHAR(50) NULL,
            pdf_filename VARCHAR(255) NOT NULL,
            email_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            email_log_id INTEGER NULL,
            issued_by INTEGER NULL,
            issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
SQL;
        Database::execDdlStatements($this->db, $ddl);

        // Création idempotente du répertoire privé hors webroot
        InvoiceService::getStorageDir();

        // Création idempotente du template d'e-mail dédié 'invoice_issued'
        try {
            $insertIgnore = Database::insertIgnorePrefix($this->db);
            $stmt = $this->db->prepare("
                {$insertIgnore} email_templates (template_key, title, subject, body_html, variables_available)
                VALUES (:key, :title, :subj, :body, :vars)
            ");
            $stmt->execute([
                'key'   => InvoiceService::EMAIL_TEMPLATE_KEY,
                'title' => 'Envoi de Facture Officielle PDF',
                'subj'  => 'Votre facture officielle {{invoice_number}} — {{club_name}}',
                'body'  => '<p>Bonjour {{recipient_name}},</p><p>Veuillez trouver ci-joint votre facture officielle <strong>{{invoice_number}}</strong> d\'un montant de <strong>{{total_amount}} €</strong> ({{source_label}}).</p><p>Sportivement,<br>{{club_name}}</p>',
                'vars'  => '{{recipient_name}}, {{invoice_number}}, {{total_amount}}, {{club_name}}, {{source_label}}',
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * Désinstallation : suppression propre des tables mod_facturation_*.
     *
     * @return void
     */
    public function uninstall(): void
    {
        $this->db->exec('DROP TABLE IF EXISTS mod_facturation_invoices');
        $this->db->exec('DROP TABLE IF EXISTS mod_facturation_settings');
    }

    /**
     * Démarrage : enregistrement des hooks événementiels, menu Sidebar, route UI et endpoints API.
     *
     * @param EventManager $em Gestionnaire d'événements SDK.
     * @return void
     */
    public function boot(EventManager $em): void
    {
        $em->addFilter('admin_sidebar_menu', static function (array $items): array {
            $items[] = [
                'id'         => 'facturation',
                'label'      => 'Factures PDF',
                'url'        => '/factures',
                'icon'       => '🧾',
                'permission' => '',
            ];
            return $items;
        });

        $em->addFilter('ui_routes', static function (array $routes): array {
            $pagePath = dirname(__DIR__) . '/FacturationUi/Pages/factures.php';
            $routes['/factures'] = ['page' => $pagePath];
            return $routes;
        });

        // Déclenchement automatique lors de la validation d'une cotisation Core
        $em->addAction('on_registration_validated', static function (array $payload): void {
            $regId = (int) ($payload['registration_id'] ?? 0);
            $adminId = !empty($payload['admin_user_id']) ? (int) $payload['admin_user_id'] : null;
            if ($regId > 0) {
                try {
                    (new InvoiceService())->issueFromRegistration($regId, $adminId);
                } catch (Throwable) {
                }
            }
        });

        // Hook transversal utilisable par d'autres modules (ex. BoutiqueApi) pour émettre une facture PDF officielle
        $em->addAction('issue_official_invoice', static function (array $payload): void {
            try {
                (new InvoiceService())->issueInvoice($payload, !isset($payload['send_email']) || (bool) $payload['send_email']);
            } catch (Throwable) {
            }
        });

        $em->addFilter('api_routes', static function (array $routes): array {
            $routes[] = [
                'method'  => 'GET',
                'pattern' => '/api/v1/modules/facturation/invoices',
                'handler' => static fn (array $ctx): array => (new FacturationController())->listInvoices($ctx),
            ];
            $routes[] = [
                'method'  => 'POST',
                'pattern' => '/api/v1/modules/facturation/invoices',
                'handler' => static fn (array $ctx): array => (new FacturationController())->issueInvoice($ctx),
            ];
            $routes[] = [
                'method'  => 'GET',
                'pattern' => '#^/api/v1/modules/facturation/invoices/(\d+)/pdf$#',
                'handler' => static fn (array $ctx, array $m): array => (new FacturationController())->getInvoicePdf((int) $m[1], $ctx),
            ];
            $routes[] = [
                'method'  => 'POST',
                'pattern' => '#^/api/v1/modules/facturation/invoices/(\d+)/send-email$#',
                'handler' => static fn (array $ctx, array $m): array => (new FacturationController())->resendInvoiceEmail((int) $m[1], $ctx),
            ];
            $routes[] = [
                'method'  => 'GET',
                'pattern' => '/api/v1/modules/facturation/templates',
                'handler' => static fn (array $ctx): array => (new FacturationController())->getTemplates($ctx),
            ];
            $routes[] = [
                'method'  => ['PUT', 'PATCH'],
                'pattern' => '/api/v1/modules/facturation/templates',
                'handler' => static fn (array $ctx): array => (new FacturationController())->updateTemplates($ctx),
            ];
            return $routes;
        });
    }
}
