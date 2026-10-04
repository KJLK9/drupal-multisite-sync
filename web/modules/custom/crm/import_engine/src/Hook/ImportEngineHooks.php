<?php

declare(strict_types=1);

namespace Drupal\import_engine\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\import_engine\Storage\RetentionPurger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hook implementations of the import engine.
 */
final class ImportEngineHooks {

  /**
   * Constructs the hooks.
   */
  public function __construct(
    #[Autowire(service: 'import_engine.retention_purger')]
    private readonly RetentionPurger $purger,
  ) {
  }

  /**
   * Implements hook_cron().
   *
   * Purges what has outlived its retention, a bounded amount per cron run.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->purger->purge();
  }

}
