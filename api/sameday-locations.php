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
$limit = min(500, max(1, (int) ($_GET['limit'] ?? 50)));
$bounds = [];
foreach (['south', 'west', 'north', 'east'] as $coordinate) {
  if (isset($_GET[$coordinate]) && $_GET[$coordinate] !== '' && is_numeric($_GET[$coordinate])) {
    $bounds[$coordinate] = (float) $_GET[$coordinate];
  }
}
if (mb_strlen($query) > 100) {
  ggs_json_response(['success' => false, 'error' => 'Search value is too long'], 422);
}
if ($bounds && count($bounds) !== 4) {
  ggs_json_response(['success' => false, 'error' => 'Incomplete map bounds'], 422);
}
if ($bounds && ($bounds['south'] < -90 || $bounds['north'] > 90 || $bounds['west'] < -180 || $bounds['east'] > 180 || $bounds['south'] > $bounds['north'])) {
  ggs_json_response(['success' => false, 'error' => 'Invalid map bounds'], 422);
}

$where = ['active = 1', 'client_visible = 1', 'ooh_type = 0'];
$params = [];
if ($query !== '') {
  $needles = ['%' . $query . '%'];
  if (preg_match('/^sector(?:ul)?\s*([1-6])$/iu', $query, $matches)) {
    $needles[] = '%Sectorul ' . $matches[1] . '%';
  }
  $searchParts = [];
  foreach ($needles as $needle) {
    $searchParts[] = '(name LIKE ? OR address LIKE ? OR city LIKE ? OR county LIKE ?)';
    array_push($params, $needle, $needle, $needle, $needle);
  }
  $where[] = '(' . implode(' OR ', $searchParts) . ')';
}
if ($bounds) {
  $where[] = 'latitude IS NOT NULL AND longitude IS NOT NULL';
  $where[] = 'latitude BETWEEN ? AND ?';
  array_push($params, $bounds['south'], $bounds['north']);
  if ($bounds['west'] <= $bounds['east']) {
    $where[] = 'longitude BETWEEN ? AND ?';
    array_push($params, $bounds['west'], $bounds['east']);
  } else {
    $where[] = '(longitude >= ? OR longitude <= ?)';
    array_push($params, $bounds['west'], $bounds['east']);
  }
}

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
