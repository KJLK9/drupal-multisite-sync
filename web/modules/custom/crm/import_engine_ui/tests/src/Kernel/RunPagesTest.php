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
   * A run page shows the run, its items and its events.
   */
  public function testRunPage(): void {
    $run = $this->importRows([['id' => 1, 'name' => 'Acme', 'code' => 'A'], ['id' => 2, 'name' => '', 'code' => 'B']]);
    $this->finishRun($run);
    $run = $this->reload($run);

    $build = $this->controller->view($run, new Request());

    $summary = [];
    foreach ($build['summary']['#rows'] as $row) {
      $summary[(string) $row[0]] = $row[1];
    }
    $this->assertSame('Customers', $summary['Import']);
    $this->assertSame('1', (string) $summary['Pages read']);
    $this->assertCount(2, $build['items']['#rows']);
    // Newest first: key 2 was queued last.
    $this->assertSame('["2"]', $build['items']['#rows'][0][0]);
    $this->assertSame('DEAD', strtoupper($build['items']['#rows'][0][1]));
    $this->assertSame(['Created', 'Dead'], array_column($build['events']['#rows'], 1));
    $this->assertArrayNotHasKey('operations', $build, 'A finished run cannot be cancelled.');
    $this->assertSame('Run 1 of Customers', (string) $this->controller->title($run));
  }

  /**
   * The items can be filtered by state; an unknown state is no filter.
   */
  public function testItemFilter(): void {
    $run = $this->importRows([['id' => 1, 'name' => 'Acme', 'code' => 'A'], ['id' => 2, 'name' => '', 'code' => 'B']]);

    $dead = $this->controller->view($run, new Request(['state' => 'dead']));
    $this->assertCount(1, $dead['items']['#rows']);
    $this->assertSame('["2"]', $dead['items']['#rows'][0][0]);

    $done = $this->controller->view($run, new Request(['state' => 'done']));
    $this->assertCount(1, $done['items']['#rows']);
    $this->assertSame('["1"]', $done['items']['#rows'][0][0]);

    $all = $this->controller->view($run, new Request(['state' => 'bogus']));
    $this->assertCount(2, $all['items']['#rows']);
  }

  /**
   * A run that is not over offers to cancel it, to those who may.
   */
  public function testCancelOperation(): void {
    $run = $this->extractRows($this->pagesOf(1, 1)[0]);

    $this->setUpCurrentUser([], ['view import runs']);
    $this->assertArrayNotHasKey('operations', $this->controller->view($run, new Request()));

    $this->setUpCurrentUser([], ['view import runs', 'administer import runs']);
    $build = $this->controller->view($run, new Request());
    $this->assertArrayHasKey('operations', $build);
    $this->assertSame('import_engine_ui.run_cancel', $build['operations']['#links']['cancel']['url']->getRouteName());
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
