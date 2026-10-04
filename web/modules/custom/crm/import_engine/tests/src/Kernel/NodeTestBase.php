<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\field\Entity\FieldConfig;
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
