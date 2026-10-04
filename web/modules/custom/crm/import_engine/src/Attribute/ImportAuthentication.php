<?php

declare(strict_types=1);

namespace Drupal\import_engine\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an import authentication plugin: how requests prove who sends them.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ImportAuthentication extends Plugin {

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
