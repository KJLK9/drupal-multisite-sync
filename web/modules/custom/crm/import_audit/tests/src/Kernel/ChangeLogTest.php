<?php

declare(strict_types=1);

namespace Drupal\Tests\import_audit\Kernel;

use Drupal\import_audit\Controller\HistoryController;
use Drupal\import_audit\Hook\CronHooks;
use Drupal\import_audit\History\ChangeLog;
use Drupal\import_engine\Run\RunStatus;
use Drupal\node\Entity\Node;
use Drupal\Tests\import_engine\Kernel\FakeSource;
use Drupal\Tests\import_engine\Kernel\NodeTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests that the module keeps what the engine wrote, and shows and removes it.
 */
#[Group('import_audit')]
#[RunTestsInSeparateProcesses]
class ChangeLogTest extends NodeTestBase {

  use UserCreationTrait;

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
    'import_engine_ui',
    'import_audit',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('import_audit', ['import_audit_change']);
    $this->installConfig(['system', 'import_audit']);
    $this->container->get('router.builder')->rebuild();
    $this->definition($this->accounts())->save();
  }

  /**
   * Runs the import once with the rows, in a run that ends.
   *
   * @param list<array<string, mixed>> $rows
   *   The source items.
   * @param int $now
   *   The time.
   */
  protected function importAt(array $rows, int $now): void {
    $run = $this->extractAll(new FakeSource([$rows]), $this->accounts());
    $this->process->process('w', 10, 'default', 600, $now);
    $run->transitionTo(RunStatus::Finishing)->transitionTo(RunStatus::Completed)->save();
  }

  /**
   * Returns the rows of the table of changes.
   *
   * @return list<array<string, mixed>>
   *   The rows.
   */
  protected function stored(): array {
    $rows = $this->container->get('database')->select('import_audit_change', 'c')->fields('c')->orderBy('id')->execute();
    $this->assertNotNull($rows);
    return array_values(array_map(static fn (object $row): array => (array) $row, $rows->fetchAll()));
  }

  /**
   * An import's writes are kept per field, with old and new values.
   */
  public function testChangesAreKeptPerField(): void {
    $this->importAt([['id' => 1, 'name' => 'Acme', 'code' => 'A']], 1000);
    $this->importAt([['id' => 1, 'name' => 'Acme BV', 'code' => 'A']], 2000);

    $rows = $this->stored();

    $this->assertSame(
      [['title', '', 'Acme'], ['field_code', '', 'A'], ['title', 'Acme', 'Acme BV']],
      array_map(static fn (array $row): array => [$row['field_name'], $row['old_value'], $row['new_value']], $rows),
    );
    $this->assertSame(
      ['node', '1', 'customers', '["1"]'],
      [$rows[2]['target_type'], $rows[2]['target_id'], $rows[2]['definition_id'], $rows[2]['source_key']],
    );
  }

  /**
   * An unchanged item adds nothing.
   */
  public function testUnchangedItemsAddNothing(): void {
    $this->importAt([['id' => 1, 'name' => 'Acme', 'code' => 'A']], 1000);
    $before = count($this->stored());

    $this->importAt([['id' => 1, 'name' => 'Acme', 'code' => 'A']], 2000);

    $this->assertCount($before, $this->stored());
  }

  /**
   * The page of an entity shows the fields beside the event.
   */
  public function testThePageShowsWhatChanged(): void {
    $this->importAt([['id' => 1, 'name' => 'Acme', 'code' => 'A']], 1000);
    $this->importAt([['id' => 1, 'name' => 'Acme BV', 'code' => 'A']], 2000);
    $node = Node::load(1);
    $this->assertNotNull($node);
    $this->setUpCurrentUser(['name' => 'gina'], ['view import history', 'access content']);
    $route = $this->container->get('router.route_provider')->getRouteByName('import_audit.history.node');
    $request = Request::create('/node/1/import-history');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->add([
      '_route' => 'import_audit.history.node',
      '_route_object' => $route,
      'node' => $node,
      '_raw_variables' => new InputBag(['node' => '1']),
    ]);
    $this->container->get('request_stack')->push($request);

    $build = $this->container->get('class_resolver')->getInstanceFromDefinition(HistoryController::class)->view();

    $rows = $build['table']['#rows'];
    $this->assertCount(2, $rows);
    $newest = array_map('strval', $rows[0][6]['data']['#items']);
    $this->assertSame(['title: Acme → Acme BV'], $newest);
    $oldest = array_map('strval', $rows[1][6]['data']['#items']);
    $this->assertSame(['title: (empty) → Acme', 'field_code: (empty) → A'], $oldest);
  }

  /**
   * What is older than the retention is removed, and only that.
   */
  public function testCronRemovesTheOld(): void {
    $this->importAt([['id' => 1, 'name' => 'Acme', 'code' => 'A']], 1000);
    $this->importAt([['id' => 1, 'name' => 'Acme BV', 'code' => 'A']], 40 * 86400);
    $hooks = $this->container->get(CronHooks::class);
    $this->assertInstanceOf(CronHooks::class, $hooks);

    $removed = $hooks->purge(41 * 86400);

    $this->assertSame(2, $removed, 'The two fields of the first write are older than 30 days.');
    $this->assertSame(['Acme BV'], array_column($this->stored(), 'new_value'));
  }

  /**
   * Of an entity with more changes than are shown, the newest are shown.
   */
  public function testTheNewestChangesWin(): void {
    $this->importAt([['id' => 1, 'name' => 'One', 'code' => 'A']], 1000);
    $this->importAt([['id' => 1, 'name' => 'Two', 'code' => 'A']], 2000);
    $this->importAt([['id' => 1, 'name' => 'Three', 'code' => 'A']], 3000);

    $log = $this->container->get('import_audit.change_log');
    $shown = array_merge(...array_values($log->forEntity('node', '1', 2)));

    $this->assertEqualsCanonicalizing(['Three', 'Two'], array_column($shown, 'new'));
  }

  /**
   * Before the update has made the table the history is empty, not broken.
   */
  public function testMissingTableGivesNoChanges(): void {
    $this->container->get('database')->schema()->dropTable('import_audit_change');

    $this->assertSame([], $this->container->get('import_audit.change_log')->forEntity('node', '1'));
  }

  /**
   * The group name ties changes to the event of the same write.
   */
  public function testGroupName(): void {
    $this->assertSame('3:customers:["1"]', ChangeLog::group(3, 'customers', '["1"]'));
  }

}
