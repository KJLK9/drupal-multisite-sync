<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Mapper;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportMapper;
use Drupal\import_engine\Mapper\MapperPluginBase;
use Drupal\import_engine\Target\TargetField;

/**
 * Maps up to three source values, joined, to a plain text field.
 *
 * For a field that the source has no single value for, such as a title made
 * of a product and a customer. Settings: separator, what goes between the
 * parts; skip_empty, leave out a part that is missing or empty (otherwise it
 * stays in as an empty part). When there is nothing to join the field is left
 * empty.
 */
#[ImportMapper(
  id: 'join',
  label: new TranslatableMarkup('Combine texts'),
  field_types: ['string'],
  sources: ['first' => TRUE, 'second' => TRUE, 'third' => FALSE],
  description: new TranslatableMarkup('Joins two or three values into one text, for example a product and a customer.'),
)]
final class JoinMapper extends MapperPluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default settings.
   */
  public function defaultConfiguration(): array {
    return ['separator' => ' - ', 'skip_empty' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function map(array $sources, TargetField $field): mixed {
    $parts = [];
    foreach (['first', 'second', 'third'] as $name) {
      // The third part is optional: without a path it is not a part at all.
      if ($name === 'third' && !array_key_exists('third', $sources)) {
        continue;
      }
      $text = $this->text($sources[$name] ?? NULL);
      $text = $text === NULL ? NULL : trim($text);
      if (($text === NULL || $text === '') && $this->configuration['skip_empty']) {
        continue;
      }
      $parts[] = $text ?? '';
    }
    $joined = implode((string) $this->configuration['separator'], $parts);
    return trim($joined) === '' ? NULL : $joined;
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
    $form['separator'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Separator'),
      '#description' => $this->t('What goes between the parts, spaces included, for example " - ".'),
      '#default_value' => $this->configuration['separator'],
      '#maxlength' => 20,
    ];
    $form['skip_empty'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Leave out a part that is missing'),
      '#default_value' => $this->configuration['skip_empty'],
    ];
    return $form;
  }

}
