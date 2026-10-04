<?php

declare(strict_types=1);

namespace Drupal\import_engine\Run;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * One execution of an import: its status, progress and a snapshot of counters.
 *
 * The live counters of a running import are derived from the items; the
 * counters on the run are the snapshot stored when the run finishes.
 */
interface ImportRunInterface extends ContentEntityInterface {

  /**
   * The counters kept on a run.
   */
  public const COUNTERS = [
    'items_extracted',
    'created',
    'updated',
    'unchanged',
    'skipped',
    'failed',
    'dead',
    'deleted',
    'page_skipped',
  ];

  /**
   * Returns the ID of the import definition this run belongs to.
   */
  public function getDefinitionId(): string;

  /**
   * Returns the status.
   */
  public function getStatus(): RunStatus;

  /**
   * Moves the run to another status.
   *
   * Sets the start time when extraction begins and the finish time when the
   * run reaches a final status.
   *
   * @param \Drupal\import_engine\Run\RunStatus $status
   *   The new status.
   * @param int|null $now
   *   The time, for tests; the current time by default.
   *
   * @throws \LogicException
   *   When the run may not move to that status from where it is.
   */
  public function transitionTo(RunStatus $status, ?int $now = NULL): static;

  /**
   * Returns what started the run.
   */
  public function getTrigger(): Trigger;

  /**
   * Returns whether every page is processed, even if it did not change.
   */
  public function isFullRun(): bool;

  /**
   * Returns whether every page of the source was fetched.
   *
   * Only then may the sweep for deleted items run.
   */
  public function isExtractComplete(): bool;

  /**
   * Sets whether every page of the source was fetched.
   */
  public function setExtractComplete(bool $complete): static;

  /**
   * Returns where extraction is, so it can resume.
   */
  public function getCursor(): ?string;

  /**
   * Sets where extraction is.
   */
  public function setCursor(?string $cursor): static;

  /**
   * Returns the number of pages read.
   */
  public function getPagesRead(): int;

  /**
   * Sets the number of pages read.
   */
  public function setPagesRead(int $pages): static;

  /**
   * Returns the counters.
   *
   * @return array<string, int>
   *   The value of each of COUNTERS.
   */
  public function getCounters(): array;

  /**
   * Sets counters; those not given stay as they are.
   *
   * @param array<string, int> $counters
   *   Values keyed by counter name, which must be one of COUNTERS.
   */
  public function setCounters(array $counters): static;

  /**
   * Returns the short summary of errors and warnings.
   */
  public function getSummary(): string;

  /**
   * Sets the summary.
   */
  public function setSummary(string $summary): static;

}
