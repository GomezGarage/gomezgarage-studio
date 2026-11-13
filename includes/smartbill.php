<?php
/**
 * SmartBill Cloud V1 invoice integration.
 *
 * No call is made unless SMARTBILL_ENABLED is explicitly true. The shop remains
 * the source of truth for orders and stock; SmartBill receives fiscal documents
 * with useStock=false and returns the authoritative invoice series/number.
 */

final class GgsSmartBillException extends RuntimeException
{
    private bool $uncertain;

    public function __construct(string $message, bool $uncertain = false)
    {
        parent::__construct($message);
        $this->uncertain = $uncertain;
    }

    public function isUncertain(): bool
    {
        return $this->uncertain;
    }
}

function ggs_smartbill_enabled(): bool
{
    return defined('SMARTBILL_ENABLED') && SMARTBILL_ENABLED === true;
}

function ggs_smartbill_assert_storefront_series(string $series): void
{
    $series = trim($series);
    if ($series === '') {
        throw new InvalidArgumentException('Configure the SmartBill storefront invoice series.');
    }
    if (strcasecmp($series, 'LL') === 0) {
        throw new InvalidArgumentException('The manual LL invoice series cannot be used by the storefront.');
    }
    if (defined('INVOICE_SERIES') && strcasecmp($series, trim((string) INVOICE_SERIES)) !== 0) {
        throw new InvalidArgumentException('The SmartBill series must match the storefront invoice series.');
    }
}

/**
 * Validate the protected SmartBill credentials and fiscal configuration using
 * read-only API calls. No document is created, changed or deleted.
 *
 * @return array{series:array,tax:array}
 */
function ggs_smartbill_check_configuration(?string $expectedNext = null, ?callable $transport = null): array
{
    foreach (['SMARTBILL_API_BASE', 'SMARTBILL_EMAIL', 'SMARTBILL_API_TOKEN', 'SMARTBILL_COMPANY_VAT_CODE', 'SMARTBILL_INVOICE_SERIES'] as $constant) {
        $value = defined($constant) ? trim((string) constant($constant)) : '';
        if ($value === '' || str_starts_with($value, 'REPLACE_WITH_')) {
            throw new RuntimeException("Missing or placeholder configuration: {$constant}");
        }
    }

    ggs_smartbill_assert_storefront_series((string) SMARTBILL_INVOICE_SERIES);

    $fetchJson = static function (string $path, array $query) use ($transport): array {
        $response = ggs_smartbill_request('GET', $path, $query, null, $transport);
        $data = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($data) || trim((string) ($data['errorText'] ?? '')) !== '') {
            $message = is_array($data) ? trim((string) ($data['errorText'] ?? '')) : 'invalid JSON response';
            throw new RuntimeException("SmartBill {$path} check failed (HTTP {$response['status']}): {$message}");
        }
        return $data;
    };

    $query = ['cif' => (string) SMARTBILL_COMPANY_VAT_CODE];
    $seriesResponse = $fetchJson('/series', $query + ['type' => 'f']);
    $taxResponse = $fetchJson('/tax', $query);
    $seriesList = is_array($seriesResponse['list'] ?? null)
        ? $seriesResponse['list']
        : (array_is_list($seriesResponse) ? $seriesResponse : []);
    $taxList = is_array($taxResponse['taxes'] ?? null)
        ? $taxResponse['taxes']
        : (is_array($taxResponse['list'] ?? null) ? $taxResponse['list'] : (array_is_list($taxResponse) ? $taxResponse : []));

    $matchingSeries = null;
    foreach ($seriesList as $item) {
        if (is_array($item) && strcasecmp((string) ($item['name'] ?? ''), (string) SMARTBILL_INVOICE_SERIES) === 0) {
            $matchingSeries = $item;
            break;
        }
    }
    if ($matchingSeries === null) {
        throw new RuntimeException('Configured storefront invoice series was not found in SmartBill.');
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
    if ($matchingTax === null) {
        $zeroTaxNames = [];
        foreach ($taxList as $item) {
            if (is_array($item) && (float) ($item['percentage'] ?? -1) === 0.0 && trim((string) ($item['name'] ?? '')) !== '') {
                $zeroTaxNames[] = trim((string) $item['name']);
            }
        }
        $available = $zeroTaxNames ? implode(', ', array_unique($zeroTaxNames)) : 'none reported';
        throw new RuntimeException('Configure SMARTBILL_TAX_NAME with the exact 0% rate name. Available 0% names: ' . $available);
    }

    if ($expectedNext !== null && (!ctype_digit($expectedNext) || (string) ($matchingSeries['nextNumber'] ?? '') !== $expectedNext)) {
        throw new RuntimeException(
            'Configured series next number is ' . ($matchingSeries['nextNumber'] ?? 'unknown') .
            '; expected ' . $expectedNext . '. Do not enable automatic invoicing.'
        );
    }

    return ['series' => $matchingSeries, 'tax' => $matchingTax];
}

