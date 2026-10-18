<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Drive\RunDriver;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Entity\ImportRun;
use Drupal\import_engine\Run\Trigger;
use Drupal\node\Entity\Node;
use Drupal\Tests\import_engine\Kernel\FakeSource;
use Drupal\Tests\import_engine\Kernel\HttpImportTestBase;

/**
 * Base class of the guarantee tests of the import engine.
 *
 * The guarantee tests run the whole chain (extract, process, finish) against a
 * real database and a scripted source, and check what must always be true of
 * it, however the run is cut up or repeated. They are heavy, so they are not
 * part of "ddev composer check": see docs/decisions/0030.
 */
abstract class GuaranteeTestBase extends HttpImportTestBase {

  /**
   * The driver that chains the stages.
   */
  protected RunDriver $driver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->driver = $this->container->get('import_engine.run_driver');
  }

  /**
   * Saves the import of accounts and returns it.
   *
   * @param array<string, mixed> $values
   *   Values that replace the defaults.
   */
  protected function saveImport(array $values = []): ImportDefinition {
    $this->definition($this->accounts($values))->save();
    $definition = ImportDefinition::load($values['id'] ?? 'customers');
    $this->assertInstanceOf(ImportDefinition::class, $definition);
    return $definition;
  }

  /**
   * Builds accounts as the source gives them.
   *
   * @return list<array{id: int, name: string, code: string}>
   *   The items.
   */
  protected function accountItems(int $from, int $to, string $prefix = 'Account'): array {
    return array_map(static fn (int $id): array => [
      'id' => $id,
      'name' => "$prefix $id",
      'code' => "C$id",
    ], $from > $to ? [] : range($from, $to));
  }

  /**
   * Splits items into pages.
   *
   * @param list<array<string, mixed>> $items
   *   The items.
   * @param int $size
   *   The size of a page.
   *
   * @return list<list<array<string, mixed>>>
   *   The pages; there is always one, empty when there are no items.
   */
  protected function pagesOfItems(array $items, int $size): array {
    return $items === [] ? [[]] : array_chunk($items, max(1, $size));
  }

  /**
   * Starts a run of the import and drives it to its end.
   *
   * @param \Drupal\import_engine\Entity\ImportDefinition $definition
   *   The import.
   * @param \Drupal\Tests\import_engine\Kernel\FakeSource $source
   *   The source.
   * @param bool $full
   *   Whether to process every page, also those that did not change.
   */
  protected function runToEnd(ImportDefinition $definition, FakeSource $source, bool $full = FALSE): ImportRun {
    $run = $this->starter->start($definition, Trigger::Drush, NULL, $full);
    $this->driver->drive($run, $definition, new RunBudget(), 'w', 50, $source);
    return $this->reload($run);
  }

  /**
   * Describes the accounts on the site, keyed by their code.
   *
   * @return array<string, array{title: string, status: bool, changed: int, id: int, revision: int}>
   *   What a person would see of each account, and its revision: a save of an
   *   account that did not change would make a new one.
   */
  protected function accountsOnSite(): array {
    $accounts = [];
    foreach (Node::loadMultiple() as $node) {
      $accounts[(string) $node->get('field_code')->value] = [
        'title' => (string) $node->label(),
        'status' => $node->isPublished(),
        'changed' => (int) $node->getChangedTime(),
        'id' => (int) $node->id(),
        'revision' => (int) $node->getRevisionId(),
      ];
    }
    ksort($accounts);
    return $accounts;
  }

  /**
   * Describes the accounts without the times, to compare two situations.
   *
   * @return array<string, array{title: string, status: bool}>
   *   Title and published flag per code.
   */
  protected function accountsWithoutTimes(): array {
    return array_map(
      static fn (array $account): array => ['title' => $account['title'], 'status' => $account['status']],
      $this->accountsOnSite(),
    );
  }

  /**
   * Describes what an import wrote, by the key of the source item.
   *
   * @param string $definitionId
   *   The import.
   * @param list<int> $ids
   *   The IDs of the source items.
   *
   * @return array<int, array{title: string, code: string}|null>
   *   The account of each item, or NULL when the item has none.
   */
  protected function writtenBy(string $definitionId, array $ids): array {
    $store = $this->container->get('import_engine.mapping_store');
    $written = [];
    foreach ($ids as $id) {
      $record = $store->find($definitionId, json_encode([(string) $id], JSON_THROW_ON_ERROR));
      $node = $record === NULL || $record->targetId === NULL ? NULL : Node::load((int) $record->targetId);
      $written[$id] = $node === NULL ? NULL : [
        'title' => (string) $node->label(),
        'code' => (string) $node->get('field_code')->value,
      ];
    }
    return $written;
  }

  /**
   * Returns everything the engine wrote down about a run, as text.
   *
   * The events, the errors of the items, and the summary of the run: what a
   * person with the right to look at a run can read, and what is kept for a
   * year. A test looks in it for what must not be there.
   */
  protected function writtenDown(ImportRun $run): string {
    $database = $this->container->get('database');
    $text = [$run->getSummary()];
    foreach ($database->select('import_event', 'e')->fields('e', ['message', 'target', 'source_key'])->execute() ?: [] as $row) {
      $text[] = implode(' ', array_map('strval', (array) $row));
    }
    foreach ($database->select('import_item', 'i')->fields('i', ['error'])->execute() ?: [] as $row) {
      $text[] = (string) $row->error;
    }
    return implode("\n", $text);
  }

}
