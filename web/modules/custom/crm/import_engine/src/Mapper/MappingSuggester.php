<?php

declare(strict_types=1);

namespace Drupal\import_engine\Mapper;

use Drupal\import_engine\Target\TargetField;

/**
 * Suggests which source values fill which fields of a target.
 *
 * It looks at the names: the paths found in sample items against the names of
 * the fields. The words of both are compared (customerNumber and
 * customer_number are the same words), a few words that mean the same count
 * as equal (a label is a name), and a value that is more than one thing, such
 * as an amount with a currency, is looked for as a pair next to each other.
 * It only suggests: what it finds is a start for a person to check, never a
 * decision. A field it is not sure about is left alone.
 */
final class MappingSuggester {

  /**
   * The share of words that a field and a path must have in common.
   *
   * Half: a path that has only a third of its words in common with a field is
   * more often a coincidence than a match.
   */
  private const MINIMUM = 0.5;

  /**
   * The same for an amount, which is looked for by the name of what holds it.
   */
  private const MINIMUM_AMOUNT = 0.33;

  /**
   * Words that mean the same for a field and a path, besides themselves.
   *
   * @var array<string, list<string>>
   */
  private const SYNONYMS = [
    'name' => ['label', 'title'],
    'title' => ['label', 'name'],
    'label' => ['name', 'title'],
    'notes' => ['description', 'body', 'remarks'],
    'summary' => ['description', 'teaser', 'excerpt'],
    'description' => ['summary', 'body', 'notes'],
    'number' => ['code', 'no'],
    'code' => ['number', 'sku'],
    'sku' => ['code'],
    'active' => ['status', 'enabled'],
    'status' => ['active', 'enabled', 'published'],
  ];

  /**
   * The words that stand for an amount and for a currency in a path.
   */
  private const AMOUNT = ['amount', 'number', 'value', 'price', 'total'];

  /**
   * The words that stand for a currency.
   */
  private const CURRENCY = ['currency', 'currencycode', 'curr'];

  /**
   * Constructs the suggester.
   */
  public function __construct(
    private readonly MapperPluginManager $mappers,
  ) {
  }

  /**
   * Suggests a mapping for the fields that are not mapped yet.
   *
   * @param array<string, \Drupal\import_engine\Target\TargetField> $fields
   *   The fields of the target, by name.
   * @param array<string, array{type: string, example: string}> $paths
   *   The paths found in the sample items, with the type of their value.
   * @param list<string> $skip
   *   The names of the fields that are mapped already and are left alone.
   *
   * @return array<string, array{mapper: string, sources: array<string, string>}>
   *   The suggestion for every field it found something for, by field name:
   *   the mapper, and the path of each of its sources.
   */
  public function suggest(array $fields, array $paths, array $skip = []): array {
    // A list or an object cannot fill a field with one value.
    $usable = array_filter($paths, static fn (array $info): bool => $info['type'] !== 'list');
    $suggestions = [];
    foreach ($fields as $name => $field) {
      if (in_array($name, $skip, TRUE)) {
        continue;
      }
      $ids = $this->mappers->idsForFieldType($field->type);
      if ($ids === []) {
        continue;
      }
      $mapper = $ids[0];
      $sources = match ($mapper) {
        'money' => $this->money($field, $usable),
        'string', 'text', 'boolean', 'number', 'timestamp' => $this->single($field, $usable, $mapper),
        default => [],
      };
      if ($sources !== []) {
        $suggestions[$name] = ['mapper' => $mapper, 'sources' => $sources];
      }
    }
    return $suggestions;
  }

