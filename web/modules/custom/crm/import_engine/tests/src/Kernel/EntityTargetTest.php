<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Target\TargetException;
use Drupal\import_engine\Target\TargetInterface;
use Drupal\import_engine\Target\TargetValidationException;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the entity target: fields, saving, hiding and deleting nodes.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class EntityTargetTest extends NodeTestBase {

  /**
   * Creates a target for a content type.
   */
  protected function target(string $type = 'node', string $bundle = 'account', int $owner = 1): TargetInterface {
    $target = $this->container->get('plugin.manager.import_engine_target')->createInstance('entity', [
      'entity_type' => $type,
      'bundle' => $bundle,
      'owner' => $owner,
    ]);
    $this->assertInstanceOf(TargetInterface::class, $target);
    return $target;
  }

  /**
   * The fields a mapping can fill are the writable fields of the bundle.
   */
  public function testFields(): void {
    $fields = $this->target()->fields();

    foreach ([
      'title',
      'status',
      'uid',
      'created',
      'field_code',
      'field_notes',
      'field_rate',
      'field_active',
      'field_since',
      'field_count',
      'field_item',
    ] as $name) {
      $this->assertArrayHasKey($name, $fields);
    }
    // The keys the entity system fills itself are not offered.
    foreach (['nid', 'uuid', 'vid', 'type'] as $name) {
      $this->assertArrayNotHasKey($name, $fields);
    }
    $this->assertSame('string', $fields['title']->type);
    $this->assertTrue($fields['title']->required);
    $this->assertSame('money_field', $fields['field_rate']->type);
    $this->assertSame('entity_reference', $fields['field_item']->type);
    $this->assertSame('node', $fields['field_item']->settings['target_type']);
    $this->assertFalse($fields['field_code']->required);
    // Another bundle has its own fields.
    $this->assertArrayHasKey('field_sku', $this->target('node', 'item')->fields());
    $this->assertArrayNotHasKey('field_code', $this->target('node', 'item')->fields());
  }

  /**
   * A node is created with the values and the configured owner.
   */
  public function testSaveCreates(): void {
    $result = $this->target()->save([
      'title' => 'Acme',
      'field_code' => 'C-1',
      'field_rate' => ['number' => '9.95', 'currency_code' => 'EUR'],
      'field_active' => TRUE,
      'field_count' => 3,
    ], NULL);

    $this->assertTrue($result->created);
    $this->assertSame('node', $result->type);
    $node = Node::load($result->id);
    $this->assertNotNull($node);
    $this->assertSame('account', $node->bundle());
    $this->assertSame('Acme', $node->label());
    $this->assertSame('C-1', $node->get('field_code')->value);
    $this->assertSame('EUR', $node->get('field_rate')->currency_code);
    $this->assertSame('1', (string) $node->get('field_active')->value);
    $this->assertSame(1, (int) $node->getOwnerId());
  }

  /**
   * Saving with the ID of an earlier result updates that node.
   */
  public function testSaveUpdates(): void {
    $target = $this->target();
    $first = $target->save(['title' => 'Acme', 'field_code' => 'C-1'], NULL);

    $second = $target->save(['title' => 'Acme Inc', 'field_code' => 'C-1'], $first->id);

    $this->assertFalse($second->created);
    $this->assertSame($first->id, $second->id);
    $this->assertSame('Acme Inc', Node::load($first->id)?->label());
    $this->assertCount(1, Node::loadMultiple());
  }

  /**
   * A node that was deleted in the meantime is created again.
   */
  public function testSaveRecreatesMissingNode(): void {
    $target = $this->target();
    $first = $target->save(['title' => 'Acme'], NULL);
    Node::load($first->id)?->delete();

    $again = $target->save(['title' => 'Acme'], $first->id);

    $this->assertTrue($again->created);
    $this->assertNotNull(Node::load($again->id));
  }

  /**
   * Values that do not pass validation are refused with the reasons.
   */
  public function testValidationFailureIsPermanent(): void {
    try {
      $this->target()->save(['title' => str_repeat('x', 300)], NULL);
      $this->fail('Expected a TargetValidationException.');
    }
    catch (TargetValidationException $exception) {
      $this->assertStringContainsString('title', $exception->getMessage());
    }
    $this->assertCount(0, Node::loadMultiple());

    $this->expectException(TargetValidationException::class);
    // The title is required.
    $this->target()->save(['field_code' => 'C-2'], NULL);
  }

  /**
   * A field that does not exist is a target problem.
   */
  public function testUnknownFieldIsRefused(): void {
    $this->expectException(TargetException::class);
    $this->target()->save(['title' => 'Acme', 'field_nope' => 'x'], NULL);
  }

  /**
   * Hiding, showing and deleting what was written.
   */
  public function testPublishUnpublishDelete(): void {
    $target = $this->target();
    $id = $target->save(['title' => 'Acme', 'status' => TRUE], NULL)->id;

    $this->assertTrue($target->supportsUnpublish());
    $this->assertTrue($target->unpublish($id));
    $this->assertFalse(Node::load($id)?->isPublished());
    $this->assertFalse($target->unpublish($id), 'already hidden');
    $this->assertTrue($target->publish($id));
    $this->assertTrue(Node::load($id)->isPublished());
    $this->assertFalse($target->publish($id), 'already visible');

    $this->assertTrue($target->delete($id));
    $this->assertNull(Node::load($id));
    $this->assertFalse($target->delete($id), 'already gone');
    $this->assertFalse($target->publish($id));
    $this->assertFalse($target->unpublish('9999'));
  }

  /**
   * An entity type without a published state cannot be hidden.
   */
  public function testUnpublishNeedsPublishedState(): void {
    $this->assertFalse($this->target('user', 'user')->supportsUnpublish());
  }

  /**
   * A target that is not configured properly says so.
   *
   * @param string $type
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   * @param string $message
   *   The expected message.
   */
  #[DataProvider('badConfigurationProvider')]
  public function testBadConfiguration(string $type, string $bundle, string $message): void {
    $this->expectException(TargetException::class);
    $this->expectExceptionMessage($message);
    $this->target($type, $bundle)->fields();
  }

  /**
   * Data provider.
   *
   * @return array<string, array{string, string, string}>
   *   The entity type, the bundle and the expected message.
   */
  public static function badConfigurationProvider(): array {
    return [
      'unknown entity type' => ['nope', 'x', 'does not exist or is not a content entity type'],
      'configuration entity type' => ['node_type', 'node_type', 'does not exist or is not a content entity type'],
      'unknown bundle' => ['node', 'nope', 'The bundle "nope" of the entity type "node" does not exist'],
    ];
  }

  /**
   * The target plugin and its settings are validated by the schema.
   */
  public function testTargetSettingsAreValidated(): void {
    $typed = $this->container->get('config.typed');
    $paths = [];
    foreach ($typed->createFromNameAndData('import_engine.target.entity', [
      'entity_type' => 'Node',
      'bundle' => '',
      'owner' => -1,
    ])->validate() as $violation) {
      $paths[] = $violation->getPropertyPath();
    }

    $this->assertEqualsCanonicalizing(['entity_type', 'bundle', 'owner'], $paths);
    $this->assertSame(['entity'], array_keys($this->container->get('plugin.manager.import_engine_target')->getDefinitions()));
  }

}
