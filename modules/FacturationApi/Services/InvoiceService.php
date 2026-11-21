<?php

declare(strict_types=1);

namespace Modules\FacturationApi\Services;

use Core\Api\Database\Database;
use Core\Api\Services\MailerService;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Service transversal de facturation PDF officielle & envoi automatique par e-mail.
 * Utilisable par :
 * - Les cotisations Core (hook on_registration_validated)
 * - Le back-office /factures (émission manuelle)
 * - Tout module tiers (ex. BoutiqueApi) via InvoiceService::issueInvoice() ou l'action SDK 'issue_official_invoice'.
 */
final class InvoiceService
{
    public const EMAIL_TEMPLATE_KEY = 'invoice_issued';

    private PDO $db;
    private NativePdfBuilder $pdfBuilder;
    private MailerService $mailer;

    /**
     * @param PDO|null              $db         Connexion PDO optionnelle.
     * @param NativePdfBuilder|null $pdfBuilder Générateur PDF natif.
     * @param MailerService|null    $mailer     Service de messagerie SMTP.
     */
    public function __construct(
        ?PDO $db = null,
        ?NativePdfBuilder $pdfBuilder = null,
        ?MailerService $mailer = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->pdfBuilder = $pdfBuilder ?? new NativePdfBuilder();
        $this->mailer = $mailer ?? new MailerService($this->db);
    }

