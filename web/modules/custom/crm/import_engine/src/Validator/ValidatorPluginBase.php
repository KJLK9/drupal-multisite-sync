<?php

declare(strict_types=1);

namespace Drupal\import_engine\Validator;

use Drupal\Component\Plugin\PluginBase;
use Drupal\import_engine\Form\PluginFormTrait;

/**
 * Base class for validator plugins: settings with defaults.
 */
abstract class ValidatorPluginBase extends PluginBase implements ValidatorInterface {

  use PluginFormTrait;

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $configuration
   *   The plugin settings.
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
   *   The settings.
   */
  public function getConfiguration(): array {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $configuration
   *   The settings; missing keys get their defaults.
   */
  public function setConfiguration(array $configuration): void {
    $this->configuration = $configuration + $this->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default settings.
   */
  public function defaultConfiguration(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function validatesEmpty(): bool {
    return FALSE;
  }

}
