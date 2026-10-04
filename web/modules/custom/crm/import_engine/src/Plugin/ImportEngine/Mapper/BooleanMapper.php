<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Mapper;

use Drupal\import_engine\Form\TextLists;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportMapper;
use Drupal\import_engine\Mapper\MapperPluginBase;
use Drupal\import_engine\Mapper\MappingException;
use Drupal\import_engine\Target\TargetField;

/**
 * Maps a source value to a boolean field.
 *
 * Settings: true_values and false_values, the texts that stand for yes and no
 * (compared without regard to case); when_empty, what a missing value means:
 * "false", "true" or "fail".
 */
#[ImportMapper(
  id: 'boolean',
  label: new TranslatableMarkup('Yes or no'),
  field_types: ['boolean'],
  sources: ['value' => TRUE],
  description: new TranslatableMarkup('A boolean, from true or false, 1 or 0, yes or no.'),
)]
final class BooleanMapper extends MapperPluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default settings.
   */
  public function defaultConfiguration(): array {
    return [
      'true_values' => ['1', 'true', 'yes', 'y', 'on'],
      'false_values' => ['0', 'false', 'no', 'n', 'off'],
      'when_empty' => 'false',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function map(array $sources, TargetField $field): mixed {
    $text = $this->text($sources['value'] ?? NULL);
    if ($text === NULL || trim($text) === '') {
      return match ($this->configuration['when_empty']) {
        'true' => TRUE,
        'false' => FALSE,
        default => throw new MappingException('There is no value for the yes or no field.'),
      };
    }
    $text = strtolower(trim($text));
    if (in_array($text, array_map('strtolower', $this->configuration['true_values']), TRUE)) {
      return TRUE;
    }
    if (in_array($text, array_map('strtolower', $this->configuration['false_values']), TRUE)) {
      return FALSE;
    }
    throw new MappingException(sprintf('"%s" is neither a yes nor a no value.', $text));
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
    $form['true_values'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Texts that mean yes'),
      '#description' => $this->t('One per line; upper or lower case does not matter.'),
      '#default_value' => TextLists::formatLines($this->configuration['true_values']),
      '#rows' => 4,
    ];
    $form['false_values'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Texts that mean no'),
      '#description' => $this->t('One per line.'),
      '#default_value' => TextLists::formatLines($this->configuration['false_values']),
      '#rows' => 4,
    ];
    $form['when_empty'] = [
      '#type' => 'select',
      '#title' => $this->t('A missing value means'),
      '#options' => ['false' => $this->t('no'), 'true' => $this->t('yes'), 'fail' => $this->t('an error')],
      '#default_value' => $this->configuration['when_empty'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $values
   *   The submitted values.
   *
   * @return array<string, mixed>
   *   The values with the lists read from their text.
   */
  protected function normalizeFormValues(array $values): array {
    foreach (['true_values', 'false_values'] as $field) {
      $values[$field] = TextLists::lines((string) ($values[$field] ?? ''));
    }
    return $values;
  }

}
