<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Unit;

use Drupal\import_engine\BackoffStrategy;
use Drupal\import_engine\Retry\RetryPolicy;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests when a failed item may be tried again.
 */
#[CoversClass(RetryPolicy::class)]
#[Group('import_engine')]
class RetryPolicyTest extends UnitTestCase {

  /**
   * The delay follows the backoff strategy.
   */
  #[DataProvider('delays')]
  public function testDelay(BackoffStrategy $backoff, int $attempts, int $expected): void {
    $this->assertSame($expected, (new RetryPolicy())->delay($attempts, $backoff, 60));
  }

  /**
   * Cases for the delay, with a base delay of 60 seconds.
   *
   * @return array<string, array{BackoffStrategy, int, int}>
   *   Strategy, attempts made, expected delay.
   */
  public static function delays(): array {
    return [
      'fixed first' => [BackoffStrategy::Fixed, 1, 60],
      'fixed later' => [BackoffStrategy::Fixed, 5, 60],
      'linear first' => [BackoffStrategy::Linear, 1, 60],
      'linear third' => [BackoffStrategy::Linear, 3, 180],
      'exponential first' => [BackoffStrategy::Exponential, 1, 60],
      'exponential fourth' => [BackoffStrategy::Exponential, 4, 480],
      'zero attempts count as one' => [BackoffStrategy::Exponential, 0, 60],
      'capped' => [BackoffStrategy::Exponential, 12, RetryPolicy::MAX_DELAY],
      'huge attempt count does not overflow' => [BackoffStrategy::Exponential, 5000, RetryPolicy::MAX_DELAY],
    ];
  }

  /**
   * The next attempt is the delay after now.
   */
  public function testNextAttempt(): void {
    $this->assertSame(1000 + 120, (new RetryPolicy())->nextAttempt(2, BackoffStrategy::Linear, 60, 1000));
  }

}
