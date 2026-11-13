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

try {
    $expectedNext = null;
    foreach (array_slice($argv ?? [], 1) as $argument) {
        if (str_starts_with($argument, '--expect-next=')) {
            $expectedNext = substr($argument, strlen('--expect-next='));
        }
    }
    $result = ggs_smartbill_check_configuration($expectedNext);
    $matchingSeries = $result['series'];
    $matchingTax = $result['tax'];

    echo 'PASS: SmartBill credentials and read-only configuration are valid.' . PHP_EOL;
    echo 'Invoice series: ' . $matchingSeries['name'] . '; next number: ' . ($matchingSeries['nextNumber'] ?? 'unknown') . PHP_EOL;
    echo $matchingTax
        ? 'Tax: ' . $matchingTax['name'] . '; percentage: ' . $matchingTax['percentage'] . PHP_EOL
        : 'Tax: not applicable; company is not VAT registered.' . PHP_EOL;
    echo 'No invoice or other document was created.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
