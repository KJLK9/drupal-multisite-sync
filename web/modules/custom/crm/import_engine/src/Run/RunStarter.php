<?php

declare(strict_types=1);

namespace Drupal\import_engine\Run;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\import_engine\Entity\ImportRun;
use Drupal\import_engine\ImportDefinitionInterface;

/**
 * Starts runs: at most one that is not finished per import.
 */
final class RunStarter {

  /**
   * Constructs the starter.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LockBackendInterface $lock,
  ) {
  }

  /**
   * Creates a queued run for an import.
   *
   * @param \Drupal\import_engine\ImportDefinitionInterface $definition
   *   The import.
   * @param \Drupal\import_engine\Run\Trigger $trigger
   *   What starts the run.
   * @param int|null $uid
   *   The user who starts it, if a person does.
   * @param bool $fullRun
   *   Whether to process every page, even those that did not change.
   *
   * @throws \Drupal\import_engine\Run\RunAlreadyActiveException
   *   When the import has a run that is not finished.
   */
  public function start(ImportDefinitionInterface $definition, Trigger $trigger, ?int $uid = NULL, bool $fullRun = FALSE): ImportRun {
    $name = 'import_engine:start:' . $definition->id();
    // The lock keeps two requests from both finding no active run.
    if (!$this->lock->acquire($name, 30)) {
      $this->lock->wait($name, 5);
      if (!$this->lock->acquire($name, 30)) {
        throw new \RuntimeException('Another run is being started for this import; try again.');
      }
    }
    try {
      $active = $this->activeRunId((string) $definition->id());
      if ($active !== NULL) {
        throw new RunAlreadyActiveException((string) $definition->id(), $active);
      }
      $run = ImportRun::create([
        'definition_id' => $definition->id(),
        'trigger' => $trigger->value,
        'uid' => $uid,
        'full_run' => $fullRun,
      ]);
      $run->save();
      return $run;
    }
    finally {
      $this->lock->release($name);
    }
  }

  /**
   * Returns the ID of the unfinished run of an import, if there is one.
   */
  public function activeRunId(string $definitionId): ?int {
    $final = array_map(static fn (RunStatus $status): string => $status->value, array_filter(RunStatus::cases(), static fn (RunStatus $status): bool => $status->isFinal()));
    $ids = $this->entityTypeManager->getStorage('import_run')->getQuery()
      ->accessCheck(FALSE)
      ->condition('definition_id', $definitionId)
      ->condition('status', $final, 'NOT IN')
      ->range(0, 1)
      ->execute();
    return $ids === [] ? NULL : (int) reset($ids);
  }

}