function ggs_smartbill_country_name(string $countryCode): string
{
    $countries = [
        'AT' => 'Austria', 'BE' => 'Belgium', 'BG' => 'Bulgaria', 'HR' => 'Croatia',
        'CY' => 'Cyprus', 'CZ' => 'Czech Republic', 'DK' => 'Denmark', 'EE' => 'Estonia',
        'FI' => 'Finland', 'FR' => 'France', 'DE' => 'Germany', 'GR' => 'Greece',
        'HU' => 'Hungary', 'IE' => 'Ireland', 'IT' => 'Italy', 'LV' => 'Latvia',
        'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MT' => 'Malta', 'NL' => 'Netherlands',
        'PL' => 'Poland', 'PT' => 'Portugal', 'RO' => 'Romania', 'SK' => 'Slovakia',
        'SI' => 'Slovenia', 'ES' => 'Spain', 'SE' => 'Sweden', 'GB' => 'United Kingdom',
    ];
    $countryCode = strtoupper(trim($countryCode));
    if (!isset($countries[$countryCode])) {
        throw new InvalidArgumentException('The invoice country is not supported by the storefront.');
    }
    return $countries[$countryCode];
}

function ggs_smartbill_issue_date(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d');
}

/** @return array{currency:string, exchange_rate:float, total:float, payload:array} */
function ggs_smartbill_build_invoice(array $order, float $eurRonRate): array
{
    if ($eurRonRate <= 0) {
        throw new InvalidArgumentException('The EUR/RON exchange rate must be positive.');
    }
    ggs_smartbill_assert_storefront_series((string) SMARTBILL_INVOICE_SERIES);

    $address = json_decode((string) ($order['shipping_address'] ?? ''), true);
    $items = json_decode((string) ($order['items'] ?? ''), true);
    if (!is_array($address) || !is_array($items) || $items === []) {
        throw new InvalidArgumentException('The order has no valid address or invoice items.');
    }

    $country = strtoupper(substr((string) ($address['country'] ?? ''), 0, 2));
    if ($country === '') {
        throw new InvalidArgumentException('The invoice country is missing.');
    }

    // SmartBill documents Romanian customers in RON. Foreign invoices follow
    // the actual payment currency when it is supported by this storefront.
    $paidCurrency = strtoupper((string) ($order['payment_currency'] ?? 'EUR'));
    $currency = $country === 'RO' ? 'RON' : (in_array($paidCurrency, ['RON', 'EUR'], true) ? $paidCurrency : 'EUR');
    $factor = $currency === 'RON' ? $eurRonRate : 1.0;

    $taxName = defined('SMARTBILL_TAX_NAME') ? trim((string) SMARTBILL_TAX_NAME) : '';
    if ($taxName === '' || str_starts_with($taxName, 'REPLACE_WITH_')) {
        throw new InvalidArgumentException('Configure the exact SmartBill zero-VAT rate name before issuing invoices.');
    }
    $taxPercentage = defined('SMARTBILL_TAX_PERCENTAGE') ? (float) SMARTBILL_TAX_PERCENTAGE : 0.0;
    $unit = defined('SMARTBILL_MEASURING_UNIT') ? trim((string) SMARTBILL_MEASURING_UNIT) : 'buc';

    $products = [];
    $total = 0.0;
    foreach ($items as $item) {
        $qty = (int) ($item['qty'] ?? 0);
        $priceEur = (float) ($item['price_eur'] ?? -1);
        $name = trim((string) ($item['name'] ?? ''));
        if ($qty <= 0 || $priceEur < 0 || $name === '') {
            throw new InvalidArgumentException('An invoice line has an invalid name, quantity or price.');
        }
        $price = round($priceEur * $factor, 2);
        $line = [
            'name' => $name,
            'quantity' => $qty,
            'price' => $price,
            'currency' => $currency,
            'measuringUnitName' => $unit,
            'taxName' => $taxName,
            'taxPercentage' => $taxPercentage,
            'isTaxIncluded' => true,
            'isService' => false,
            'saveToDb' => false,
        ];
        $sku = trim((string) ($item['sku'] ?? ''));
        if (defined('SMARTBILL_USE_PRODUCT_CODES') && SMARTBILL_USE_PRODUCT_CODES === true && $sku === '') {
            throw new InvalidArgumentException('Every invoiced product must have a SKU when SmartBill product codes are enabled.');
        }
        if ($sku !== '') {
            $line['code'] = $sku;
        }
        $products[] = $line;
        $total += $price * $qty;
    }

    $shippingEur = (float) ($order['shipping_eur'] ?? 0);
    if ($shippingEur < 0) {
        throw new InvalidArgumentException('Shipping cannot be negative.');
    }
    if ($shippingEur > 0) {
        $shipping = round($shippingEur * $factor, 2);
        $products[] = [
            'name' => 'Livrare',
            'code' => 'GGS-SHIPPING',
            'quantity' => 1,
            'price' => $shipping,
            'currency' => $currency,
            'measuringUnitName' => $unit,
            'taxName' => $taxName,
            'taxPercentage' => $taxPercentage,
            'isTaxIncluded' => true,
            'isService' => true,
            'saveToDb' => false,
        ];
        $total += $shipping;
    }
    $total = round($total, 2);

    $isCompany = ($order['customer_type'] ?? 'pf') === 'pj';
    $clientName = $isCompany
        ? trim((string) ($order['company_name'] ?? ''))
        : trim((string) ($order['customer_name'] ?? ''));
    if ($clientName === '') {
        throw new InvalidArgumentException('The invoice client name is missing.');
    }

    $street = trim((string) ($address['street'] ?? ''));
    $postal = trim((string) ($address['postal'] ?? ''));
    $clientCountryCode = $isCompany ? strtoupper(trim((string) ($order['company_country'] ?? ''))) : $country;
    $client = [
        'name' => $clientName,
        'address' => $isCompany ? trim((string) ($order['company_address'] ?? '')) : trim($street . ($postal !== '' ? ', ' . $postal : '')),
        'city' => $isCompany ? trim((string) ($order['company_city'] ?? '')) : trim((string) ($address['city'] ?? '')),
        'county' => $isCompany ? trim((string) ($order['company_county'] ?? '')) : trim((string) ($address['county'] ?? '')),
        'country' => ggs_smartbill_country_name($clientCountryCode),
        'isTaxPayer' => $isCompany ? !empty($order['company_vat_payer']) : false,
        'email' => trim((string) ($order['customer_email'] ?? '')),
        'phone' => trim((string) ($order['customer_phone'] ?? '')),
        'saveToDb' => false,
    ];
    if ($isCompany) {
        $client['vatCode'] = trim((string) ($order['company_cui'] ?? ''));
        if ($client['vatCode'] === '' || $client['address'] === '' || $client['city'] === '' || $client['county'] === '' || $client['country'] === '') {
            throw new InvalidArgumentException('Company fiscal code and registered office are required.');
        }
        $client['regCom'] = trim((string) ($order['company_reg'] ?? ''));
    }

    $payload = [
        'companyVatCode' => (string) SMARTBILL_COMPANY_VAT_CODE,
        'seriesName' => (string) SMARTBILL_INVOICE_SERIES,
        'issueDate' => ggs_smartbill_issue_date(),
        'language' => $country === 'RO' ? 'RO' : 'EN',
        'currency' => $currency,
        'precision' => 2,
        'useStock' => false,
        'isDraft' => false,
        'client' => $client,
        'products' => $products,
        'observations' => 'Comanda ' . (string) ($order['order_number'] ?? ''),
    ];
    if ($currency === 'RON') {
        $payload['exchangeRate'] = 1;
    }
    if (($order['payment_method'] ?? '') === 'card') {
        $payload['payment'] = [
            'value' => $total,
            'type' => 'Card online',
            'isCash' => false,
        ];
        $payload['paymentDate'] = ggs_smartbill_issue_date();
    }

    return [
        'currency' => $currency,
        'exchange_rate' => $currency === 'RON' ? $eurRonRate : 1.0,
        'total' => $total,
        'payload' => $payload,
    ];
}

