<?php
/**
 * Read-only SmartBill configuration check.
 *
 * Lists the configured invoice series and zero-VAT rates, then verifies that
 * the storefront values in ignored config.php exist. It never creates,
 * changes or deletes a SmartBill document.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/smartbill.php';

foreach (['SMARTBILL_API_BASE', 'SMARTBILL_EMAIL', 'SMARTBILL_API_TOKEN', 'SMARTBILL_COMPANY_VAT_CODE', 'SMARTBILL_INVOICE_SERIES'] as $constant) {
    if (!defined($constant) || trim((string) constant($constant)) === '' || str_starts_with((string) constant($constant), 'REPLACE_WITH_')) {
        fwrite(STDERR, "Missing or placeholder configuration: {$constant}\n");
        exit(2);
    }
}

ggs_smartbill_assert_storefront_series((string) SMARTBILL_INVOICE_SERIES);

function smartbillConfigJson(string $path, array $query): array
{
    $response = ggs_smartbill_request('GET', $path, $query);
    $data = json_decode($response['body'], true);
    if ($response['status'] !== 200 || !is_array($data) || trim((string) ($data['errorText'] ?? '')) !== '') {
        $message = is_array($data) ? trim((string) ($data['errorText'] ?? '')) : 'invalid JSON response';
        throw new RuntimeException("SmartBill {$path} check failed (HTTP {$response['status']}): {$message}");
    }
    return $data;
}

try {
    $query = ['cif' => (string) SMARTBILL_COMPANY_VAT_CODE];
    $series = smartbillConfigJson('/series', $query + ['type' => 'f']);
    $taxes = smartbillConfigJson('/tax', $query);

    $seriesList = is_array($series['list'] ?? null) ? $series['list'] : (array_is_list($series) ? $series : []);
    $taxList = is_array($taxes['taxes'] ?? null)
        ? $taxes['taxes']
        : (is_array($taxes['list'] ?? null) ? $taxes['list'] : (array_is_list($taxes) ? $taxes : []));
    $matchingSeries = null;
    foreach ($seriesList as $item) {
        if (is_array($item) && strcasecmp((string) ($item['name'] ?? ''), (string) SMARTBILL_INVOICE_SERIES) === 0) {
            $matchingSeries = $item;
            break;
        }
    }
    $configuredTaxName = defined('SMARTBILL_TAX_NAME') ? trim((string) SMARTBILL_TAX_NAME) : '';
    $configuredTaxPercentage = defined('SMARTBILL_TAX_PERCENTAGE') ? (float) SMARTBILL_TAX_PERCENTAGE : 0.0;
    $matchingTax = null;
    if ($configuredTaxName !== '' && !str_starts_with($configuredTaxName, 'REPLACE_WITH_')) {
        foreach ($taxList as $item) {
            if (is_array($item) && strcasecmp((string) ($item['name'] ?? ''), $configuredTaxName) === 0 && (float) ($item['percentage'] ?? -1) === $configuredTaxPercentage) {
                $matchingTax = $item;
                break;
            }
        }
    }

    if ($matchingSeries === null) {
        throw new RuntimeException('Configured storefront invoice series was not found in SmartBill.');
    }
    if ($matchingTax === null) {
        $zeroTaxNames = [];
        foreach ($taxList as $item) {
            if (is_array($item) && (float) ($item['percentage'] ?? -1) === 0.0 && trim((string) ($item['name'] ?? '')) !== '') {
                $zeroTaxNames[] = trim((string) $item['name']);
            }
        }
        $available = $zeroTaxNames ? implode(', ', array_unique($zeroTaxNames)) : 'none reported';
        throw new RuntimeException(
            'Configure SMARTBILL_TAX_NAME with the exact 0% rate name. Available 0% names: ' . $available
        );
    }

    $expectedNext = null;
    foreach (array_slice($argv ?? [], 1) as $argument) {
        if (str_starts_with($argument, '--expect-next=')) {
            $expectedNext = substr($argument, strlen('--expect-next='));
        }
    }
    if ($expectedNext !== null && (!ctype_digit($expectedNext) || (string) ($matchingSeries['nextNumber'] ?? '') !== $expectedNext)) {
        throw new RuntimeException(
            'Configured series next number is ' . ($matchingSeries['nextNumber'] ?? 'unknown') .
            '; expected ' . $expectedNext . '. Do not enable automatic invoicing.'
        );
    }

    echo 'PASS: SmartBill credentials and read-only configuration are valid.' . PHP_EOL;
    echo 'Invoice series: ' . $matchingSeries['name'] . '; next number: ' . ($matchingSeries['nextNumber'] ?? 'unknown') . PHP_EOL;
    echo 'Tax: ' . $matchingTax['name'] . '; percentage: ' . $matchingTax['percentage'] . PHP_EOL;
    echo 'No invoice or other document was created.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
