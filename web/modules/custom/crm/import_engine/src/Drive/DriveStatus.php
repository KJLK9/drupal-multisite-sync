<?php

declare(strict_types=1);

namespace Drupal\import_engine\Drive;

/**
 * How a call of the run driver ended.
 */
enum DriveStatus: string {

  // The run is over; see its status.
  case Finished = 'finished';

  // The budget ran out or a stop was requested; call again to continue.
  case OutOfBudget = 'out_of_budget';

  // Everything that could be done is done; items wait for a retry or for
  // another worker, so call again later.
  case Waiting = 'waiting';

  // The source had a temporary problem; call again later.
  case Interrupted = 'interrupted';

  // Another process is working on this part of the run.
  case Busy = 'busy';

}
