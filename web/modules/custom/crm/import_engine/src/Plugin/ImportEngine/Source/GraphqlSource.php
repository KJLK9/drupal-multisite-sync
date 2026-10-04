<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Source;

use Drupal\import_engine\Form\TextLists;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportSource;
use Drupal\import_engine\Http\RequestSpec;
use Drupal\import_engine\Source\SourceException;

/**
 * Reads items from a GraphQL endpoint.
 *
 * It shares everything with the HTTP source (authentication, paging, decoding,
 * checks) and differs in how the request is described and in what counts as a
 * failure: a GraphQL server answers 200 even when the query failed, with the
 * problem in an "errors" list.
 *
 * Configuration: url, query (the GraphQL document), variables (a JSON object
 * as text), headers, items_path and timeout. Paging plugins with
 * target "body" set variables, for example "variables.offset".
 */
#[ImportSource(
  id: 'graphql',
  label: new TranslatableMarkup('GraphQL'),
  description: new TranslatableMarkup('Reads items from a GraphQL endpoint.'),
)]
final class GraphqlSource extends HttpSource {

  /**
   * The longest GraphQL error message that is passed on.
   */
  private const MAX_ERROR_LENGTH = 200;

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default configuration.
   */
  public function defaultConfiguration(): array {
    return [
      'url' => '',
      'query' => '',
      'variables' => '',
      'headers' => [],
      'items_path' => 'data',
      'timeout' => 30,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function baseRequest(): RequestSpec {
    $variables = [];
    if (is_string($this->configuration['variables']) && trim($this->configuration['variables']) !== '') {
      $variables = json_decode($this->configuration['variables'], TRUE);
      if (!is_array($variables)) {
        throw SourceException::permanent('The variables of the source are not valid JSON.');
      }
    }
    $body = ['query' => (string) $this->configuration['query']];
    if ($variables !== []) {
      $body['variables'] = $variables;
    }
    $headers = $this->configuration['headers'] + ['Accept' => 'application/json'];
    return new RequestSpec('POST', (string) $this->configuration['url'], [], $headers, $body);
  }

  /**
   * {@inheritdoc}
   */
  protected function responseFormat(): string {
    return 'json';
  }

  /**
   * {@inheritdoc}
   */
  protected function csvDelimiter(): string {
    return ',';
  }

  /**
   * {@inheritdoc}
   */
  protected function assertValidResponse(array $data, string $label): void {
    $errors = $data['errors'] ?? NULL;
    if (!is_array($errors) || $errors === []) {
      return;
    }
    $first = $errors[0];
    $message = is_array($first) && isset($first['message']) && is_string($first['message']) ? $first['message'] : 'unknown error';
    throw SourceException::permanent(sprintf('%s: GraphQL error: %s', $label, mb_substr($message, 0, self::MAX_ERROR_LENGTH)));
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL'),
      '#description' => $this->t('The GraphQL endpoint, without a query string.'),
      '#default_value' => $this->configuration['url'],
      '#required' => TRUE,
    ];
    $form['query'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Query'),
      '#description' => $this->t('The GraphQL query. Paging values are sent as variables by the pagination step.'),
      '#default_value' => $this->configuration['query'],
      '#rows' => 10,
      '#required' => TRUE,
    ];
    $form['variables'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Variables (JSON)'),
      '#description' => $this->t('A JSON object with fixed variables, or empty.'),
      '#default_value' => $this->configuration['variables'],
      '#rows' => 3,
    ];
    $form['headers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Headers'),
      '#description' => $this->t('One per line, as <code>Name: value</code>. Keys and tokens do not belong here: use the authentication step.'),
      '#default_value' => TextLists::formatPairs($this->configuration['headers'], ': '),
      '#rows' => 3,
    ];
    $form['items_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path of the list of items'),
      '#description' => $this->t('Dotted path in the response, for example <code>data.customers.items</code>.'),
      '#default_value' => $this->configuration['items_path'],
      '#required' => TRUE,
    ];
    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout in seconds'),
      '#default_value' => $this->configuration['timeout'],
      '#min' => 1,
      '#max' => 300,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    foreach (['headers' => ': '] as $field => $separator) {
      try {
        TextLists::pairs((string) $form_state->getValue($field), $separator);
      }
      catch (\InvalidArgumentException $exception) {
        $form_state->setErrorByName($field, $exception->getMessage());
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $values
   *   The submitted values.
   *
   * @return array<string, mixed>
   *   The values with the maps read from their text.
   */
  protected function normalizeFormValues(array $values): array {
    foreach (['headers' => ': '] as $field => $separator) {
      $values[$field] = TextLists::pairs((string) ($values[$field] ?? ''), $separator);
    }
    return $values;
  }

}
