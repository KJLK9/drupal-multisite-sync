<?php

declare(strict_types=1);

namespace Drupal\import_engine\Target;

/**
 * A field of a target that a field mapping can fill.
 */
final class TargetField {

  /**
   * Constructs a target field.
   *
   * @param string $name
   *   The name of the field.
   * @param string $label
   *   A label for people.
   * @param string $type
   *   The type of the field, which decides the mappers that apply to it, for
   *   example "string", "text_long", "money_field" or "entity_reference".
   * @param bool $required
   *   Whether a value is required.
   * @param array<string, mixed> $settings
   *   Settings of the field that a mapper may need, such as the entity type an
   *   entity reference points to.
   */
  public function __construct(
    public readonly string $name,
    public readonly string $label,
    public readonly string $type,
    public readonly bool $required = FALSE,
    public readonly array $settings = [],
  ) {
  }

}
