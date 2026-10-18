<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Run\RunStatus;
use Drupal\node\Entity\Node;
use Drupal\Tests\import_engine\Kernel\FakeSource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Imports saved by an older version still run; the update makes them current.
 */
#[Group('guarantee')]
#[RunTestsInSeparateProcesses]
class UpgradeGuaranteeTest extends GuaranteeTestBase {

  /**
   * Writes a definition as an older version saved it.
   *
   * @param callable $age
   *   Changes the stored data to what the old version wrote.
   */
  protected function saveAsOldVersion(callable $age): ImportDefinition {
    $this->saveImport();
    $storage = $this->container->get('config.storage');
    $data = $age($storage->read('import_engine.import_definition.customers'));
    // Written to the storage: the schema of today no longer allows what an old
    // version stored, and a save through the config object would refuse it.
    $storage->write('import_engine.import_definition.customers', $data);
    $this->container->get('config.factory')->reset();
    $this->container->get('entity_type.manager')->getStorage('import_definition')->resetCache();
    $definition = ImportDefinition::load('customers');
    $this->assertInstanceOf(ImportDefinition::class, $definition);
    return $definition;
  }

  /**
   * A definition without the keys that came later runs like a new one.
   */
  public function testDefinitionWithoutNewerKeysStillRuns(): void {
    $definition = $this->saveAsOldVersion(static function (array $data): array {
      unset($data['template'], $data['schedule']);
      foreach ($data['mapping'] as $delta => $row) {
        unset($data['mapping'][$delta]['validators'], $data['mapping'][$delta]['on_invalid']);
      }
      return $data;
    });

    $run = $this->runToEnd($definition, new FakeSource($this->pagesOfItems($this->accountItems(1, 12), 5)));

    $this->assertSame(RunStatus::Completed, $run->getStatus());
    $this->assertCount(12, Node::loadMultiple());
    $this->assertFalse($definition->isTemplate());
    $this->assertFalse($definition->getSchedule()['enabled']);
  }

  /**
   * A reference saved with "required" keeps its meaning until the update runs.
   */
  public function testOldRequiredReferenceStillMeansWhatItMeant(): void {
    $definition = $this->saveAsOldVersion(static function (array $data): array {
      $data['mapping'][] = [
        'target_field' => 'field_item',
        'mapper' => [
          'plugin' => 'reference',
          'sources' => ['id' => 'item'],
          'settings' => ['definition' => 'items', 'required' => FALSE],
        ],
      ];
      return $data;
    });
    $items = array_map(static fn (array $item): array => $item + ['item' => 'S-404'], $this->accountItems(1, 3));

    $run = $this->runToEnd($definition, new FakeSource([$items]));

    $this->assertSame(RunStatus::Completed, $run->getStatus());
    $this->assertCount(3, Node::loadMultiple(), 'Not required: saved without the reference.');
  }

  /**
   * The keys of source items are compared exactly, also after the update.
   */
  public function testKeysAreComparedExactlyAndTheUpdateGetsThere(): void {
    $database = $this->container->get('database');
    $collation = static function (string $table) use ($database): string {
      $row = $database->query('SHOW FULL COLUMNS FROM {' . $table . '} WHERE Field = :field', [':field' => 'source_key'])?->fetchObject();
      assert($row instanceof \stdClass);
      return (string) $row->Collation;
    };
    $this->assertStringEndsWith('_bin', $collation('import_item'), 'A new site has it.');
    $this->assertStringEndsWith('_bin', $collation('import_mapping'));

    // A site from before: the columns ignore case.
    foreach (['import_item', 'import_mapping'] as $table) {
      $database->query('ALTER TABLE {' . $table . '} MODIFY source_key VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL');
    }
    $this->assertStringEndsWith('_general_ci', $collation('import_item'));

    require_once __DIR__ . '/../../../import_engine.install';
    import_engine_update_10002();

    $this->assertStringEndsWith('_bin', $collation('import_item'));
    $this->assertStringEndsWith('_bin', $collation('import_mapping'));
    // And the update can be run again.
    import_engine_update_10002();
    $this->assertStringEndsWith('_bin', $collation('import_mapping'));
  }

  /**
   * The update turns "required" into "when_missing", and is safe to run again.
   */
  public function testTheUpdateConvertsAndIsRepeatable(): void {
    $this->saveAsOldVersion(static function (array $data): array {
      $data['mapping'][] = [
        'target_field' => 'field_item',
        'mapper' => [
          'plugin' => 'reference',
          'sources' => ['id' => 'item'],
          'settings' => ['definition' => 'items', 'required' => TRUE],
        ],
      ];
      return $data;
    });
    require_once __DIR__ . '/../../../import_engine.install';

    $first = import_engine_update_10001();
    $second = import_engine_update_10001();

    $this->assertSame('Changed the reference mappers of 1 imports.', $first);
    $this->assertSame('Changed the reference mappers of 0 imports.', $second);
    $settings = $this->config('import_engine.import_definition.customers')->get('mapping.2.mapper.settings');
    $this->assertSame(['definition' => 'items', 'when_missing' => 'wait'], $settings);
  }

}
