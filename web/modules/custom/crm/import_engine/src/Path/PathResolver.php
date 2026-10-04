<?php

declare(strict_types=1);

namespace Drupal\import_engine\Path;

/**
 * Reads values from decoded data with dotted paths.
 *
 * A path is names separated by dots, for example
 * `price.customer.customer_code`. A numeric segment indexes into a list
 * (`tags.0`). There are no wildcards and no filters; an empty path means the
 * data itself.
 */
final class PathResolver {

  /**
   * The pattern a valid path matches.
   */
  public const PATTERN = '/^[A-Za-z0-9_@#-]+(\.[A-Za-z0-9_@#-]+)*$/';

  /**
   * Returns whether the path leads to a value (a NULL value counts).
   *
   * @param array<mixed> $data
   *   The data to read from.
   * @param string $path
   *   The dotted path.
   */
  public function has(array $data, string $path): bool {
    return $this->lookup($data, $path)['found'];
  }

  /**
   * Returns the value at the path, or NULL when there is none.
   *
   * @param array<mixed> $data
   *   The data to read from.
   * @param string $path
   *   The dotted path.
   */
  public function get(array $data, string $path): mixed {
    return $this->lookup($data, $path)['value'];
  }

  /**
   * Turns the value at an items path into a list of items.
   *
   * A list stays as it is. A single object becomes a list of one, because
   * formats like XML cannot tell one item from a list of one.
   *
   * @param array<mixed> $data
   *   The decoded response.
   * @param string $path
   *   The path of the items; empty for a response that is the list itself.
   *
   * @return list<array<string, mixed>>|null
   *   The items, or NULL when the path leads nowhere or to a non-list value.
   */
  public function items(array $data, string $path): ?array {
    $lookup = $this->lookup($data, $path);
    $value = $lookup['value'];
    if (!$lookup['found'] || !is_array($value)) {
      return NULL;
    }
    if ($value === []) {
      return [];
    }
    if (array_is_list($value)) {
      foreach ($value as $item) {
        if (!is_array($item)) {
          return NULL;
        }
      }
      /** @var list<array<string, mixed>> $value */
      return $value;
    }
    /** @var array<string, mixed> $value */
    return [$value];
  }

  /**
   * Looks a path up.
   *
   * @param array<mixed> $data
   *   The data to read from.
   * @param string $path
   *   The dotted path.
   *
   * @return array{found: bool, value: mixed}
   *   Whether the path leads to a value, and that value.
   */
  private function lookup(array $data, string $path): array {
    if ($path === '') {
      return ['found' => TRUE, 'value' => $data];
    }
    $current = $data;
    foreach (explode('.', $path) as $segment) {
      if (!is_array($current) || !array_key_exists($segment, $current)) {
        return ['found' => FALSE, 'value' => NULL];
      }
      $current = $current[$segment];
    }
    return ['found' => TRUE, 'value' => $current];
  }

}
