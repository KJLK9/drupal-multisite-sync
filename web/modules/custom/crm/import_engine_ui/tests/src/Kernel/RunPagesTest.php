<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine_ui\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine_ui\Controller\RunController;
use Drupal\import_engine_ui\Form\RunCancelForm;
use Drupal\Tests\import_engine\Kernel\NodeTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the pages of runs: the list, one run, access and cancelling.
 */
#[Group('import_engine_ui')]
#[RunTestsInSeparateProcesses]
class RunPagesTest extends NodeTestBase {

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
  ];

  /**
   * The controller.
   */
  protected RunController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->container->get('router.builder')->rebuild();
    $this->controller = $this->container->get('class_resolver')->getInstanceFromDefinition(RunController::class);
  }

  /**
   * Returns the text of a table cell, whatever it is made of.
   */
  protected function text(mixed $cell): string {
    if (is_array($cell)) {
      $cell = $cell['data'] ?? $cell;
    }
    if (is_array($cell)) {
      return strip_tags((string) ($cell['#markup'] ?? ''));
    }
    return strip_tags((string) $cell);
  }

  /**
   * The list shows a finished run with its stored counters.
   */
  public function testListShowsFinishedRun(): void {
    $run = $this->importRows([['id' => 1, 'name' => 'Acme', 'code' => 'A'], ['id' => 2, 'name' => '', 'code' => 'B']]);
    $this->finishRun($run);

    $build = $this->controller->list();

    $this->assertCount(1, $build['table']['#rows']);
    $row = $build['table']['#rows'][0];
    $this->assertSame('1', (string) $row[0]->getText());
    $this->assertSame('Customers', $row[1]);
    $this->assertSame('completed with errors', $this->text($row[2]));
    $this->assertSame('drush', $row[3]);
    // Extracted, created, updated, failed, dead.
    $this->assertSame([2, 1, 0, 0, 1], array_slice($row, 6));
  }

  /**
   * A run that is not over shows live counters, derived from its items.
   */
  public function testListShowsLiveCounters(): void {
    $this->extractRows($this->pagesOf(1, 3)[0]);
    $this->process->process('w', 2);

    $row = $this->controller->list()['table']['#rows'][0];

    $this->assertSame('processing', $this->text($row[2]));
    $this->assertSame([3, 2, 0, 0, 0], array_slice($row, 6));
  }

  /**
   * The list says what to do when there are no runs.
   */
  public function testEmptyList(): void {
    $this->assertStringContainsString('drush import:run', (string) $this->controller->list()['table']['#empty']);
  }

  /**
   * The page of a run shows what it is and how it went, in a few lines.
   */
  public function testRunPage(): void {
    $run = $this->importRows([['id' => 1, 'name' => 'Acme', 'code' => 'A'], ['id' => 2, 'name' => '', 'code' => 'B']]);
    $this->finishRun($run);
    $run = $this->reload($run);

    $build = $this->controller->view($run);

    $facts = [];
    foreach ($build['summary']['#context']['facts'] as $fact) {
      $facts[(string) $fact['label']] = $fact['value'];
    }
    $this->assertSame('Customers', $facts['Import']);
    $this->assertSame(1, $facts['Pages read']);
    $this->assertSame('completed with errors', $this->text($facts['Status']));
    $this->assertArrayHasKey('Duration', $facts);
    $this->assertArrayNotHasKey('items', $build, 'The items have a page of their own.');
    $this->assertArrayNotHasKey('events', $build, 'The events have a page of their own.');
    $this->assertArrayNotHasKey('actions', $build, 'A finished run cannot be cancelled.');
    $this->assertSame('Run 1 of Customers', (string) $this->controller->title($run));

    $tiles = [];
    foreach ($build['counters']['#context']['tiles'] as $tile) {
      $tiles[$tile['label']] = $tile;
    }
    $this->assertSame(2, $tiles['items extracted']['count']);
    $this->assertSame('is-problem', $tiles['dead']['class'], 'A counter that went wrong stands out.');
    $this->assertSame('', $tiles['created']['class']);
  }

  /**
   * The pages of a run render: facts, counters, buttons and tables.
   */
  public function testPagesRender(): void {
    $run = $this->importRows([['id' => 1, 'name' => 'Acme', 'code' => 'A'], ['id' => 2, 'name' => '', 'code' => 'B']]);
    $this->finishRun($run);
    $run = $this->reload($run);
    $renderer = $this->container->get('renderer');

    $overview = $this->controller->view($run);
    $html = (string) $renderer->renderInIsolation($overview);
    $this->assertStringContainsString('import-run-facts', $html);
    $this->assertStringContainsString('import-run-status--completed-with-errors', $html);
    $this->assertStringContainsString('import-run-counter is-problem', $html);
    $this->assertStringContainsString('Items (2)', $html);

    $items = $this->controller->items($run, new Request(['state' => 'dead']));
    $html = (string) $renderer->renderInIsolation($items);
    $this->assertStringContainsString('import-run-pill is-active', $html);
    $this->assertStringContainsString('Dead (1)', $html);

    $events = $this->controller->events($run);
    $html = (string) $renderer->renderInIsolation($events);
    $this->assertStringContainsString('Events (2)', $html);
  }

  /**
   * The buttons between the pages of a run say how much each page has.
   */
  public function testRunNavigation(): void {
    $run = $this->importRows([['id' => 1, 'name' => 'Acme', 'code' => 'A'], ['id' => 2, 'name' => '', 'code' => 'B']]);
    $this->finishRun($run);
    $run = $this->reload($run);

    $nav = $this->controller->view($run)['nav'];

    $this->assertSame('Overview', (string) $nav['overview']['#title']);
    $this->assertSame('Items (2)', (string) $nav['items']['#title']);
    $this->assertSame('Events (2)', (string) $nav['events']['#title']);
    $this->assertSame('import_engine_ui.run_items', $nav['items']['#url']->getRouteName());
    $this->assertContains('is-active', $nav['overview']['#attributes']['class']);
    $this->assertNotContains('is-active', $nav['items']['#attributes']['class']);
    $this->assertContains('is-active', $this->controller->items($run, new Request())['nav']['items']['#attributes']['class']);
    $this->assertContains('is-active', $this->controller->events($run)['nav']['events']['#attributes']['class']);
  }

  /**
   * The items page lists the items, newest first.
   */
  public function testItemsPage(): void {
    $run = $this->importRows([['id' => 1, 'name' => 'Acme', 'code' => 'A'], ['id' => 2, 'name' => '', 'code' => 'B']]);

    $build = $this->controller->items($run, new Request());

    $this->assertCount(2, $build['items']['#rows']);
    // Newest first: key 2 was queued last.
    $this->assertSame('["2"]', $build['items']['#rows'][0][0]);
    $this->assertSame('DEAD', strtoupper($build['items']['#rows'][0][1]));
  }

  /**
   * The events page lists what changed or went wrong.
   */
  public function testEventsPage(): void {
    $run = $this->importRows([['id' => 1, 'name' => 'Acme', 'code' => 'A'], ['id' => 2, 'name' => '', 'code' => 'B']]);
    $this->finishRun($run);

    $build = $this->controller->events($this->reload($run));

    $this->assertSame(['Created', 'Dead'], array_column($build['events']['#rows'], 1));
  }

  /**
   * The items can be filtered by state, with buttons; unknown is no filter.
   */
  public function testItemFilter(): void {
    $run = $this->importRows([['id' => 1, 'name' => 'Acme', 'code' => 'A'], ['id' => 2, 'name' => '', 'code' => 'B']]);

    $dead = $this->controller->items($run, new Request(['state' => 'dead']));
    $this->assertCount(1, $dead['items']['#rows']);
    $this->assertSame('["2"]', $dead['items']['#rows'][0][0]);

    $done = $this->controller->items($run, new Request(['state' => 'done']));
    $this->assertCount(1, $done['items']['#rows']);
    $this->assertSame('["1"]', $done['items']['#rows'][0][0]);

    $all = $this->controller->items($run, new Request(['state' => 'bogus']));
    $this->assertCount(2, $all['items']['#rows']);

    // The buttons: the state that is shown is the active one.
    $active = [];
    foreach ($done['filter'] as $key => $button) {
      if (is_int($key) && in_array('is-active', $button['#attributes']['class'], TRUE)) {
        $active[] = (string) $button['#title'];
      }
    }
    $this->assertSame(['Done (1)'], $active);
    $this->assertSame('All (2)', (string) $all['filter'][0]['#title']);
    $this->assertContains('is-active', $all['filter'][0]['#attributes']['class']);
  }

  /**
   * A run that is not over offers to cancel it, to those who may.
   */
  public function testCancelOperation(): void {
    $run = $this->extractRows($this->pagesOf(1, 1)[0]);

    $this->setUpCurrentUser([], ['view import runs']);
    $this->assertArrayNotHasKey('actions', $this->controller->view($run));

    $this->setUpCurrentUser([], ['view import runs', 'administer import runs']);
    $build = $this->controller->view($run);
    $this->assertArrayHasKey('actions', $build);
    $this->assertSame('import_engine_ui.run_cancel', $build['actions']['cancel']['#url']->getRouteName());
    $this->assertSame('import_engine_ui.definition_run', $build['actions']['continue']['#url']->getRouteName());
    $this->assertContains('button--danger', $build['actions']['cancel']['#attributes']['class']);
  }

  /**
   * Who may see and who may cancel is decided by the permissions.
   */
  public function testAccess(): void {
    $run = $this->extractRows($this->pagesOf(1, 1)[0]);
    $manager = $this->container->get('access_manager');
    $parameters = ['import_run' => $run->id()];

    $nobody = $this->createUser();
    $viewer = $this->createUser(['view import runs']);
    $admin = $this->createUser(['administer import runs']);

    $this->assertFalse($manager->checkNamedRoute('import_engine_ui.runs', [], $nobody));
    $this->assertTrue($manager->checkNamedRoute('import_engine_ui.runs', [], $viewer));
    $this->assertFalse($manager->checkNamedRoute('import_engine_ui.run', $parameters, $nobody));
    $this->assertTrue($manager->checkNamedRoute('import_engine_ui.run', $parameters, $viewer));
    $this->assertFalse($manager->checkNamedRoute('import_engine_ui.run_cancel', $parameters, $viewer));
    $this->assertTrue($manager->checkNamedRoute('import_engine_ui.run_cancel', $parameters, $admin));
  }

  /**
   * Confirming the form cancels the run and says so.
   */
  public function testCancelForm(): void {
    $run = $this->extractRows($this->pagesOf(1, 3)[0]);
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$run]);

    $this->container->get('form_builder')->submitForm(RunCancelForm::class, $form_state);

    $this->assertSame(RunStatus::Cancelled, $this->reload($run)->getStatus());
    $messages = $this->container->get('messenger')->all();
    $this->assertStringContainsString('is cancelled; 3 waiting items were skipped', (string) $messages['status'][0]);
  }

  /**
   * Cancelling a run that is over is refused with a message.
   */
  public function testCancelFormForFinishedRun(): void {
    $run = $this->importRows($this->pagesOf(1, 1)[0]);
    $this->finishRun($run);
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$this->reload($run)]);

    $this->container->get('form_builder')->submitForm(RunCancelForm::class, $form_state);

    $messages = $this->container->get('messenger')->all();
    $this->assertStringContainsString('is already over', (string) $messages['error'][0]);
    $this->assertSame(RunStatus::Completed, $this->reload($run)->getStatus());
  }

}
