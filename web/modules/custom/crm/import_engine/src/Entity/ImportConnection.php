<?php

declare(strict_types=1);

namespace Drupal\import_engine\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\ImportConnectionInterface;
use Drupal\import_engine\Storage\ImportConnectionStorage;

/**
 * Defines the import connection config entity.
 */
#[ConfigEntityType(
  id: 'import_connection',
  label: new TranslatableMarkup('Import connection'),
  label_collection: new TranslatableMarkup('Import connections'),
  label_singular: new TranslatableMarkup('import connection'),
  label_plural: new TranslatableMarkup('import connections'),
  config_prefix: 'import_connection',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
  ],
  handlers: ['storage' => ImportConnectionStorage::class],
  admin_permission: 'administer import definitions',
  label_count: [
    'singular' => '@count import connection',
    'plural' => '@count import connections',
  ],
  config_export: [
    'id',
    'label',
    'description',
    'source',
    'authentication',
  ],
)]
class ImportConnection extends ConfigEntityBase implements ImportConnectionInterface {

  /**
   * The machine name.
   */
  protected string $id;

  /**
   * The human readable name.
   */
  protected string $label;

  /**
   * What this connection is for.
   */
  protected string $description = '';

  /**
   * The source plugin and the settings of it that belong to a connection.
   *
   * @var array{plugin: string, configuration: array<string, mixed>}
   */
  protected array $source = ['plugin' => 'http', 'configuration' => []];

  /**
   * The authentication plugin and its configuration.
   *
   * @var array{plugin: string, configuration: array<string, mixed>}
   */
  protected array $authentication = ['plugin' => 'none', 'configuration' => []];

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * {@inheritdoc}
   */
  public function getSource(): array {
    return $this->source;
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthentication(): array {
    return $this->authentication;
  }

}
