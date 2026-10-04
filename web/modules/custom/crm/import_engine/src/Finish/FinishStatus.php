<?php

declare(strict_types=1);

namespace Drupal\import_engine\Finish;

/**
 * How a call of the finish stage ended.
 */
enum FinishStatus: string {

  // The run still has items to process or retry.
  case Waiting = 'waiting';

  // Another process is finishing this run.
  case Busy = 'busy';

  // The run is finished.
  case Finished = 'finished';

  // The run is not in a status to be finished: it is still extracting, or over.
  case NotApplicable = 'not_applicable';

}
