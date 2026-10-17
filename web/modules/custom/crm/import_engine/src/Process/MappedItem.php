<?php

declare(strict_types=1);

namespace Drupal\import_engine\Process;

/**
 * What mapping a source item gave: the values, and what is worth saying.
 */
final class MappedItem {

  /**
   * Constructs the result.
   *
   * @param array<string, mixed> $values
   *   The value for each mapped target field, in the form the field accepts.
   * @param list<string> $notes
   *   What happened that a person may want to know without the item failing: a
   *   value that was not valid and was left out. Without the values themselves.
   */
  public function __construct(
    public readonly array $values,
    public readonly array $notes = [],
  ) {
  }

}
