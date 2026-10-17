<?php

declare(strict_types=1);

namespace Drupal\import_engine\Process;

use Drupal\import_engine\Mapper\MappingException;
use Drupal\import_engine\Path\PathResolver;
use Drupal\import_engine\Target\TargetInterface;
use Drupal\import_engine\Validator\OnInvalid;
use Drupal\import_engine\Validator\SkipItemException;

/**
 * What it takes to turn a source item into a written item: the mapping.
 *
 * Built once per import from its definition: the target, and for every mapping
 * row the mapper, the target field and where each named source is read.
 */
final class ImportPlan {

  /**
   * Constructs the plan.
   *
   * @param \Drupal\import_engine\Target\TargetInterface $target
   *   The target.
   * @param list<array{field: \Drupal\import_engine\Target\TargetField, mapper: \Drupal\import_engine\Mapper\MapperInterface, paths: array<string, string>, validators: list<\Drupal\import_engine\Validator\ValidatorInterface>, on_invalid: \Drupal\import_engine\Validator\OnInvalid}> $rows
   *   The mapping rows: the target field, the mapper, the dotted path of every
   *   source name the mapper reads, the validators of the value and what
   *   happens when it is not valid.
   * @param \Drupal\import_engine\Path\PathResolver $paths
   *   The path resolver.
   */
  public function __construct(
    public readonly TargetInterface $target,
    private readonly array $rows,
    private readonly PathResolver $paths,
  ) {
  }

  /**
   * Maps a source item to values for the target.
   *
   * @param array<string, mixed> $item
   *   The source item.
   *
   * @return array<string, mixed>
   *   The value for each mapped target field, in the form the field accepts.
   *
   * @throws \Drupal\import_engine\Mapper\MappingException
   *   When a value cannot be mapped.
   * @throws \Drupal\import_engine\Mapper\DependencyNotReadyException
   *   When a value refers to something that is not imported yet.
   */
  public function map(array $item): array {
    return $this->mapWithNotes($item)->values;
  }

  /**
   * Maps a source item, and says what was left out.
   *
   * A value that is not valid is handled as its row says: the item fails, the
   * field stays empty (and that is a note), or the item is skipped.
   *
   * @param array<string, mixed> $item
   *   The source item.
   *
   * @throws \Drupal\import_engine\Mapper\MappingException
   *   When a value cannot be mapped, or is not valid and the row says fail.
   * @throws \Drupal\import_engine\Mapper\DependencyNotReadyException
   *   When a value refers to something that is not imported yet.
   * @throws \Drupal\import_engine\Validator\SkipItemException
   *   When a value is not valid and the row says the item is left out.
   */
  public function mapWithNotes(array $item): MappedItem {
    $values = [];
    $notes = [];
    foreach ($this->rows as $row) {
      $sources = [];
      foreach ($row['paths'] as $name => $path) {
        $sources[$name] = $this->paths->get($item, $path);
      }
      $value = $row['mapper']->map($sources, $row['field']);
      foreach ($row['validators'] as $validator) {
        $subject = self::subject($value);
        if (($subject === NULL || $subject === '') && !$validator->validatesEmpty()) {
          continue;
        }
        $outcome = $validator->validate($subject ?? '');
        if ($outcome->valid) {
          $value = self::withSubject($value, $outcome->value);
          continue;
        }
        $what = sprintf('Field "%s": the value %s', $row['field']->name, $outcome->reason);
        switch ($row['on_invalid']) {
          case OnInvalid::Empty:
            $value = NULL;
            $notes[] = $what . '; left empty.';
            break 2;

          case OnInvalid::SkipItem:
            throw new SkipItemException($what . '; the item was not imported.');

          default:
            throw new MappingException($what . '.');
        }
      }
      $values[$row['field']->name] = $value;
    }
    return new MappedItem($values, $notes);
  }

  /**
   * Returns the value a validator looks at: a single text or number, or NULL.
   *
   * The text of a formatted text is its value, not its format; a value of
   * another shape (a reference, an amount with a currency) has nothing to
   * check here and is let through.
   */
  private static function subject(mixed $value): string|int|float|NULL {
    if (is_array($value)) {
      $value = $value['value'] ?? NULL;
    }
    return is_string($value) || is_int($value) || is_float($value) ? $value : NULL;
  }

  /**
   * Puts a normalized value back where the subject came from.
   */
  private static function withSubject(mixed $value, mixed $normalized): mixed {
    if (is_array($value)) {
      return isset($value['value']) ? ['value' => $normalized] + $value : $value;
    }
    return is_string($value) || is_int($value) || is_float($value) ? $normalized : $value;
  }

}
