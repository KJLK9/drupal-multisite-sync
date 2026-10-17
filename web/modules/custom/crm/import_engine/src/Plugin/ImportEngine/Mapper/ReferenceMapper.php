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
 * the ID of the import that wrote the referenced entities; when_missing, what
 * to do when the referenced item is not there:
 * - wait: the item is tried again later, as the other import may simply not
 *   have run yet, and fails when the attempts run out (and when there is no
 *   value to refer to at all);
 * - error: the item fails at once, for a reference that has to be there already
 *   (the other import is known to have run);
 * - ignore: the field stays empty.
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
    return ['definition' => '', 'when_missing' => 'wait'];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $configuration
   *   The settings; the "required" of before is read as what it meant.
   */
  public function setConfiguration(array $configuration): void {
    if (array_key_exists('required', $configuration)) {
      // Before there were three behaviors, there was a box: required meant
      // wait, and not required meant ignore.
      $configuration += ['when_missing' => $configuration['required'] ? 'wait' : 'ignore'];
      unset($configuration['required']);
    }
    parent::setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function map(array $sources, TargetField $field): mixed {
    $value = $this->text($sources['id'] ?? NULL);
    $when_missing = (string) $this->configuration['when_missing'];
    if ($value === NULL || trim($value) === '') {
      if ($when_missing !== 'ignore') {
        throw new MappingException('There is no value to refer to.');
      }
      return NULL;
    }

    $definition = (string) $this->configuration['definition'];
    $key = $this->keys->fromValues([trim($value)]);
    $record = $this->mapping->find($definition, $key);
    if ($record === NULL || $record->targetId === NULL) {
      return match ($when_missing) {
        'ignore' => NULL,
        'error' => throw new MappingException(sprintf('The item "%s" of the import "%s" is not imported, and this field does not wait for it.', trim($value), $definition)),
        default => throw new DependencyNotReadyException(sprintf('The item "%s" of the import "%s" is not imported yet.', trim($value), $definition)),
      };
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
    $form['when_missing'] = [
      '#type' => 'select',
      '#title' => $this->t('When the referenced item is not there'),
      '#options' => [
        'wait' => $this->t('Wait for it: try the item again later, and fail when that runs out'),
        'error' => $this->t('Fail at once: it should be there already'),
        'ignore' => $this->t('Leave the field empty'),
      ],
      '#description' => $this->t('"Wait" suits an import that runs before the one it refers to has finished. A missing value to refer to is an error, except when the field is left empty.'),
      '#default_value' => $this->configuration['when_missing'],
    ];
    return $form;
  }

}
