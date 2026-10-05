<?php

declare(strict_types=1);

namespace Drupal\Tests\site_b_catalog\Kernel;

use Drupal\import_engine\Target\TargetInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the content model of site B: its entity types and fields.
 */
#[Group('site_b_catalog')]
#[RunTestsInSeparateProcesses]
class ContentModelTest extends CatalogTestBase {

  /**
   * Returns the fields a mapping can fill for an entity type.
   *
   * @return array<string, \Drupal\import_engine\Target\TargetField>
   *   The fields.
   */
  protected function targetFields(string $type): array {
    $target = $this->container->get('plugin.manager.import_engine_target')->createInstance('entity', [
      'entity_type' => $type,
      'bundle' => $type,
      'owner' => 1,
    ]);
    $this->assertInstanceOf(TargetInterface::class, $target);
    return $target->fields();
  }

  /**
   * Each entity type has the fields of the model, with the types given.
   *
   * @param string $type
   *   The entity type.
   * @param array<string, string> $expected
   *   The field types of the fields of the type, by field name.
   */
  #[DataProvider('modelProvider')]
  public function testEntityTypeHasItsFields(string $type, array $expected): void {
    $fields = $this->targetFields($type);

    foreach ($expected as $name => $field_type) {
      $this->assertArrayHasKey($name, $fields, "$type.$name");
      $this->assertSame($field_type, $fields[$name]->type, "$type.$name");
    }
    // The model has no fields that are not described here.
    $this->assertEqualsCanonicalizing(array_keys($expected), array_diff(array_keys($fields), ['changed', 'created']));
  }

  /**
   * The model: field names and their types, by entity type.
   *
   * @return array<string, array{string, array<string, string>}>
   *   The entity type and its fields.
   */
  public static function modelProvider(): array {
    return [
      'account' => ['account', [
        'name' => 'string',
        'number' => 'string',
        'notes' => 'text_long',
        'status' => 'boolean',
        'source_id' => 'string',
      ],
      ],
      'item' => ['item', [
        'title' => 'string',
        'sku' => 'string',
        'list_price' => 'money_field',
        'summary' => 'text_long',
        'status' => 'boolean',
        'source_id' => 'string',
      ],
      ],
      'agreement' => ['agreement', [
        'title' => 'string',
        'account' => 'entity_reference',
        'item' => 'entity_reference',
        'price' => 'money_field',
        'status' => 'boolean',
        'source_id' => 'string',
      ],
      ],
    ];
  }

  /**
   * The entity types have no bundles: the type is the bundle.
   */
  public function testEntityTypesHaveNoBundles(): void {
    $bundles = $this->container->get('entity_type.bundle.info');

    foreach (['account', 'item', 'agreement'] as $type) {
      $this->assertSame([$type], array_keys($bundles->getBundleInfo($type)), $type);
    }
  }

  /**
   * Every field of the model can be filled by at least one mapper.
   */
  public function testEveryFieldCanBeMapped(): void {
    $mappers = $this->container->get('plugin.manager.import_engine_mapper');

    foreach (['account', 'item', 'agreement'] as $type) {
      foreach ($this->targetFields($type) as $name => $field) {
        if (in_array($name, ['created', 'changed'], TRUE)) {
          continue;
        }
        $this->assertNotSame([], $mappers->idsForFieldType($field->type), "$type.$name has type {$field->type}");
      }
    }
  }

  /**
   * References point at the right type, and the right fields are required.
   */
  public function testReferencesAndRequiredFields(): void {
    $agreement = $this->targetFields('agreement');

    $this->assertTrue($agreement['account']->required);
    $this->assertTrue($agreement['item']->required);
    $this->assertTrue($agreement['price']->required);
    $this->assertSame('account', $agreement['account']->settings['target_type']);
    $this->assertSame('item', $agreement['item']->settings['target_type']);
    $this->assertFalse($agreement['title']->required, 'The title is made when it is left empty.');
    $this->assertTrue($this->targetFields('account')['number']->required);
    $this->assertFalse($this->targetFields('item')['list_price']->required, 'A product without a price can be imported.');
  }

  /**
   * The entities can be managed in the interface: their routes exist.
   */
  public function testAdminRoutesExist(): void {
    $this->container->get('router.builder')->rebuild();
    $routes = $this->container->get('router.route_provider');

    foreach (['account' => 'accounts', 'item' => 'items', 'agreement' => 'agreements'] as $type => $path) {
      foreach (['collection' => "/admin/site-b/$path", 'add_form' => "/admin/site-b/$path/add"] as $name => $expected) {
        $this->assertSame($expected, $routes->getRouteByName("entity.$type.$name")->getPath(), "entity.$type.$name");
      }
      $this->assertSame("/admin/site-b/$path/{{$type}}/edit", $routes->getRouteByName("entity.$type.edit_form")->getPath());
    }
  }

  /**
   * Permissions exist for every entity type and operation.
   */
  public function testPermissionsExist(): void {
    $permissions = array_keys($this->container->get('user.permissions')->getPermissions());

    foreach (['account', 'item', 'agreement'] as $type) {
      foreach (['administer', 'view', 'edit', 'delete', 'create'] as $operation) {
        $this->assertContains("$operation $type", $permissions);
      }
    }
  }

}
