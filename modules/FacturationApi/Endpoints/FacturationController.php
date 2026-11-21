<?php

declare(strict_types=1);

namespace Modules\FacturationApi\Endpoints;

use Core\Api\Database\Database;
use Modules\FacturationApi\Services\InvoiceService;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Contrôleur REST du module Facturation PDF Officielle (/api/v1/modules/facturation/*).
 * Gère :
 * - Liste cloisonnée des factures (toutes pour finances.manage/Superadmin, propres factures pour Adhérent)
 * - Émission manuelle de facture PDF + envoi e-mail automatique (gestionnaires)
 * - Téléchargement sécurisé du PDF binaire en base64 (JSend HTTP 200)
 * - Renvoi d'e-mail avec pièce jointe PDF (gestionnaires)
 * - Lecture/sauvegarde du template PDF spécifique et du template d'e-mail dédié 'invoice_issued'.
 */
final class FacturationController
{
    private PDO $db;
    private InvoiceService $service;

    /**
     * @param PDO|null            $db      Connexion PDO optionnelle.
     * @param InvoiceService|null $service Service de facturation.
     */
    public function __construct(?PDO $db = null, ?InvoiceService $service = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->service = $service ?? new InvoiceService($this->db);
    }

    /**
     * Indique si l'utilisateur courant peut administrer la facturation (finances.manage ou Superadmin).
     *
     * @param array<string, mixed> $ctx Contexte transmis par ApiRouter.
     * @return bool
     */
    public function canManageInvoices(array $ctx): bool
    {
        if (!empty($ctx['is_superadmin'])) {
            return true;
        }
        if (empty($ctx['is_authenticated'])) {
            return false;
        }
        $perms = is_array($ctx['permissions'] ?? null) ? $ctx['permissions'] : [];
        return in_array('finances.manage', $perms, true);
    }

    /**
     * Liste les factures visibles par l'utilisateur connecté.
     *
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function listInvoices(array $ctx): array
    {
        if (empty($ctx['is_authenticated']) && empty($ctx['is_superadmin'])) {
            return ['status' => 'error', 'code' => 401, 'message' => 'Authentification requise.', 'data' => null];
        }

        $canManage = $this->canManageInvoices($ctx);
        $userId = (int) ($ctx['user_id'] ?? 0);

        if ($canManage) {
            $stmt = $this->db->query('SELECT * FROM mod_facturation_invoices ORDER BY id DESC LIMIT 100');
            $invoices = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } else {
            $uEmail = $this->resolveUserEmail($userId);
            $stmt = $this->db->prepare("
                SELECT * FROM mod_facturation_invoices
                WHERE user_id = :uid OR (:uemail != '' AND LOWER(recipient_email) = :uemail2)
                ORDER BY id DESC
                LIMIT 100
            ");
            $stmt->execute([
                'uid'     => $userId,
                'uemail'  => $uEmail,
                'uemail2' => $uEmail,
            ]);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Factures récupérées.',
            'data'    => [
                'can_manage' => $canManage,
                'invoices'   => $invoices,
            ],
        ];
    }

    /**
     * Émet manuellement une facture PDF officielle et l'expédie par e-mail (gestionnaires uniquement).
     *
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function issueInvoice(array $ctx): array
    {
        if (!$this->canManageInvoices($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droit finances.manage requis.', 'data' => null];
        }

        $payload = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
        $payload['issued_by'] = (int) ($ctx['user_id'] ?? 0) ?: null;
        $sendEmail = !array_key_exists('send_email', $payload) || (bool) $payload['send_email'];

        try {
            $invoice = $this->service->issueInvoice($payload, $sendEmail);
            return [
                'status'  => 'success',
                'code'    => 201,
                'message' => sprintf('Facture %s émise avec succès.', (string) $invoice['invoice_number']),
                'data'    => [
                    'invoice'      => $invoice,
                    'email_status' => (string) ($invoice['email_status'] ?? 'pending'),
                ],
            ];
        } catch (RuntimeException $e) {
            return ['status' => 'error', 'code' => 422, 'message' => $e->getMessage(), 'data' => null];
        } catch (Throwable $e) {
            return ['status' => 'error', 'code' => 500, 'message' => 'Erreur lors de la génération de la facture : ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Retourne le fichier PDF binaire encodé en base64 après contrôle strict RBAC/propriété.
     *
     * @param int                  $invoiceId Identifiant de la facture.
     * @param array<string, mixed> $ctx       Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function getInvoicePdf(int $invoiceId, array $ctx): array
    {
        if (empty($ctx['is_authenticated']) && empty($ctx['is_superadmin'])) {
            return ['status' => 'error', 'code' => 401, 'message' => 'Authentification requise.', 'data' => null];
        }

        $stmt = $this->db->prepare('SELECT * FROM mod_facturation_invoices WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $invoiceId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($inv === false) {
            return ['status' => 'error', 'code' => 404, 'message' => 'Facture introuvable.', 'data' => null];
        }

        if (!$this->canAccessInvoice($inv, $ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé à cette facture.', 'data' => null];
        }

        $filename = basename((string) $inv['pdf_filename']);
        $pdfPath = InvoiceService::getStorageDir() . '/' . $filename;
        if (!is_file($pdfPath)) {
            return ['status' => 'error', 'code' => 404, 'message' => 'Fichier PDF introuvable sur le serveur.', 'data' => null];
        }

        $bytes = (string) file_get_contents($pdfPath);
        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'PDF de facture récupéré.',
            'data'    => [
                'invoice_number' => (string) $inv['invoice_number'],
                'filename'       => $filename,
                'mime_type'      => 'application/pdf',
                'size_bytes'     => strlen($bytes),
                'pdf_base64'     => base64_encode($bytes),
            ],
        ];
    }

    /**
     * Renvoie par e-mail une facture officielle avec sa pièce jointe PDF (gestionnaires uniquement).
     *
     * @param int                  $invoiceId Identifiant de la facture.
     * @param array<string, mixed> $ctx       Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function resendInvoiceEmail(int $invoiceId, array $ctx): array
    {
        if (!$this->canManageInvoices($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droit finances.manage requis.', 'data' => null];
        }

        try {
            $res = $this->service->sendInvoiceEmail($invoiceId);
            if ($res['success']) {
                return [
                    'status'  => 'success',
                    'code'    => 200,
                    'message' => 'Facture expédiée par e-mail avec pièce jointe PDF.',
                    'data'    => $res,
                ];
            }
            return [
                'status'  => 'error',
                'code'    => 502,
                'message' => 'Échec d\'envoi SMTP : ' . $res['smtp_response'],
                'data'    => $res,
            ];
        } catch (RuntimeException $e) {
            return ['status' => 'error', 'code' => 404, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Retourne la configuration du template PDF spécifique et du template d'e-mail 'invoice_issued'.
     *
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function getTemplates(array $ctx): array
    {
        if (!$this->canManageInvoices($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droit finances.manage requis.', 'data' => null];
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Templates de facturation récupérés.',
            'data'    => [
                'pdf_template'   => $this->service->getPdfTemplateSettings(),
                'email_template' => $this->service->getEmailTemplate(),
            ],
        ];
    }

    /**
     * Met à jour les paramètres du template PDF et/ou du template d'e-mail dédié 'invoice_issued'.
     *
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return array<string, mixed> Réponse JSend.
     */
    public function updateTemplates(array $ctx): array
    {
        if (!$this->canManageInvoices($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droit finances.manage requis.', 'data' => null];
        }

        $payload = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];

        if (isset($payload['pdf_template']) && is_array($payload['pdf_template'])) {
            $this->service->savePdfTemplateSettings($payload['pdf_template']);
        }

        if (isset($payload['email_template']) && is_array($payload['email_template'])) {
            $subj = trim((string) ($payload['email_template']['subject'] ?? ''));
            $body = trim((string) ($payload['email_template']['body_html'] ?? ''));
            if ($subj === '' || $body === '') {
                return ['status' => 'error', 'code' => 422, 'message' => 'Sujet et corps HTML du template e-mail obligatoires.', 'data' => null];
            }
            $this->service->saveEmailTemplate($subj, $body);
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Templates PDF et e-mail enregistrés avec succès.',
            'data'    => [
                'pdf_template'   => $this->service->getPdfTemplateSettings(),
                'email_template' => $this->service->getEmailTemplate(),
            ],
        ];
    }

    /**
     * Vérifie si l'utilisateur peut accéder à une facture donnée.
     *
     * @param array<string, mixed> $inv Ligne mod_facturation_invoices.
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return bool
     */
    private function canAccessInvoice(array $inv, array $ctx): bool
    {
        if ($this->canManageInvoices($ctx)) {
            return true;
        }
        $userId = (int) ($ctx['user_id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }
        if (!empty($inv['user_id']) && (int) $inv['user_id'] === $userId) {
            return true;
        }
        $uEmail = $this->resolveUserEmail($userId);
        return $uEmail !== '' && strtolower((string) ($inv['recipient_email'] ?? '')) === $uEmail;
    }

    /**
     * Retourne l'adresse e-mail en minuscules de l'utilisateur connecté.
     *
     * @param int $userId Identifiant users.id.
     * @return string
     */
    private function resolveUserEmail(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        $stmt = $this->db->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        return strtolower(trim((string) ($stmt->fetchColumn() ?: '')));
    }
}
