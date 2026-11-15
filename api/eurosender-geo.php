<?php
/** Server-side Eurosender region/city suggestions. The API key never reaches the browser. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/eurosender.php';

ggs_cors_headers();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  ggs_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}
if (!ggs_eurosender_quote_enabled()) {
  ggs_json_response(['success' => false, 'error' => 'Eurosender address suggestions are unavailable'], 503);
}

$country = strtoupper(trim((string) ($_GET['country'] ?? '')));
$kind = strtolower(trim((string) ($_GET['kind'] ?? '')));
$query = trim((string) ($_GET['q'] ?? ''));
$region = trim((string) ($_GET['region'] ?? ''));
if (!isset(ggs_eurosender_eu_countries()[$country])) ggs_json_response(['success' => false, 'error' => 'Choose an EU country'], 422);
if (!in_array($kind, ['regions', 'cities'], true)) ggs_json_response(['success' => false, 'error' => 'Invalid suggestion type'], 422);
if (mb_strlen($query) < 2 || mb_strlen($query) > 120 || mb_strlen($region) > 120) {
  ggs_json_response(['success' => true, 'suggestions' => []]);
}

try {
  $items = ggs_eurosender_get_cached_list('/v1/countries/' . rawurlencode($country) . '/' . $kind);
  $regionId = null;
  if ($kind === 'cities' && $region !== '') {
    $regionLookup = $country === 'RO' && ggs_eurosender_is_bucharest_alias($region) ? 'Bucharest' : $region;
    $regionMatch = ggs_eurosender_match_geo_item(
      ggs_eurosender_get_cached_list('/v1/countries/' . rawurlencode($country) . '/regions'),
      $regionLookup,
      'region'
    );
    $regionId = $regionMatch['id'];
  }

  $needleValue = $country === 'RO' && ggs_eurosender_is_bucharest_alias($query) ? 'Bucharest' : $query;
  $needle = ggs_eurosender_normalize_geo_name($needleValue);
  $matches = [];
  foreach ($items as $item) {
    if ($regionId !== null && isset($item['regionId']) && (int) $item['regionId'] !== $regionId) continue;
    $name = trim((string) ($item['name'] ?? ''));
    if ($name === '') continue;
    $normalized = ggs_eurosender_normalize_geo_name($name);
    if (!str_contains($normalized, $needle) && !str_contains($needle, $normalized)) continue;
    $score = $normalized === $needle ? 0 : (str_starts_with($normalized, $needle) ? 1 : 2);
    $matches[] = ['value' => $name, 'score' => $score];
  }
  usort($matches, static fn(array $left, array $right): int => [$left['score'], $left['value']] <=> [$right['score'], $right['value']]);
  $suggestions = array_map(static fn(array $match): string => $match['value'], array_slice($matches, 0, 12));
  ggs_json_response(['success' => true, 'suggestions' => array_values(array_unique($suggestions))]);
} catch (Throwable $error) {
  error_log('GGS Eurosender geographic suggestions failed: ' . $error->getMessage());
  ggs_json_response(['success' => false, 'error' => 'Address suggestions are temporarily unavailable'], 502);
}
