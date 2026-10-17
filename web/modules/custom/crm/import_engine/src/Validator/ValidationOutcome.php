<?php

declare(strict_types=1);

namespace Drupal\import_engine\Validator;

/**
 * What a validator found.
 *
 * It never holds the value that was wrong: the reason ends up in events and
 * messages that are kept for a long time and read by others, and a value (an
 * email address, a telephone number) is often personal data.
 */
final class ValidationOutcome {

  /**
   * Constructs the outcome.
   *
   * @param bool $valid
   *   Whether the value is valid.
   * @param mixed $value
   *   The value to go on with: the one that was checked, or its normalized
   *   form (a telephone number without spaces).
   * @param string|null $reason
   *   Why the value is not valid, in words that do not repeat it, for example
   *   "is not a valid email address".
   */
  private function __construct(
    public readonly bool $valid,
    public readonly mixed $value,
    public readonly ?string $reason,
  ) {
  }

  /**
   * The value is valid; go on with this value.
   */
  public static function valid(mixed $value): self {
    return new self(TRUE, $value, NULL);
  }

  /**
   * The value is not valid.
   *
   * @param string $reason
   *   Why, without the value, for example "is not a valid email address".
   */
  public static function invalid(string $reason): self {
    return new self(FALSE, NULL, $reason);
  }

}
