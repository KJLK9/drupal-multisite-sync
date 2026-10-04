<?php

declare(strict_types=1);

namespace Drupal\import_engine\Source;

/**
 * The severity of a message from checking a source.
 */
enum Severity: string {

  // Blocks saving the import.
  case Error = 'error';

  // Worth knowing, does not block.
  case Warning = 'warning';

  // Confirms something works.
  case Info = 'info';

}
