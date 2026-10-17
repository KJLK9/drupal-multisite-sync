<?php

declare(strict_types=1);

namespace Drupal\import_engine\Validator;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\import_engine\Attribute\ImportValidator;

/**
 * Manages the validator plugins of the import engine.
 */
final class ValidatorPluginManager extends DefaultPluginManager {

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
    parent::__construct('Plugin/ImportEngine/Validator', $namespaces, $module_handler, ValidatorInterface::class, ImportValidator::class);
    $this->alterInfo('import_engine_validator_info');
    $this->setCacheBackend($cache_backend, 'import_engine_validator_plugins');
  }

  /**
   * Returns the IDs of the validators that can check the value of a mapper.
   *
   * @return list<string>
   *   The plugin IDs, by weight and then by ID, so that the order does not
   *   depend on the machine.
   */
  public function idsForMapper(string $mapperId): array {
    $found = [];
    foreach ($this->getDefinitions() as $id => $definition) {
      if (in_array($mapperId, $definition['mappers'] ?? [], TRUE)) {
        $found[(string) $id] = (int) ($definition['weight'] ?? 0);
      }
    }
    uksort($found, static fn (string $a, string $b): int => [$found[$a], $a] <=> [$found[$b], $b]);
    return array_keys($found);
  }

}
