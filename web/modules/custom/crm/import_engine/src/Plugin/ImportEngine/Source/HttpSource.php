<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Source;

use Psr\Http\Message\ResponseInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportSource;
use Drupal\import_engine\Authentication\AuthenticationInterface;
use Drupal\import_engine\Authentication\AuthenticationPluginManager;
use Drupal\import_engine\Decoder\DecodeException;
use Drupal\import_engine\Decoder\ResponseDecoder;
use Drupal\import_engine\Http\RequestSpec;
use Drupal\import_engine\Path\PathResolver;
use Drupal\import_engine\Secret\MissingSecretException;
use Drupal\import_engine\Source\Severity;
use Drupal\import_engine\Source\SourceCheck;
use Drupal\import_engine\Source\SourceException;
use Drupal\import_engine\Source\SourcePage;
use Drupal\import_engine\Source\SourcePluginBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Reads items from an HTTP endpoint that returns JSON, XML or CSV.
 *
 * Configuration:
 * - url, method (GET or POST), headers, query: the request.
 * - body: for POST, the JSON body as text (a GraphQL query goes here).
 * - items_path: where the list of items is in the decoded response; empty when
 *   the response is the list.
 * - id_path: where the unique id is in each item.
 * - format and csv_delimiter: how to read the response, see ResponseDecoder.
 * - timeout: seconds, for connecting and for the whole request.
 *
 * The authentication plugin is added by the source factory under the key
 * "authentication".
 */
#[ImportSource(
  id: 'http',
  label: new TranslatableMarkup('HTTP'),
  description: new TranslatableMarkup('Reads items from an HTTP endpoint returning JSON, XML or CSV.'),
)]
final class HttpSource extends SourcePluginBase implements ContainerFactoryPluginInterface {

  /**
   * How many items a check returns as samples.
   */
  public const SAMPLE_SIZE = 5;

  /**
   * The authentication plugin of the definition.
   */
  private readonly AuthenticationInterface $authentication;

  /**
   * Constructs the plugin.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\import_engine\Decoder\ResponseDecoder $decoder
   *   The response decoder.
   * @param \Drupal\import_engine\Path\PathResolver $paths
   *   The path resolver.
   * @param \Drupal\import_engine\Authentication\AuthenticationPluginManager $authenticationManager
   *   The authentication plugin manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ClientInterface $httpClient,
    private readonly ResponseDecoder $decoder,
    private readonly PathResolver $paths,
    AuthenticationPluginManager $authenticationManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $authentication = $this->configuration['authentication'] ?? ['plugin' => 'none', 'configuration' => []];
    $instance = $authenticationManager->createInstance($authentication['plugin'], $authentication['configuration']);
    assert($instance instanceof AuthenticationInterface);
    $this->authentication = $instance;
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
      $container->get('http_client'),
      $container->get('import_engine.response_decoder'),
      $container->get('import_engine.path_resolver'),
      $container->get('plugin.manager.import_engine_authentication'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default configuration.
   */
  public function defaultConfiguration(): array {
    return [
      'url' => '',
      'method' => 'GET',
      'headers' => [],
      'query' => [],
      'body' => '',
      'items_path' => '',
      'id_path' => 'id',
      'timeout' => 30,
      'format' => 'auto',
      'csv_delimiter' => ',',
    ];
  }

