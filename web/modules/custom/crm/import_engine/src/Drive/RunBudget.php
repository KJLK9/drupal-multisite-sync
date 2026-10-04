<?php

declare(strict_types=1);

namespace Drupal\import_engine\Drive;

/**
 * How long a process may keep working: time, memory and a request to stop.
 *
 * A worker checks it between units of work and stops cleanly when it is spent,
 * so it can be started again later and carry on where it stopped.
 */
final class RunBudget {

  /**
   * Whether a stop was requested.
   */
  private bool $stopRequested = FALSE;

  /**
   * Constructs the budget.
   *
   * @param int|null $deadline
   *   A timestamp at which the budget is spent; NULL for no time limit.
   * @param float $memoryFraction
   *   The share of the PHP memory limit that may be used; 0 for no limit.
   */
  public function __construct(
    public readonly ?int $deadline = NULL,
    private readonly float $memoryFraction = 0.8,
  ) {
  }

  /**
   * Creates a budget of a number of seconds from now.
   *
   * @param int $seconds
   *   The seconds; 0 or less for no time limit.
   * @param int $now
   *   The current time.
   */
  public static function ofSeconds(int $seconds, int $now): self {
    return new self($seconds > 0 ? $now + $seconds : NULL);
  }

  /**
   * Asks the worker to stop after the unit of work it is busy with.
   */
  public function stop(): void {
    $this->stopRequested = TRUE;
  }

  /**
   * Returns whether a stop was requested.
   */
  public function isStopRequested(): bool {
    return $this->stopRequested;
  }

  /**
   * Stops the budget when the process gets SIGTERM or SIGINT.
   *
   * Kubernetes sends SIGTERM before it kills a pod. Does nothing when the
   * pcntl extension is missing.
   *
   * @return bool
   *   Whether the handlers could be installed.
   */
  public function stopOnSignals(): bool {
    if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
      return FALSE;
    }
    pcntl_async_signals(TRUE);
    $handler = function (): void {
      $this->stop();
    };
    return pcntl_signal(SIGTERM, $handler) && pcntl_signal(SIGINT, $handler);
  }

  /**
   * Returns whether the worker must stop now.
   *
   * @param int $now
   *   The current time.
   */
  public function isExhausted(int $now): bool {
    return $this->stopRequested
      || ($this->deadline !== NULL && $now >= $this->deadline)
      || $this->memoryExceeded();
  }

  /**
   * Returns whether the process uses more memory than it may.
   */
  private function memoryExceeded(): bool {
    if ($this->memoryFraction <= 0) {
      return FALSE;
    }
    $limit = self::bytes((string) ini_get('memory_limit'));
    return $limit > 0 && memory_get_usage(TRUE) > $limit * $this->memoryFraction;
  }

  /**
   * Converts a PHP memory setting such as 256M to bytes; -1 is 0 (no limit).
   */
  public static function bytes(string $setting): int {
    $setting = trim($setting);
    if ($setting === '' || $setting === '-1') {
      return 0;
    }
    $number = (int) $setting;
    return match (strtolower(substr($setting, -1))) {
      'g' => $number * 1024 ** 3,
      'm' => $number * 1024 ** 2,
      'k' => $number * 1024,
      default => $number,
    };
  }

}
