<?php

declare(strict_types=1);

namespace Drupal\import_engine\Path;

/**
 * Lists the dotted paths in a sample item, with the type of each value.
 *
 * Used to offer the paths a field mapping can read from. Lists are reported as
 * one `list` value and are not descended into.
 */
final class PathDiscovery {

  /**
   * Returns the paths of the leaf values in an item, with their types.
   *
   * @param array<string, mixed> $item
   *   A sample item.
   *
   * @return array<string, string>
   *   The type (string, integer, float, boolean, null or list) per path.
   */
  public function discover(array $item): array {
    $paths = [];
    $this->walk($item, '', $paths);
    return $paths;
  }

  /**
   * Collects the paths below a value.
   *
   * @param array<mixed> $value
   *   The value to walk.
   * @param string $prefix
   *   The path of the value, empty for the item itself.
   * @param array<string, string> $paths
   *   The paths found so far.
   */
  private function walk(array $value, string $prefix, array &$paths): void {
    foreach ($value as $key => $child) {
      $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
      if (is_array($child) && !array_is_list($child)) {
        $this->walk($child, $path, $paths);
      }
      else {
        $paths[$path] = match (TRUE) {
          is_array($child) => 'list',
          is_bool($child) => 'boolean',
          is_int($child) => 'integer',
          is_float($child) => 'float',
          $child === NULL => 'null',
          default => 'string',
        };
      }
    }
  }

}
