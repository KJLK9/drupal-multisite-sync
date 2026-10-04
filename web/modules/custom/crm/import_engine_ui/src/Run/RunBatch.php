<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Run;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\import_engine\Drive\DriveStatus;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Drive\RunDriver;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Run\ImportRunInterface;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Storage\ItemStorage;

/**
 * Drives a run from the interface, with a progress bar.
 *
 * A request cannot last for hours, so the run is driven in calls of a few
 * seconds: every call continues where the previous one stopped, which the
 * engine is built for (ADR 0012). The Batch API calls drive() again and again
 * until the run is over or cannot go on. Called by the Batch API as
 * import_engine_ui.run_batch:drive and import_engine_ui.run_batch:finished.
 */
final class RunBatch {

  use StringTranslationTrait;

  /**
   * The seconds one call of the batch works.
   */
  public const SECONDS = 15;

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly RunDriver $driver,
    private readonly ItemStorage $items,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly MessengerInterface $messenger,
    private readonly TimeInterface $time,
    private readonly int $seconds = self::SECONDS,
  ) {
  }

  /**
   * Batch operation: works on a run for a few seconds.
   *
   * @param int $runId
   *   The run.
   * @param array<string, mixed> $context
   *   The batch context.
   */
  public function drive(int $runId, array &$context): void {
    $run = $this->entityTypeManager->getStorage('import_run')->loadUnchanged($runId);
    $definition = $run instanceof ImportRunInterface ? ImportDefinition::load($run->getDefinitionId()) : NULL;
    $results = $context['results'] + ['run' => $runId, 'status' => '', 'pages' => 0, 'items' => 0, 'stopped' => NULL];
    if (!$run instanceof ImportRunInterface || $definition === NULL) {
      $results['stopped'] = 'The run or its import no longer exists.';
      $context['results'] = $results;
      $context['finished'] = 1;
      return;
    }
    if ($run->getStatus()->isFinal()) {
      $results['status'] = $run->getStatus()->value;
      $context['results'] = $results;
      $context['finished'] = 1;
      return;
    }

    $budget = new RunBudget($this->time->getCurrentTime() + $this->seconds);
    $result = $this->driver->drive($run, $definition, $budget, 'ui-' . $this->currentUser->id());
    $results['status'] = $result->runStatus->value;
    $results['pages'] += $result->pages;
    $results['items'] += $result->items;
    $context['results'] = $results;
    $context['message'] = (string) $this->t('Run @id: @pages pages read, @items items handled.', [
      '@id' => (string) $runId,
      '@pages' => (string) $results['pages'],
      '@items' => (string) $results['items'],
    ]);

    if ($result->status === DriveStatus::OutOfBudget) {
      $current = $this->entityTypeManager->getStorage('import_run')->loadUnchanged($runId);
      $context['finished'] = $current instanceof ImportRunInterface ? $this->progress($current) : 0.5;
      return;
    }
    if ($result->status !== DriveStatus::Finished) {
      // It cannot go on now: it waits for retries, for another process, or
      // for the source to come back. A worker or cron carries on later.
      $results['stopped'] = $result->message ?? $result->status->value;
      $context['results'] = $results;
    }
    $context['finished'] = 1;
  }

  /**
   * Batch finished callback: tells how it went.
   *
   * @param bool $success
   *   Whether the batch completed.
   * @param array<string, mixed> $results
   *   What the operation collected.
   * @param array<int, mixed> $operations
   *   The operations that were left, if the batch did not complete.
   */
  public function finished(bool $success, array $results, array $operations): void {
    if (!$success || !isset($results['run'])) {
      $this->messenger->addError($this->t('The run stopped unexpectedly. It can be continued.'));
      return;
    }
    $args = [
      '@id' => (string) $results['run'],
      '@pages' => (string) $results['pages'],
      '@items' => (string) $results['items'],
    ];
    if ($results['stopped'] !== NULL) {
      $this->messenger->addWarning($this->t('Run @id is not over: @reason Continue it later, with a worker (drush import:work) or by running the import again.', $args + ['@reason' => (string) $results['stopped']]));
      return;
    }
    match ($results['status']) {
      RunStatus::Completed->value => $this->messenger->addStatus($this->t('Run @id completed: @pages pages read, @items items handled.', $args)),
      RunStatus::CompletedWithErrors->value => $this->messenger->addWarning($this->t('Run @id completed with errors: @pages pages read, @items items handled. See the run for the items that failed.', $args)),
      RunStatus::Failed->value => $this->messenger->addError($this->t('Run @id failed. See the run for the reason.', $args)),
      RunStatus::Cancelled->value => $this->messenger->addWarning($this->t('Run @id was cancelled.', $args)),
      default => $this->messenger->addWarning($this->t('Run @id is not over yet.', $args)),
    };
  }

  /**
   * Estimates how far a run is, between 0 and 1.
   *
   * The source does not say how much there is, so reading it counts for a
   * tenth; after that the items that are done show the way.
   */
  private function progress(ImportRunInterface $run): float {
    return match ($run->getStatus()) {
      RunStatus::Queued, RunStatus::Extracting => 0.1,
      RunStatus::Processing => $this->processingProgress((int) $run->id()),
      default => 0.95,
    };
  }

  /**
   * Returns the progress of a run that is processing.
   */
  private function processingProgress(int $runId): float {
    $states = $this->items->countByState($runId);
    $total = array_sum($states);
    if ($total === 0) {
      return 0.9;
    }
    return 0.1 + 0.8 * (($states['done'] + $states['dead']) / $total);
  }

}