  /**
   * Config schema callback: the body must be empty or a JSON object.
   */
  public static function validateJsonBody(mixed $value, ExecutionContextInterface $context): void {
    if (!is_string($value) || trim($value) === '') {
      return;
    }
    $decoded = json_decode($value, TRUE);
    if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
      $context->buildViolation('The body must be a JSON object.')->addViolation();
    }
  }

  /**
   * {@inheritdoc}
   *
   * Paging is added with the pagination plugins; until then a source returns
   * everything in one page and a cursor is refused.
   */
  public function fetchPage(?string $cursor = NULL): SourcePage {
    if ($cursor !== NULL) {
      throw SourceException::permanent('This source does not support paging yet.');
    }
    $request = $this->buildRequest();
    $label = $request->method . ' ' . $this->describe($request);
    $response = $this->send($request, $label);

    try {
      $data = $this->decoder->decode(
        (string) $response->getBody(),
        $response->getHeaderLine('Content-Type'),
        (string) $this->configuration['format'],
        (string) $this->configuration['csv_delimiter'],
      );
    }
    catch (DecodeException $exception) {
      throw SourceException::permanent($label . ': ' . $exception->getMessage(), $exception);
    }

    $items = $this->paths->items($data, (string) $this->configuration['items_path']);
    if ($items === NULL) {
      throw SourceException::permanent(sprintf('%s: there is no list of items at "%s".', $label, $this->configuration['items_path']));
    }
    return new SourcePage($items);
  }

  /**
   * {@inheritdoc}
   */
  public function check(): SourceCheck {
    $check = new SourceCheck();
    try {
      $page = $this->fetchPage();
    }
    catch (SourceException $exception) {
      $prefix = $exception->retryable ? 'Temporary problem, try again: ' : '';
      return $check->add(Severity::Error, $prefix . $exception->getMessage());
    }

    $check->sampleItems = array_slice($page->items, 0, self::SAMPLE_SIZE);
    $check->add(Severity::Info, sprintf('Connected: the first page holds %d items.', count($page->items)));
    if ($page->items === []) {
      return $check->add(Severity::Warning, 'The source returned no items, so the field mapping cannot be built from a sample.');
    }

    $ids = [];
    foreach ($check->sampleItems as $index => $item) {
      $id_path = (string) $this->configuration['id_path'];
      if (!$this->paths->has($item, $id_path) || $this->paths->get($item, $id_path) === NULL || $this->paths->get($item, $id_path) === '') {
        $check->add(Severity::Error, sprintf('Item %d has no id at "%s".', $index + 1, $id_path));
        continue;
      }
      $ids[] = json_encode($this->paths->get($item, $id_path));
    }
    if (count($ids) !== count(array_unique($ids))) {
      $check->add(Severity::Error, sprintf('The ids at "%s" are not unique.', $this->configuration['id_path']));
    }
    return $check;
  }

  /**
   * Builds the request for the first page from the configuration.
   *
   * @throws \Drupal\import_engine\Source\SourceException
   *   When the configuration cannot make a valid request.
   */
  private function buildRequest(): RequestSpec {
    $headers = $this->configuration['headers'];
    if (!isset($headers['Accept']) && in_array($this->configuration['format'], ['auto', 'json'], TRUE)) {
      $headers['Accept'] = 'application/json';
    }
    $body = NULL;
    if (is_string($this->configuration['body']) && trim($this->configuration['body']) !== '') {
      $body = json_decode($this->configuration['body'], TRUE);
      if (!is_array($body)) {
        throw SourceException::permanent('The body of the source is not valid JSON.');
      }
    }

    $request = new RequestSpec(
      strtoupper((string) $this->configuration['method']),
      (string) $this->configuration['url'],
      $this->configuration['query'],
      $headers,
      $body,
    );
    try {
      return $this->authentication->apply($request);
    }
    catch (MissingSecretException $exception) {
      throw SourceException::permanent($exception->getMessage(), $exception);
    }
  }

  /**
   * Sends a request and returns the response of a successful one.
   *
   * @throws \Drupal\import_engine\Source\SourceException
   *   Transient for connection problems, timeouts, rate limiting and server
   *   errors; permanent for other error responses.
   */
  private function send(RequestSpec $request, string $label): ResponseInterface {
    $timeout = (int) $this->configuration['timeout'];
    $options = $request->toGuzzleOptions() + [
      'timeout' => $timeout,
      'connect_timeout' => $timeout,
      // Status codes are judged here, not turned into exceptions.
      'http_errors' => FALSE,
    ];
    try {
      $response = $this->httpClient->request($request->method, $request->url, $options);
    }
    catch (ConnectException $exception) {
      // The low level reason is left out on purpose: it can echo the request.
      throw SourceException::transient($label . ': could not connect, or the request timed out.', $exception);
    }
    catch (GuzzleException $exception) {
      throw SourceException::transient($label . ': the request failed.', $exception);
    }

    $status = $response->getStatusCode();
    if ($status >= 200 && $status < 300) {
      return $response;
    }
    $retryable = $status === 408 || $status === 429 || $status >= 500;
    $message = sprintf('%s: HTTP %d.', $label, $status);
    throw $retryable ? SourceException::transient($message) : SourceException::permanent($message);
  }

  /**
   * Describes a request for messages.
   *
   * It is the URL, which the schema keeps free of a query string and so of
   * secrets.
   */
  private function describe(RequestSpec $request): string {
    return $request->url;
  }

}
