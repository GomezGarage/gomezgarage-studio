<?php
/**
 * PDF fiscal invoice generation (setasign/fpdf).
 *
 * Lancer Land SRL is not VAT-registered, so invoices carry the
 * "TVA neaplicabil" note under art. 310 Cod Fiscal instead of itemized
 * VAT. All line amounts are converted from the canonical EUR bookkeeping
 * figures to RON at generation time — Romanian fiscal invoices are issued
 * in RON regardless of what currency the customer paid in.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/helpers.php';

/** FPDF's core fonts expect Latin-1; transliterate UTF-8 input to avoid garbled diacritics. */
function ggs_pdf_text(string $text): string
{
  $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
  return $converted !== false ? $converted : $text;
}

/** Extracts the numeric sequence from an invoice number like "GGS3D-1524" -> "1524". */
function ggs_invoice_sequence(string $invoiceNumber): string
{
  $parts = explode('-', $invoiceNumber);
  return (string) end($parts);
}

/**
 * Renders a fiscal invoice PDF for a paid order and saves it to
 * invoices/{invoice_number}.pdf, creating the directory if needed.
 * $order must already have `invoice_number` set.
 * Returns the absolute path to the saved file.
 */
function ggs_generate_invoice_pdf(array $order): string
{
  $items = json_decode($order['items'], true) ?: [];
  $shippingAddress = json_decode($order['shipping_address'], true) ?: [];
  $rate = (float) ggs_get_setting('eur_ron_rate', '5.00');
  $isCompany = ($order['customer_type'] ?? 'pf') === 'pj';

  $pdf = new FPDF('P', 'mm', 'A4');
  $pdf->SetTitle('Factura ' . $order['invoice_number']);
  $pdf->SetMargins(10, 10, 10);
  $pdf->SetAutoPageBreak(true, 25);
  $pdf->AddPage();
  $pdf->SetTextColor(20, 20, 20);

  // ---- Header: company details (left column) ----
  $pdf->SetXY(10, 10);
  $pdf->SetFont('Arial', 'B', 14);
  $pdf->Cell(100, 7, ggs_pdf_text(COMPANY_NAME), 0, 1);

  $pdf->SetFont('Arial', '', 9);
  $pdf->SetX(10);
  $pdf->MultiCell(95, 5, ggs_pdf_text(COMPANY_ADDRESS));
  $pdf->SetX(10);
  $pdf->Cell(95, 5, ggs_pdf_text('CUI: ' . COMPANY_CUI), 0, 1);
  $pdf->SetX(10);
  $pdf->Cell(95, 5, ggs_pdf_text('EUID: ' . COMPANY_EUID), 0, 1);
  $pdf->SetX(10);
  $pdf->Cell(95, 5, ggs_pdf_text('Reg. Com: ' . COMPANY_REG_COM), 0, 1);
  $pdf->SetX(10);
  $pdf->Cell(95, 5, ggs_pdf_text('Banca: ' . COMPANY_BANK), 0, 1);
  $pdf->SetX(10);
  $pdf->Cell(95, 5, ggs_pdf_text('IBAN: ' . COMPANY_IBAN), 0, 1);
  if (!VAT_REGISTERED) {
    $pdf->SetX(10);
    $pdf->Cell(95, 5, ggs_pdf_text('Neplatitor de TVA'), 0, 1);
  }

  // ---- Header: invoice meta (right column) ----
  $pdf->SetXY(120, 10);
  $pdf->SetFont('Arial', 'B', 16);
  $pdf->Cell(80, 8, ggs_pdf_text('FACTURA FISCALA'), 0, 2, 'R');

  $pdf->SetFont('Arial', '', 10);
  $pdf->SetX(120);
  $pdf->Cell(80, 6, ggs_pdf_text('Seria ' . INVOICE_SERIES . ' Nr. ' . ggs_invoice_sequence($order['invoice_number'])), 0, 2, 'R');
  $pdf->SetX(120);
  $pdf->Cell(80, 6, 'Data: ' . date('d.m.Y'), 0, 2, 'R');
  $pdf->SetX(120);
  $pdf->Cell(80, 6, ggs_pdf_text('Nr. comanda: ' . $order['order_number']), 0, 2, 'R');

  // ---- Client section ----
  $addressLine = implode(', ', array_filter([
    $shippingAddress['street'] ?? '',
    $shippingAddress['city'] ?? '',
    $shippingAddress['postal'] ?? '',
    $shippingAddress['country'] ?? '',
  ]));

  if ($isCompany) {
    $clientLine = sprintf(
      'Cumparator: %s, CUI: %s, Reg. Com: %s, %s',
      $order['company_name'] ?? '',
      $order['company_cui'] ?? '',
      $order['company_reg'] ?? '',
      $order['company_address'] ?: $addressLine
    );
  } else {
    $clientLine = sprintf('Cumparator: %s, %s', $order['customer_name'], $addressLine);
  }

  $pdf->SetY(72);
  $pdf->SetX(10);
  $pdf->SetFont('Arial', '', 10);
  $pdf->MultiCell(190, 6, ggs_pdf_text($clientLine));
  $pdf->Ln(4);

  // ---- Line items table ----
  // Nr. crt | Denumire | Cantitate | Pret unitar RON | Total RON
  $colWidths = [15, 95, 25, 27, 28];
  $headers = ['Nr. crt', 'Denumire', 'Cantitate', 'Pret unitar RON', 'Total RON'];

  $pdf->SetFont('Arial', 'B', 9);
  $pdf->SetFillColor(230, 230, 230);
  $pdf->SetX(10);
  foreach ($headers as $i => $header) {
    $pdf->Cell($colWidths[$i], 7, ggs_pdf_text($header), 1, 0, 'C', true);
  }
  $pdf->Ln();

  $pdf->SetFont('Arial', '', 9);
  $rowNum = 1;
  $subtotalRon = 0.0;

  foreach ($items as $item) {
    $unitPriceRon = (float) $item['price_eur'] * $rate;
    $qty = (int) $item['qty'];
    $lineTotalRon = $unitPriceRon * $qty;
    $subtotalRon += $lineTotalRon;

    $pdf->SetX(10);
    $pdf->Cell($colWidths[0], 7, (string) $rowNum, 1, 0, 'C');
    $pdf->Cell($colWidths[1], 7, ggs_pdf_text($item['name']), 1, 0, 'L');
    $pdf->Cell($colWidths[2], 7, (string) $qty, 1, 0, 'C');
    $pdf->Cell($colWidths[3], 7, number_format($unitPriceRon, 2), 1, 0, 'R');
    $pdf->Cell($colWidths[4], 7, number_format($lineTotalRon, 2), 1, 1, 'R');
    $rowNum++;
  }

  // Shipping as its own line item.
  $shippingRon = (float) $order['shipping_eur'] * $rate;
  $pdf->SetX(10);
  $pdf->Cell($colWidths[0], 7, (string) $rowNum, 1, 0, 'C');
  $pdf->Cell($colWidths[1], 7, ggs_pdf_text('Livrare'), 1, 0, 'L');
  $pdf->Cell($colWidths[2], 7, '1', 1, 0, 'C');
  $pdf->Cell($colWidths[3], 7, number_format($shippingRon, 2), 1, 0, 'R');
  $pdf->Cell($colWidths[4], 7, number_format($shippingRon, 2), 1, 1, 'R');

  $totalRon = $subtotalRon + $shippingRon;

  // TOTAL row, bold.
  $pdf->SetFont('Arial', 'B', 9);
  $pdf->SetX(10);
  $labelWidth = $colWidths[0] + $colWidths[1] + $colWidths[2] + $colWidths[3];
  $pdf->Cell($labelWidth, 8, ggs_pdf_text('TOTAL'), 1, 0, 'R');
  $pdf->Cell($colWidths[4], 8, number_format($totalRon, 2) . ' RON', 1, 1, 'R');

  $pdf->SetFont('Arial', 'I', 8);
  $pdf->SetX(10);
  $pdf->Ln(2);
  $pdf->Cell(0, 5, ggs_pdf_text('TVA neaplicabil - Neplatitor de TVA conform art. 310 Cod Fiscal'), 0, 1);

  // ---- Footer ----
  // Auto page break must be off here: its trigger margin (25mm) exactly
  // coincides with this footer's position (also 25mm from the bottom),
  // which otherwise pushes this line onto a spurious second page.
  $pdf->SetAutoPageBreak(false);
  $pdf->SetY(-25);
  $pdf->SetFont('Arial', 'I', 8);
  $pdf->SetX(10);
  $pdf->MultiCell(190, 5, ggs_pdf_text('Factura reprezinta document fiscal valabil fara semnatura si stampila conform legii'), 0, 'C');

  $invoicesDir = __DIR__ . '/../invoices';
  if (!is_dir($invoicesDir)) {
    mkdir($invoicesDir, 0755, true);
  }

  $path = $invoicesDir . '/' . $order['invoice_number'] . '.pdf';
  $pdf->Output('F', $path);

  return $path;
}
