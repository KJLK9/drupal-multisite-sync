<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\Core\Database\Connection;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Entity\ImportRun;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that what the engine kept for a deleted import goes with it.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class DefinitionCleanerTest extends NodeTestBase {

  /**
   * Returns the database.
   */
  protected function db(): Connection {
    $database = $this->container->get('database');
    assert($database instanceof Connection);
    return $database;
  }

  /**
   * Counts the rows a table has for an import.
   *
   * @param string $table
   *   The table.
   * @param string $import
   *   The ID of the import.
   */
  protected function rowsOf(string $table, string $import): int {
    $query = $this->db()->select($table, 't');
    if ($table === 'import_item') {
      $query->join('import_run', 'r', 'r.id = t.run_id');
      $query->condition('r.definition_id', $import);
    }
    elseif ($table === 'import_run') {
      $query->condition('t.definition_id', $import);
    }
    else {
      $query->condition('t.definition_id', $import);
    }
    return (int) $query->countQuery()->execute()?->fetchField();
  }

  /**
   * Imports a few items for an import, including one that ends dead.
   */
  protected function importFor(string $id): ImportRun {
    $values = $this->accounts(['id' => $id, 'label' => ucfirst($id)]);
    $definition = ImportDefinition::create($values + $this->definition()->toArray());
    $definition->save();
    $run = $this->starter->start($definition, Trigger::Drush);
    $this->stage->extract($run, $definition, new FakeSource([[
      ['id' => 1, 'name' => 'Acme', 'code' => 'A'],
      ['id' => 2, 'name' => '', 'code' => 'B'],
    ],
    ]));
    $this->process->process('w', 10, 'default', 600, 1000);
    $run->transitionTo(RunStatus::Finishing)->transitionTo(RunStatus::Completed)->save();
    return $run;
  }

  /**
   * Deleting an import removes its data and leaves the data of others alone.
   */
  public function testDeletingAnImportRemovesItsData(): void {
    $this->importFor('first');
    $this->importFor('second');
    foreach (['import_item', 'import_event', 'import_mapping', 'import_run', 'import_page'] as $table) {
      $this->assertGreaterThan(0, $this->rowsOf($table, 'first'), $table);
      $this->assertGreaterThan(0, $this->rowsOf($table, 'second'), $table);
    }
    $second_before = [];
    foreach (['import_item', 'import_event', 'import_mapping', 'import_run', 'import_page'] as $table) {
      $second_before[$table] = $this->rowsOf($table, 'second');
    }

    ImportDefinition::load('first')?->delete();

    foreach (['import_item', 'import_event', 'import_mapping', 'import_run', 'import_page'] as $table) {
      $this->assertSame(0, $this->rowsOf($table, 'first'), $table . ' of the deleted import');
    }
    foreach ($second_before as $table => $count) {
      $this->assertSame($count, $this->rowsOf($table, 'second'), $table . ' of another import stays');
    }
  }

  /**
   * An import made again with the same ID does not start from the old mapping.
   */
  public function testNewImportWithTheSameIdStartsEmpty(): void {
    $this->importFor('first');
    $this->assertGreaterThan(0, $this->container->get('import_engine.mapping_store')->count('first'));

    ImportDefinition::load('first')?->delete();

    $this->assertSame(0, $this->container->get('import_engine.mapping_store')->count('first'));
  }

  /**
   * An import with a run that is not over is removed with the run.
   */
  public function testRunThatIsNotOverIsRemovedToo(): void {
    $definition = $this->definition($this->accounts(['id' => 'busy']));
    $definition->save();
    $this->starter->start($definition, Trigger::Drush);
    $this->assertNotNull($this->starter->activeRunId('busy'));

    $definition->delete();

    $this->assertNull($this->starter->activeRunId('busy'));
    $this->assertSame(0, $this->rowsOf('import_run', 'busy'));
  }

  /**
   * More runs than are handled at once are all removed.
   */
  public function testManyRunsAreAllRemoved(): void {
    $definition = $this->definition($this->accounts(['id' => 'many']));
    $definition->save();
    for ($i = 0; $i < 130; $i++) {
      ImportRun::create(['definition_id' => 'many', 'trigger' => 'drush', 'status' => RunStatus::Completed->value])->save();
    }
    $this->assertSame(130, $this->rowsOf('import_run', 'many'));

    $definition->delete();

    $this->assertSame(0, $this->rowsOf('import_run', 'many'));
  }

}
