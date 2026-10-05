<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Drive\DriveResult;
use Drupal\import_engine\Drive\DriveStatus;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what a run does when the connection of its import is gone.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class ConnectionRunTest extends ExtractTestBase {

  /**
   * A run of an import whose connection is gone fails, and says why.
   */
  public function testRunFailsWhenTheConnectionIsGone(): void {
    $definition = $this->definition([
      'connection' => 'ghost',
      'source' => [
        'plugin' => 'graphql',
        'configuration' => ['query' => '{ x }', 'variables' => '', 'items_path' => 'data'],
      ],
    ]);
    $run = $this->starter->start($definition, Trigger::Drush);

    $result = $this->container->get('import_engine.run_driver')->drive($run, $definition, new RunBudget(), 'w');

    $this->assertSame(DriveStatus::Finished, $result->status);
    $this->assertSame(RunStatus::Failed, $result->runStatus);
    $this->assertSame(DriveResult::EXIT_FAILED, $result->exitCode());
    $this->assertSame('The connection "ghost" of the import "customers" no longer exists.', $result->message);
    $this->assertSame($result->message, $run->getSummary());
    $this->assertSame(RunStatus::Failed, $run->getStatus());
    // Nothing is left that blocks a new run.
    $this->assertNull($this->starter->activeRunId('customers'));
  }

}
