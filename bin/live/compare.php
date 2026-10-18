<?php

/**
 * @file
 * Compares what site A holds with what site B imported from it.
 *
 * Usage: php bin/live/compare.php build/live/a.json build/live/b.json
 * Exits with 1 and says what differs.
 */

declare(strict_types=1);

[, $fileA, $fileB] = $argv + [NULL, NULL, NULL];
$a = json_decode((string) file_get_contents((string) $fileA), TRUE, 512, JSON_THROW_ON_ERROR);
$b = json_decode((string) file_get_contents((string) $fileB), TRUE, 512, JSON_THROW_ON_ERROR);

$problems = [];

/**
 * Compares two maps and lists what is missing, extra or different.
 */
function live_compare(string $what, array $a, array $b, array &$problems): void {
  foreach (array_diff_key($a, $b) as $key => $_) {
    $problems[] = sprintf('%s %s is on site A and not on site B.', $what, $key);
  }
  foreach (array_diff_key($b, $a) as $key => $_) {
    $problems[] = sprintf('%s %s is on site B and not on site A.', $what, $key);
  }
  foreach (array_intersect_key($a, $b) as $key => $value) {
    if ($value !== $b[$key]) {
      $problems[] = sprintf('%s %s differs: A %s, B %s.', $what, $key, json_encode($value), json_encode($b[$key]));
    }
  }
}

// Accounts: the number, the name and whether it is on.
live_compare('Customer', $a['customers'], $b['customers'], $problems);
live_compare('Product', $a['products'], $b['products'], $problems);
live_compare('Price', $a['prices'], $b['prices'], $problems);

printf("Compared %d customers, %d products and %d prices.\n", count($a['customers']), count($a['products']), count($a['prices']));
if ($problems !== []) {
  echo count($problems) . " differences:\n - " . implode("\n - ", array_slice($problems, 0, 25)) . "\n";
  exit(1);
}
echo "Site B holds exactly what site A holds.\n";
