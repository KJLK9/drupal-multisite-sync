<?php

declare(strict_types=1);

namespace Drupal\import_engine\Source;

/**
 * Reads and writes the variables of a GraphQL query as plain lines.
 *
 * A person types <code>first = 100</code>; the source keeps JSON. A text that
 * starts with a brace is taken as JSON as it is, so that nested values stay
 * possible. Numbers, true, false and null are those types; a text in double
 * quotes is a string; anything else is a string.
 */
final class GraphqlVariables {

  /**
   * Turns what was typed into the JSON the source keeps.
   *
   * @param string $text
   *   Lines of <code>name = value</code>, or a JSON object.
   *
   * @return string
   *   JSON, or an empty string when there are no variables.
   *
   * @throws \InvalidArgumentException
   *   When a line or the JSON cannot be read.
   */
  public static function toJson(string $text): string {
    $text = trim($text);
    if ($text === '') {
      return '';
    }
    if (str_starts_with($text, '{')) {
      $decoded = json_decode($text, TRUE);
      if (!is_array($decoded)) {
        throw new \InvalidArgumentException('The variables are not a valid JSON object.');
      }
      return $decoded === [] ? '' : (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $variables = [];
    foreach (preg_split('/\R/', $text) ?: [] as $number => $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $match)) {
        throw new \InvalidArgumentException(sprintf('Line %d: write a variable as name = value.', $number + 1));
      }
      $variables[$match[1]] = self::typed(trim($match[2]));
    }
    return $variables === [] ? '' : (string) json_encode($variables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  /**
   * Turns the JSON a source keeps into lines to edit, if that is possible.
   *
   * @param string $json
   *   The stored JSON.
   *
   * @return string
   *   Lines of <code>name = value</code>, or the JSON itself when it has
   *   nested values that lines cannot show.
   */
  public static function toLines(string $json): string {
    $json = trim($json);
    $decoded = $json === '' ? [] : json_decode($json, TRUE);
    if (!is_array($decoded)) {
      return $json;
    }
    $lines = [];
    foreach ($decoded as $name => $value) {
      if (!is_scalar($value) && $value !== NULL) {
        return $json;
      }
      $lines[] = $name . ' = ' . match (TRUE) {
        $value === NULL => 'null',
        is_bool($value) => $value ? 'true' : 'false',
        is_string($value) => self::needsQuotes($value) ? '"' . $value . '"' : $value,
        default => (string) $value,
      };
    }
    return implode("\n", $lines);
  }

  /**
   * Reads one value as the type it looks like.
   */
  private static function typed(string $value): mixed {
    return match (TRUE) {
      $value === 'true' => TRUE,
      $value === 'false' => FALSE,
      $value === 'null' => NULL,
      (bool) preg_match('/^-?\d+$/', $value) => (int) $value,
      (bool) preg_match('/^-?\d+\.\d+$/', $value) => (float) $value,
      strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"') => substr($value, 1, -1),
      default => $value,
    };
  }

  /**
   * Says whether a string would be read back as another type without quotes.
   */
  private static function needsQuotes(string $value): bool {
    return self::typed($value) !== $value || $value === '' || $value !== trim($value);
  }

}
