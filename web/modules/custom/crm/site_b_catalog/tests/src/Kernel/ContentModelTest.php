<?php

declare(strict_types=1);

namespace Drupal\Tests\site_b_catalog\Kernel;

use Drupal\import_engine\Target\TargetInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the content model of site B: its types, fields and displays.
 */
#[Group('site_b_catalog')]
#[RunTestsInSeparateProcesses]
class ContentModelTest extends KernelTestBase {

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
    'site_b_catalog',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'filter', 'site_b_catalog']);
    User::create(['name' => 'importer', 'status' => 1])->save();
  }

  /**
   * Returns the fields a mapping can fill for a content type.
   *
   * @return array<string, \Drupal\import_engine\Target\TargetField>
   *   The fields.
   */
  protected function targetFields(string $bundle): array {
    $target = $this->container->get('plugin.manager.import_engine_target')->createInstance('entity', [
      'entity_type' => 'node',
      'bundle' => $bundle,
      'owner' => 1,
    ]);
    $this->assertInstanceOf(TargetInterface::class, $target);
    return $target->fields();
  }

  /**
   * The three content types exist, each with its own fields.
   *
   * @param string $bundle
   *   The content type.
   * @param array<string, string> $expected
   *   The field types of the fields of the type, by field name.
   */
  #[DataProvider('modelProvider')]
  public function testContentTypeHasItsFields(string $bundle, array $expected): void {
    $this->assertNotNull(NodeType::load($bundle));

    $fields = $this->targetFields($bundle);

    foreach ($expected as $name => $type) {
      $this->assertArrayHasKey($name, $fields, "$bundle.$name");
      $this->assertSame($type, $fields[$name]->type, "$bundle.$name");
    }
    // Nothing else a person has to fill in besides what the model has.
    $custom = array_filter(array_keys($fields), static fn (string $name): bool => str_starts_with($name, 'field_'));
    $this->assertEqualsCanonicalizing(array_filter(array_keys($expected), static fn (string $name): bool => str_starts_with($name, 'field_')), $custom);
  }

  /**
   * The model: field names and their types, by content type.
   *
   * @return array<string, array{string, array<string, string>}>
   *   The content type and its fields.
   */
  public static function modelProvider(): array {
    return [
      'account' => ['account', [
        'title' => 'string',
        'status' => 'boolean',
        'field_account_number' => 'string',
        'field_notes' => 'text_long',
        'field_source_id' => 'string',
      ],
      ],
      'item' => ['item', [
        'title' => 'string',
        'status' => 'boolean',
        'field_list_price' => 'money_field',
        'field_summary' => 'text_long',
        'field_source_id' => 'string',
      ],
      ],
      'agreement' => ['agreement', [
        'title' => 'string',
        'status' => 'boolean',
        'field_account' => 'entity_reference',
        'field_item' => 'entity_reference',
        'field_agreed_price' => 'money_field',
        'field_source_id' => 'string',
      ],
      ],
    ];
  }

  /**
   * Every field of the model can be filled by at least one mapper.
   */
  public function testEveryFieldCanBeMapped(): void {
    $mappers = $this->container->get('plugin.manager.import_engine_mapper');

    foreach (['account', 'item', 'agreement'] as $bundle) {
      foreach ($this->targetFields($bundle) as $name => $field) {
        // The fields of the model, not the ones Drupal fills in by itself.
        if (!str_starts_with($name, 'field_') && !in_array($name, ['title', 'status'], TRUE)) {
          continue;
        }
        $this->assertNotSame([], $mappers->idsForFieldType($field->type), "$bundle.$name has type {$field->type}");
      }
    }
  }

  /**
   * References point at the right type, and the right fields are required.
   */
  public function testReferencesAndRequiredFields(): void {
    $agreement = $this->targetFields('agreement');

    $this->assertTrue($agreement['field_account']->required);
    $this->assertTrue($agreement['field_item']->required);
    $this->assertTrue($agreement['field_agreed_price']->required);
    $this->assertSame('node', $agreement['field_item']->settings['target_type']);
    $this->assertTrue($this->targetFields('account')['field_account_number']->required);
    $this->assertFalse($this->targetFields('item')['field_list_price']->required, 'A product without a price can be imported.');
  }

  /**
   * The content types can be edited and shown: they have their displays.
   */
  public function testDisplaysExist(): void {
    $displays = $this->container->get('entity_display.repository');

    foreach (['account', 'item', 'agreement'] as $bundle) {
      $form = $displays->getFormDisplay('node', $bundle);
      $view = $displays->getViewDisplay('node', $bundle);
      $fields = array_filter(array_keys($this->targetFields($bundle)), static fn (string $name): bool => str_starts_with($name, 'field_'));
      foreach ($fields as $name) {
        $this->assertNotNull($form->getComponent($name), "$bundle form: $name");
        $this->assertNotNull($view->getComponent($name), "$bundle view: $name");
      }
    }
  }

}
