<?php

declare(strict_types=1);

namespace Drupal\import_engine\Page;

/**
 * Computes a compact fingerprint of the items on a page.
 *
 * The fingerprint is a hash of the decoded items in a canonical form: object
 * keys are sorted, so the order of keys in a response does not matter, and
 * the order of the items is kept, because a page is an ordered list. The raw
 * response is not hashed, since an envelope with a timestamp or request id
 * would make the same data look different every time.
 *
 * The hash is the whole page, never a sample: a fingerprint that says "same"
 * for a page that changed would make an update go unnoticed. It is xxh128:
 * fast, 16 bytes when stored in binary, and not meant to resist an attacker,
 * only to tell data apart.
 */
final class PageFingerprint {

  /**
   * The hash algorithm.
   */
  public const ALGORITHM = 'xxh128';

  /**
   * Returns the fingerprint of the items on a page, as 32 hex characters.
   *
   * @param array<mixed> $items
   *   The decoded items of a page.
   */
  public function fingerprint(array $items): string {
    $json = json_encode(
      $this->canonical($items),
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
    );
    return hash(self::ALGORITHM, $json);
  }

  /**
   * Sorts the keys of every object, leaving lists in their order.
   *
   * @param mixed $value
   *   Any decoded value.
   */
  private function canonical(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    $result = array_map($this->canonical(...), $value);
    if (!array_is_list($result)) {
      ksort($result);
    }
    return $result;
  }

}
