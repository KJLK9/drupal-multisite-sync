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
    public readonly ?int $retryAfter = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

  /**
   * Creates an exception for a failure that may go away by itself.
   *
   * @param string $message
   *   What went wrong.
   * @param \Throwable|null $previous
   *   The cause.
   * @param int|null $retryAfter
   *   The seconds the server asked to wait before calling again, if it did.
   */
  public static function transient(string $message, ?\Throwable $previous = NULL, ?int $retryAfter = NULL): self {
    return new self($message, TRUE, $previous, $retryAfter);
  }

  /**
   * Creates an exception for a failure that will not go away by retrying.
   */
  public static function permanent(string $message, ?\Throwable $previous = NULL): self {
    return new self($message, FALSE, $previous);
  }

}
