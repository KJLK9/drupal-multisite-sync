<?php

declare(strict_types=1);

namespace Drupal\Tests\import_audit\Kernel;

use Symfony\Component\HttpFoundation\InputBag;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatch;
use Drupal\import_audit\Access\HistoryAccessCheck;
use Drupal\import_audit\Controller\HistoryController;
use Drupal\import_audit\Hook\AuditHooks;
use Drupal\import_audit\Routing\HistoryRoutes;
use Drupal\import_engine\Entity\ImportRun;
use Drupal\import_engine\Storage\EventType;
use Drupal\import_engine_ui\Controller\RunController;
use Drupal\node\Entity\Node;
use Drupal\Tests\import_engine\Kernel\NodeTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests the way from an import event to an entity, and back.
 */
#[Group('import_audit')]
#[RunTestsInSeparateProcesses]
class ImportHistoryTest extends NodeTestBase {

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
   * An account node that an import wrote, with its mapping.
   */
  protected Node $written;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->container->get('router.builder')->rebuild();
    $this->definition($this->accounts())->save();
    $this->written = Node::create(['type' => 'account', 'title' => 'Acme']);
    $this->written->save();
  }

  /**
   * Makes the log say what an import did to the node.
   *
   * @return int
   *   The run.
   */
  protected function importWrote(): int {
    $run = $this->createRun();
    $this->container->get('import_engine.mapping_store')->record('customers', '["C1"]', 'node', (string) $this->written->id(), NULL, (int) $run->id(), TRUE, 100);
    $events = $this->container->get('import_engine.event_log');
    $events->record((int) $run->id(), 'customers', EventType::Created, '["C1"]', 'node:' . $this->written->id(), NULL, 100);
    $events->record((int) $run->id(), 'customers', EventType::Updated, '["C1"]', 'node:' . $this->written->id(), 'Changed after all.', 200);
    return (int) $run->id();
  }

  /**
   * Returns the cells of the Target column after the hook has worked on them.
   *
   * @param list<\Drupal\import_engine\Storage\EventRecord> $events
   *   The events.
   *
   * @return array<int, mixed>
   *   The cells, by event ID.
   */
  protected function targetsOf(array $events): array {
    $targets = [];
    foreach ($events as $event) {
      $targets[$event->id] = $event->target ?? '';
    }
    $hooks = $this->container->get(AuditHooks::class);
    $this->assertInstanceOf(AuditHooks::class, $hooks);
    $hooks->eventTargets($targets, $events);
    return $targets;
  }

  /**
   * Makes the current route the history page of the node.
   */
  protected function visit(Node $node): RouteMatch {
    $route = $this->container->get('router.route_provider')->getRouteByName('import_audit.history.node');
    $this->assertSame('/node/{node}/import-history', $route->getPath());
    $request = Request::create('/node/' . $node->id() . '/import-history');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->add([
      '_route' => 'import_audit.history.node',
      '_route_object' => $route,
      'node' => $node,
      '_raw_variables' => new InputBag(['node' => $node->id()]),
    ]);
    $this->container->get('request_stack')->push($request);
    return new RouteMatch('import_audit.history.node', $route, ['node' => $node], ['node' => (string) $node->id()]);
  }

  /**
   * Every content entity type with a page gets a route and a tab.
   */
  public function testRoutesAndTabs(): void {
    $routes = $this->container->get('class_resolver')->getInstanceFromDefinition(HistoryRoutes::class)->routes();
    $route = $routes->get('import_audit.history.node');

    $this->assertNotNull($route);
    $this->assertSame('/node/{node}/import-history', $route->getPath());
    $this->assertSame('view import history', $route->getRequirement('_permission'));
    $this->assertSame('node.view', $route->getRequirement('_entity_access'));
    $this->assertNull($routes->get('import_audit.history.import_run'), 'An entity without a page of its own gets nothing.');

    $tasks = $this->container->get('plugin.manager.menu.local_task')->getDefinitions();
    $this->assertSame('entity.node.canonical', $tasks['import_audit.history:node']['base_route']);
  }

  /**
   * The page is only there for an entity that an import wrote.
   */
  public function testOnlyImportedEntitiesHaveHistory(): void {
    $other = Node::create(['type' => 'account', 'title' => 'By hand']);
    $other->save();
    $this->importWrote();
    $check = new HistoryAccessCheck($this->container->get('import_audit.history'));
    $this->setUpCurrentUser(['name' => 'bob'], []);

    $this->assertTrue($check->access($this->visit($this->written))->isAllowed());
    $this->assertFalse($check->access($this->visit($other))->isAllowed());
    $result = $check->access($this->visit($this->written));
    $this->assertInstanceOf(AccessResult::class, $result);
    $this->assertSame(HistoryAccessCheck::MAX_AGE, $result->getCacheMaxAge(), 'The log changes with every run: only briefly cached.');
  }

  /**
   * The list says what happened, newest first, and says what it leaves out.
   */
  public function testThePageShowsTheEventsAndTheirLimits(): void {
    $run_id = $this->importWrote();
    $this->setUpCurrentUser(['name' => 'bob'], ['view import history', 'access content']);
    $this->visit($this->written);

    $build = $this->container->get('class_resolver')->getInstanceFromDefinition(HistoryController::class)->view();

    $rows = $build['table']['#rows'];
    $this->assertSame(['Updated', 'Created'], array_column($rows, 1));
    $this->assertSame('Changed after all.', $rows[0][5]);
    $this->assertSame('Customers', (string) $rows[0][2]);
    $this->assertSame('Run ' . $run_id, (string) $rows[0][3]['data']['#title']);
    $this->assertStringContainsString('2 changes', (string) $build['header']['#markup']);
    $text = implode(' ', array_map('strval', array_column($build['about']['box']['list']['#items'], '#markup')));
    $this->assertStringContainsString('A change made by hand is not in it.', $text);
    $this->assertStringContainsString('Events are kept for 365 days.', $text);
  }

  /**
   * A run that has been removed is named, not linked.
   */
  public function testRemovedRunIsNotLinked(): void {
    $run_id = $this->importWrote();
    ImportRun::load($run_id)?->delete();
    $this->setUpCurrentUser(['name' => 'bob'], ['view import history', 'access content']);
    $this->visit($this->written);

    $rows = $this->container->get('class_resolver')->getInstanceFromDefinition(HistoryController::class)->view()['table']['#rows'];

    $this->assertSame('Run ' . $run_id . ' (removed)', (string) $rows[0][3]);
  }

  /**
   * The target of an event on a run page becomes a link, or says it is gone.
   */
  public function testTargetsBecomeLinks(): void {
    $this->importWrote();
    $gone = $this->container->get('import_engine.event_log');
    $gone->record(1, 'customers', EventType::Deleted, '["C2"]', 'node:999', NULL, 300);
    $gone->record(1, 'customers', EventType::Failed, '["C3"]', NULL, 'bad', 301);
    $gone->record(1, 'customers', EventType::Updated, '["C4"]', 'node_type:x', NULL, 302);
    $events = $gone->forRun(1);
    $this->setUpCurrentUser(['name' => 'carol'], ['view import history', 'access content']);

    $targets = $this->targetsOf($events);

    $by_key = [];
    foreach ($events as $event) {
      $by_key[$event->key] = $targets[$event->id];
    }
    $linked = $by_key['["C1"]'];
    $this->assertIsArray($linked);
    $this->assertSame('Acme', $linked[0]['#title']);
    $this->assertSame('history', (string) $linked[2]['#title']);
    $this->assertSame('node_type:x', $by_key['["C4"]'], 'A type without a history page stays text.');
    $this->assertSame('node:999 (no longer exists)', $by_key['["C2"]']);
    $this->assertSame('', $by_key['["C3"]'], 'An event without a target is left alone.');
  }

  /**
   * The events page of a run shows the link, through the hook of the module.
   */
  public function testEventsPageOfRunUsesTheHook(): void {
    $run_id = $this->importWrote();
    $this->setUpCurrentUser(['name' => 'frank'], ['view import runs', 'view import history', 'access content']);
    $run = ImportRun::load($run_id);
    $this->assertNotNull($run);

    $build = $this->container->get('class_resolver')->getInstanceFromDefinition(RunController::class)->events($run);

    $cell = $build['events']['#rows'][0][3];
    $this->assertSame('Acme', $cell['data'][0]['#title']);
  }

  /**
   * Without the permission there is no history link; a hidden entity is text.
   */
  public function testNoHistoryLinkWithoutThePermission(): void {
    $this->importWrote();
    $events = $this->container->get('import_engine.event_log')->forRun(1);
    $this->setUpCurrentUser(['name' => 'dave'], ['access content']);

    $targets = $this->targetsOf($events);
    $cell = reset($targets);

    $this->assertIsArray($cell);
    $this->assertCount(1, $cell, 'Only the link to the entity.');

    $this->setUpCurrentUser(['name' => 'erin'], []);
    $other = $this->targetsOf($events);
    $this->assertSame('node:' . $this->written->id(), reset($other), 'An entity that may not be seen stays as text.');
  }

}
