<?php
/** Static checkout regression checks. No config, database or services loaded. */

$root = dirname(__DIR__);
$html = file_get_contents($root . '/checkout.html');
$js = file_get_contents($root . '/assets/js/checkout.js');
$css = file_get_contents($root . '/assets/css/shop.css');
$locations = file_get_contents($root . '/api/sameday-locations.php');
$orders = file_get_contents($root . '/api/create-order.php');
$sameday = file_get_contents($root . '/includes/sameday.php');

if ($html === false || $js === false || $css === false || $locations === false || $orders === false || $sameday === false) {
    throw new RuntimeException('Checkout sources could not be read.');
}

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Checkout regression: ' . $label);
    $checks++;
};

$check(!str_contains($html, 'checkout-submit-btn'), 'no intermediate payment button');
$check(!str_contains($html, 'Continue to Payment'), 'no obsolete payment copy');
$check(strpos($html, 'data-order-summary') < strpos($html, 'revolut-embedded-target'), 'payment widget follows order summary');
$check(str_contains($html, 'data-payment-ready-status'), 'payment status remains in summary card');
$check(str_contains($html, 'data-easybox-map'), 'Easybox map container');
$check(str_contains($html, 'leaflet@1.9.4'), 'pinned Leaflet asset');
$check(!str_contains($html, 'leaflet.css'), 'Leaflet layout CSS does not depend on a blocked CDN stylesheet');
$check(!str_contains($html, '<option value="GB">'), 'UK removed from checkout countries');
$check(substr_count($html, '<option value="RO">Romania</option>') >= 3, 'EU country selects include Romania');
$check(str_contains($html, 'billing_same_as_shipping'), 'editable billing-address choice');

$check(str_contains($js, 'billingAddress: payload.billing_address'), 'billing address passed to Revolut');
$check(str_contains($js, 'onShippingAddressChange(address)'), 'shipping-address callback');
$check(str_contains($js, 'onShippingOptionChange()'), 'shipping-option callback');
$check(str_contains($js, 'requestSamedayQuote();'), 'Easybox selection auto-quotes');
$check(str_contains($js, 'window.L.map'), 'interactive Easybox map');
$check(str_contains($js, 'window.L.circleMarker'), 'map markers do not depend on CDN image assets');
$check(str_contains($js, 'easyboxProgrammaticMoveUntil'), 'search results are not overwritten by programmatic map moves');
$check(str_contains($js, 'EU_COUNTRIES.includes'), 'EU-only client validation');
$check(str_contains($css, '.leaflet-tile-pane') && str_contains($css, '.leaflet-control'), 'local Leaflet layout and controls');

$check(str_contains($locations, "ooh_type = 0"), 'locker-only location query');
$check(str_contains($locations, "latitude BETWEEN ? AND ?"), 'map-bounds location query');
$check(str_contains($locations, "'%Sectorul '"), 'Bucharest sector search alias');
$check(str_contains($orders, 'Company invoice country must be within the European Union'), 'EU-only server validation');
$check(str_contains($sameday, "'%Locker%Nextday%'"), 'Easybox quote uses locker service');
$check(str_contains($sameday, "\$payload['oohLastMile'] = \$oohId"), 'selected locker reaches Sameday payload');
$check(str_contains($sameday, "'serviceTaxes'] = 'PDO '"), 'PDO tax uses documented payload format');

echo "PASS: {$checks} isolated checkout, payment and Easybox regression checks.\n";
