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
  constraints: ['ImportConnection' => []],
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
    'connection',
    'target',
    'mapping',
    'delete_policy',
    'delete_threshold_percent',
    'resilience',
    'breaker',
    'reporters',
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
   * The target plugin and its configuration.
   *
   * @var array{plugin: string, configuration: array<string, mixed>}
   */
  protected array $target = [
    'plugin' => 'entity',
    'configuration' => ['entity_type' => '', 'bundle' => '', 'owner' => 0],
  ];

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
   * The share of known items, in percent, that may go missing in one run.
   *
   * 0 means no limit.
   */
  protected int $delete_threshold_percent = 20;

  /**
   * Retry settings.
   *
   * @var array{max_attempts: int, backoff: string, retry_delay: int, dlq_enabled: bool, max_repeated_pages: int}
   */
  protected array $resilience = [
    'max_attempts' => 5,
    'backoff' => 'exponential',
    'retry_delay' => 60,
    'dlq_enabled' => TRUE,
    'max_repeated_pages' => 3,
  ];

  /**
   * The circuit breaker of the source.
   *
   * @var array{enabled: bool, threshold: int, cooldown: int}
   */
  protected array $breaker = [
    'enabled' => TRUE,
    'threshold' => 5,
    'cooldown' => 60,
  ];

  /**
   * The ID of the connection this import uses, if it uses one.
   */
  protected ?string $connection = NULL;

  /**
   * The reporters that are told how a run went.
   *
   * @var list<array{plugin: string, configuration: array<string, mixed>}>
   */
  protected array $reporters = [];

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
  public function getTarget(): array {
    return $this->target;
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
  public function getConnection(): ?string {
    return $this->connection === '' ? NULL : $this->connection;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): static {
    parent::calculateDependencies();
    if ($this->getConnection() !== NULL) {
      // So the connection is imported first, and is not deleted from under
      // the imports that use it.
      $this->addDependency('config', 'import_engine.import_connection.' . $this->getConnection());
    }
    return $this;
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
  public function getDeleteThresholdPercent(): int {
    return $this->delete_threshold_percent;
  }

  /**
   * {@inheritdoc}
   */
  public function getBreaker(): array {
    return $this->breaker;
  }

  /**
   * {@inheritdoc}
   */
  public function getReporters(): array {
    return $this->reporters;
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
  public function getRetryDelay(): int {
    return $this->resilience['retry_delay'];
  }

  /**
   * {@inheritdoc}
   */
  public function getMaxRepeatedPages(): int {
    return $this->resilience['max_repeated_pages'];
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
