<?php

declare(strict_types=1);

namespace Drupal\import_engine\Target;

/**
 * Turns the value of a field into short text for a change log.
 */
final class FieldValueText {

  /**
   * The longest text kept; longer values are cut.
   */
  public const MAX_LENGTH = 500;

  /**
   * Returns the text of the items of a field.
   *
   * A field item with one property (most fields) shows that value; one with
   * several (money, text with a format, a link) shows "name value" pairs, so
   * that nothing is lost and nothing is guessed.
   *
   * @param array<int|string, mixed> $items
   *   The value of the field, as the list of its items.
   */
  public static function of(array $items): string {
    $parts = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        $parts[] = self::scalar($item);
        continue;
      }
      $item = array_filter($item, static fn (mixed $value): bool => $value !== NULL && $value !== '');
      if (count($item) === 1) {
        $parts[] = self::scalar(reset($item));
        continue;
      }
      $pairs = [];
      foreach ($item as $name => $value) {
        $pairs[] = $name . ' ' . self::scalar($value);
      }
      $parts[] = implode(', ', $pairs);
    }
    $text = implode(' | ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    return mb_strlen($text) > self::MAX_LENGTH ? mb_substr($text, 0, self::MAX_LENGTH - 1) . '…' : $text;
  }

  /**
   * Returns the text of one value.
   */
  private static function scalar(mixed $value): string {
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }
    if (is_scalar($value) || $value instanceof \Stringable) {
      return (string) $value;
    }
    // An object (an entity set on a reference item) is not written out: it
    // could be huge, and what identifies it is in its other properties.
    return is_array($value) ? (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') : '';
  }

}
