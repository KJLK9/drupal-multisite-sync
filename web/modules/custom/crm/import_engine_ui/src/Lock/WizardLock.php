<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Lock;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Lets one person at a time edit an import in the wizard.
 *
 * The wizard keeps the whole import in the form and saves it at the end, so two
 * people editing the same import would overwrite each other without either
 * knowing: the one who saves last wins. Whoever opens an import takes the lock;
 * a second person is told who has it. The lock ends when the import is saved,
 * after 15 minutes without a step (every step and every AJAX call renews it),
 * or when someone takes it over on purpose; the person who lost it is told at
 * the next step and nothing of what they had is saved over what the other did.
 * The same person in two tabs is not locked out of their own work.
 */
final class WizardLock {

  /**
   * How long a lock lasts without a step, in seconds.
   */
  public const SECONDS = 900;

  /**
   * Constructs the lock.
   */
  public function __construct(
    private readonly KeyValueExpirableFactoryInterface $factory,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Takes the lock of an import, or renews it, for a person.
   *
   * @param string $id
   *   The ID of the import.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person.
   *
   * @return array{uid: int, name: string, since: int, until: int}|null
   *   NULL when the person has the lock now; who has it when somebody else
   *   does.
   */
  public function acquire(string $id, AccountInterface $account): ?array {
    $now = $this->time->getCurrentTime();
    $store = $this->store();
    $held = $this->holder($id);
    if ($held !== NULL && $held['uid'] !== (int) $account->id()) {
      return $held;
    }
    $record = [
      'uid' => (int) $account->id(),
      'name' => (string) $account->getAccountName(),
      'since' => $held['since'] ?? $now,
      'until' => $now + self::SECONDS,
    ];
    if ($held === NULL) {
      // Two people who open the import at the same moment: one gets it.
      $store->delete($id);
      if (!$store->setWithExpireIfNotExists($id, $record, self::SECONDS * 2)) {
        $other = $this->holder($id);
        if ($other !== NULL && $other['uid'] !== $record['uid']) {
          return $other;
        }
      }
      return NULL;
    }
    $store->setWithExpire($id, $record, self::SECONDS * 2);
    return NULL;
  }

  /**
   * Returns who has the lock of an import, if somebody does.
   *
   * @return array{uid: int, name: string, since: int, until: int}|null
   *   The holder; NULL when nobody has it, or when it has run out.
   */
  public function holder(string $id): ?array {
    $record = $this->store()->get($id);
    if (!is_array($record) || !isset($record['uid'], $record['until']) || $record['until'] <= $this->time->getCurrentTime()) {
      return NULL;
    }
    return [
      'uid' => (int) $record['uid'],
      'name' => (string) ($record['name'] ?? ''),
      'since' => (int) ($record['since'] ?? 0),
      'until' => (int) $record['until'],
    ];
  }

  /**
   * Gives the lock up, if the person has it.
   */
  public function release(string $id, AccountInterface $account): void {
    $held = $this->holder($id);
    if ($held !== NULL && $held['uid'] === (int) $account->id()) {
      $this->store()->delete($id);
    }
  }

  /**
   * Takes the lock from whoever has it.
   */
  public function takeOver(string $id, AccountInterface $account): void {
    $this->store()->delete($id);
    $this->acquire($id, $account);
  }

  /**
   * Returns the store of the locks.
   */
  private function store(): KeyValueStoreExpirableInterface {
    return $this->factory->get('import_engine_ui.wizard_lock');
  }

}
