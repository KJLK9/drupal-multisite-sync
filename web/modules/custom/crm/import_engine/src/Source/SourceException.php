<?php

declare(strict_types=1);

namespace Drupal\import_engine\Source;

/**
 * A source failed while fetching during a run.
 *
 * The flag tells the engine what to do: a transient failure (timeout, server
 * error, rate limit) is retried and counts towards the circuit breaker, a
 * permanent one (bad credentials, malformed data) is not retried because it
 * will fail the same way again.
 */
final class SourceException extends \RuntimeException {

  /**
   * Constructs the exception.
   */
  private function __construct(
    string $message,
    public readonly bool $retryable,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

  /**
   * Creates an exception for a failure that may go away by itself.
   */
  public static function transient(string $message, ?\Throwable $previous = NULL): self {
    return new self($message, TRUE, $previous);
  }

  /**
   * Creates an exception for a failure that will not go away by retrying.
   */
  public static function permanent(string $message, ?\Throwable $previous = NULL): self {
    return new self($message, FALSE, $previous);
  }

}
