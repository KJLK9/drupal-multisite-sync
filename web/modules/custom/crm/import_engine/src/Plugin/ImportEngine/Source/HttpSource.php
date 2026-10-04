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
use Drupal\import_engine\Key\InvalidKeyException;
use Drupal\import_engine\Key\ItemKey;
use Drupal\import_engine\Page\PageFingerprint;
use Drupal\import_engine\Pagination\PaginationInterface;
use Drupal\import_engine\Pagination\PaginationPluginManager;
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
 * - format and csv_delimiter: how to read the response, see ResponseDecoder.
 * - timeout: seconds, for connecting and for the whole request.
 *
 * The authentication and pagination plugins and the key paths of the definition
 * are added by the source factory under the keys "authentication",
 * "pagination" and "source_key".
 */
#[ImportSource(
  id: 'http',
  label: new TranslatableMarkup('HTTP'),
  description: new TranslatableMarkup('Reads items from an HTTP endpoint returning JSON, XML or CSV.'),
)]
class HttpSource extends SourcePluginBase implements ContainerFactoryPluginInterface {

  /**
   * How many items a check returns as samples.
   */
  public const SAMPLE_SIZE = 5;

  /**
   * How many pages a check reads to see whether the paging works.
   */
  public const CHECK_PAGES = 3;

  /**
   * The authentication plugin of the definition.
   */
  private readonly AuthenticationInterface $authentication;

