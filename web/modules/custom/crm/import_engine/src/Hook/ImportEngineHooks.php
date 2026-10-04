<?php

declare(strict_types=1);

namespace Drupal\import_engine\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Drive\RunDriver;
use Drupal\import_engine\Reporter\RunReport;
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
    #[Autowire(service: 'import_engine.run_driver')]
    private readonly RunDriver $driver,
    #[Autowire(service: 'config.factory')]
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'datetime.time')]
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Implements hook_cron().
   *
   * Purges what has outlived its retention, a bounded amount per cron run, and
   * continues runs that are not over when the settings allow it.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->purger->purge();
    $seconds = (int) $this->configFactory->get('import_engine.settings')->get('cron_resume_seconds');
    if ($seconds > 0) {
      $this->driver->resumeActive(RunBudget::ofSeconds($seconds, $this->time->getCurrentTime()), 'cron');
    }
  }

  /**
   * Implements hook_mail().
   *
   * Composes the report of a run.
   *
   * @param string $key
   *   The mail key.
   * @param array<string, mixed> $message
   *   The message; its subject and body are filled here.
   * @param array<string, mixed> $params
   *   The parameters of the mail: the report.
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    if ($key === 'run_report' && ($params['report'] ?? NULL) instanceof RunReport) {
      $message['subject'] = $params['report']->subject();
      $message['body'] = $params['report']->lines();
    }
  }

}
