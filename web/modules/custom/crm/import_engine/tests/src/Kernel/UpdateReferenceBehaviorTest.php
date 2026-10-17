<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Entity\ImportDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the update that replaces "required" of a reference by "when_missing".
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class UpdateReferenceBehaviorTest extends NodeTestBase {

  /**
   * A required reference waits, an optional one is ignored when missing.
   */
  public function testRequiredBecomesWhenMissing(): void {
    $row = static fn (string $field, bool $required): array => [
      'target_field' => $field,
      'mapper' => [
        'plugin' => 'reference',
        'sources' => ['value' => 'ref'],
        'settings' => ['definition' => 'accounts', 'required' => $required],
      ],
    ];
    $other = [
      'target_field' => 'title',
      'mapper' => ['plugin' => 'string', 'sources' => ['value' => 'name'], 'settings' => []],
    ];
    // Written to the storage: the schema no longer allows the old key.
    $this->container->get('config.storage')->write('import_engine.import_definition.legacy', [
      'id' => 'legacy',
      'label' => 'Legacy',
      'mapping' => [$row('field_item', TRUE), $row('field_other', FALSE), $other],
    ]);

    require_once __DIR__ . '/../../../import_engine.install';
    $message = import_engine_update_10001();

    $this->assertSame('Changed the reference mappers of 1 imports.', $message);
    $loaded = ImportDefinition::load('legacy');
    $this->assertNotNull($loaded);
    $mapping = $loaded->getMapping();
    $this->assertSame(['definition' => 'accounts', 'when_missing' => 'wait'], $mapping[0]['mapper']['settings']);
    $this->assertSame(['definition' => 'accounts', 'when_missing' => 'ignore'], $mapping[1]['mapper']['settings']);
    $this->assertSame([], $mapping[2]['mapper']['settings']);
    $this->assertSame('Changed the reference mappers of 0 imports.', import_engine_update_10001(), 'A second run changes nothing.');
  }

}
