<?php

declare(strict_types=1);

namespace Drupal\import_engine;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * A named list of imports that are run one after the other.
 *
 * Imports often depend on each other: the agreements of a catalog refer to its
 * accounts and its items, so those must be there first. A run set says in
 * which order, and stops at the first import that goes wrong, since what
 * follows would build on data that is not there.
 */
interface ImportRunSetInterface extends ConfigEntityInterface {

  /**
   * Returns the description.
   */
  public function getDescription(): string;

  /**
   * Returns the IDs of the imports, in the order they are run.
   *
   * @return list<string>
   *   The import IDs.
   */
  public function getImports(): array;

  /**
   * Returns whether the set also stops when items of an import went wrong.
   *
   * By default it stops when an import fails, is cancelled or cannot go on;
   * an import that is completed with errors (some items are in the dead
   * letter queue) lets the next one start.
   */
  public function stopsOnErrors(): bool;

}
