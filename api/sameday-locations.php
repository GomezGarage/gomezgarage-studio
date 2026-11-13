<?php
/** Search the locally synchronized Sameday Easybox list. No upstream call. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/sameday.php';

ggs_cors_headers();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  ggs_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}
if (!ggs_sameday_enabled()) {
  ggs_json_response(['success' => false, 'error' => 'Sameday checkout is not enabled'], 503);
}

$query = trim((string) ($_GET['q'] ?? ''));
$county = trim((string) ($_GET['county'] ?? ''));
$city = trim((string) ($_GET['city'] ?? ''));
$limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
if (mb_strlen($query) > 100 || mb_strlen($county) > 120 || mb_strlen($city) > 120) {
  ggs_json_response(['success' => false, 'error' => 'Search value is too long'], 422);
}

$where = ['active = 1', 'client_visible = 1', 'ooh_type = 0'];
$params = [];
if ($query !== '') {
  $where[] = '(name LIKE ? OR address LIKE ? OR city LIKE ? OR county LIKE ?)';
  $needle = '%' . $query . '%';
  array_push($params, $needle, $needle, $needle, $needle);
}
if ($county !== '') { $where[] = 'county LIKE ?'; $params[] = '%' . $county . '%'; }
if ($city !== '') { $where[] = 'city LIKE ?'; $params[] = '%' . $city . '%'; }

$sql = 'SELECT ooh_id, name, county, city, address, postal_code, latitude, longitude FROM sameday_ooh_locations WHERE ' . implode(' AND ', $where) . ' ORDER BY county, city, name LIMIT ' . $limit;
$stmt = ggs_db()->prepare($sql);
$stmt->execute($params);
$locations = array_map(static fn(array $row): array => [
  'id' => (int) $row['ooh_id'],
  'name' => (string) $row['name'],
  'county' => (string) $row['county'],
  'city' => (string) $row['city'],
  'address' => (string) $row['address'],
  'postal' => (string) $row['postal_code'],
  'lat' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
  'lng' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
], $stmt->fetchAll());

ggs_json_response(['success' => true, 'locations' => $locations]);
