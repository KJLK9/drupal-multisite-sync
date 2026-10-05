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
   * @param list<string> $connection_keys
   *   The settings of the source that belong to a connection (where the
   *   source is, and how to talk to it), as opposed to the ones that belong to
   *   an import (what to ask for). An import that uses a connection leaves
   *   these to the connection.
   * @param list<string> $required_keys
   *   The settings an import must have, from the connection or of its own.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly array $connection_keys = [],
    public readonly array $required_keys = [],
  ) {
  }

}
