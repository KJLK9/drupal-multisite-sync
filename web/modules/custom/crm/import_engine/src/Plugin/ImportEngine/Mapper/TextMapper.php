<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Mapper;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportMapper;
use Drupal\import_engine\Mapper\MapperPluginBase;
use Drupal\import_engine\Target\TargetField;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Maps a source value to a formatted text field.
 *
 * Setting: format, the text format of the imported text. Anything that is not
 * plain text should be given a format that filters it safely.
 */
#[ImportMapper(
  id: 'text',
  label: new TranslatableMarkup('Formatted text'),
  field_types: ['text', 'text_long', 'text_with_summary'],
  sources: ['value' => TRUE, 'summary' => FALSE],
  description: new TranslatableMarkup('Text with a text format, and for some fields a summary.'),
)]
final class TextMapper extends MapperPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the mapper.
   *
   * @param array<string, mixed> $configuration
   *   The plugin settings.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, to list the text formats.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
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
    return new self($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default settings.
   */
  public function defaultConfiguration(): array {
    return ['format' => 'plain_text'];
  }

  /**
   * {@inheritdoc}
   */
  public function map(array $sources, TargetField $field): mixed {
    $value = $this->text($sources['value'] ?? NULL);
    if ($value === NULL || $value === '') {
      return NULL;
    }
    $result = ['value' => $value, 'format' => (string) $this->configuration['format']];
    $summary = $this->text($sources['summary'] ?? NULL);
    if ($field->type === 'text_with_summary' && $summary !== NULL) {
      $result['summary'] = $summary;
    }
    return $result;
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
    foreach ($this->entityTypeManager->getStorage('filter_format')->loadMultiple() as $id => $format) {
      $options[$id] = (string) $format->label();
    }
    $form['format'] = [
      '#type' => 'select',
      '#title' => $this->t('Text format'),
      '#description' => $this->t('Anything that is not plain text should get a format that filters it safely.'),
      '#options' => $options,
      '#default_value' => $this->configuration['format'],
      '#required' => TRUE,
    ];
    return $form;
  }

}
