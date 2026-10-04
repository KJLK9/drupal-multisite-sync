<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

/**
 * Where an item is in its life in the work queue.
 *
 * Stored as a small integer.
 */
enum ItemState: int {

  // Waiting for a worker.
  case Pending = 0;

  // Claimed by a worker, under a lease.
  case Processing = 1;

  // Handled; the payload is dropped.
  case Done = 2;

  // Failed, will be tried again at next_attempt.
  case Retrying = 3;

  // Out of attempts and kept with its payload: the dead letter queue.
  case Dead = 4;

}
