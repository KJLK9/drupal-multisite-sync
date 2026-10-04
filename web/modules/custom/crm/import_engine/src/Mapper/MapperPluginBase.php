<?php

declare(strict_types=1);

namespace Drupal\import_engine\Mapper;

use Drupal\import_engine\Form\PluginFormTrait;
use Drupal\Component\Plugin\PluginBase;

/**
 * Base class for mapper plugins: settings with defaults, and text helpers.
 */
abstract class MapperPluginBase extends PluginBase implements MapperInterface {

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
   * Returns a source value as text, or NULL when there is none.
   *
   * @throws \Drupal\import_engine\Mapper\MappingException
   *   When the value is a list or an object.
   */
  protected function text(mixed $value): ?string {
    if ($value === NULL) {
      return NULL;
    }
    if (is_array($value)) {
      throw new MappingException('A list or object cannot be used as a single value.');
    }
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    return (string) $value;
  }

}
