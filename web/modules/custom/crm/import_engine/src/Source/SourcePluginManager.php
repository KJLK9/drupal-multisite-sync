<?php

declare(strict_types=1);

namespace Drupal\import_engine\Source;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\import_engine\Attribute\ImportSource;

/**
 * Manages the source plugins of the import engine.
 */
final class SourcePluginManager extends DefaultPluginManager {

  /**
   * Constructs the plugin manager.
   *
   * @param \Traversable<string, string> $namespaces
   *   The namespaces to look for plugins in.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ImportEngine/Source', $namespaces, $module_handler, SourceInterface::class, ImportSource::class);
    $this->alterInfo('import_engine_source_info');
    $this->setCacheBackend($cache_backend, 'import_engine_source_plugins');
  }

}
