<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Unit;

use Drupal\import_engine\Drive\RunBudget;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the budget a worker has: time, memory and a request to stop.
 */
#[CoversClass(RunBudget::class)]
#[Group('import_engine')]
class RunBudgetTest extends UnitTestCase {

  /**
   * Without limits the budget is not spent until a stop is requested.
   */
  public function testNoLimits(): void {
    $budget = new RunBudget(NULL, 0.0);

    $this->assertFalse($budget->isExhausted(PHP_INT_MAX));
    $budget->stop();
    $this->assertTrue($budget->isStopRequested());
    $this->assertTrue($budget->isExhausted(0));
  }

  /**
   * The deadline is the moment the budget is spent.
   */
  public function testDeadline(): void {
    $budget = RunBudget::ofSeconds(60, 1000);

    $this->assertSame(1060, $budget->deadline);
    $this->assertFalse($budget->isExhausted(1059));
    $this->assertTrue($budget->isExhausted(1060));
  }

  /**
   * Zero seconds means no time limit.
   */
  public function testZeroSecondsIsNoLimit(): void {
    $this->assertNull(RunBudget::ofSeconds(0, 1000)->deadline);
  }

  /**
   * Memory settings are read like PHP reads them.
   */
  #[DataProvider('settings')]
  public function testBytes(string $setting, int $expected): void {
    $this->assertSame($expected, RunBudget::bytes($setting));
  }

  /**
   * Cases for the memory setting.
   *
   * @return array<string, array{string, int}>
   *   The setting and the bytes.
   */
  public static function settings(): array {
    return [
      'unlimited' => ['-1', 0],
      'empty' => ['', 0],
      'plain bytes' => ['1048576', 1048576],
      'kilobytes' => ['512K', 524288],
      'megabytes' => ['256M', 268435456],
      'gigabytes' => ['2G', 2147483648],
      'lowercase' => ['128m', 134217728],
    ];
  }

  /**
   * A budget with a memory fraction of nearly nothing is spent at once.
   */
  public function testMemory(): void {
    if (RunBudget::bytes((string) ini_get('memory_limit')) === 0) {
      $this->markTestSkipped('There is no memory limit to measure against.');
    }

    $this->assertTrue((new RunBudget(NULL, 0.000001))->isExhausted(0));
    $this->assertFalse((new RunBudget(NULL, 1000.0))->isExhausted(0));
  }

  /**
   * SIGTERM asks the budget to stop.
   */
  public function testStopsOnSigterm(): void {
    if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
      $this->markTestSkipped('The pcntl and posix extensions are needed.');
    }
    $budget = new RunBudget(NULL, 0.0);

    $this->assertTrue($budget->stopOnSignals());
    $this->assertFalse($budget->isStopRequested());
    posix_kill((int) getmypid(), SIGTERM);

    $this->assertTrue($budget->isStopRequested());
    // Leave the process as it was found.
    pcntl_signal(SIGTERM, SIG_DFL);
    pcntl_signal(SIGINT, SIG_DFL);
  }

}
