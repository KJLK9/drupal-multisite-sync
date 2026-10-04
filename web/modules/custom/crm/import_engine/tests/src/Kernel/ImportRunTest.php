<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Entity\ImportRun;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the run entity: defaults, status transitions, counters and access.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class ImportRunTest extends StorageTestBase {

  use UserCreationTrait;

  /**
   * A new run is queued with zeroed counters.
   */
  public function testDefaults(): void {
    $run = $this->createRun();

    $this->assertSame('customers', $run->getDefinitionId());
    $this->assertSame(RunStatus::Queued, $run->getStatus());
    $this->assertSame(Trigger::Drush, $run->getTrigger());
    $this->assertFalse($run->isExtractComplete());
    $this->assertNull($run->getCursor());
    $this->assertSame(0, $run->getPagesRead());
    $this->assertSame(array_fill_keys(ImportRun::COUNTERS, 0), $run->getCounters());
    $this->assertSame('', $run->getSummary());
  }

  /**
   * A run goes through its statuses and records when it started and ended.
   */
  public function testLifecycleSetsTimes(): void {
    $run = $this->createRun();

    $run->transitionTo(RunStatus::Extracting, 1000)->save();
    $this->assertSame(1000, (int) $run->get('started')->value);
    $this->assertNull($run->get('finished')->value);

    $run->transitionTo(RunStatus::Processing, 1100)->setExtractComplete(TRUE);
    $run->transitionTo(RunStatus::Finishing, 1200);
    $run->transitionTo(RunStatus::CompletedWithErrors, 1300)->save();

    $loaded = ImportRun::load($run->id());
    $this->assertNotNull($loaded);
    $this->assertSame(RunStatus::CompletedWithErrors, $loaded->getStatus());
    $this->assertSame(1300, (int) $loaded->get('finished')->value);
    $this->assertTrue($loaded->isExtractComplete());
    $this->assertSame(1000, (int) $loaded->get('started')->value);
  }

  /**
   * Which moves are allowed.
   *
   * @param \Drupal\import_engine\Run\RunStatus $from
   *   The status the run is in.
   * @param \Drupal\import_engine\Run\RunStatus $to
   *   The status it moves to.
   * @param bool $allowed
   *   Whether that is allowed.
   */
  #[DataProvider('transitionProvider')]
  public function testTransitions(RunStatus $from, RunStatus $to, bool $allowed): void {
    $this->assertSame($allowed, $from->canMoveTo($to));
    if ($allowed) {
      $run = $this->createRun();
      $run->set('status', $from->value);
      $this->assertSame($to, $run->transitionTo($to)->getStatus());
    }
    else {
      $run = $this->createRun();
      $run->set('status', $from->value);
      $this->expectException(\LogicException::class);
      $run->transitionTo($to);
    }
  }

  /**
   * Data provider.
   *
   * @return array<string, array{\Drupal\import_engine\Run\RunStatus, \Drupal\import_engine\Run\RunStatus, bool}>
   *   From, to and whether it is allowed.
   */
  public static function transitionProvider(): array {
    return [
      'queued to extracting' => [RunStatus::Queued, RunStatus::Extracting, TRUE],
      'extracting to processing' => [RunStatus::Extracting, RunStatus::Processing, TRUE],
      'processing to finishing' => [RunStatus::Processing, RunStatus::Finishing, TRUE],
      'finishing to completed' => [RunStatus::Finishing, RunStatus::Completed, TRUE],
      'finishing to completed with errors' => [RunStatus::Finishing, RunStatus::CompletedWithErrors, TRUE],
      'cancel while queued' => [RunStatus::Queued, RunStatus::Cancelled, TRUE],
      'cancel while extracting' => [RunStatus::Extracting, RunStatus::Cancelled, TRUE],
      'fail while processing' => [RunStatus::Processing, RunStatus::Failed, TRUE],
      'queued straight to completed' => [RunStatus::Queued, RunStatus::Completed, FALSE],
      'extracting straight to finishing' => [RunStatus::Extracting, RunStatus::Finishing, FALSE],
      'back from processing to extracting' => [RunStatus::Processing, RunStatus::Extracting, FALSE],
      'completed is final' => [RunStatus::Completed, RunStatus::Extracting, FALSE],
      'failed is final' => [RunStatus::Failed, RunStatus::Cancelled, FALSE],
      'cancelled is final' => [RunStatus::Cancelled, RunStatus::Failed, FALSE],
    ];
  }

  /**
   * Final statuses are the four that end a run.
   */
  public function testFinalStatuses(): void {
    $final = array_filter(RunStatus::cases(), static fn (RunStatus $status): bool => $status->isFinal());

    $this->assertEqualsCanonicalizing(
      [RunStatus::Completed, RunStatus::CompletedWithErrors, RunStatus::Failed, RunStatus::Cancelled],
      array_values($final),
    );
    foreach ($final as $status) {
      $this->assertSame([], $status->next());
    }
  }

  /**
   * Counters and the extraction position are stored and read back.
   */
  public function testCountersCursorAndSummary(): void {
    $run = $this->createRun();

    $run->setCounters(['created' => 5, 'failed' => 2])->setCursor('100')->setPagesRead(3)->setSummary('2 items failed.')->save();
    $run->setCounters(['updated' => 7])->save();

    $loaded = ImportRun::load($run->id());
    $this->assertNotNull($loaded);
    $this->assertSame(5, $loaded->getCounters()['created']);
    $this->assertSame(7, $loaded->getCounters()['updated']);
    $this->assertSame(2, $loaded->getCounters()['failed']);
    $this->assertSame(0, $loaded->getCounters()['dead']);
    $this->assertSame('100', $loaded->getCursor());
    $this->assertSame(3, $loaded->getPagesRead());
    $this->assertSame('2 items failed.', $loaded->getSummary());

    $this->expectException(\InvalidArgumentException::class);
    $loaded->setCounters(['bogus' => 1]);
  }

  /**
   * A status or trigger that does not exist is a validation error.
   */
  public function testValidation(): void {
    $this->assertCount(0, $this->createRun()->validate());

    $bad = ImportRun::create(['definition_id' => 'customers', 'trigger' => 'carrier_pigeon', 'status' => 'dancing']);
    $paths = [];
    foreach ($bad->validate() as $violation) {
      $paths[] = $violation->getPropertyPath();
    }
    $this->assertContains('trigger.0.value', $paths);
    $this->assertContains('status.0.value', $paths);
  }

  /**
   * Viewing needs its permission; changing a run needs the admin permission.
   */
  public function testAccess(): void {
    $run = $this->createRun();
    // User 1 bypasses all access checks; burn it.
    $this->createUser();
    $viewer = $this->createUser(['view import runs']);
    $admin = $this->createUser(['administer import runs']);
    $nobody = $this->createUser();

    $this->assertTrue($run->access('view', $viewer));
    $this->assertFalse($run->access('update', $viewer));
    $this->assertFalse($run->access('delete', $viewer));
    $this->assertFalse($run->access('view', $nobody));
    $this->assertTrue($run->access('view', $admin));
    $this->assertTrue($run->access('update', $admin));
    $this->assertTrue($run->access('delete', $admin));
    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('import_run');
    $this->assertTrue($handler->createAccess(NULL, $admin));
    $this->assertFalse($handler->createAccess(NULL, $viewer));
  }

  /**
   * The indexes that the interface and the purge rely on exist.
   */
  public function testIndexesExist(): void {
    $schema = $this->container->get('database')->schema();

    foreach (['import_run__definition', 'import_run__status', 'import_run__finished'] as $index) {
      $this->assertTrue($schema->indexExists('import_run', $index), $index);
    }
  }

}
