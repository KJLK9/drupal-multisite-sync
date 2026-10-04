<?php

declare(strict_types=1);

namespace Drupal\import_engine\Reporter;

use Drupal\import_engine\ImportDefinitionInterface;
use Psr\Log\LoggerInterface;

/**
 * Tells the reporters of an import how a run went.
 *
 * A reporter that fails does not change the run: the failure is logged and the
 * other reporters still get the report.
 */
final class RunReporter {

  /**
   * Constructs the reporter.
   */
  public function __construct(
    private readonly ReporterPluginManager $reporters,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Sends the report to every reporter of the import.
   *
   * @return int
   *   How many reporters were told.
   */
  public function report(ImportDefinitionInterface $definition, RunReport $report): int {
    $told = 0;
    foreach ($definition->getReporters() as $row) {
      try {
        $reporter = $this->reporters->createInstance($row['plugin'], $row['configuration']);
        if (!$reporter instanceof ReporterInterface) {
          continue;
        }
        if ($reporter->getConfiguration()['only_on_problems'] && !$report->hasProblems()) {
          continue;
        }
        $reporter->report($report);
        $told++;
      }
      catch (\Throwable $exception) {
        $this->logger->error('Reporter @plugin of import @import failed: @message', [
          '@plugin' => $row['plugin'],
          '@import' => $report->definitionId,
          '@message' => $exception->getMessage(),
        ]);
      }
    }
    return $told;
  }

}
