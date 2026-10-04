<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Entity\ImportRun;
use Drupal\import_engine\Finish\FinishResult;
use Drupal\import_engine\Finish\FinishStage;
use Drupal\import_engine\Process\ProcessStage;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Base class for tests that write real nodes: content types with fields.
 *
 * Content type "account" has a code, notes, a rate (money), an active flag, a
 * date, a count and a reference to an "item"; content type "item" has a SKU.
 */
abstract class NodeTestBase extends ExtractTestBase {

  /**
   * The process stage.
   */
  protected ProcessStage $process;

  /**
   * The finish stage.
   */
  protected FinishStage $finish;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'money_field',
    'import_engine',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->process = $this->container->get('import_engine.process_stage');
    $this->finish = $this->container->get('import_engine.finish_stage');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'filter']);
    // The user that owns imported nodes; the first user gets ID 1.
    User::create(['name' => 'importer'])->save();

    NodeType::create(['type' => 'item', 'name' => 'Item'])->save();
    NodeType::create(['type' => 'account', 'name' => 'Account'])->save();
    $this->addField('item', 'field_sku', 'string');
    $this->addField('account', 'field_code', 'string');
    $this->addField('account', 'field_notes', 'text_long');
    $this->addField('account', 'field_rate', 'money_field');
    $this->addField('account', 'field_active', 'boolean');
    $this->addField('account', 'field_since', 'timestamp');
    $this->addField('account', 'field_count', 'integer');
    $this->addField('account', 'field_item', 'entity_reference', [
      'target_type' => 'node',
    ], ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['item' => 'item']]]);
  }

  /**
   * Values for a definition that writes accounts as the importer.
   *
   * @param array<string, mixed> $values
   *   Values that replace the defaults.
   *
   * @return array<string, mixed>
   *   The definition values.
   */
  protected function accounts(array $values = []): array {
    return $values + [
      'target' => [
        'plugin' => 'entity',
        'configuration' => ['entity_type' => 'node', 'bundle' => 'account', 'owner' => 1],
      ],
      'mapping' => [
        [
          'target_field' => 'title',
          'mapper' => ['plugin' => 'string', 'sources' => ['value' => 'name'], 'settings' => []],
        ],
        [
          'target_field' => 'field_code',
          'mapper' => ['plugin' => 'string', 'sources' => ['value' => 'code'], 'settings' => []],
        ],
      ],
      'resilience' => [
        'max_attempts' => 3,
        'backoff' => 'fixed',
        'retry_delay' => 60,
        'dlq_enabled' => TRUE,
        'max_repeated_pages' => 3,
      ],
    ];
  }

  /**
   * Extracts items and saves the definition, as a real run would.
   *
   * @param list<array<string, mixed>> $rows
   *   The source items.
   * @param array<string, mixed> $values
   *   Definition values.
   */
  protected function extractRows(array $rows, array $values = []): ImportRun {
    $values = $this->accounts($values);
    $this->definition($values)->save();
    return $this->extractAll(new FakeSource([$rows]), $values);
  }

  /**
   * Runs an import of accounts: extracts the rows and processes every item.
   *
   * The definition is saved on the first call; later calls reuse it.
   *
   * @param list<array<string, mixed>> $rows
   *   The source items, in one page.
   * @param array<string, mixed> $values
   *   Definition values, used when the definition is saved.
   * @param bool $fullRun
   *   Whether the run reads every page again.
   */
  protected function importRows(array $rows, array $values = [], bool $fullRun = FALSE): ImportRun {
    $values = $this->accounts($values);
    if (ImportDefinition::load('customers') === NULL) {
      $this->definition($values)->save();
    }
    $run = $this->extractAll(new FakeSource([$rows]), $values, $fullRun);
    while ($this->process->process('w', 100, 'default', 600)->claimed > 0) {
      // Keep going until the queue is empty.
    }
    return $run;
  }

  /**
   * Finishes a run and returns what happened.
   */
  protected function finishRun(ImportRun $run): FinishResult {
    $definition = ImportDefinition::load('customers');
    $this->assertNotNull($definition);
    return $this->finish->finish($run, $definition);
  }

  /**
   * Loads a run again from the database.
   */
  protected function reload(ImportRun $run): ImportRun {
    $loaded = ImportRun::load((int) $run->id());
    $this->assertNotNull($loaded);
    return $loaded;
  }

  /**
   * Adds a field to a content type.
   *
   * @param string $bundle
   *   The content type.
   * @param string $name
   *   The field name.
   * @param string $type
   *   The field type.
   * @param array<string, mixed> $storage_settings
   *   Field storage settings.
   * @param array<string, mixed> $field_settings
   *   Field settings.
   */
  protected function addField(string $bundle, string $name, string $type, array $storage_settings = [], array $field_settings = []): void {
    if (!FieldStorageConfig::loadByName('node', $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $storage_settings,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => $bundle,
      'settings' => $field_settings,
    ])->save();
  }

}
