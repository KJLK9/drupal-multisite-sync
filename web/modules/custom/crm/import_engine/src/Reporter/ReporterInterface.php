<?php

declare(strict_types=1);

namespace Drupal\import_engine\Reporter;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * A reporter is told how a run went, once it is over.
 *
 * Every reporter has the setting only_on_problems: when it is on, the reporter
 * is only called for runs that did not end as completed.
 */
interface ReporterInterface extends PluginInspectionInterface, ConfigurableInterface {

  /**
   * Reports a finished run.
   *
   * @param \Drupal\import_engine\Reporter\RunReport $report
   *   What happened.
   *
   * @throws \RuntimeException
   *   When the report cannot be delivered. The run is not affected.
   */
  public function report(RunReport $report): void;

}
