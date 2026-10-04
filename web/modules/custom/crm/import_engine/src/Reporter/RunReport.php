<?php

declare(strict_types=1);

namespace Drupal\import_engine\Reporter;

use Drupal\import_engine\Run\RunStatus;

/**
 * What a reporter is told about a finished run.
 */
final class RunReport {

  /**
   * Constructs the report.
   *
   * @param int $runId
   *   The run.
   * @param string $definitionId
   *   The import definition.
   * @param string $label
   *   The name of the import.
   * @param \Drupal\import_engine\Run\RunStatus $status
   *   How the run ended.
   * @param array<string, int> $counters
   *   The counters of the run.
   * @param string $summary
   *   The remarks of the run.
   * @param int|null $started
   *   When the run started.
   * @param int|null $finished
   *   When the run ended.
   * @param list<\Drupal\import_engine\Storage\EventRecord> $problems
   *   The first failed and dead items.
   */
  public function __construct(
    public readonly int $runId,
    public readonly string $definitionId,
    public readonly string $label,
    public readonly RunStatus $status,
    public readonly array $counters,
    public readonly string $summary,
    public readonly ?int $started,
    public readonly ?int $finished,
    public readonly array $problems,
  ) {
  }

  /**
   * Returns whether the run did not end as completed.
   */
  public function hasProblems(): bool {
    return $this->status !== RunStatus::Completed;
  }

  /**
   * Returns the subject line.
   */
  public function subject(): string {
    return sprintf('Import "%s": %s', $this->label, str_replace('_', ' ', $this->status->value));
  }

  /**
   * Returns the report as lines of plain text.
   *
   * @return list<string>
   *   The lines.
   */
  public function lines(): array {
    $lines = [sprintf('Import "%s" (run %d) ended as %s.', $this->label, $this->runId, $this->status->value)];
    if ($this->started !== NULL && $this->finished !== NULL) {
      $lines[] = sprintf('Duration: %d seconds.', max(0, $this->finished - $this->started));
    }
    if ($this->summary !== '') {
      $lines[] = $this->summary;
    }
    foreach ($this->counters as $name => $count) {
      $lines[] = sprintf('%s: %d', str_replace('_', ' ', $name), $count);
    }
    if ($this->problems !== []) {
      $lines[] = 'First problems:';
      foreach ($this->problems as $problem) {
        $lines[] = sprintf('- %s: %s', $problem->key, $problem->message ?? $problem->event->name);
      }
    }
    return $lines;
  }

}
