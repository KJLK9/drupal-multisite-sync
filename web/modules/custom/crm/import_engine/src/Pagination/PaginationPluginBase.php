<?php

declare(strict_types=1);

namespace Drupal\import_engine\Pagination;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\import_engine\Http\RequestSpec;
use Drupal\import_engine\Path\PathResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for pagination plugins.
 *
 * Provides configuration with defaults, reading the total from a response and
 * setting a paging value either as a query parameter or in the JSON body (a
 * GraphQL variable), depending on the "target" setting.
 */
abstract class PaginationPluginBase extends PluginBase implements PaginationInterface, ContainerFactoryPluginInterface {

  /**
   * Constructs the plugin.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\import_engine\Path\PathResolver $paths
   *   The path resolver.
   */
  final public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly PathResolver $paths,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('import_engine.path_resolver'));
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

  /**
   * {@inheritdoc}
   */
  public function total(array $data): ?int {
    $path = (string) ($this->configuration['total_path'] ?? '');
    if ($path === '' || !$this->paths->has($data, $path)) {
      return NULL;
    }
    $total = $this->paths->get($data, $path);
    return is_numeric($total) ? (int) $total : NULL;
  }

  /**
   * Sets a paging value on a request, as a query parameter or in the body.
   *
   * @param \Drupal\import_engine\Http\RequestSpec $request
   *   The request.
   * @param string $name
   *   The parameter name, or for the "body" target a dotted path such as
   *   "variables.offset".
   * @param string|int $value
   *   The value.
   */
  protected function setParameter(RequestSpec $request, string $name, string|int $value): RequestSpec {
    if ($this->configuration['target'] === 'body') {
      return $request->withBody($this->paths->with($request->body ?? [], $name, $value));
    }
    return $request->withQueryParameter($name, $value);
  }

}
