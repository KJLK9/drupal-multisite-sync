<?php

/**
 * @file
 * Writes what a site of the catalog holds, as JSON, for bin/verify-live.sh.
 *
 * Run with drush php:script on site A (customers, products, prices) or on
 * site B (accounts, items, agreements), with LIVE_OUT set to the file to write.
 * The two files are then compared by bin/live/compare.php.
 */

declare(strict_types=1);

$manager = \Drupal::entityTypeManager();
$out = getenv('LIVE_OUT');
if ($out === FALSE || $out === '') {
  throw new \RuntimeException('Set LIVE_OUT to the file to write.');
}

/**
 * Normalizes a money field to "10.00 EUR".
 */
function live_money(mixed $field): string {
  if ($field === NULL || $field->isEmpty()) {
    return '-';
  }
  $value = $field->first()->getValue();
  return sprintf('%.2f %s', (float) ($value['number'] ?? 0), (string) ($value['currency_code'] ?? ''));
}

$data = ['side' => '', 'customers' => [], 'products' => [], 'prices' => []];

if ($manager->hasDefinition('customer')) {
  $data['side'] = 'a';
  foreach ($manager->getStorage('customer')->loadMultiple() as $customer) {
    $data['customers'][(string) $customer->get('customer_number')->value] = [
      'label' => (string) $customer->label(),
      'status' => (bool) $customer->get('status')->value,
    ];
  }
  foreach ($manager->getStorage('product')->loadMultiple() as $product) {
    $data['products'][(string) $product->id()] = [
      'label' => (string) $product->label(),
      'price' => live_money($product->get('base_price')),
      'status' => (bool) $product->get('status')->value,
    ];
  }
  foreach ($manager->getStorage('product_price')->loadMultiple() as $price) {
    $customer = $price->get('customer')->entity;
    $product = $price->get('product_id')->entity;
    $data['prices'][(string) $price->id()] = [
      'customer' => $customer === NULL ? '-' : (string) $customer->get('customer_number')->value,
      'product' => $product === NULL ? '-' : (string) $product->id(),
      'price' => live_money($price->get('price')),
    ];
  }
}
elseif ($manager->hasDefinition('account')) {
  $data['side'] = 'b';
  foreach ($manager->getStorage('account')->loadMultiple() as $account) {
    $data['customers'][(string) $account->get('number')->value] = [
      'label' => (string) $account->get('name')->value,
      'status' => (bool) $account->get('status')->value,
    ];
  }
  foreach ($manager->getStorage('item')->loadMultiple() as $item) {
    $data['products'][(string) $item->get('source_id')->value] = [
      'label' => (string) $item->label(),
      'price' => live_money($item->get('list_price')),
      'status' => (bool) $item->get('status')->value,
    ];
  }
  foreach ($manager->getStorage('agreement')->loadMultiple() as $agreement) {
    $account = $agreement->get('account')->entity;
    $item = $agreement->get('item')->entity;
    $data['prices'][(string) $agreement->get('source_id')->value] = [
      'customer' => $account === NULL ? '-' : (string) $account->get('number')->value,
      'product' => $item === NULL ? '-' : (string) $item->get('source_id')->value,
      'price' => live_money($agreement->get('price')),
    ];
  }
}
else {
  throw new \RuntimeException('This is neither site A nor site B of the catalog.');
}

ksort($data['customers']);
ksort($data['products']);
ksort($data['prices']);
file_put_contents($out, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo sprintf("Site %s: %d customers, %d products, %d prices.\n", strtoupper($data['side']), count($data['customers']), count($data['products']), count($data['prices']));