  /**
   * Finds the path for a mapper that reads one value.
   *
   * @param \Drupal\import_engine\Target\TargetField $field
   *   The field.
   * @param array<string, array{type: string, example: string}> $paths
   *   The paths that can be used.
   * @param string $mapper
   *   The mapper plugin.
   *
   * @return array<string, string>
   *   The source and its path, or nothing.
   */
  private function single(TargetField $field, array $paths, string $mapper): array {
    $best = NULL;
    $best_score = 0.0;
    foreach ($paths as $path => $info) {
      if (!$this->fits($mapper, $info['type'])) {
        continue;
      }
      $score = $this->similarity($this->words($field->name), $this->words((string) $path), TRUE);
      // A shorter path is the more direct value when the score is the same.
      if ($score > $best_score || ($score === $best_score && $best !== NULL && strlen((string) $path) < strlen($best))) {
        $best = (string) $path;
        $best_score = $score;
      }
    }
    return $best !== NULL && $best_score >= self::MINIMUM ? ['value' => $best] : [];
  }

  /**
   * Finds the paths of an amount and its currency.
   *
   * @param \Drupal\import_engine\Target\TargetField $field
   *   The field.
   * @param array<string, array{type: string, example: string}> $paths
   *   The paths that can be used.
   *
   * @return array<string, string>
   *   The paths of the amount and of the currency, or nothing.
   */
  private function money(TargetField $field, array $paths): array {
    $best = NULL;
    $best_score = 0.0;
    foreach ($paths as $path => $info) {
      $parts = explode('.', (string) $path);
      $last = $this->words((string) end($parts));
      if (array_intersect($last, self::AMOUNT) === [] || !in_array($info['type'], ['string', 'integer', 'float', 'null'], TRUE)) {
        continue;
      }
      // The name of what holds the amount says what it is the amount of: the
      // path without its last part, or the last part itself when it is alone.
      $owner = count($parts) > 1 ? implode('.', array_slice($parts, 0, -1)) : (string) $path;
      $score = $this->similarity($this->words($field->name), $this->words($owner), FALSE);
      if ($score > $best_score) {
        $best = (string) $path;
        $best_score = $score;
      }
    }
    if ($best === NULL || $best_score < self::MINIMUM_AMOUNT) {
      return [];
    }
    $sources = ['amount' => $best];
    $parts = explode('.', $best);
    $parent = implode('.', array_slice($parts, 0, -1));
    foreach (array_keys($paths) as $path) {
      $path = (string) $path;
      $siblings = explode('.', $path);
      if (implode('.', array_slice($siblings, 0, -1)) === $parent && array_intersect($this->words((string) end($siblings)), self::CURRENCY) !== []) {
        $sources['currency'] = $path;
        break;
      }
    }
    return $sources;
  }

  /**
   * Returns whether a value of a type can fill a field of a mapper.
   */
  private function fits(string $mapper, string $type): bool {
    return match ($mapper) {
      'boolean' => in_array($type, ['boolean', 'string', 'integer', 'null'], TRUE),
      'number' => in_array($type, ['integer', 'float', 'string', 'null'], TRUE),
      default => $type !== 'list',
    };
  }

  /**
   * Splits a name in its lower case words.
   *
   * Dots, underscores, dashes and the capitals of camelCase separate words:
   * customerNumber, customer_number and customer.number are the same words.
   *
   * @return list<string>
   *   The words.
   */
  private function words(string $name): array {
    $name = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $name);
    $name = (string) preg_replace('/^field[_ ]/i', '', $name);
    $words = preg_split('/[^A-Za-z0-9]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY);
    return $words === FALSE ? [] : $words;
  }

  /**
   * Says how alike the words of a field and of a path are, from 0 to 1.
   *
   * @param list<string> $field
   *   The words of the name of the field.
   * @param list<string> $path
   *   The words of the path.
   * @param bool $synonyms
   *   Whether words that mean the same count as equal.
   */
  private function similarity(array $field, array $path, bool $synonyms): float {
    if ($field === [] || $path === []) {
      return 0.0;
    }
    $matched = 0;
    foreach ($field as $word) {
      $equal = $synonyms ? [$word, ...(self::SYNONYMS[$word] ?? [])] : [$word];
      if (array_intersect($equal, $path) !== []) {
        $matched++;
      }
    }
    // Words of the path that the field has nothing for count against it.
    return $matched / count(array_unique([...$field, ...$path]));
  }

}
