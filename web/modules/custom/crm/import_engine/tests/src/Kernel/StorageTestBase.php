<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\import_engine\Entity\ImportRun;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\Storage\EventLog;
use Drupal\import_engine\Storage\ItemStorage;
use Drupal\import_engine\Storage\PageStore;
use Drupal\KernelTests\KernelTestBase;

/**
 * Base class for tests of the run entity and the tables.
 */
abstract class StorageTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'import_engine'];

  /**
   * The item storage.
   */
  protected ItemStorage $items;

  /**
   * The page store.
   */
  protected PageStore $pages;

  /**
   * The event log.
   */
  protected EventLog $events;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('import_run');
    $this->installSchema('import_engine', [
      'import_item',
      'import_page',
      'import_event',
      'import_mapping',
      'import_breaker',
    ]);
    $this->installConfig('import_engine');
    $this->items = $this->container->get('import_engine.item_storage');
    $this->pages = $this->container->get('import_engine.page_store');
    $this->events = $this->container->get('import_engine.event_log');
  }

  /**
   * Creates and saves a run.
   */
  protected function createRun(string $definition = 'customers', Trigger $trigger = Trigger::Drush): ImportRun {
    $run = ImportRun::create(['definition_id' => $definition, 'trigger' => $trigger->value]);
    $run->save();
    return $run;
  }

  /**
   * Returns the number of rows in a table.
   */
  protected function rows(string $table): int {
    return (int) $this->field($this->container->get('database')->select($table, 't')->countQuery());
  }

  /**
   * Returns the first column of the first row of a select query.
   */
  protected function field(SelectInterface $query): mixed {
    $statement = $query->execute();
    $this->assertNotNull($statement);
    return $statement->fetchField();
  }

  /**
   * Builds items with keys and payloads.
   *
   * @return list<array{key: string, payload: array<string, mixed>}>
   *   The items, keys "k1" to "kN".
   */
  protected function makeItems(int $count, int $from = 1): array {
    $items = [];
    for ($n = $from; $n < $from + $count; $n++) {
      $items[] = ['key' => "k$n", 'payload' => ['id' => $n, 'label' => "Customer $n"]];
    }
    return $items;
  }

}
