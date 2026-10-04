<?php

declare(strict_types=1);

namespace Drupal\import_engine\Breaker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine\Source\SourceException;
use Drupal\import_engine\Source\SourceInterface;
use Psr\Log\LoggerInterface;

/**
 * Stops calling a server that is down, and finds out when it is back.
 *
 * Closed: calls go through. After a number of transient failures in a row
 * (timeouts, server errors, rate limits) it opens and calls are refused
 * without being made, which spares the server and the run. After a cooldown
 * one process probes the server. If the probe answers, the breaker is half
 * open: calls go through, the first failure opens it again for longer, the
 * first success closes it. Permanent errors (bad credentials) mean the server
 * answered, so they are no outage and do not count.
 *
 * The breaker is kept per server, shared by every import that reads from it.
 * Each import applies its own threshold to the shared count, so the strictest
 * one opens it first.
 */
final class CircuitBreaker {

  /**
   * Constructs the breaker.
   */
  public function __construct(
    private readonly BreakerStore $store,
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Wraps the source of an import: its pages go through the breaker.
   *
   * @param \Drupal\import_engine\Source\SourceInterface $source
   *   The source.
   * @param \Drupal\import_engine\ImportDefinitionInterface $definition
   *   The import; its breaker settings apply.
   * @param callable|null $clock
   *   Returns the current time; for tests.
   */
  public function wrap(SourceInterface $source, ImportDefinitionInterface $definition, ?callable $clock = NULL): SourceInterface {
    $settings = $definition->getBreaker();
    if (!$settings['enabled']) {
      return $source;
    }
    return new BreakerSource($source, $this, $settings['threshold'], $settings['cooldown'], $clock ?? fn (): int => $this->time->getCurrentTime());
  }

  /**
   * Called before a call: refuses it while the breaker is open.
   *
   * When the cooldown is over, one caller probes the source on behalf of all.
   *
   * @throws \Drupal\import_engine\Source\SourceException
   *   A transient one when the call must not be made.
   */
  public function before(SourceInterface $source, int $cooldown, int $now): void {
    $endpoint = $source->getEndpoint();
    $record = $this->store->get($endpoint);
    if ($record->state !== BreakerState::Open) {
      return;
    }
    if ($record->manual) {
      throw SourceException::transient(sprintf('The circuit breaker for %s was opened by hand; reset it to continue.', $endpoint));
    }
    if ($record->nextProbe > $now) {
      throw SourceException::transient(sprintf('The circuit breaker for %s is open; the next probe is in %d seconds.', $endpoint, $record->nextProbe - $now));
    }
    if (!$this->store->claimProbe($endpoint, $now)) {
      throw SourceException::transient(sprintf('The circuit breaker for %s is open and another process is probing it.', $endpoint));
    }

    try {
      $source->probe();
    }
    catch (SourceException $exception) {
      if ($exception->retryable) {
        $this->store->probeFailed($endpoint, $cooldown, $now);
        $this->logger->warning('Probe of @endpoint failed, the circuit breaker stays open: @message', [
          '@endpoint' => $endpoint,
          '@message' => $exception->getMessage(),
        ]);
        throw SourceException::transient(sprintf('The circuit breaker for %s is open: the probe failed.', $endpoint), $exception);
      }
      // It answered, with an error that waiting does not fix: the server is up.
    }
    $this->store->probeSucceeded($endpoint, $now);
    $this->logger->notice('Probe of @endpoint succeeded, the circuit breaker is half open.', ['@endpoint' => $endpoint]);
  }

  /**
   * Called after a call that failed, or after a call that did not.
   *
   * @param \Drupal\import_engine\Source\SourceInterface $source
   *   The source.
   * @param \Drupal\import_engine\Source\SourceException|null $failure
   *   What went wrong, or NULL when the call succeeded.
   * @param int $threshold
   *   The failures in a row that open the breaker.
   * @param int $cooldown
   *   The base cooldown in seconds.
   * @param int $now
   *   The current time.
   */
  public function after(SourceInterface $source, ?SourceException $failure, int $threshold, int $cooldown, int $now): void {
    $endpoint = $source->getEndpoint();
    if ($failure !== NULL && $failure->retryable) {
      if ($this->store->recordFailure($endpoint, $threshold, $cooldown, $now)) {
        $this->logger->error('The circuit breaker for @endpoint opened: @message', [
          '@endpoint' => $endpoint,
          '@message' => $failure->getMessage(),
        ]);
      }
      return;
    }
    if ($this->store->recordSuccess($endpoint, $now)) {
      $this->logger->notice('The circuit breaker for @endpoint closed.', ['@endpoint' => $endpoint]);
    }
  }

}
