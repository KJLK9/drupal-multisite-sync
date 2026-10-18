<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\RunSet\SetProgress;
use Drupal\import_engine\RunSet\SetState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a template never runs, even when its file says it is enabled.
 *
 * Saving a template switches it off. These tests write the configuration
 * directly, as a configuration import or a hand-edited file would, so that the
 * guard in the paths that start a run is what is tried.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class TemplateGuardTest extends HttpImportTestBase {

  /**
   * Makes an import a template that still says it is enabled and scheduled.
   */
  protected function sneakTemplate(string $id): ImportDefinition {
    $this->config('import_engine.import_definition.' . $id)
      ->set('template', TRUE)
      ->set('status', TRUE)
      ->set('schedule', ['enabled' => TRUE, 'every' => 15, 'start' => 0])
      ->save();
    $definition = ImportDefinition::load($id);
    $this->assertInstanceOf(ImportDefinition::class, $definition);
    $this->assertTrue($definition->status(), 'The file says it is enabled.');
    $this->assertTrue($definition->isTemplate());
    return $definition;
  }

  /**
   * It cannot be started.
   */
  public function testTemplateCannotBeStarted(): void {
    $this->httpImport('first');
    $definition = $this->sneakTemplate('first');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('is a template');
    $this->container->get('import_engine.run_starter')->start($definition, Trigger::Drush);
  }

  /**
   * The scheduler leaves it alone.
   */
  public function testSchedulerSkipsTemplates(): void {
    $this->httpImport('first');
    $this->sneakTemplate('first');

    $result = $this->container->get('import_engine.scheduler')->runDue(
      (int) strtotime('2026-10-12 10:15:00 UTC'),
      new RunBudget(),
      'test',
    );

    $this->assertSame([], $result->outcomes);
    $this->assertNull($this->container->get('import_engine.run_manager')->latest('first'));
  }

  /**
   * A run set stops at it and says why.
   */
  public function testRunSetStopsAtTemplate(): void {
    $this->httpImport('first');
    $this->sneakTemplate('first');
    $set = $this->runSet(['first']);

    $progress = $this->container->get('import_engine.run_set_runner')->run($set, new SetProgress(), new RunBudget(), 'test', Trigger::Drush);

    $this->assertSame(SetState::Stopped, $progress->state);
    $this->assertSame('The import "first" is a template.', $progress->outcomes[0]['summary']);
    $this->assertNull($this->container->get('import_engine.run_manager')->latest('first'));
  }

}
