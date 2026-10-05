<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Run;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\ImportRunSetInterface;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\RunSet\RunSetRunner;
use Drupal\import_engine\RunSet\SetProgress;
use Drupal\import_engine\RunSet\SetState;

/**
 * Runs a set of imports from the interface, with a progress bar.
 *
 * Like RunBatch, in calls of a few seconds: every call gives the runner the
 * progress of the previous one. Called by the Batch API as
 * import_engine_ui.run_set_batch:drive and :finished.
 */
final class RunSetBatch {

  use StringTranslationTrait;

  /**
   * The seconds one call of the batch works.
   */
  public const SECONDS = 15;

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly RunSetRunner $runner,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly MessengerInterface $messenger,
    private readonly TimeInterface $time,
    private readonly int $seconds = self::SECONDS,
  ) {
  }

  /**
   * Batch operation: works on a set for a few seconds.
   *
   * @param string $setId
   *   The run set.
   * @param bool $full
   *   Whether the runs process every page.
   * @param array<string, mixed> $context
   *   The batch context.
   */
  public function drive(string $setId, bool $full, array &$context): void {
    $set = $this->entityTypeManager->getStorage('import_run_set')->load($setId);
    if (!$set instanceof ImportRunSetInterface) {
      $context['results'] = ['progress' => new SetProgress(state: SetState::Stopped, message: (string) $this->t('The run set no longer exists.'))];
      $context['finished'] = 1;
      return;
    }
    $progress = $context['results']['progress'] ?? new SetProgress();
    $budget = new RunBudget($this->time->getCurrentTime() + $this->seconds);
    $progress = $this->runner->run($set, $progress, $budget, 'ui-' . $this->currentUser->id(), Trigger::Ui, (int) $this->currentUser->id(), $full);
    $context['results'] = ['progress' => $progress, 'set' => $setId];

    $total = max(1, count($set->getImports()));
    $imports = $set->getImports();
    $context['message'] = (string) $this->t('Import @n of @total: @import.', [
      '@n' => (string) min($total, $progress->index + 1),
      '@total' => (string) $total,
      '@import' => $imports[$progress->index] ?? '',
    ]);
    // The imports that are over count in full; the one that is busy for half.
    $context['finished'] = $progress->state === SetState::Running ? min(0.99, ($progress->index + 0.5) / $total) : 1;
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
    $progress = $results['progress'] ?? NULL;
    if (!$success || !$progress instanceof SetProgress) {
      $this->messenger->addError($this->t('The set stopped unexpectedly. Run it again to go on.'));
      return;
    }
    foreach ($progress->outcomes as $outcome) {
      $args = ['@import' => $outcome['import'], '@status' => str_replace('_', ' ', $outcome['status'])];
      if ($outcome['status'] === 'completed') {
        $this->messenger->addStatus($this->t('@import: @status.', $args));
      }
      else {
        $this->messenger->addWarning($this->t('@import: @status.', $args));
      }
    }
    match ($progress->state) {
      SetState::Completed => $this->messenger->addStatus($this->t('The set is complete.')),
      SetState::Stopped => $this->messenger->addError((string) $progress->message),
      default => $this->messenger->addWarning($this->t('The set is not over. @reason Run it again to go on; an import with a run that is not over continues it.', [
        '@reason' => (string) $progress->message,
      ])),
    };
  }

}
