<?php

declare(strict_types=1);

namespace Drupal\import_engine\Run;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\import_engine\Storage\ItemStorage;

/**
 * Actions on runs that people ask for: cancel, retry dead items, look up.
 */
final class RunManager {

  /**
   * Constructs the manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ItemStorage $items,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Cancels a run that is not over.
   *
   * Items that wait are skipped. Items a worker is handling right now are
   * finished by that worker. A cancelled run is not swept and not reported.
   * It cannot be cancelled while extraction or finishing is in progress, as
   * those would write over the status; stop them first, or wait.
   *
   * @param \Drupal\import_engine\Run\ImportRunInterface $run
   *   The run.
   *
   * @return int
   *   How many waiting items were skipped.
   *
   * @throws \Drupal\import_engine\Run\RunBusyException
   *   When extraction or finishing of the run is in progress.
   * @throws \LogicException
   *   When the run is already over.
   */
  public function cancel(ImportRunInterface $run): int {
    $locks = [
      'import_engine:extract:' . $run->getDefinitionId(),
      'import_engine:finish:' . $run->id(),
    ];
    $held = [];
    try {
      foreach ($locks as $name) {
        if (!$this->lock->acquire($name, 30)) {
          throw new RunBusyException(sprintf('Run %d is being extracted or finished by another process.', $run->id()));
        }
        $held[] = $name;
      }
      $current = $this->entityTypeManager->getStorage('import_run')->loadUnchanged((int) $run->id());
      if (!$current instanceof ImportRunInterface) {
        throw new \LogicException('The run no longer exists.');
      }
      $now = $this->time->getCurrentTime();
      $current->setSummary('Cancelled.')->transitionTo(RunStatus::Cancelled, $now)->save();
      return $this->items->skipWaiting((int) $current->id(), $now);
    }
    finally {
      foreach ($held as $name) {
        $this->lock->release($name);
      }
    }
  }

  /**
   * Makes the dead items of an import pending again, with fresh attempts.
   *
   * Dead items of earlier runs are included: they are processed by a worker
   * like any other item, also when their run is long over.
   *
   * @param string $definitionId
   *   The import.
   * @param int $limit
   *   The most items to requeue.
   *
   * @return int
   *   How many items were requeued.
   */
  public function retryDead(string $definitionId, int $limit = 1000): int {
    $runs = array_values(array_map(intval(...), $this->entityTypeManager->getStorage('import_run')->getQuery()
      ->accessCheck(FALSE)
      ->condition('definition_id', $definitionId)
      ->execute()));
    return $this->items->requeue($this->items->deadIds($runs, $limit));
  }

  /**
   * Returns the latest run of an import, if it has one.
   */
  public function latest(string $definitionId): ?ImportRunInterface {
    $storage = $this->entityTypeManager->getStorage('import_run');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('definition_id', $definitionId)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    $run = $ids === [] ? NULL : $storage->load(reset($ids));
    return $run instanceof ImportRunInterface ? $run : NULL;
  }

}
