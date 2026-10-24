<?php
declare(strict_types=1);
namespace Ggs\Agent;

use RuntimeException;

/** Strict projection: corrupt legacy rows fail the page, never silently disappear. */
final class Dto
{
    public static function text($value): ?string
    {
        if ($value !== null && !is_string($value)) {
            throw new RuntimeException('Invalid source text.');
        }
        return $value;
    }

    public static function id($value): string
    {
        $value = (string) $value;
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $value)) {
            throw new RuntimeException('Invalid source identifier.');
        }
        return $value;
    }

    public static function money($value, bool $legacyJson = false): string
    {
        // Legacy item JSON contains floats; accept only cent-precision finite values.
        if ($legacyJson && (is_float($value) || is_int($value))) {
            if (!is_finite((float) $value) || $value < 0 || abs($value * 100 - round($value * 100)) > 0.00001) {
                throw new RuntimeException('Invalid source amount.');
            }
            $value = number_format($value, 2, '.', '');
        }
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $value, $parts)) {
            throw new RuntimeException('Invalid source amount.');
        }
        return $parts[1] . '.' . str_pad($parts[2] ?? '', 2, '0');
    }

    public static function integer($value): int
    {
        if (!preg_match('/^-?(0|[1-9][0-9]{0,9})$/D', (string) $value)) {
            throw new RuntimeException('Invalid source integer.');
        }
        return (int) $value;
    }

    public static function date($value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('Invalid source timestamp.');
        }
        $result = str_replace(' ', 'T', $value) . 'Z';
        try {
            timestamp($result);
        } catch (\Throwable $e) {
            throw new RuntimeException('Invalid source timestamp.');
        }
        return $result;
    }

    public static function choice($value, array $choices): string
    {
        if (!in_array($value, $choices, true)) {
            throw new RuntimeException('Invalid source status.');
        }
        return $value;
    }

    private static function listJson($value): array
    {
        if ($value !== null && (!is_string($value) || !str_starts_with(ltrim($value), '['))) {
            throw new RuntimeException('Invalid source list.');
        }
        $result = $value === null ? [] : json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($result) || ($result !== [] && array_keys($result) !== range(0, count($result) - 1))) {
            throw new RuntimeException('Invalid source list.');
        }
        return $result;
    }

    public static function product(array $row): array
    {
        $result = ['source' => 'studio', 'id' => self::id($row['id'])];
        foreach (['slug', 'name_ro', 'name_en', 'desc_short_ro', 'desc_short_en', 'desc_long_ro', 'desc_long_en',
            'how_made_ro', 'how_made_en', 'material', 'category'] as $field) {
            $result[$field] = self::text($row[$field]);
        }
        $result['status'] = self::choice($row['status'], ['published', 'draft']);
        $result['price'] = ['currency' => 'EUR', 'value' => self::money($row['price_eur'])];
        $result['images'] = array_map(function ($image): string {
            if (!is_string($image)) {
                throw new RuntimeException('Invalid source image.');
            }
            return $image;
        }, self::listJson($row['images']));
        $result['specs'] = array_map(function ($spec): array {
            if (!is_array($spec) || !is_string($spec['key'] ?? null) || !is_string($spec['value'] ?? null)) {
                throw new RuntimeException('Invalid source spec.');
            }
            return ['key' => $spec['key'], 'value' => $spec['value']];
        }, self::listJson($row['specs']));
        $result['stock'] = ['quantity' => self::integer($row['stock']), 'semantics' => 'legacy_checkout_counter'];
        $currentViews = self::integer($row['views_current_7d']);
        $previousViews = self::integer($row['views_previous_7d']);
        if ($currentViews < 0 || $previousViews < 0) {
            throw new RuntimeException('Invalid product view count.');
        }
        $result['view_stats'] = [
            'metric' => 'product_detail_view_events',
            'current_7d' => $currentViews,
            'previous_7d' => $previousViews,
            'delta_7d' => $currentViews - $previousViews,
            'window' => 'current_7_utc_days_vs_previous_7_utc_days',
        ];
        $revision = [];
        foreach (['slug', 'name_ro', 'name_en', 'desc_short_ro', 'desc_short_en', 'desc_long_ro', 'desc_long_en',
            'how_made_ro', 'how_made_en', 'material', 'category'] as $field) {
            $revision[$field] = $result[$field];
        }
        $revision['price_eur'] = $result['price']['value'];
        $revision['images'] = $result['images'];
        $revision['specs'] = $result['specs'];
        $result['revision'] = hash('sha256', json_encode($revision,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $result['updated_at'] = self::date($row['updated_at']);
        return $result;
    }

    public static function order(array $row, string $customerRefKey): array
    {
        $result = ['source' => 'studio', 'id' => self::id($row['id'])];
        foreach (['order_number', 'payment_method', 'tracking_number', 'country'] as $field) {
            $result[$field] = self::text($row[$field]);
        }
        $email = self::text($row['customer_email'] ?? null);
        $normalizedEmail = $email === null ? '' : strtolower(trim($email));
        $result['customer_ref'] = $normalizedEmail !== '' && filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)
            ? 'studio_' . hash_hmac('sha256', "customer-ref-v1\0" . $normalizedEmail, $customerRefKey)
            : null;
        $result['status'] = self::choice($row['status'], ['pending', 'paid', 'processing', 'shipped', 'delivered', 'cancelled']);
        // Admin can manually set every native status. None proves receipt of funds.
        $result['payment_state'] = 'unknown';
        $result['fulfilment_status'] = in_array($row['status'], ['processing', 'shipped', 'delivered'], true) ? $row['status'] : 'unknown';
        $result['customer_language'] = null;
        $result['currency'] = 'EUR';
        foreach (['subtotal', 'shipping', 'total'] as $amount) {
            $result[$amount] = self::money($row[$amount . '_eur']);
        }
        foreach (['created_at', 'updated_at'] as $date) {
            $result[$date] = self::date($row[$date]);
        }
        $result['items'] = array_map(function ($item): array {
            if (!is_array($item) || !is_string($item['name'] ?? null)) {
                throw new RuntimeException('Invalid source item.');
            }
            $qty = self::integer($item['qty'] ?? null);
            if ($qty < 1) {
                throw new RuntimeException('Invalid source quantity.');
            }
            return ['product_id' => self::id($item['product_id'] ?? null), 'name' => $item['name'],
                'qty' => $qty, 'price' => self::money($item['price_eur'] ?? null, true)];
        }, self::listJson($row['items']));
        return $result;
    }
}
