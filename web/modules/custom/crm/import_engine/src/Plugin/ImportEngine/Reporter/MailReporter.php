<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Reporter;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportReporter;
use Drupal\import_engine\Reporter\ReporterPluginBase;
use Drupal\import_engine\Reporter\RunReport;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Mails the report of a run to a list of recipients.
 *
 * The text is composed in hook_mail() (module import_engine, key run_report).
 * A recipient the mail system refuses makes the reporter fail, which the run
 * reporter logs; the run itself is not affected.
 */
#[ImportReporter(
  id: 'mail',
  label: new TranslatableMarkup('Mail'),
  description: new TranslatableMarkup('Mails the report of a run.'),
)]
final class MailReporter extends ReporterPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the reporter.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
  ) {
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
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default configuration.
   */
  public function defaultConfiguration(): array {
    return ['recipients' => []] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function report(RunReport $report): void {
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    foreach ((array) $this->configuration['recipients'] as $recipient) {
      $message = $this->mailManager->mail('import_engine', 'run_report', (string) $recipient, $langcode, ['report' => $report]);
      if (empty($message['result'])) {
        throw new \RuntimeException(sprintf('The mail to %s could not be sent.', $recipient));
      }
    }
  }

}