/**
 * @param callable|null $transport Test seam. Receives method, URL, headers, body.
 * @return array{series:string,number:string,document_id:string,view_url:string,document_url:string}
 */
function ggs_smartbill_issue(array $payload, ?callable $transport = null): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $response = ggs_smartbill_request('POST', '/invoice/v2', [], $body, $transport);
    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        throw new GgsSmartBillException('SmartBill returned an invalid JSON response.', true);
    }
    $error = trim((string) ($data['errorText'] ?? ''));
    if ($response['status'] >= 500) {
        throw new GgsSmartBillException('SmartBill server error HTTP ' . $response['status'] . '.', true);
    }
    if ($response['status'] >= 400 || $error !== '') {
        $message = $error !== '' ? $error : ggs_smartbill_error_message($data, $response['status']);
        throw new GgsSmartBillException('SmartBill invoice rejected: ' . $message);
    }
    $series = trim((string) ($data['series'] ?? ''));
    $number = trim((string) ($data['number'] ?? ''));
    if ($series === '' || $number === '') {
        throw new GgsSmartBillException('SmartBill accepted the request without returning an invoice number.', true);
    }
    if (!preg_match('/^[A-Za-z0-9_-]{1,30}$/', $series) || !preg_match('/^[0-9]{1,20}$/', $number)) {
        throw new GgsSmartBillException('SmartBill returned an unsafe invoice identifier.', true);
    }
    return [
        'series' => $series,
        'number' => $number,
        'document_id' => (string) ($data['documentId'] ?? ''),
        'view_url' => ggs_smartbill_safe_url((string) ($data['documentViewUrl'] ?? '')),
        'document_url' => ggs_smartbill_safe_url((string) ($data['documentUrl'] ?? '')),
    ];
}

