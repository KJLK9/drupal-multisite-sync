<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Mapper;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportMapper;
use Drupal\import_engine\Key\ItemKey;
use Drupal\import_engine\Mapper\DependencyNotReadyException;
use Drupal\import_engine\Mapper\MapperPluginBase;
use Drupal\import_engine\Mapper\MappingException;
use Drupal\import_engine\Storage\MappingStore;
use Drupal\import_engine\Target\TargetField;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Maps a value to a reference to an entity that another import wrote.
 *
 * The source value is the key of the item in the other import (a single value).
 * The mapping store knows which entity that item became. Settings: definition,
 * the ID of the import that wrote the referenced entities; required, whether a
 * missing value or a referenced item that is not imported yet is a problem. A
 * referenced item that is not imported yet is retried later, as the other
 * import may simply not have run yet.
 */
#[ImportMapper(
  id: 'reference',
  label: new TranslatableMarkup('Reference to an imported item'),
  field_types: ['entity_reference'],
  sources: ['id' => TRUE],
  description: new TranslatableMarkup('Refers to an entity that another import wrote, by the key of its source item.'),
)]
final class ReferenceMapper extends MapperPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the mapper.
   *
   * @param array<string, mixed> $configuration
   *   The plugin settings.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\import_engine\Storage\MappingStore $mapping
   *   The mapping store.
   * @param \Drupal\import_engine\Key\ItemKey $keys
   *   The item key builder.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, to list the imports.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly MappingStore $mapping,
    private readonly ItemKey $keys,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin settings.
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
      $container->get('import_engine.mapping_store'),
      $container->get('import_engine.item_key'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default settings.
   */
  public function defaultConfiguration(): array {
    return ['definition' => '', 'required' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function map(array $sources, TargetField $field): mixed {
    $value = $this->text($sources['id'] ?? NULL);
    if ($value === NULL || trim($value) === '') {
      if ($this->configuration['required']) {
        throw new MappingException('There is no value to refer to.');
      }
      return NULL;
    }

    $definition = (string) $this->configuration['definition'];
    $key = $this->keys->fromValues([trim($value)]);
    $record = $this->mapping->find($definition, $key);
    if ($record === NULL || $record->targetId === NULL) {
      if ($this->configuration['required']) {
        throw new DependencyNotReadyException(sprintf('The item "%s" of the import "%s" is not imported yet.', trim($value), $definition));
      }
      return NULL;
    }
    return ['target_id' => $record->targetId];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('import_definition')->loadMultiple() as $id => $definition) {
      $options[$id] = (string) $definition->label();
    }
    $form['definition'] = [
      '#type' => 'select',
      '#title' => $this->t('Import that wrote the referenced items'),
      '#description' => $this->t('The value of the source item is the key of an item of that import.'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $this->configuration['definition'],
      '#required' => TRUE,
    ];
    $form['required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Required'),
      '#description' => $this->t('A missing value is an error, and an item that is not imported yet is tried again later. Otherwise the field stays empty.'),
      '#default_value' => $this->configuration['required'],
    ];
    return $form;
  }

}
