<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\DeadLetter;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\import_engine\Process\ProcessStage;
use Drupal\import_engine\Run\ImportRunInterface;
use Drupal\import_engine\Storage\EventLog;
use Drupal\import_engine\Storage\EventType;
use Drupal\import_engine\Storage\ItemState;
use Drupal\import_engine\Storage\ItemStorage;

/**
 * What a person can do with the items in the dead letter queue.
 *
 * Every action is written to the event log with the name of the person, so
 * the history of an item tells who retried or discarded it. The methods that
 * work as batch operations are called by the Batch API as
 * import_engine_ui.dead_letter:processNow and so on.
 */
final class DeadLetterOperations {

  use StringTranslationTrait;

  /**
   * How long an item belongs to the person who processes it now, in seconds.
   */
  private const LEASE_SECONDS = 300;

  /**
   * The items per batch operation.
   */
  public const CHUNK = 10;

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly ItemStorage $items,
    private readonly ProcessStage $process,
    private readonly EventLog $events,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly MessengerInterface $messenger,
  ) {
  }

  /**
   * Makes dead items pending again, with fresh attempts.
   *
   * @param list<int> $ids
   *   The item IDs.
   *
   * @return int
   *   How many items were requeued.
   */
  public function retry(array $ids): int {
    $this->audit($ids, EventType::Requeued, [ItemState::Dead, ItemState::Retrying], 'Retried by @user.');
    return $this->items->requeue($ids);
  }

  /**
   * Throws away dead items.
   *
   * @param list<int> $ids
   *   The item IDs.
   *
   * @return int
   *   How many items were discarded.
   */
  public function discard(array $ids): int {
    $this->audit($ids, EventType::Discarded, [ItemState::Dead], 'Discarded by @user.');
    return $this->items->discard($ids);
  }

  /**
   * Batch operation: retries dead items and handles them right now.
   *
   * @param list<int> $ids
   *   The item IDs of this operation.
   * @param array<string, mixed> $context
   *   The batch context.
   */
  public function processNow(array $ids, array &$context): void {
    $this->retry($ids);
    $result = $this->process->processItems('ui-' . $this->currentUser->id(), $ids, self::LEASE_SECONDS);
    $totals = $context['results'] ?? [];
    foreach ([
      'created' => $result->created,
      'updated' => $result->updated,
      'unchanged' => $result->unchanged,
      'retried' => $result->retried,
      'failed' => $result->failed,
    ] as $name => $count) {
      $totals[$name] = ($totals[$name] ?? 0) + $count;
    }
    $context['results'] = $totals;
    $context['message'] = (string) $this->t('Handled @count items.', ['@count' => array_sum($totals)]);
  }

  /**
   * Batch finished callback: tells what happened.
   *
   * @param bool $success
   *   Whether the batch completed.
   * @param array<string, int> $results
   *   The totals of the operations.
   * @param array<int, mixed> $operations
   *   The operations that were left, if the batch did not complete.
   */
  public function finished(bool $success, array $results, array $operations): void {
    if (!$success) {
      $this->messenger->addError($this->t('The batch stopped before it was done. Items that were not handled are pending.'));
      return;
    }
    $this->messenger->addStatus($this->t('Handled the items: @created created, @updated updated, @unchanged unchanged, @retried retrying, @failed failed.', [
      '@created' => (string) ($results['created'] ?? 0),
      '@updated' => (string) ($results['updated'] ?? 0),
      '@unchanged' => (string) ($results['unchanged'] ?? 0),
      '@retried' => (string) ($results['retried'] ?? 0),
      '@failed' => (string) ($results['failed'] ?? 0),
    ]));
  }

  /**
   * Writes an event for every item that the action will change.
   *
   * @param list<int> $ids
   *   The item IDs.
   * @param \Drupal\import_engine\Storage\EventType $type
   *   The event.
   * @param list<\Drupal\import_engine\Storage\ItemState> $states
   *   The states in which the action changes an item.
   * @param string $message
   *   The message; @user is replaced by the name of the person.
   */
  private function audit(array $ids, EventType $type, array $states, string $message): void {
    $definitions = [];
    $text = str_replace('@user', (string) $this->currentUser->getAccountName(), $message);
    foreach ($ids as $id) {
      $item = $this->items->find($id);
      if ($item === NULL || !in_array($item->state, $states, TRUE)) {
        continue;
      }
      $definitions[$item->runId] ??= $this->definitionOf($item->runId);
      $this->events->record($item->runId, $definitions[$item->runId], $type, $item->key, NULL, $text);
    }
  }

  /**
   * Returns the ID of the import a run belongs to.
   */
  private function definitionOf(int $runId): string {
    $run = $this->entityTypeManager->getStorage('import_run')->load($runId);
    return $run instanceof ImportRunInterface ? $run->getDefinitionId() : 'unknown';
  }

}
