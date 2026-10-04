<?php

declare(strict_types=1);

namespace Drupal\import_engine\Form;

/**
 * Turns lists and maps into text for a form field, and back.
 *
 * A form shows a list as one value per line and a map as one "name: value" or
 * "name=value" per line, which is easier to edit than a table with add and
 * remove buttons. Reading is strict: a line that is not a pair is an error
 * the person can fix, never a value that silently disappears.
 */
final class TextLists {

  /**
   * Splits text in its lines, trimmed, without the empty ones.
   *
   * @return list<string>
   *   The lines.
   */
  public static function lines(string $text): array {
    $lines = preg_split('/\R/', $text);
    return array_values(array_filter(array_map('trim', $lines === FALSE ? [] : $lines), static fn (string $line): bool => $line !== ''));
  }

  /**
   * Formats a list as text, one value per line.
   *
   * @param array<mixed> $values
   *   The values.
   */
  public static function formatLines(array $values): string {
    return implode("\n", array_map(static fn (mixed $value): string => (string) $value, array_values($values)));
  }

  /**
   * Reads text with a "name<separator>value" on each line.
   *
   * @param string $text
   *   The text.
   * @param string $separator
   *   What separates the name from the value, for example ": " or "=".
   *
   * @return array<string, string>
   *   The pairs, in order. A later line with the same name wins.
   *
   * @throws \InvalidArgumentException
   *   When a line has no separator or no name; the message names the line.
   */
  public static function pairs(string $text, string $separator): array {
    $separator = trim($separator);
    $pairs = [];
    foreach (self::lines($text) as $index => $line) {
      $position = strpos($line, $separator);
      $name = $position === FALSE ? '' : trim(substr($line, 0, $position));
      if ($position === FALSE || $name === '') {
        throw new \InvalidArgumentException(sprintf('Line %d ("%s") must look like name%svalue.', $index + 1, mb_substr($line, 0, 40), $separator));
      }
      $pairs[$name] = trim(substr($line, $position + strlen($separator)));
    }
    return $pairs;
  }

  /**
   * Formats pairs as text, one per line.
   *
   * @param array<mixed> $pairs
   *   The pairs.
   * @param string $separator
   *   What goes between the name and the value, for example ": ".
   */
  public static function formatPairs(array $pairs, string $separator): string {
    $lines = [];
    foreach ($pairs as $name => $value) {
      $lines[] = $name . $separator . (is_scalar($value) ? (string) $value : '');
    }
    return implode("\n", $lines);
  }

}
