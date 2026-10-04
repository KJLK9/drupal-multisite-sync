<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

/**
 * Something that happened to an item and is worth keeping in the event log.
 *
 * Only changes and problems are logged, never "unchanged": the log grows with
 * what happens, not with the size of the dataset times the number of runs.
 */
enum EventType: int {

  case Created = 1;
  case Updated = 2;
  case Unpublished = 3;
  case Deleted = 4;
  case Failed = 5;
  case Dead = 6;
  case Requeued = 7;
  case Discarded = 8;

}
