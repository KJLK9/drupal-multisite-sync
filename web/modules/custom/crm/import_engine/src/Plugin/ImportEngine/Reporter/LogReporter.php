<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Reporter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportReporter;
use Drupal\import_engine\Reporter\ReporterPluginBase;
use Drupal\import_engine\Reporter\RunReport;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Writes the report of a run to the log, as a warning when it had problems.
 */
#[ImportReporter(
  id: 'log',
  label: new TranslatableMarkup('Log'),
  description: new TranslatableMarkup('Writes the report of a run to the log.'),
)]
final class LogReporter extends ReporterPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the reporter.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger of the import engine.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
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
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self($configuration, $plugin_id, $plugin_definition, $container->get('logger.channel.import_engine'));
  }

  /**
   * {@inheritdoc}
   */
  public function report(RunReport $report): void {
    $this->logger->log($report->hasProblems() ? 'warning' : 'info', '@report', ['@report' => implode("\n", $report->lines())]);
  }

}
