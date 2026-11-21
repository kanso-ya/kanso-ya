<?php

declare(strict_types=1);

namespace Modules\FacturationApi\Services;

/**
 * Générateur PDF 1.4 natif en Vanilla PHP 8.5 (format A4, zéro dépendance externe).
 * Produit un document PDF binaire conforme RFC/ISO avec polices Type1 Helvetica / Helvetica-Bold
 * encodées en WinAnsiEncoding (support complet des caractères accentués français).
 */
final class NativePdfBuilder
{
    /**
     * Génère le contenu binaire d'une facture PDF officielle A4.
     *
     * @param array{
     *     invoice_number: string,
     *     issued_at: string,
     *     source_type: string,
     *     recipient_name: string,
     *     recipient_email: string,
     *     recipient_address?: string,
     *     items: list<array{label: string, qty: int, unit_price: float, total: float}>,
     *     subtotal_amount: float,
     *     discount_amount: float,
     *     total_amount: float,
     *     payment_method?: string
     * } $invoice  Données structurées de la facture.
     * @param array<string, string> $template Paramètres du template PDF spécifique.
     * @return string Flux binaire PDF (%PDF-1.4 ... %%EOF).
     */
    public function buildInvoicePdf(array $invoice, array $template): string
    {
        $orgName = $template['org_name'] ?? 'Kanso-ya Association';
        $orgLegal = $template['org_legal_id'] ?? 'Association Loi 1901';
        $orgAddress = $template['org_address'] ?? '';
        $orgEmail = $template['org_email'] ?? 'secretariat@kanso-ya.fr';
        $headerTitle = $template['header_title'] ?? 'FACTURE OFFICIELLE';
        $footerMentions = $template['footer_mentions'] ?? 'Association exonérée de TVA (Art. 261-7-1° du CGI). Facture acquittée.';

        $ops = [];

        // Bandeau d'en-tête sobre
        $ops[] = '0.06 0.09 0.16 rg';
        $ops[] = '40 740 515 70 re f';

        // Titre & Nom du club en blanc
        $ops[] = '1 1 1 rg';
        $ops[] = $this->textOp('F2', 16, 56, 785, $orgName);
        $ops[] = $this->textOp('F1', 9, 56, 768, $orgLegal);
        if ($orgAddress !== '') {
            $ops[] = $this->textOp('F1', 8.5, 56, 754, $orgAddress . ' — ' . $orgEmail);
        } else {
            $ops[] = $this->textOp('F1', 8.5, 56, 754, $orgEmail);
        }

        // Badge numéro de facture à droite du bandeau
        $ops[] = $this->textOp('F2', 13, 375, 785, $headerTitle);
        $ops[] = $this->textOp('F2', 10, 375, 768, 'N° ' . $invoice['invoice_number']);
        $ops[] = $this->textOp('F1', 8.5, 375, 754, 'Date : ' . substr($invoice['issued_at'], 0, 10));

        // Couleur texte principal gris foncé
        $ops[] = '0.1 0.12 0.16 rg';

        // Encadré Destinataire & Référence
        $ops[] = '0.96 0.97 0.98 rg';
        $ops[] = '40 648 515 76 re f';
        $ops[] = '0.85 0.88 0.91 RG 0.7 w 40 648 515 76 re S';

        $ops[] = '0.1 0.12 0.16 rg';
        $ops[] = $this->textOp('F2', 9.5, 56, 706, 'DESTINATAIRE / PAYEUR');
        $ops[] = $this->textOp('F2', 11, 56, 688, $invoice['recipient_name']);
        $ops[] = $this->textOp('F1', 9, 56, 673, $invoice['recipient_email']);
        if (!empty($invoice['recipient_address'])) {
            $ops[] = $this->textOp('F1', 8.5, 56, 659, mb_substr($invoice['recipient_address'], 0, 70));
        }

        $sourceLabels = [
            'cotisation' => 'Cotisation / Adhésion Club',
            'boutique'   => 'Commande Boutique Club',
            'autre'      => 'Prestation Associative',
        ];
        $sourceText = $sourceLabels[$invoice['source_type']] ?? ucfirst($invoice['source_type']);
        $ops[] = $this->textOp('F2', 9.5, 340, 706, 'ORIGINE & RÈGLEMENT');
        $ops[] = $this->textOp('F1', 9.5, 340, 688, 'Objet : ' . $sourceText);
        $payMethod = !empty($invoice['payment_method']) ? strtoupper((string) $invoice['payment_method']) : 'HORS-LIGNE';
        $ops[] = $this->textOp('F1', 9.5, 340, 673, 'Mode : ' . $payMethod);

        // En-tête du tableau des lignes
        $tableTop = 620;
        $ops[] = '0.92 0.94 0.96 rg';
        $ops[] = sprintf('40 %d 515 24 re f', $tableTop - 24);
        $ops[] = '0.1 0.12 0.16 rg';
        $ops[] = $this->textOp('F2', 9, 50, $tableTop - 16, 'DÉSIGNATION');
        $ops[] = $this->textOp('F2', 9, 345, $tableTop - 16, 'QTÉ');
        $ops[] = $this->textOp('F2', 9, 400, $tableTop - 16, 'PRIX UNIT.');
        $ops[] = $this->textOp('F2', 9, 480, $tableTop - 16, 'TOTAL');

        // Lignes de facturation
        $y = $tableTop - 42;
        foreach ($invoice['items'] as $item) {
            if ($y < 160) {
                break;
            }
            $label = mb_substr((string) $item['label'], 0, 58);
            $qty = (int) $item['qty'];
            $unitPrice = number_format((float) $item['unit_price'], 2, ',', ' ') . ' EUR';
            $lineTotal = number_format((float) $item['total'], 2, ',', ' ') . ' EUR';

            $ops[] = $this->textOp('F1', 9.5, 50, $y, $label);
            $ops[] = $this->textOp('F1', 9.5, 350, $y, (string) $qty);
            $ops[] = $this->textOp('F1', 9.5, 400, $y, $unitPrice);
            $ops[] = $this->textOp('F2', 9.5, 478, $y, $lineTotal);

            // Séparateur fin
            $ops[] = '0.88 0.90 0.92 RG 0.5 w';
            $ops[] = sprintf('40 %d m 555 %d l S', $y - 7, $y - 7);
            $y -= 24;
        }

        // Bloc Totaux
        $totY = max(140, $y - 16);
        $ops[] = '0.1 0.12 0.16 rg';
        $ops[] = $this->textOp('F1', 9.5, 360, $totY, 'Sous-total :');
        $ops[] = $this->textOp('F1', 9.5, 478, $totY, number_format($invoice['subtotal_amount'], 2, ',', ' ') . ' EUR');

        if ($invoice['discount_amount'] > 0) {
            $totY -= 18;
            $ops[] = $this->textOp('F1', 9.5, 360, $totY, 'Remise :');
            $ops[] = $this->textOp('F1', 9.5, 478, $totY, '-' . number_format($invoice['discount_amount'], 2, ',', ' ') . ' EUR');
        }

        $totY -= 26;
        $ops[] = '0.02 0.59 0.41 rg';
        $ops[] = sprintf('350 %d 205 26 re f', $totY - 8);
        $ops[] = '1 1 1 rg';
        $ops[] = $this->textOp('F2', 11, 362, $totY, 'TOTAL NET :');
        $ops[] = $this->textOp('F2', 11, 472, $totY, number_format($invoice['total_amount'], 2, ',', ' ') . ' EUR');

        // Pied de page légal
        $ops[] = '0.82 0.85 0.88 RG 0.6 w 40 78 m 555 78 l S';
        $ops[] = '0.35 0.40 0.46 rg';
        $ops[] = $this->textOp('F1', 8, 40, 62, mb_substr($footerMentions, 0, 110));
        $ops[] = $this->textOp('F1', 7.5, 40, 48, 'Document généré officiellement par Kanso-ya — Référence : ' . $invoice['invoice_number']);

        $stream = implode("\n", $ops) . "\n";

        return $this->assemblePdfDocument($stream);
    }

