<?php

declare(strict_types=1);

namespace Drupal\import_engine\Breaker;

use Drupal\Core\Form\FormStateInterface;
use Drupal\import_engine\Source\SourceCheck;
use Drupal\import_engine\Source\SourceException;
use Drupal\import_engine\Source\SourceInterface;
use Drupal\import_engine\Source\SourcePage;

/**
 * A source that asks the circuit breaker before every page it reads.
 *
 * It is a decorator: everything but reading pages is the source itself. Setup
 * checks are not guarded, since a person is looking at them.
 */
final class BreakerSource implements SourceInterface {

  /**
   * Constructs the decorator.
   *
   * @param \Drupal\import_engine\Source\SourceInterface $inner
   *   The real source.
   * @param \Drupal\import_engine\Breaker\CircuitBreaker $breaker
   *   The circuit breaker.
   * @param int $threshold
   *   The failures in a row that open the breaker.
   * @param int $cooldown
   *   The base cooldown in seconds.
   * @param callable $clock
   *   Returns the current time.
   */
  public function __construct(
    private readonly SourceInterface $inner,
    private readonly CircuitBreaker $breaker,
    private readonly int $threshold,
    private readonly int $cooldown,
    private readonly mixed $clock,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function fetchPage(?string $cursor = NULL): SourcePage {
    $this->breaker->before($this->inner, $this->cooldown, $this->now());
    try {
      $page = $this->inner->fetchPage($cursor);
    }
    catch (SourceException $exception) {
      $this->breaker->after($this->inner, $exception, $this->threshold, $this->cooldown, $this->now());
      throw $exception;
    }
    $this->breaker->after($this->inner, NULL, $this->threshold, $this->cooldown, $this->now());
    return $page;
  }

  /**
   * {@inheritdoc}
   */
  public function getEndpoint(): string {
    return $this->inner->getEndpoint();
  }

  /**
   * {@inheritdoc}
   */
  public function probe(): void {
    $this->inner->probe();
  }

  /**
   * {@inheritdoc}
   */
  public function check(): SourceCheck {
    return $this->inner->check();
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginId(): string {
    return $this->inner->getPluginId();
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>|object
   *   The plugin definition.
   */
  public function getPluginDefinition(): mixed {
    return $this->inner->getPluginDefinition();
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The configuration.
   */
  public function getConfiguration(): array {
    return $this->inner->getConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $configuration
   *   The configuration.
   */
  public function setConfiguration(array $configuration): void {
    $this->inner->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default configuration.
   */
  public function defaultConfiguration(): array {
    return $this->inner->defaultConfiguration();
  }

  /**
   * Returns the current time.
   */
  private function now(): int {
    return (int) ($this->clock)();
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
    return $this->inner->buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->inner->validateConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->inner->submitConfigurationForm($form, $form_state);
  }

}
