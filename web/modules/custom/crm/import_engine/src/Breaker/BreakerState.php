<?php

declare(strict_types=1);

namespace Drupal\import_engine\Breaker;

/**
 * The state of a circuit breaker.
 *
 * Stored as a small integer.
 */
enum BreakerState: int {

  // Calls are allowed.
  case Closed = 0;

  // The server is taken to be down: no calls, until a probe finds it back.
  case Open = 1;

  // A probe succeeded: calls are allowed again, and the first one to fail
  // opens the breaker again; the first one to succeed closes it.
  case HalfOpen = 2;

}
