<?php

declare(strict_types=1);

namespace Drupal\import_engine\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an import validator plugin.
 *
 * A validator checks the value a mapper made, for a mapping row, before it is
 * written: an email address, a telephone number, a length.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ImportValidator extends Plugin {

  /**
   * Constructs the attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The label.
   * @param list<string> $mappers
   *   The IDs of the mappers whose value this validator can check: those that
   *   make a single text or number.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   A short description.
   * @param int $weight
   *   Decides the order in a list: the lowest comes first, then by ID.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly array $mappers,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly int $weight = 0,
  ) {
  }

}
