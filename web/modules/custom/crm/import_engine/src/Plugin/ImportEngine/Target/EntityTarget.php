<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Target;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportTarget;
use Drupal\import_engine\Target\SaveResult;
use Drupal\import_engine\Target\TargetException;
use Drupal\import_engine\Target\TargetField;
use Drupal\import_engine\Target\TargetPluginBase;
use Drupal\import_engine\Target\TargetValidationException;
use Drupal\user\EntityOwnerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Writes imported items as content entities of one type and bundle.
 *
 * Configuration: entity_type, bundle, and optionally owner: the ID of the user
 * that owns created entities and that the entity is saved as. Validation of
 * references (the author, an entity reference) checks what the current user
 * may refer to, so an import that runs without a user (cron, drush) could not
 * refer to anything; the owner should be a user who may. 0 keeps the current
 * user.
 *
 * Every entity is validated before it is saved. Validation failures are
 * reported as a TargetValidationException, which is not worth retrying.
 */
#[ImportTarget(
  id: 'entity',
  label: new TranslatableMarkup('Entity'),
  description: new TranslatableMarkup('Writes content entities of one type and bundle.'),
)]
final class EntityTarget extends TargetPluginBase implements ContainerFactoryPluginInterface {

  /**
   * How many validation messages are put in an exception.
   */
  private const MAX_MESSAGES = 3;

  /**
   * Constructs the plugin.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $fieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The account switcher.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('account_switcher'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default configuration.
   */
  public function defaultConfiguration(): array {
    return ['entity_type' => '', 'bundle' => '', 'owner' => 0];
  }

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    $type = $this->entityType();
    $bundle = $this->bundle();
    $definition = $this->entityTypeManager->getDefinition($type);

    // The keys the entity system fills itself are not for a mapping.
    $skip = array_filter([
      $definition->getKey('id'),
      $definition->getKey('uuid'),
      $definition->getKey('revision'),
      $definition->getKey('bundle'),
    ]);
    $fields = [];
    foreach ($this->fieldManager->getFieldDefinitions($type, $bundle) as $name => $field) {
      if (in_array($name, $skip, TRUE) || $field->isComputed() || $field->isReadOnly()) {
        continue;
      }
      $fields[$name] = new TargetField(
        $name,
        (string) $field->getLabel(),
        $field->getType(),
        $field->isRequired(),
        $field->getSettings(),
      );
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $values, ?string $existingId): SaveResult {
    $owner = (int) $this->configuration['owner'];
    $account = $owner > 0 ? $this->entityTypeManager->getStorage('user')->load($owner) : NULL;
    if ($owner > 0 && $account === NULL) {
      throw new TargetException(sprintf('The owner, user %d, does not exist.', $owner));
    }
    if ($account === NULL) {
      return $this->write($values, $existingId);
    }
    $this->accountSwitcher->switchTo($account);
    try {
      return $this->write($values, $existingId);
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * Creates or updates the entity, validated, and returns the result.
   *
   * @param array<string, mixed> $values
   *   The values keyed by field name.
   * @param string|null $existingId
   *   The ID of the entity an earlier run wrote, if any.
   */
  private function write(array $values, ?string $existingId): SaveResult {
    $type = $this->entityType();
    $storage = $this->entityTypeManager->getStorage($type);
    $entity = $existingId === NULL ? NULL : $storage->load($existingId);
    $created = $entity === NULL;
    if ($entity === NULL) {
      $bundle_key = $this->entityTypeManager->getDefinition($type)->getKey('bundle');
      $entity = $storage->create($bundle_key ? [$bundle_key => $this->bundle()] : []);
      if ($this->configuration['owner'] > 0 && $entity instanceof EntityOwnerInterface) {
        $entity->setOwnerId((int) $this->configuration['owner']);
      }
    }
    if (!$entity instanceof FieldableEntityInterface) {
      throw new TargetException(sprintf('The entity type "%s" has no fields.', $type));
    }

    foreach ($values as $field => $value) {
      try {
        $entity->set($field, $value);
      }
      catch (\InvalidArgumentException $exception) {
        throw new TargetException(sprintf('The field "%s" cannot be set: %s', $field, $exception->getMessage()), 0, $exception);
      }
    }

    $violations = $entity->validate();
    if (count($violations) > 0) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[] = ltrim($violation->getPropertyPath() . ': ', ': ') . strip_tags((string) $violation->getMessage());
        if (count($messages) === self::MAX_MESSAGES) {
          break;
        }
      }
      throw new TargetValidationException(implode(' | ', $messages));
    }
    $entity->save();
    return new SaveResult($type, (string) $entity->id(), $created);
  }

  /**
   * {@inheritdoc}
   */
  public function supportsUnpublish(): bool {
    $class = $this->entityTypeManager->getDefinition($this->entityType())->getClass();
    return is_subclass_of($class, EntityPublishedInterface::class);
  }

  /**
   * {@inheritdoc}
   */
  public function publish(string $id): bool {
    $entity = $this->entityTypeManager->getStorage($this->entityType())->load($id);
    if (!$entity instanceof EntityPublishedInterface || $entity->isPublished()) {
      return FALSE;
    }
    $entity->setPublished()->save();
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function unpublish(string $id): bool {
    $entity = $this->entityTypeManager->getStorage($this->entityType())->load($id);
    if (!$entity instanceof EntityPublishedInterface || !$entity->isPublished()) {
      return FALSE;
    }
    $entity->setUnpublished()->save();
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $id): bool {
    $entity = $this->entityTypeManager->getStorage($this->entityType())->load($id);
    if ($entity === NULL) {
      return FALSE;
    }
    $entity->delete();
    return TRUE;
  }

  /**
   * Returns the configured entity type, which must be a content entity type.
   */
  private function entityType(): string {
    $type = (string) $this->configuration['entity_type'];
    $definition = $this->entityTypeManager->getDefinition($type, FALSE);
    if (!$definition instanceof ContentEntityTypeInterface) {
      throw new TargetException(sprintf('The entity type "%s" does not exist or is not a content entity type.', $type));
    }
    return $type;
  }

  /**
   * Returns the configured bundle, which must exist.
   */
  private function bundle(): string {
    $bundle = (string) $this->configuration['bundle'];
    if (!array_key_exists($bundle, $this->bundleInfo->getBundleInfo($this->entityType()))) {
      throw new TargetException(sprintf('The bundle "%s" of the entity type "%s" does not exist.', $bundle, $this->entityType()));
    }
    return $bundle;
  }

}