  /**
   * The pagination plugin of the definition.
   */
  private readonly PaginationInterface $pagination;

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
   * @param \Drupal\import_engine\Page\PageFingerprint $fingerprint
   *   The page fingerprint.
   * @param \Drupal\import_engine\Key\ItemKey $itemKey
   *   The item key builder.
   * @param \Drupal\import_engine\Authentication\AuthenticationPluginManager $authenticationManager
   *   The authentication plugin manager.
   * @param \Drupal\import_engine\Pagination\PaginationPluginManager $paginationManager
   *   The pagination plugin manager.
   */
  final public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ClientInterface $httpClient,
    private readonly ResponseDecoder $decoder,
    protected readonly PathResolver $paths,
    private readonly PageFingerprint $fingerprint,
    private readonly ItemKey $itemKey,
    AuthenticationPluginManager $authenticationManager,
    PaginationPluginManager $paginationManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $authentication = $this->configuration['authentication'] ?? ['plugin' => 'none', 'configuration' => []];
    $instance = $authenticationManager->createInstance($authentication['plugin'], $authentication['configuration']);
    assert($instance instanceof AuthenticationInterface);
    $this->authentication = $instance;
    $pagination = $this->configuration['pagination'] ?? ['plugin' => 'none', 'configuration' => []];
    $instance = $paginationManager->createInstance($pagination['plugin'], $pagination['configuration']);
    assert($instance instanceof PaginationInterface);
    $this->pagination = $instance;
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
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('import_engine.response_decoder'),
      $container->get('import_engine.path_resolver'),
      $container->get('import_engine.page_fingerprint'),
      $container->get('import_engine.item_key'),
      $container->get('plugin.manager.import_engine_authentication'),
      $container->get('plugin.manager.import_engine_pagination'),
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
   */
  public function fetchPage(?string $cursor = NULL): SourcePage {
    $request = $this->pagination->applyCursor($this->baseRequest(), $cursor);
    $request = $this->authenticate($request);
    $label = $request->method . ' ' . $this->describe($request);
    $response = $this->send($request, $label);

    try {
      $data = $this->decoder->decode(
        (string) $response->getBody(),
        $response->getHeaderLine('Content-Type'),
        $this->responseFormat(),
        $this->csvDelimiter(),
      );
    }
    catch (DecodeException $exception) {
      throw SourceException::permanent($label . ': ' . $exception->getMessage(), $exception);
    }
    $this->assertValidResponse($data, $label);

    $items = $this->paths->items($data, (string) $this->configuration['items_path']);
    if ($items === NULL) {
      throw SourceException::permanent(sprintf('%s: there is no list of items at "%s".', $label, $this->configuration['items_path']));
    }
    return new SourcePage(
      $items,
      $this->pagination->nextCursor($cursor, $data, $items),
      $this->pagination->total($data),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Reads up to CHECK_PAGES pages, following the paging settings, and reports
   * a page that holds the same data as an earlier one: the usual sign of a
   * paging parameter the server ignores.
   */
  public function check(): SourceCheck {
    $check = new SourceCheck();
    $cursor = NULL;
    $fingerprints = [];
    $seen_keys = [];
    $overlap = 0;
    $items_read = 0;

    for ($number = 1; $number <= self::CHECK_PAGES; $number++) {
      try {
        $page = $this->fetchPage($cursor);
      }
      catch (SourceException $exception) {
        $prefix = ($number > 1 ? sprintf('Page %d: ', $number) : '') . ($exception->retryable ? 'Temporary problem, try again: ' : '');
        return $check->add(Severity::Error, $prefix . $exception->getMessage());
      }

      if ($number === 1) {
        $check->sampleItems = array_slice($page->items, 0, self::SAMPLE_SIZE);
        $check->add(Severity::Info, sprintf('Connected: the first page holds %d items.', count($page->items)));
        if ($page->items === []) {
          return $check->add(Severity::Warning, 'The source returned no items, so the field mapping cannot be built from a sample.');
        }
        if ($page->total !== NULL) {
          $check->add(Severity::Info, sprintf('The source reports %d items in total.', $page->total));
        }
      }
      elseif ($page->items === []) {
        break;
      }

      $fingerprint = $this->fingerprint->fingerprint($page->items);
      if (isset($fingerprints[$fingerprint])) {
        return $check->add(Severity::Error, sprintf(
          'Page %d holds exactly the same data as page %d: the server ignores the paging settings. Check the names of the paging parameters and where they are sent (query or body).',
          $number,
          $fingerprints[$fingerprint],
        ));
      }
      $fingerprints[$fingerprint] = $number;
      $items_read += count($page->items);
      $overlap += $this->checkKeys($check, $page->items, $number, $seen_keys);

      if ($page->nextCursor === NULL) {
        break;
      }
      $cursor = $page->nextCursor;
    }

    if ($overlap > 0) {
      $check->add(Severity::Warning, sprintf('%d items appeared on more than one page; the source may shift while paging.', $overlap));
    }
    if (count($fingerprints) > 1) {
      $check->add(Severity::Info, sprintf('Read %d pages with %d items and no page repeated.', count($fingerprints), $items_read));
    }
    return $check;
  }

  /**
   * Checks that the items of a page have a usable, unique key.
   *
   * @param \Drupal\import_engine\Source\SourceCheck $check
   *   The check to add messages to.
   * @param list<array<string, mixed>> $items
   *   The items of the page.
   * @param int $number
   *   The number of the page, counting from 1.
   * @param array<string, true> $seen_keys
   *   The keys on earlier pages; updated.
   *
   * @return int
   *   How many items were already on an earlier page.
   */
  private function checkKeys(SourceCheck $check, array $items, int $number, array &$seen_keys): int {
    $key_paths = $this->configuration['source_key'] ?? [];
    $on_this_page = [];
    $overlap = 0;
    foreach ($number === 1 ? array_slice($items, 0, self::SAMPLE_SIZE) : $items as $index => $item) {
      try {
        $key = $this->itemKey->build($item, $key_paths);
      }
      catch (InvalidKeyException $exception) {
        $check->add(Severity::Error, $number === 1
          ? sprintf('Item %d: %s.', $index + 1, $exception->getMessage())
          : sprintf('Page %d, item %d: %s.', $number, $index + 1, $exception->getMessage()));
        continue;
      }
      if (isset($on_this_page[$key])) {
        $check->add(Severity::Error, 'The keys of the items are not unique.');
        return $overlap;
      }
      $on_this_page[$key] = TRUE;
      if (isset($seen_keys[$key])) {
        $overlap++;
      }
    }
    $seen_keys += $on_this_page;
    return $overlap;
  }

  /**
   * Builds the request for the first page from the configuration.
   *
   * Subclasses replace this to describe their own kind of request. Paging and
   * authentication are applied to it afterwards.
   *
   * @throws \Drupal\import_engine\Source\SourceException
   *   When the configuration cannot make a valid request.
   */
  protected function baseRequest(): RequestSpec {
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

    return new RequestSpec(
      strtoupper((string) $this->configuration['method']),
      (string) $this->configuration['url'],
      $this->configuration['query'],
      $headers,
      $body,
    );
  }

  /**
   * Returns the format of the response; "auto" detects it.
   */
  protected function responseFormat(): string {
    return (string) $this->configuration['format'];
  }

  /**
   * Returns the CSV delimiter.
   */
  protected function csvDelimiter(): string {
    return (string) $this->configuration['csv_delimiter'];
  }

  /**
   * Lets a subclass reject a decoded response that is an error in disguise.
   *
   * @param array<mixed> $data
   *   The decoded response.
   * @param string $label
   *   A description of the request for messages.
   *
   * @throws \Drupal\import_engine\Source\SourceException
   *   When the response reports a failure.
   */
  protected function assertValidResponse(array $data, string $label): void {
  }

  /**
   * Applies the authentication to a request.
   *
   * @throws \Drupal\import_engine\Source\SourceException
   *   When a secret is missing.
   */
  private function authenticate(RequestSpec $request): RequestSpec {
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
   * Describes a request for messages: its URL without query and fragment.
   *
   * A next-page link can carry tokens in its query, so it is never printed.
   */
  private function describe(RequestSpec $request): string {
    return (string) strtok($request->url, '?#');
  }

}