    /**
     * Retourne le répertoire privé hors webroot de stockage des PDF de factures.
     *
     * @return string Chemin absolu du dossier.
     */
    public static function getStorageDir(): string
    {
        $dir = dirname(__DIR__, 3) . '/storage/private_uploads/invoices';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Récupère les paramètres du template PDF (avec valeurs par défaut issues du club).
     *
     * @return array<string, string>
     */
    public function getPdfTemplateSettings(): array
    {
        $clubName = 'Kanso-ya Association';
        $clubEmail = 'ne-pas-repondre@kanso-ya.fr';
        try {
            $sStmt = $this->db->query("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key IN ('site_name', 'smtp_from_email')");
            if ($sStmt !== false) {
                /** @var array<string, string> $sys */
                $sys = $sStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                if (!empty($sys['site_name'])) {
                    $clubName = $sys['site_name'];
                }
                if (!empty($sys['smtp_from_email'])) {
                    $clubEmail = $sys['smtp_from_email'];
                }
            }
        } catch (Throwable) {
        }

        $defaults = [
            'invoice_prefix'  => 'FAC',
            'org_name'        => $clubName,
            'org_legal_id'    => 'Association Loi 1901 — RNA W000000000',
            'org_address'     => 'Dojo Municipal — France',
            'org_email'       => $clubEmail,
            'header_title'    => 'FACTURE OFFICIELLE',
            'footer_mentions' => 'Association exonérée de TVA (Art. 261-7-1° du CGI). Merci de votre confiance.',
        ];

        $stmt = $this->db->query('SELECT setting_key, setting_value FROM mod_facturation_settings');
        if ($stmt !== false) {
            /** @var array<string, string> $saved */
            $saved = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($saved as $k => $v) {
                $defaults[$k] = $v;
            }
        }

        return $defaults;
    }

    /**
     * Enregistre les paramètres du template PDF dans mod_facturation_settings.
     *
     * @param array<string, mixed> $pdfTemplate Clés/valeurs du template PDF.
     * @return void
     */
    public function savePdfTemplateSettings(array $pdfTemplate): void
    {
        $allowed = ['invoice_prefix', 'org_name', 'org_legal_id', 'org_address', 'org_email', 'header_title', 'footer_mentions'];
        $sql = Database::isSqlite($this->db)
            ? 'INSERT INTO mod_facturation_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, CURRENT_TIMESTAMP) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO mod_facturation_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->db->prepare($sql);

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $pdfTemplate)) {
                continue;
            }
            $val = trim((string) $pdfTemplate[$key]);
            if ($key === 'invoice_prefix') {
                $val = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', $val) ?: 'FAC');
            }
            $stmt->execute(['k' => $key, 'v' => $val]);
        }
    }

    /**
     * Récupère le template d'e-mail dédié 'invoice_issued' depuis email_templates.
     *
     * @return array{subject: string, body_html: string, variables_available: string}
     */
    public function getEmailTemplate(): array
    {
        $stmt = $this->db->prepare('SELECT subject, body_html, variables_available FROM email_templates WHERE template_key = :key LIMIT 1');
        $stmt->execute(['key' => self::EMAIL_TEMPLATE_KEY]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            return [
                'subject'             => (string) $row['subject'],
                'body_html'           => (string) $row['body_html'],
                'variables_available' => (string) $row['variables_available'],
            ];
        }

        return [
            'subject'             => 'Votre facture officielle {{invoice_number}} — {{club_name}}',
            'body_html'           => '<p>Bonjour {{recipient_name}},</p><p>Veuillez trouver ci-joint votre facture officielle <strong>{{invoice_number}}</strong> d\'un montant de <strong>{{total_amount}} €</strong> ({{source_label}}).</p><p>Sportivement,<br>{{club_name}}</p>',
            'variables_available' => '{{recipient_name}}, {{invoice_number}}, {{total_amount}}, {{club_name}}, {{source_label}}',
        ];
    }

    /**
     * Met à jour le template d'e-mail dédié 'invoice_issued'.
     *
     * @param string $subject  Sujet du modèle.
     * @param string $bodyHtml Corps HTML du modèle.
     * @return void
     */
    public function saveEmailTemplate(string $subject, string $bodyHtml): void
    {
        $upd = $this->db->prepare('UPDATE email_templates SET subject = :subj, body_html = :body, updated_at = CURRENT_TIMESTAMP WHERE template_key = :key');
        $upd->execute([
            'subj' => trim($subject),
            'body' => trim($bodyHtml),
            'key'  => self::EMAIL_TEMPLATE_KEY,
        ]);

        if ($upd->rowCount() === 0) {
            $ins = $this->db->prepare('
                INSERT INTO email_templates (template_key, title, subject, body_html, variables_available)
                VALUES (:key, :title, :subj, :body, :vars)
            ');
            $ins->execute([
                'key'   => self::EMAIL_TEMPLATE_KEY,
                'title' => 'Envoi de Facture Officielle PDF',
                'subj'  => trim($subject),
                'body'  => trim($bodyHtml),
                'vars'  => '{{recipient_name}}, {{invoice_number}}, {{total_amount}}, {{club_name}}, {{source_label}}',
            ]);
        }
    }

    /**
     * Émet une facture PDF officielle (idempotente sur source_type + source_id), la stocke hors webroot
     * et l'expédie automatiquement par e-mail avec pièce jointe PDF si $sendEmail est vrai.
     *
     * @param array<string, mixed> $params    Paramètres de facturation.
     * @param bool                 $sendEmail Déclenche l'envoi automatique par e-mail.
     * @return array<string, mixed> Enregistrement complet de la facture créée ou existante.
     */
    public function issueInvoice(array $params, bool $sendEmail = true): array
    {
        $sourceType = strtolower(trim((string) ($params['source_type'] ?? 'autre')));
        if (!in_array($sourceType, ['cotisation', 'boutique', 'autre'], true)) {
            $sourceType = 'autre';
        }
        $sourceId = !empty($params['source_id']) ? (int) $params['source_id'] : null;

        // Idempotence sur (source_type, source_id)
        if ($sourceId !== null && $sourceId > 0) {
            $existingStmt = $this->db->prepare('SELECT * FROM mod_facturation_invoices WHERE source_type = :st AND source_id = :sid LIMIT 1');
            $existingStmt->execute(['st' => $sourceType, 'sid' => $sourceId]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if ($existing !== false) {
                return $existing;
            }
        }

        $recipientName = trim((string) ($params['recipient_name'] ?? ''));
        $recipientEmail = strtolower(trim((string) ($params['recipient_email'] ?? '')));
        $recipientAddress = trim((string) ($params['recipient_address'] ?? ''));
        $userId = !empty($params['user_id']) ? (int) $params['user_id'] : null;
        $issuedBy = !empty($params['issued_by']) ? (int) $params['issued_by'] : null;
        $paymentMethod = trim((string) ($params['payment_method'] ?? 'cheque'));

        if ($recipientName === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Nom et adresse e-mail valide du destinataire obligatoires.');
        }

        $rawItems = $params['items'] ?? [];
        if (!is_array($rawItems) || empty($rawItems)) {
            throw new RuntimeException('Au moins une ligne de facturation est obligatoire.');
        }

        $items = [];
        $subtotal = 0.0;
        foreach ($rawItems as $it) {
            if (!is_array($it)) {
                continue;
            }
            $label = trim((string) ($it['label'] ?? ''));
            $qty = max(1, (int) ($it['qty'] ?? 1));
            $unitPrice = max(0.0, (float) ($it['unit_price'] ?? 0.0));
            if ($label === '') {
                continue;
            }
            $lineTotal = round($qty * $unitPrice, 2);
            $subtotal += $lineTotal;
            $items[] = [
                'label'      => $label,
                'qty'        => $qty,
                'unit_price' => $unitPrice,
                'total'      => $lineTotal,
            ];
        }

        if (empty($items)) {
            throw new RuntimeException('Aucune ligne de facturation valide fournie.');
        }

        $discount = max(0.0, round((float) ($params['discount_amount'] ?? 0.0), 2));
        $total = max(0.0, round($subtotal - $discount, 2));
        $issuedAt = date('Y-m-d H:i:s');
        $template = $this->getPdfTemplateSettings();

        // Attribution du numéro séquentiel & insertion atomique
        $invoiceRow = Database::transaction(function () use (
            $template,
            $sourceType,
            $sourceId,
            $userId,
            $recipientName,
            $recipientEmail,
            $recipientAddress,
            $items,
            $subtotal,
            $discount,
            $total,
            $paymentMethod,
            $issuedBy,
            $issuedAt
        ): array {
            $prefix = $template['invoice_prefix'] ?: 'FAC';
            $year = date('Y');
            $likePattern = "{$prefix}-{$year}-%";
            $cntStmt = $this->db->prepare('SELECT COUNT(*) FROM mod_facturation_invoices WHERE invoice_number LIKE :pat');
            $cntStmt->execute(['pat' => $likePattern]);
            $seq = ((int) $cntStmt->fetchColumn()) + 1;

            $invoiceNumber = sprintf('%s-%s-%04d', $prefix, $year, $seq);
            $pdfFilename = $invoiceNumber . '.pdf';

            $ins = $this->db->prepare("
                INSERT INTO mod_facturation_invoices (
                    invoice_number, source_type, source_id, user_id,
                    recipient_name, recipient_email, recipient_address,
                    items_json, subtotal_amount, discount_amount, total_amount,
                    payment_method, pdf_filename, email_status, issued_by, issued_at
                ) VALUES (
                    :num, :stype, :sid, :uid,
                    :rname, :remail, :raddr,
                    :items, :sub, :disc, :tot,
                    :pm, :pdf, 'pending', :iby, :iat
                )
            ");
            $ins->execute([
                'num'    => $invoiceNumber,
                'stype'  => $sourceType,
                'sid'    => $sourceId,
                'uid'    => $userId,
                'rname'  => $recipientName,
                'remail' => $recipientEmail,
                'raddr'  => $recipientAddress !== '' ? $recipientAddress : null,
                'items'  => json_encode($items, JSON_UNESCAPED_UNICODE),
                'sub'    => $subtotal,
                'disc'   => $discount,
                'tot'    => $total,
                'pm'     => $paymentMethod !== '' ? $paymentMethod : null,
                'pdf'    => $pdfFilename,
                'iby'    => $issuedBy,
                'iat'    => $issuedAt,
            ]);

            $newId = (int) $this->db->lastInsertId();
            return [
                'id'                => $newId,
                'invoice_number'    => $invoiceNumber,
                'source_type'       => $sourceType,
                'source_id'         => $sourceId,
                'user_id'           => $userId,
                'recipient_name'    => $recipientName,
                'recipient_email'   => $recipientEmail,
                'recipient_address' => $recipientAddress,
                'items'             => $items,
                'items_json'        => json_encode($items, JSON_UNESCAPED_UNICODE),
                'subtotal_amount'   => $subtotal,
                'discount_amount'   => $discount,
                'total_amount'      => $total,
                'payment_method'    => $paymentMethod,
                'pdf_filename'      => $pdfFilename,
                'email_status'      => 'pending',
                'issued_at'         => $issuedAt,
            ];
        });

        // Génération binaire PDF & écriture hors webroot
        $pdfBinary = $this->pdfBuilder->buildInvoicePdf([
            'invoice_number'    => (string) $invoiceRow['invoice_number'],
            'issued_at'         => (string) $invoiceRow['issued_at'],
            'source_type'       => (string) $invoiceRow['source_type'],
            'recipient_name'    => (string) $invoiceRow['recipient_name'],
            'recipient_email'   => (string) $invoiceRow['recipient_email'],
            'recipient_address' => (string) $invoiceRow['recipient_address'],
            'items'             => $items,
            'subtotal_amount'   => $subtotal,
            'discount_amount'   => $discount,
            'total_amount'      => $total,
            'payment_method'    => $paymentMethod,
        ], $template);

        $targetPath = self::getStorageDir() . '/' . basename((string) $invoiceRow['pdf_filename']);
        file_put_contents($targetPath, $pdfBinary);

        // Envoi automatique par e-mail avec pièce jointe PDF (hors transaction SQLite)
        if ($sendEmail) {
            $sendResult = $this->sendInvoiceEmail((int) $invoiceRow['id']);
            $invoiceRow['email_status'] = $sendResult['status'];
            $invoiceRow['email_log_id'] = $sendResult['email_log_id'];
        }

        return $invoiceRow;
    }

    /**
     * Génère et expédie automatiquement une facture officielle suite à la validation d'un dossier d'inscription Core.
     *
     * @param int      $registrationId Identifiant du dossier registrations.id.
     * @param int|null $adminUserId    Identifiant du valideur.
     * @return array<string, mixed>|null Facture émise ou null si dossier/email introuvable.
     */
    public function issueFromRegistration(int $registrationId, ?int $adminUserId = null): ?array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, u.email AS payer_email, u.first_name AS payer_first, u.last_name AS payer_last, u.address AS payer_address
            FROM registrations r
            LEFT JOIN users u ON u.id = r.payer_user_id
            WHERE r.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $registrationId]);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($reg === false) {
            return null;
        }

        $email = trim((string) ($reg['payer_email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $name = trim(((string) ($reg['payer_first'] ?? '')) . ' ' . ((string) ($reg['payer_last'] ?? '')));
        if ($name === '') {
            $name = $email;
        }

        $total = (float) ($reg['total_amount'] ?? 0.0);
        $discount = (float) ($reg['discount_amount'] ?? 0.0);
        $subtotal = round($total + $discount, 2);
        $season = (string) ($reg['season'] ?? '2026-2027');

        return $this->issueInvoice([
            'source_type'       => 'cotisation',
            'source_id'         => $registrationId,
            'user_id'           => !empty($reg['payer_user_id']) ? (int) $reg['payer_user_id'] : null,
            'recipient_name'    => $name,
            'recipient_email'   => $email,
            'recipient_address' => (string) ($reg['payer_address'] ?? ''),
            'items'             => [
                [
                    'label'      => sprintf('Cotisation associative — Saison %s (Dossier #%d)', $season, $registrationId),
                    'qty'        => 1,
                    'unit_price' => $subtotal,
                ],
            ],
            'discount_amount'   => $discount,
            'payment_method'    => (string) ($reg['payment_method'] ?? 'cheque'),
            'issued_by'         => $adminUserId,
        ], true);
    }

    /**
     * Envoie (ou renvoie) par e-mail la facture PDF officielle via le template dédié 'invoice_issued'.
     *
     * @param int $invoiceId Identifiant de la facture dans mod_facturation_invoices.
     * @return array{success: bool, status: string, email_log_id: int, smtp_response: string}
     */
    public function sendInvoiceEmail(int $invoiceId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM mod_facturation_invoices WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $invoiceId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($inv === false) {
            throw new RuntimeException('Facture introuvable.');
        }

        $pdfPath = self::getStorageDir() . '/' . basename((string) $inv['pdf_filename']);
        $pdfContent = is_file($pdfPath) ? (string) file_get_contents($pdfPath) : '';

        $pdfTpl = $this->getPdfTemplateSettings();
        $mailTpl = $this->getEmailTemplate();

        $sourceLabels = [
            'cotisation' => 'Cotisation associative',
            'boutique'   => 'Commande Boutique Club',
            'autre'      => 'Prestation associative',
        ];
        $vars = [
            '{{recipient_name}}' => (string) $inv['recipient_name'],
            '{{invoice_number}}' => (string) $inv['invoice_number'],
            '{{total_amount}}'   => number_format((float) $inv['total_amount'], 2, ',', ' '),
            '{{club_name}}'      => $pdfTpl['org_name'],
            '{{source_label}}'   => $sourceLabels[(string) $inv['source_type']] ?? 'Facture club',
        ];

        $subject = strtr($mailTpl['subject'], $vars);
        $bodyHtml = strtr($mailTpl['body_html'], $vars);

        $attachments = [];
        if ($pdfContent !== '') {
            $attachments[] = [
                'filename'  => basename((string) $inv['pdf_filename']),
                'content'   => $pdfContent,
                'mime_type' => 'application/pdf',
            ];
        }

        $result = $this->mailer->send(
            (string) $inv['recipient_email'],
            $subject,
            $bodyHtml,
            'INVOICE_ISSUED',
            $attachments
        );

        $upd = $this->db->prepare('UPDATE mod_facturation_invoices SET email_status = :st, email_log_id = :lid WHERE id = :id');
        $upd->execute([
            'st'  => $result['status'],
            'lid' => $result['id'],
            'id'  => $invoiceId,
        ]);

        return [
            'success'       => $result['success'],
            'status'        => $result['status'],
            'email_log_id'  => $result['id'],
            'smtp_response' => $result['smtp_response'],
        ];
    }
}
