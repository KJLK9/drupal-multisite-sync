<?php

declare(strict_types=1);

namespace Drupal\import_engine\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\BackoffStrategy;
use Drupal\import_engine\DeletePolicy;
use Drupal\import_engine\ImportDefinitionInterface;

/**
 * Defines the import definition config entity.
 */
#[ConfigEntityType(
  id: 'import_definition',
  label: new TranslatableMarkup('Import definition'),
  label_collection: new TranslatableMarkup('Import definitions'),
  label_singular: new TranslatableMarkup('import definition'),
  label_plural: new TranslatableMarkup('import definitions'),
  config_prefix: 'import_definition',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
    'uuid' => 'uuid',
  ],
  admin_permission: 'administer import definitions',
  label_count: [
    'singular' => '@count import definition',
    'plural' => '@count import definitions',
  ],
  config_export: [
    'id',
    'label',
    'description',
    'source',
    'source_key',
    'pagination',
    'authentication',
    'target',
    'mapping',
    'delete_policy',
    'resilience',
    'pool',
  ],
)]
class ImportDefinition extends ConfigEntityBase implements ImportDefinitionInterface {

  /**
   * The machine name.
   */
  protected string $id;

  /**
   * The human readable name.
   */
  protected string $label;

  /**
   * A description of what the import does.
   */
  protected string $description = '';

  /**
   * The source plugin and its configuration.
   *
   * @var array{plugin: string, configuration: array<string, mixed>}
   */
  protected array $source = ['plugin' => 'http', 'configuration' => []];

  /**
   * The dotted paths whose values together identify a source item.
   *
   * @var list<string>
   */
  protected array $source_key = [];

  /**
   * The pagination plugin and its configuration.
   *
   * @var array{plugin: string, configuration: array<string, mixed>}
   */
  protected array $pagination = ['plugin' => 'none', 'configuration' => []];

  /**
   * The authentication plugin and its configuration.
   *
   * @var array{plugin: string, configuration: array<string, mixed>}
   */
  protected array $authentication = ['plugin' => 'none', 'configuration' => []];

  /**
   * The target entity type and bundle.
   *
   * @var array{entity_type: string, bundle: string}
   */
  protected array $target = ['entity_type' => '', 'bundle' => ''];

  /**
   * The field mapping.
   *
   * @var list<array{target_field: string, mapper: array{plugin: string, sources: array<string, string>, settings: array<string, mixed>}}>
   */
  protected array $mapping = [];

  /**
   * What happens to entities whose source item is gone.
   */
  protected string $delete_policy = 'unpublish';

  /**
   * Retry settings.
   *
   * @var array{max_attempts: int, backoff: string, dlq_enabled: bool}
   */
  protected array $resilience = [
    'max_attempts' => 5,
    'backoff' => 'exponential',
    'dlq_enabled' => TRUE,
  ];

  /**
   * The worker pool that processes this import's items.
   */
  protected string $pool = 'default';

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
  public function getSourceKey(): array {
    return $this->source_key;
  }

  /**
   * {@inheritdoc}
   */
  public function getPagination(): array {
    return $this->pagination;
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthentication(): array {
    return $this->authentication;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityType(): string {
    return $this->target['entity_type'];
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetBundle(): string {
    return $this->target['bundle'];
  }

  /**
   * {@inheritdoc}
   */
  public function getMapping(): array {
    return $this->mapping;
  }

  /**
   * {@inheritdoc}
   */
  public function getDeletePolicy(): DeletePolicy {
    return DeletePolicy::from($this->delete_policy);
  }

  /**
   * {@inheritdoc}
   */
  public function getMaxAttempts(): int {
    return $this->resilience['max_attempts'];
  }

  /**
   * {@inheritdoc}
   */
  public function getBackoff(): BackoffStrategy {
    return BackoffStrategy::from($this->resilience['backoff']);
  }

  /**
   * {@inheritdoc}
   */
  public function isDlqEnabled(): bool {
    return $this->resilience['dlq_enabled'];
  }

  /**
   * {@inheritdoc}
   */
  public function getPool(): string {
    return $this->pool;
  }

}
