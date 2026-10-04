<?php

declare(strict_types=1);

namespace Drupal\import_engine\Key;

use Drupal\import_engine\Path\PathResolver;

/**
 * Builds the key that identifies a source item.
 *
 * The key is made from the key paths of an import.
 *
 * An import names one or more dotted paths (its "source key"); together their
 * values identify an item, so a key can be made of several values, such as a
 * customer code and a site. The key is what tells a new item from one that is
 * already known.
 *
 * The key is canonical: every value becomes text (the number 1 and the text
 * "1" are the same value) and the values are put in a JSON list, so that
 * ["a", "bc"] and ["ab", "c"] stay different. A key that does not fit in a
 * key column is replaced by its hash.
 */
final class ItemKey {

  /**
   * The longest key that is stored as it is.
   */
  public const MAX_LENGTH = 190;

  /**
   * Constructs the key builder.
   */
  public function __construct(
    private readonly PathResolver $paths,
  ) {
  }

  /**
   * Builds the key of an item.
   *
   * @param array<string, mixed> $item
   *   The source item.
   * @param list<string> $keyPaths
   *   The dotted paths of the values that identify an item.
   *
   * @throws \Drupal\import_engine\Key\InvalidKeyException
   *   When a value is missing, empty, or not a single value.
   */
  public function build(array $item, array $keyPaths): string {
    if ($keyPaths === []) {
      throw new InvalidKeyException('The import has no key paths.');
    }
    $values = [];
    foreach ($keyPaths as $path) {
      if (!$this->paths->has($item, $path)) {
        throw new InvalidKeyException(sprintf('no value at "%s"', $path));
      }
      $value = $this->paths->get($item, $path);
      if (is_array($value)) {
        throw new InvalidKeyException(sprintf('the value at "%s" is not a single value', $path));
      }
      $text = match (TRUE) {
        is_bool($value) => $value ? 'true' : 'false',
        $value === NULL => '',
        default => (string) $value,
      };
      if ($text === '') {
        throw new InvalidKeyException(sprintf('the value at "%s" is empty', $path));
      }
      $values[] = $text;
    }

    return $this->fromValues($values);
  }

  /**
   * Builds a key from values that are already text.
   *
   * Used where an item refers to another by the values of its key, for example
   * a price that names its product.
   *
   * @param list<string> $values
   *   The values of the key, in the order of the key paths.
   */
  public function fromValues(array $values): string {
    $key = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (strlen($key) <= self::MAX_LENGTH) {
      return $key;
    }
    return 'h:' . hash('xxh128', $key);
  }

}
