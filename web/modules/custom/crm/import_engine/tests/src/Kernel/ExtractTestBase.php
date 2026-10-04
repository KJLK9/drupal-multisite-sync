<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Entity\ImportRun;
use Drupal\import_engine\Extract\ExtractResult;
use Drupal\import_engine\Extract\ExtractStage;
use Drupal\import_engine\Run\RunStarter;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\Storage\Outcome;

/**
 * Base class for tests of the extract stage.
 */
abstract class ExtractTestBase extends StorageTestBase {

  /**
   * The extract stage.
   */
  protected ExtractStage $stage;

  /**
   * The run starter.
   */
  protected RunStarter $starter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->stage = $this->container->get('import_engine.extract_stage');
    $this->starter = $this->container->get('import_engine.run_starter');
  }

  /**
   * Builds an import definition.
   *
   * @param array<string, mixed> $values
   *   Values that replace the defaults.
   */
  protected function definition(array $values = []): ImportDefinition {
    return ImportDefinition::create($values + [
      'id' => 'customers',
      'label' => 'Customers',
      'source' => ['plugin' => 'http', 'configuration' => []],
      'source_key' => ['id'],
      'target' => ['entity_type' => 'node', 'bundle' => 'account'],
      'mapping' => [
        [
          'target_field' => 'title',
          'mapper' => ['plugin' => 'string', 'sources' => ['value' => 'name'], 'settings' => []],
        ],
      ],
    ]);
  }

  /**
   * Builds source items with consecutive ids.
   *
   * @return list<array{id: int, name: string}>
   *   The items.
   */
  protected function sourceRows(int $from, int $to): array {
    return array_map(static fn (int $id): array => ['id' => $id, 'name' => "Customer $id"], range($from, $to));
  }

  /**
   * Builds pages of consecutive items.
   *
   * @return list<list<array{id: int, name: string}>>
   *   The pages.
   */
  protected function pagesOf(int $pages, int $size): array {
    $result = [];
    for ($page = 0; $page < $pages; $page++) {
      $result[] = $this->sourceRows($page * $size + 1, ($page + 1) * $size);
    }
    return $result;
  }

  /**
   * Starts a run and extracts it completely.
   *
   * @param \Drupal\Tests\import_engine\Kernel\FakeSource $source
   *   The source to read.
   * @param array<string, mixed> $definitionValues
   *   Values for the definition.
   * @param bool $fullRun
   *   Whether the run processes every page.
   */
  protected function extractAll(FakeSource $source, array $definitionValues = [], bool $fullRun = FALSE): ImportRun {
    $definition = $this->definition($definitionValues);
    $run = $this->starter->start($definition, Trigger::Drush, NULL, $fullRun);
    $result = $this->stage->extract($run, $definition, $source);
    $this->assertSame('complete', $result->status->value, (string) $result->message);
    return $run;
  }

  /**
   * Handles every item of a run like the process stage will, then verifies.
   *
   * @param \Drupal\import_engine\Entity\ImportRun $run
   *   The run.
   * @param list<string> $failKeys
   *   Keys of items that fail and end up dead.
   */
  protected function handleAll(ImportRun $run, array $failKeys = []): void {
    while ($claimed = $this->items->claim('w', 100, 600)) {
      foreach ($claimed as $item) {
        if (in_array($item->key, $failKeys, TRUE)) {
          $this->items->fail($item, 'bad data', TRUE);
        }
        else {
          $this->items->complete($item, Outcome::Created);
        }
      }
    }
    $this->pages->verify($run->getDefinitionId(), (int) $run->id(), $this->items->pagesWithFailures((int) $run->id()));
    // The run is over, so the import can be started again.
    $run->transitionTo(RunStatus::Finishing)->transitionTo(RunStatus::Completed)->save();
  }

  /**
   * Asserts the numbers of a result.
   */
  protected function assertResult(ExtractResult $result, string $status, int $pages, int $queued, int $skipped = 0, int $invalid = 0): void {
    $this->assertSame($status, $result->status->value, (string) $result->message);
    $this->assertSame($pages, $result->pages, 'pages');
    $this->assertSame($queued, $result->queued, 'queued');
    $this->assertSame($skipped, $result->skipped, 'skipped');
    $this->assertSame($invalid, $result->invalid, 'invalid');
  }

}
