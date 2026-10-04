<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;

/**
 * Small helpers for the storage classes that read with select queries.
 */
trait QueryHelpers {

  /**
   * Runs a select query and returns its statement.
   *
   * The database API documents that execute() may return NULL, which for a
   * select query it never does for a supported driver.
   *
   * @return \Drupal\Core\Database\StatementInterface<\stdClass>
   *   The statement.
   */
  private function statement(SelectInterface $query): StatementInterface {
    $statement = $query->execute();
    if ($statement === NULL) {
      throw new \LogicException('The select query returned no result.');
    }
    return $statement;
  }

  /**
   * Runs a select query and returns its rows.
   *
   * @return list<\stdClass>
   *   The rows.
   */
  private function rows(SelectInterface $query): array {
    /** @var list<\stdClass> $rows */
    $rows = array_values($this->statement($query)->fetchAll());
    return $rows;
  }

  /**
   * Runs a select query and returns the values of its first column.
   *
   * @return list<string>
   *   The values.
   */
  private function column(SelectInterface $query): array {
    /** @var list<string> $values */
    $values = array_values($this->statement($query)->fetchCol());
    return $values;
  }

}
