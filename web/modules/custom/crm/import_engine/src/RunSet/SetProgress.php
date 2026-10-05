<?php

declare(strict_types=1);

namespace Drupal\import_engine\RunSet;

/**
 * Where a run of a set is, and what the imports so far did.
 *
 * Immutable: every call of the runner returns the next one. It is plain data,
 * so it can wait in a batch between two calls.
 */
final class SetProgress {

  /**
   * Constructs the progress.
   *
   * @param int $index
   *   The position of the import that is on its turn.
   * @param int|null $runId
   *   The run of that import, once it has one.
   * @param list<array{import: string, run: int|null, status: string, summary: string}> $outcomes
   *   What the imports that are over did, in order.
   * @param \Drupal\import_engine\RunSet\SetState $state
   *   How the set is doing.
   * @param string|null $message
   *   Why the set stopped or waits.
   */
  public function __construct(
    public readonly int $index = 0,
    public readonly ?int $runId = NULL,
    public readonly array $outcomes = [],
    public readonly SetState $state = SetState::Running,
    public readonly ?string $message = NULL,
  ) {
  }

  /**
   * Returns the exit code for a command line caller.
   *
   * 0 when every import ran, 1 when the set stopped at a failure, 2 when it
   * is not over.
   */
  public function exitCode(): int {
    return match ($this->state) {
      SetState::Completed => 0,
      SetState::Stopped => 1,
      default => 2,
    };
  }

}
