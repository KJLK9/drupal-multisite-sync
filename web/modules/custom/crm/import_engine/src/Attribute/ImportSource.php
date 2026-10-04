<?php

declare(strict_types=1);

namespace Drupal\import_engine\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an import source plugin: where the items of an import come from.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ImportSource extends Plugin {

  /**
   * Constructs the attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The label.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   A short description.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
  ) {
  }

}
