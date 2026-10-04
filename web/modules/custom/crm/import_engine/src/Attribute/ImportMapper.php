<?php

declare(strict_types=1);

namespace Drupal\import_engine\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an import mapper plugin.
 *
 * A mapper turns source values into the value of a target field.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ImportMapper extends Plugin {

  /**
   * Constructs the attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The label.
   * @param list<string> $field_types
   *   The types of target fields this mapper can fill. The mapper that applies
   *   is chosen from the type of the field, like a formatter.
   * @param array<string, bool> $sources
   *   The named source values the mapper reads, each with whether it is
   *   required. A mapping row gives a dotted path for every name.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   A short description.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly array $field_types,
    public readonly array $sources,
    public readonly ?TranslatableMarkup $description = NULL,
  ) {
  }

}
