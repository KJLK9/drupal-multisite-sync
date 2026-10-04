<?php

declare(strict_types=1);

namespace Drupal\import_engine\Extract;

/**
 * How a call of the extract stage ended.
 */
enum ExtractStatus: string {

  // Every page was read; the run moved on to processing.
  case Complete = 'complete';

  // The page or time budget of the call ran out; call again to continue.
  case MoreToDo = 'more_to_do';

  // The source had a temporary problem; the position is kept, call again later.
  case Interrupted = 'interrupted';

  // Extraction ended abnormally; the run drains what is queued and fails.
  case Failed = 'failed';

  // Another extraction of the same import is running.
  case Busy = 'busy';

}
