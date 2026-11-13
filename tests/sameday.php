<?php
/** Pure/offline Sameday integration tests. No config.php and no network. */
define('SAMEDAY_ENABLED', true);
define('SAMEDAY_API_BASE', 'https://synthetic.invalid');
define('SAMEDAY_USERNAME', 'fixture@example.invalid');
define('SAMEDAY_PASSWORD', 'fixture-password');
define('SAMEDAY_TIMEOUT_SECONDS', 5);
define('SAMEDAY_PICKUP_POINT_ID', 0);
define('SAMEDAY_CONTACT_PERSON_ID', 0);
define('SAMEDAY_HOME_SERVICE_ID', 7);
define('SAMEDAY_LOCKER_SERVICE_ID', 15);
define('SAMEDAY_PERSONAL_DROPOFF_ENABLED', true);
define('SAMEDAY_PDO_SERVICE_TAX_IDS', []);
define('SAMEDAY_PDO_SERVICE_TAX_ID', 0);
define('SAMEDAY_DEFAULT_LENGTH_CM', 30.0);
define('SAMEDAY_DEFAULT_WIDTH_CM', 20.0);
define('SAMEDAY_DEFAULT_HEIGHT_CM', 10.0);
define('SAMEDAY_DEFAULT_WEIGHT_KG', 1.0);
require __DIR__ . '/../includes/sameday.php';

$checks = 0;
function sdCheck(bool $condition, string $label): void {
  global $checks;
  $checks++;
  if (!$condition) throw new RuntimeException('FAILED: ' . $label);
}

$package = ggs_sameday_package_from_items([
  ['qty' => 2, 'shipping_length_cm' => 12, 'shipping_width_cm' => 8, 'shipping_height_cm' => 4, 'shipping_weight_kg' => 0.3],
  ['qty' => 1, 'shipping_length_cm' => 20, 'shipping_width_cm' => 10, 'shipping_height_cm' => 5, 'shipping_weight_kg' => 0.6],
]);
sdCheck($package === ['length' => 20.0, 'width' => 10.0, 'height' => 13.0, 'weight' => 1.2, 'used_defaults' => false], 'package aggregation');
sdCheck(ggs_sameday_package_type(1.0) === 1 && ggs_sameday_package_type(1.01) === 0 && ggs_sameday_package_type(38.01) === 2, 'package type thresholds');
sdCheck(ggs_sameday_tracking_url('1SDY12345') === 'https://sameday.ro/#awb=1SDY12345', 'safe tracking URL');
sdCheck(ggs_sameday_tracking_url('bad value') === '', 'unsafe tracking refused');

