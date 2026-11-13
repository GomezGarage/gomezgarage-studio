<?php
/** Pure SmartBill mapping tests. No config.php, database or network. */
define('SMARTBILL_ENABLED', true);
define('SMARTBILL_API_BASE', 'https://synthetic.invalid/api');
define('SMARTBILL_EMAIL', 'fixture@example.invalid');
define('SMARTBILL_API_TOKEN', 'fixture-token');
define('SMARTBILL_COMPANY_VAT_CODE', '24884753');
define('INVOICE_SERIES', 'GGS3D');
define('SMARTBILL_INVOICE_SERIES', 'GGS3D');
define('SMARTBILL_MEASURING_UNIT', 'buc');
define('SMARTBILL_USE_PRODUCT_CODES', true);
define('SMARTBILL_TAX_NAME', 'Neplatitor TVA');
define('SMARTBILL_TAX_PERCENTAGE', 0.0);
define('VAT_REGISTERED', false);
require __DIR__ . '/../includes/smartbill.php';

$checks = 0;
function sbCheck(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function fixtureOrder(string $country, string $paidCurrency = 'EUR'): array {
    return [
        'order_number' => 'GGS-2026-0001', 'payment_method' => 'card',
        'payment_currency' => $paidCurrency, 'customer_type' => 'pf',
        'customer_name' => 'Client Sintetic', 'customer_email' => 'client@example.invalid',
        'customer_phone' => '+40000000000',
        'shipping_address' => json_encode(['street' => 'Str. Test 1', 'city' => 'Test', 'county' => $country === 'RO' ? 'Ilfov' : 'Test region', 'postal' => '000000', 'country' => $country]),
        'items' => json_encode([['product_id' => 1, 'sku' => 'GGS-TEST-001', 'name' => 'Produs sintetic', 'price_eur' => 10.00, 'qty' => 2]]),
        'shipping_eur' => 5.00, 'total_eur' => 25.00,
    ];
}

$ro = ggs_smartbill_build_invoice(fixtureOrder('RO'), 5.10);
sbCheck($ro['currency'] === 'RON' && $ro['total'] === 127.50, 'Romanian invoice uses RON');
sbCheck($ro['payload']['products'][0]['price'] === 51.0, 'RON line converted once');
sbCheck($ro['payload']['payment']['type'] === 'Card online' && $ro['payload']['payment']['value'] === 127.50, 'online card receipt recorded');
sbCheck($ro['payload']['issueDate'] === $ro['payload']['paymentDate'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ro['payload']['issueDate']) === 1, 'Romanian fiscal date explicit');
sbCheck($ro['payload']['useStock'] === false && $ro['payload']['products'][0]['saveToDb'] === false, 'shop remains stock authority');
sbCheck(!isset($ro['payload']['products'][0]['taxPercentage'], $ro['payload']['products'][0]['taxName'], $ro['payload']['products'][0]['isTaxIncluded']), 'non-VAT issuer omits product tax fields');

$de = ggs_smartbill_build_invoice(fixtureOrder('DE'), 5.10);
sbCheck($de['currency'] === 'EUR' && $de['total'] === 25.0, 'foreign EUR invoice keeps EUR');
sbCheck($de['payload']['language'] === 'EN' && $de['payload']['client']['country'] === 'Germany', 'foreign client mapping');
sbCheck($ro['payload']['client']['country'] === 'Romania', 'SmartBill country name mapping');

$company = fixtureOrder('RO');
$company['customer_type'] = 'pj';
$company['company_name'] = 'Client Sintetic SRL';
$company['company_cui'] = 'RO12345678';
$company['company_reg'] = 'J23/1/2026';
$company['company_address'] = 'Str. Fiscala 1';
$company['company_city'] = 'Balotesti';
$company['company_county'] = 'Ilfov';
$company['company_postal'] = '077015';
$company['company_country'] = 'RO';
$company['company_vat_payer'] = 1;
$companyInvoice = ggs_smartbill_build_invoice($company, 5.10);
sbCheck($companyInvoice['payload']['client']['vatCode'] === 'RO12345678', 'company VAT code mapped');
sbCheck($companyInvoice['payload']['client']['city'] === 'Balotesti' && $companyInvoice['payload']['client']['county'] === 'Ilfov', 'company registered office mapped');
sbCheck($companyInvoice['payload']['client']['isTaxPayer'] === true, 'company VAT status mapped');

try {
    ggs_smartbill_assert_storefront_series('LL');
    sbCheck(false, 'manual LL series accepted for storefront');
} catch (InvalidArgumentException $e) {
    sbCheck(true, 'manual LL series rejected for storefront');
}

try {
    ggs_smartbill_assert_storefront_series('OTHER');
    sbCheck(false, 'mismatched storefront series accepted');
} catch (InvalidArgumentException $e) {
    sbCheck(true, 'mismatched storefront series rejected');
}

$configurationCalls = [];
$configuration = ggs_smartbill_check_configuration('1524', static function ($method, $url) use (&$configurationCalls) {
    $configurationCalls[] = [$method, $url];
    if (str_contains($url, '/series?')) {
        return ['status' => 200, 'body' => json_encode(['list' => [
            ['name' => 'LL', 'nextNumber' => '438'],
            ['name' => 'GGS3D', 'nextNumber' => '1524'],
        ]])];
    }
    return ['status' => 404, 'body' => '{}'];
});
sbCheck($configuration['series']['name'] === 'GGS3D' && $configuration['series']['nextNumber'] === '1524', 'read-only check finds storefront series');
sbCheck($configuration['tax'] === null, 'non-VAT issuer does not require a tax rate');
sbCheck(count($configurationCalls) === 1 && array_column($configurationCalls, 0) === ['GET'], 'non-VAT configuration check uses only the series GET request');

$issued = ggs_smartbill_issue($de['payload'], static function ($method, $url, $headers, $body) {
    sbCheck($method === 'POST' && str_ends_with($url, '/invoice/v2'), 'invoice endpoint');
    sbCheck(in_array('Accept: application/json', $headers, true), 'invoice requests JSON responses');
    sbCheck(str_contains((string) $body, 'GGS3D'), 'invoice payload encoded');
    return ['status' => 200, 'body' => json_encode(['errorText' => '', 'series' => 'GGS3D', 'number' => '1524', 'documentId' => 'fixture', 'documentViewUrl' => 'https://example.invalid/view'])];
});
sbCheck($issued['series'] === 'GGS3D' && $issued['number'] === '1524', 'SmartBill response parsed');
sbCheck(ggs_smartbill_safe_url('https://cloud.smartbill.ro/documente/extern/test') !== '', 'official SmartBill link accepted');
sbCheck(ggs_smartbill_safe_url('https://smartbill.ro.example.invalid/document') === '', 'lookalike SmartBill link rejected');

$pdfDestination = sys_get_temp_dir() . '/ggs-smartbill-' . bin2hex(random_bytes(4)) . '.pdf';
ggs_smartbill_download_pdf('GGS3D', '1524', $pdfDestination, static function ($method, $url, $headers) {
    sbCheck($method === 'GET' && str_contains($url, '/invoice/pdf?'), 'invoice PDF endpoint');
    sbCheck(in_array('Accept: application/octet-stream', $headers, true), 'invoice PDF requests supported binary response');
    return ['status' => 200, 'body' => "%PDF-1.4\nsynthetic"];
});
sbCheck(is_file($pdfDestination), 'invoice PDF stored atomically');
@unlink($pdfDestination);

try {
    $bad = fixtureOrder('RO');
    $bad['items'] = json_encode([['name' => 'Bad', 'price_eur' => 1, 'qty' => 0]]);
    ggs_smartbill_build_invoice($bad, 5.1);
    sbCheck(false, 'zero quantity accepted');
} catch (InvalidArgumentException $e) {
    sbCheck(true, 'zero quantity rejected locally');
}

try {
    ggs_smartbill_issue($de['payload'], static fn() => ['status' => 422, 'body' => json_encode(['errorText' => 'Serie invalida'])]);
    sbCheck(false, 'API error accepted');
} catch (GgsSmartBillException $e) {
    sbCheck(!$e->isUncertain(), 'business error is safe failure');
}

echo "PASS: {$checks} SmartBill invoice mapping checks. No config, database or network loaded.\n";
