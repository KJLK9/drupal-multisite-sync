<?php

declare(strict_types=1);

namespace Drupal\import_engine\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\ImportRunSetInterface;

/**
 * Defines the run set config entity.
 */
#[ConfigEntityType(
  id: 'import_run_set',
  label: new TranslatableMarkup('Run set'),
  label_collection: new TranslatableMarkup('Run sets'),
  label_singular: new TranslatableMarkup('run set'),
  label_plural: new TranslatableMarkup('run sets'),
  config_prefix: 'import_run_set',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
  ],
  admin_permission: 'administer import definitions',
  label_count: [
    'singular' => '@count run set',
    'plural' => '@count run sets',
  ],
  config_export: [
    'id',
    'label',
    'description',
    'imports',
    'stop_on_errors',
  ],
)]
class ImportRunSet extends ConfigEntityBase implements ImportRunSetInterface {

  /**
   * The machine name.
   */
  protected string $id;

  /**
   * The human readable name.
   */
  protected string $label;

  /**
   * What the set is for.
   */
  protected string $description = '';

  /**
   * The IDs of the imports, in the order they are run.
   *
   * @var list<string>
   */
  protected array $imports = [];

  /**
   * Whether items that went wrong also stop the set.
   */
  protected bool $stop_on_errors = FALSE;

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * {@inheritdoc}
   */
  public function getImports(): array {
    return $this->imports;
  }

  /**
   * {@inheritdoc}
   */
  public function stopsOnErrors(): bool {
    return $this->stop_on_errors;
  }

}