$pdoClass = class_exists('Pdo\\Sqlite') ? 'Pdo\\Sqlite' : PDO::class;
$db = new $pdoClass('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$createFunction = method_exists($db, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
$db->$createFunction('UTC_TIMESTAMP', static fn(): string => '2026-11-13 12:00:00');
$db->exec('CREATE TABLE sameday_api_cache (cache_key TEXT PRIMARY KEY, cache_value TEXT, expires_at TEXT, updated_at TEXT)');
$db->exec('CREATE TABLE sameday_pickup_points (pickup_point_id INTEGER PRIMARY KEY, contact_person_id INTEGER)');
$db->exec('CREATE TABLE sameday_services (service_id INTEGER PRIMARY KEY, name TEXT, raw_json TEXT)');
$db->exec('CREATE TABLE sameday_ooh_locations (ooh_id INTEGER PRIMARY KEY, ooh_type INTEGER, active INTEGER, client_visible INTEGER)');
$db->exec('INSERT INTO sameday_pickup_points VALUES (123, 20)');
$homeRaw = json_encode(['id' => 7, 'name' => '24H', 'packageTypes' => [['packageType' => 1, 'serviceTaxes' => [['id' => 990001, 'name' => 'Predare personala in punct fix']]]]], JSON_THROW_ON_ERROR);
$lockerRaw = json_encode(['id' => 15, 'name' => 'Locker Nextday', 'packageTypes' => [['packageType' => 1, 'serviceTaxes' => [['id' => 991001, 'name' => 'Predare personala in punct fix']]]]], JSON_THROW_ON_ERROR);
$serviceInsert = $db->prepare('INSERT INTO sameday_services VALUES (?, ?, ?)');
$serviceInsert->execute([7, '24H', $homeRaw]);
$serviceInsert->execute([15, 'Locker Nextday', $lockerRaw]);
$db->exec('INSERT INTO sameday_ooh_locations VALUES (174, 0, 1, 1)');

$order = [
  'order_number' => 'GGS-2026-0001', 'customer_type' => 'pf', 'customer_name' => 'Synthetic Customer',
  'customer_email' => 'customer@example.invalid', 'customer_phone' => '0726000000',
  'shipping_address' => ['street' => 'Synthetic 1', 'city' => 'Balotesti', 'county' => 'Ilfov', 'postal' => '077015', 'country' => 'RO'],
  'items' => [['qty' => 1, 'shipping_length_cm' => 20, 'shipping_width_cm' => 10, 'shipping_height_cm' => 5, 'shipping_weight_kg' => 0.6]],
];
$built = ggs_sameday_build_payload($db, $order, 'easybox', 174);
sdCheck($built['payload']['pickupPoint'] === 123 && $built['payload']['contactPerson'] === 20, 'synchronized pickup and contact');
sdCheck($built['payload']['service'] === 15 && $built['payload']['oohLastMile'] === 174, 'locker service and OOH');
sdCheck(!isset($built['payload']['awbRecipient']['address']) && !isset($built['payload']['awbRecipient']['cityString']), 'locker recipient uses identity only');
sdCheck($built['payload']['serviceTaxes'] === 'PDO 991001', 'package-specific PDO service tax');
sdCheck($built['payload']['clientInternalReference'] === 'GGS-2026-0001' && $built['payload']['cashOnDelivery'] === 0, 'idempotent paid-order payload');
$homeBuilt = ggs_sameday_build_payload($db, $order, 'home');
sdCheck($homeBuilt['payload']['awbRecipient']['address'] === 'Synthetic 1' && $homeBuilt['payload']['serviceTaxes'] === 'PDO 990001', 'home recipient and PDO payload');

$calls = [];
$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
  $calls[] = [$method, $url, $headers, $body];
  if (str_ends_with($url, '/api/authenticate')) return ['status' => 200, 'body' => json_encode(['token' => 'fixture-token', 'expire_at_utc' => '2026-11-20 12:00'])];
  if (str_ends_with($url, '/api/awb/estimate-cost')) return ['status' => 200, 'body' => json_encode(['amount' => 19.5, 'currency' => 'RON', 'time' => 1])];
  if (str_ends_with($url, '/api/awb')) return ['status' => 200, 'body' => json_encode(['awbNumber' => '1SDY12345', 'awbCost' => 19.5, 'parcels' => [['position' => 1, 'awbNumber' => '1SDY12345001']]])];
  throw new RuntimeException('Unexpected synthetic request: ' . $url);
};
$quote = ggs_sameday_estimate($db, $order, 'easybox', 174, $transport);
sdCheck($quote['amount'] === 19.5 && $quote['currency'] === 'RON', 'quote mapping');
$estimateCall = current(array_values(array_filter($calls, static fn(array $call): bool => str_ends_with($call[1], '/api/awb/estimate-cost'))));
$estimateForm = [];
parse_str((string) $estimateCall[3], $estimateForm);
sdCheck(($estimateForm['serviceTaxes'][0] ?? null) === '991001', 'estimate uses PDO tax ID array');
sdCheck(!isset($estimateForm['oohLastMile']) && !isset($estimateForm['clientInternalReference']), 'estimate excludes AWB-only fields');
sdCheck(($estimateForm['awbRecipient']['address'] ?? '') === 'Synthetic 1', 'estimate includes complete recipient address');
$issued = ggs_sameday_create_awb($db, $order + ['shipping_method' => 'easybox', 'shipping_ooh_id' => 174], $transport);
sdCheck($issued['awb_number'] === '1SDY12345' && count($issued['parcels']) === 1, 'AWB mapping');
sdCheck(count(array_filter($calls, static fn(array $call): bool => str_ends_with($call[1], '/api/authenticate'))) === 1, 'authentication token reused');

echo "Sameday tests passed: {$checks} checks\n";
