<?php
function ggs_customer_status_names(string $status, bool $custom = false): array
{
  $orders = [
    'pending' => ['Awaiting payment', 'Asteapta plata'], 'paid' => ['Confirmed', 'Confirmata'],
    'processing' => ['In preparation', 'In pregatire'], 'printing' => ['Printing', 'La printare'],
    'shipped' => ['Shipped', 'Expediata'], 'delivered' => ['Delivered', 'Livrata'], 'cancelled' => ['Cancelled', 'Anulata'],
  ];
  $inquiries = [
    'received' => ['Received', 'Primita'], 'reviewing' => ['Under review', 'In analiza'],
    'needs_info' => ['More information needed', 'Avem nevoie de informatii'], 'quoted' => ['Quote sent', 'Oferta trimisa'],
    'accepted' => ['Accepted', 'Acceptata'], 'in_progress' => ['In progress', 'In lucru'],
    'completed' => ['Completed', 'Finalizata'], 'declined' => ['Closed', 'Inchisa'],
  ];
  $names = $custom ? $inquiries : $orders;
  return $names[$status] ?? [ucfirst(str_replace('_', ' ', $status)), ucfirst(str_replace('_', ' ', $status))];
}

function ggs_customer_status_badge(string $status, bool $custom = false): string
{
  [$en, $ro] = ggs_customer_status_names($status, $custom);
  return '<span class="customer-status" data-en="' . ggs_customer_escape($en) . '" data-ro="' . ggs_customer_escape($ro) . '">' . ggs_customer_escape($en) . '</span>';
}

function ggs_customer_format_date(string $date): string
{
  $timestamp = strtotime($date);
  return $timestamp ? date('M j, Y · H:i', $timestamp) : $date;
}
