<?php

declare(strict_types=1);

namespace Drupal\import_engine\Retry;

use Drupal\import_engine\BackoffStrategy;

/**
 * Decides when a failed item may be tried again.
 *
 * The delay grows with the number of attempts made, following the backoff
 * strategy of the import: the same every time, a growing step, or doubling. It
 * never exceeds a day-part cap so one stuck item cannot wait for weeks. There
 * is no random jitter, which keeps the behaviour predictable and testable.
 */
final class RetryPolicy {

  /**
   * The longest delay between two attempts, in seconds (6 hours).
   */
  public const MAX_DELAY = 21600;

  /**
   * Returns the timestamp from which an item may be tried again.
   *
   * @param int $attempts
   *   The number of attempts made so far, including the one that just failed.
   * @param \Drupal\import_engine\BackoffStrategy $backoff
   *   How the delay grows.
   * @param int $baseDelay
   *   The delay before the first retry, in seconds.
   * @param int $now
   *   The current time.
   */
  public function nextAttempt(int $attempts, BackoffStrategy $backoff, int $baseDelay, int $now): int {
    return $now + $this->delay($attempts, $backoff, $baseDelay);
  }

  /**
   * Returns the delay after a failed attempt, in seconds.
   *
   * @param int $attempts
   *   The number of attempts made so far, at least 1.
   * @param \Drupal\import_engine\BackoffStrategy $backoff
   *   How the delay grows.
   * @param int $baseDelay
   *   The delay before the first retry, in seconds.
   */
  public function delay(int $attempts, BackoffStrategy $backoff, int $baseDelay): int {
    $attempts = max(1, $attempts);
    $delay = match ($backoff) {
      BackoffStrategy::Fixed => $baseDelay,
      BackoffStrategy::Linear => $baseDelay * $attempts,
      // Doubling, with the exponent bounded so the number cannot overflow.
      BackoffStrategy::Exponential => $baseDelay * (2 ** min($attempts - 1, 20)),
    };
    return min($delay, self::MAX_DELAY);
  }

}
