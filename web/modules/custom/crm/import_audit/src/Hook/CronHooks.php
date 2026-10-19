<?php

declare(strict_types=1);

namespace Drupal\import_audit\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\import_audit\History\ChangeLog;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Removes the changes that are older than the retention.
 */
final class CronHooks {

  /**
   * The days kept when nothing is set.
   */
  public const DEFAULT_DAYS = 30;

  /**
   * The most batches per cron run, so that one run does not take for ever.
   */
  private const MAX_BATCHES = 20;

  /**
   * Constructs the hooks.
   */
  public function __construct(
    #[Autowire(service: 'import_audit.change_log')]
    private readonly ChangeLog $log,
    #[Autowire(service: 'config.factory')]
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'datetime.time')]
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->purge($this->time->getRequestTime());
  }

  /**
   * Removes what is too old.
   *
   * @return int
   *   How many rows were removed.
   */
  public function purge(int $now): int {
    $days = (int) ($this->configFactory->get('import_audit.settings')->get('retention_days') ?? self::DEFAULT_DAYS);
    $total = 0;
    for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
      $removed = $this->log->purge($now - max(1, $days) * 86400);
      $total += $removed;
      if ($removed < ChangeLog::BATCH) {
        break;
      }
    }
    return $total;
  }

}
