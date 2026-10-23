<?php
declare(strict_types=1);

final class GgsProductViewRequestError extends RuntimeException
{
  public function __construct(public int $httpStatus)
  {
    parent::__construct('Invalid product view request.');
  }
}

function ggs_parse_product_view_request(array $server, string $raw, string $allowedOrigin): int
{
  if (($server['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new GgsProductViewRequestError(405);
  }
  if (!isset($server['HTTP_ORIGIN']) || !hash_equals($allowedOrigin, $server['HTTP_ORIGIN'])) {
    throw new GgsProductViewRequestError(403);
  }
  if (($server['CONTENT_TYPE'] ?? '') !== 'application/json') {
    throw new GgsProductViewRequestError(415);
  }
  if (strlen($raw) > 256) {
    throw new GgsProductViewRequestError(400);
  }
  try {
    $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
  } catch (JsonException $error) {
    throw new GgsProductViewRequestError(400);
  }
  if (!is_array($body) || array_keys($body) !== ['product_id'] || !is_int($body['product_id'])
      || $body['product_id'] < 1 || $body['product_id'] > 2147483647) {
    throw new GgsProductViewRequestError(400);
  }
  return $body['product_id'];
}

/** Increment one aggregate product-detail page-load count. No visitor identifiers are stored. */
function ggs_record_product_view(PDO $db, int $productId, ?string $utcDate = null): bool
{
  if ($productId < 1 || $productId > 2147483647) {
    return false;
  }

  $published = $db->prepare("SELECT 1 FROM products WHERE id = ? AND status = 'published' LIMIT 1");
  $published->execute([$productId]);
  if ($published->fetchColumn() === false) {
    return false;
  }

  $date = $utcDate ?? gmdate('Y-m-d');
  $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
  if (!$parsed || $parsed->format('Y-m-d') !== $date) {
    throw new InvalidArgumentException('Invalid UTC date.');
  }

  if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $statement = $db->prepare('INSERT INTO product_views_daily(product_id,view_date,view_count) VALUES(?,?,1) '
      . 'ON CONFLICT(product_id,view_date) DO UPDATE SET view_count=product_views_daily.view_count+1');
  } else {
    $statement = $db->prepare('INSERT INTO product_views_daily(product_id,view_date,view_count) VALUES(?,?,1) '
      . 'ON DUPLICATE KEY UPDATE view_count=view_count+1');
  }
  $statement->execute([$productId, $date]);
  return true;
}
