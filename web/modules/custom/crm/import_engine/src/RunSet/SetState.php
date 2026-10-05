<?php

declare(strict_types=1);

namespace Drupal\import_engine\RunSet;

/**
 * Where a run of a set has got to.
 */
enum SetState: string {

  // Going on: the budget of this call is spent, or the next import follows.
  case Running = 'running';

  // Every import ran and none went wrong.
  case Completed = 'completed';

  // An import went wrong, so the imports after it did not run.
  case Stopped = 'stopped';

  // An import cannot go on now (it waits for retries, or for the source).
  case Waiting = 'waiting';

}
