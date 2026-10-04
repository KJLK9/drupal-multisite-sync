<?php

declare(strict_types=1);

namespace Drupal\import_engine\Run;

/**
 * An import already has a run that is not finished.
 */
final class RunAlreadyActiveException extends \RuntimeException {

  /**
   * Constructs the exception.
   */
  public function __construct(
    string $definitionId,
    public readonly int $runId,
  ) {
    parent::__construct(sprintf('Run %d of the import "%s" is still active.', $runId, $definitionId));
  }

}