    /**
     * Génère une instruction PDF de tracé de texte (BT ... ET) avec échappement WinAnsi.
     *
     * @param string $font Font PDF ('F1' ou 'F2').
     * @param float  $size Taille en points.
     * @param float  $x    Abscisse (pt).
     * @param float  $y    Ordonnée (pt).
     * @param string $text Texte UTF-8.
     * @return string
     */
    private function textOp(string $font, float $size, float $x, float $y, string $text): string
    {
        $encoded = $this->encodePdfText($text);
        return sprintf('BT /%s %.1f Tf %.1f %.1f Td (%s) Tj ET', $font, $size, $x, $y, $encoded);
    }

    /**
     * Convertit UTF-8 vers WinAnsiEncoding (ISO-8859-1) et échappe les parenthèses/antislashs PDF.
     *
     * @param string $text Texte UTF-8.
     * @return string Texte échappé pour chaîne littérale PDF.
     */
    private function encodePdfText(string $text): string
    {
        $clean = str_replace(["\r", "\n", '€'], [' ', ' ', 'EUR'], $text);
        $winAnsi = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $clean);
        $bytes = is_string($winAnsi) ? $winAnsi : preg_replace('/[^\x20-\x7E]/', ' ', $clean) ?? '';
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $bytes);
    }

    /**
     * Assemble les 6 objets PDF 1.4 (Catalog, Pages, Page, Font Helvetica, Font Helvetica-Bold, Stream)
     * avec table xref exacte et trailer.
     *
     * @param string $contentStream Flux d'opérateurs graphiques de la page A4.
     * @return string Document PDF binaire complet.
     */
    private function assemblePdfDocument(string $contentStream): string
    {
        $objects = [
            1 => "<< /Type /Catalog /Pages 2 0 R >>",
            2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>",
            4 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
            5 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>",
            6 => sprintf("<< /Length %d >>\nstream\n%sendstream", strlen($contentStream), $contentStream),
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $body);
        }

        $xrefOffset = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n", count($objects) + 1);
        $pdf .= "0000000000 65535 f \n";
        foreach ($objects as $id => $_) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            count($objects) + 1,
            $xrefOffset
        );

        return $pdf;
    }
}
