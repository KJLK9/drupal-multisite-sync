<?php

declare(strict_types=1);

namespace Drupal\import_engine\Authentication;

use Drupal\import_engine\Form\PluginFormTrait;
use Drupal\Component\Plugin\PluginBase;

/**
 * Base class for authentication plugins: configuration with defaults.
 */
abstract class AuthenticationPluginBase extends PluginBase implements AuthenticationInterface {

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
    return [];
  }

}