function ggs_smartbill_safe_url(string $url): string
{
    if ($url === '') return '';
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || ($host !== 'smartbill.ro' && !str_ends_with($host, '.smartbill.ro'))) {
        return '';
    }
    return $url;
}

function ggs_smartbill_download_pdf(string $series, string $number, string $destination, ?callable $transport = null): void
{
    $response = ggs_smartbill_request('GET', '/invoice/pdf', [
        'cif' => (string) SMARTBILL_COMPANY_VAT_CODE,
        'seriesname' => $series,
        'number' => $number,
    ], null, $transport);
    if ($response['status'] !== 200 || !str_starts_with($response['body'], '%PDF-')) {
        throw new GgsSmartBillException('SmartBill did not return a valid invoice PDF.');
    }
    $dir = dirname($destination);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('The invoice directory could not be created.');
    }
    $temporary = $destination . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, $response['body'], LOCK_EX) === false || !rename($temporary, $destination)) {
        @unlink($temporary);
        throw new RuntimeException('The SmartBill invoice PDF could not be saved.');
    }
}

/** @return array{status:int,body:string} */
function ggs_smartbill_request(string $method, string $path, array $query = [], ?string $body = null, ?callable $transport = null): array
{
    $base = rtrim((string) SMARTBILL_API_BASE, '/');
    $url = $base . $path . ($query ? '?' . http_build_query($query) : '');
    $accept = $path === '/invoice/pdf' ? 'application/pdf' : 'application/json';
    $headers = ['Content-Type: application/json', 'Accept: ' . $accept];
    if ($transport !== null) {
        $result = $transport($method, $url, $headers, $body);
        if (!is_array($result) || !isset($result['status'], $result['body'])) {
            throw new LogicException('The SmartBill test transport returned an invalid response.');
        }
        return ['status' => (int) $result['status'], 'body' => (string) $result['body']];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => (string) SMARTBILL_EMAIL . ':' . (string) SMARTBILL_API_TOKEN,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => defined('SMARTBILL_TIMEOUT_SECONDS') ? (int) SMARTBILL_TIMEOUT_SECONDS : 20,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $responseBody = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($responseBody === false) {
        // A timeout may happen after SmartBill created the document. Never retry
        // automatically: mark the order uncertain and reconcile it in Cloud.
        throw new GgsSmartBillException('SmartBill connection failed: ' . $curlError, true);
    }
    return ['status' => $status, 'body' => (string) $responseBody];
}

function ggs_smartbill_error_message(array $data, int $status): string
{
    $messages = [];
    foreach (($data['errors'] ?? []) as $error) {
        if (is_array($error) && !empty($error['message'])) {
            $messages[] = (string) $error['message'];
        } elseif (is_array($error) && !empty($error['code'])) {
            $messages[] = (string) $error['code'];
        }
    }
    return $messages ? implode('; ', $messages) : 'HTTP ' . $status;
}
