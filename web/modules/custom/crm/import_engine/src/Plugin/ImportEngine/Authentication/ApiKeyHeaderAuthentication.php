<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Authentication;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportAuthentication;
use Drupal\import_engine\Authentication\AuthenticationPluginBase;
use Drupal\import_engine\Http\RequestSpec;
use Drupal\import_engine\Secret\SecretResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends an API key in a request header.
 *
 * The configuration holds the name of the environment variable that contains
 * the key, never the key itself.
 */
#[ImportAuthentication(
  id: 'api_key_header',
  label: new TranslatableMarkup('API key in a header'),
  description: new TranslatableMarkup('Sends a key from an environment variable in a request header.'),
)]
final class ApiKeyHeaderAuthentication extends AuthenticationPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the plugin.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration: "header" and "env_var".
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\import_engine\Secret\SecretResolver $secrets
   *   The secret resolver.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly SecretResolver $secrets,
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
    return new self($configuration, $plugin_id, $plugin_definition, $container->get('import_engine.secret_resolver'));
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default configuration.
   */
  public function defaultConfiguration(): array {
    return ['header' => 'api-key', 'env_var' => ''];
  }

  /**
   * {@inheritdoc}
   */
  public function apply(RequestSpec $request): RequestSpec {
    return $request->withHeader(
      (string) $this->configuration['header'],
      $this->secrets->get((string) $this->configuration['env_var']),
    );
  }

}
