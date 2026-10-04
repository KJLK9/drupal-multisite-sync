<?php

declare(strict_types=1);

namespace Drupal\import_engine\Reporter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\import_engine\Form\PluginFormTrait;
use Drupal\Component\Plugin\PluginBase;

/**
 * Base class for reporter plugins: configuration with defaults.
 */
abstract class ReporterPluginBase extends PluginBase implements ReporterInterface {

  use PluginFormTrait;

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The configuration.
   */
  public function getConfiguration(): array {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $configuration
   *   The configuration; missing keys get their defaults.
   */
  public function setConfiguration(array $configuration): void {
    $this->configuration = $configuration + $this->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default configuration.
   */
  public function defaultConfiguration(): array {
    return ['only_on_problems' => FALSE];
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
    $form['only_on_problems'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Only report runs that had problems'),
      '#description' => $this->t('A run that completed without problems is not reported.'),
      '#default_value' => $this->configuration['only_on_problems'],
    ];
    return $form;
  }

}
