<?php

declare(strict_types=1);

namespace Drupal\import_engine\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * The settings form of a plugin: nothing by default, the plugin adds fields.
 *
 * A plugin describes its own settings form (buildConfigurationForm), so a
 * plugin from another module needs nothing in the interface module to be
 * configurable. The values of the form are turned into configuration by the
 * type of each setting's default value: a number stays a number, a flag a flag.
 * A plugin whose settings are lists or maps overrides normalizeFormValues().
 *
 * The class that uses this trait has the properties and methods of a
 * configurable plugin: $configuration, defaultConfiguration() and
 * setConfiguration().
 */
trait PluginFormTrait {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form, or the part of it that belongs to this plugin.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form with the fields of the plugin.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->setConfiguration($this->configurationFromForm($form_state->getValues()));
  }

  /**
   * Turns submitted values into configuration.
   *
   * Settings the form did not have keep their value.
   *
   * @param array<string, mixed> $values
   *   The submitted values.
   *
   * @return array<string, mixed>
   *   The configuration.
   */
  protected function configurationFromForm(array $values): array {
    $values = $this->normalizeFormValues($values);
    $configuration = [];
    foreach ($this->defaultConfiguration() as $key => $default) {
      if (!array_key_exists($key, $values)) {
        continue;
      }
      $configuration[$key] = match (TRUE) {
        is_int($default) => (int) $values[$key],
        is_bool($default) => (bool) $values[$key],
        is_string($default) => (string) $values[$key],
        default => $values[$key],
      };
    }
    return $configuration + $this->configuration;
  }

  /**
   * Turns fields that are text into the lists and maps they stand for.
   *
   * @param array<string, mixed> $values
   *   The submitted values.
   *
   * @return array<string, mixed>
   *   The values, ready to be cast.
   */
  protected function normalizeFormValues(array $values): array {
    return $values;
  }

}
