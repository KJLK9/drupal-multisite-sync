<?php

declare(strict_types=1);

namespace Drupal\import_engine\Target;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\import_engine\Attribute\ImportTarget;

/**
 * Manages the target plugins of the import engine.
 */
final class TargetPluginManager extends DefaultPluginManager {

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
    parent::__construct('Plugin/ImportEngine/Target', $namespaces, $module_handler, TargetInterface::class, ImportTarget::class);
    $this->alterInfo('import_engine_target_info');
    $this->setCacheBackend($cache_backend, 'import_engine_target_plugins');
  }

}
