<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

/**
 * What handling an item resulted in (a small integer in the table).
 */
enum Outcome: int {

  case Created = 1;
  case Updated = 2;
  case Unchanged = 3;
  case Skipped = 4;
  case Failed = 5;

}
