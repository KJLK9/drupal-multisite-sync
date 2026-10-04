<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Breaker\BreakerState;
use Drupal\import_engine\Breaker\BreakerStore;
use Drupal\import_engine\Breaker\CircuitBreaker;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\Source\SourceException;
use Drupal\import_engine\Source\SourceInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the circuit breaker: states, probe, sharing and the extract stage.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class BreakerTest extends ExtractTestBase {

  /**
   * The time the tests live in; moved by the tests.
   */
  protected int $now = 10000;

  /**
   * The breaker.
   */
  protected CircuitBreaker $breaker;

  /**
   * The store of breakers.
   */
  protected BreakerStore $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->breaker = $this->container->get('import_engine.circuit_breaker');
    $this->store = $this->container->get('import_engine.breaker_store');
  }

  /**
   * Wraps a source with a breaker of 3 failures and a cooldown of 60 seconds.
   *
   * @param \Drupal\import_engine\Source\SourceInterface $source
   *   The source.
   * @param array<string, mixed> $breaker
   *   Settings that replace the defaults of the definition.
   */
  protected function guard(SourceInterface $source, array $breaker = []): SourceInterface {
    $definition = $this->definition(['breaker' => $breaker + ['enabled' => TRUE, 'threshold' => 3, 'cooldown' => 60]]);
    return $this->breaker->wrap($source, $definition, fn (): int => $this->now);
  }

  /**
   * Reads a page and returns the message of the failure, or NULL.
   */
  protected function attempt(SourceInterface $source): ?string {
    try {
      $source->fetchPage(NULL);
    }
    catch (SourceException $exception) {
      return $exception->getMessage();
    }
    return NULL;
  }

  /**
   * Builds a source that is down.
   */
  protected function downSource(string $endpoint = 'fake.test'): FakeSource {
    $source = new FakeSource($this->pagesOf(3, 2));
    $source->endpoint = $endpoint;
    $source->down = SourceException::transient('timeout');
    return $source;
  }

  /**
   * The breaker opens after enough failures in a row and spares the source.
   */
  public function testOpensAfterThreshold(): void {
    $fake = $this->downSource();
    $source = $this->guard($fake);

    $this->assertSame('timeout', $this->attempt($source));
    $this->assertSame('timeout', $this->attempt($source));
    $this->assertSame(BreakerState::Closed, $this->store->get('fake.test')->state);
    $this->assertSame('timeout', $this->attempt($source));

    $record = $this->store->get('fake.test');
    $this->assertSame(BreakerState::Open, $record->state);
    $this->assertSame($this->now + 60, $record->nextProbe);
    $this->assertCount(3, $fake->calls);

    // The next calls are refused without reaching the source.
    $this->assertStringContainsString('is open; the next probe is in 60 seconds', (string) $this->attempt($source));
    $this->assertCount(3, $fake->calls);
  }

  /**
   * A success starts the count of failures over.
   */
  public function testSuccessResetsFailures(): void {
    $fake = new FakeSource($this->pagesOf(3, 2));
    $source = $this->guard($fake);
    $fake->down = SourceException::transient('timeout');
    $this->attempt($source);
    $this->attempt($source);
    $fake->down = NULL;
    $this->assertNull($this->attempt($source));
    $fake->down = SourceException::transient('timeout');
    $this->attempt($source);
    $this->attempt($source);

    $this->assertSame(BreakerState::Closed, $this->store->get('fake.test')->state);
    $this->assertSame(2, $this->store->get('fake.test')->failures);
  }

  /**
   * An error that waiting does not fix is no outage.
   */
  public function testPermanentErrorsDoNotCount(): void {
    $fake = new FakeSource($this->pagesOf(1, 1));
    $fake->down = SourceException::permanent('HTTP 401');
    $source = $this->guard($fake);

    for ($i = 0; $i < 10; $i++) {
      $this->assertSame('HTTP 401', $this->attempt($source));
    }

    $this->assertSame(BreakerState::Closed, $this->store->get('fake.test')->state);
    $this->assertSame(0, $this->store->get('fake.test')->failures);
  }

  /**
   * A probe that fails keeps the breaker open for twice as long.
   */
  public function testFailedProbeWaitsLonger(): void {
    $fake = $this->downSource();
    $source = $this->guard($fake);
    for ($i = 0; $i < 3; $i++) {
      $this->attempt($source);
    }

    $this->now += 60;
    $this->assertStringContainsString('the probe failed', (string) $this->attempt($source));

    $record = $this->store->get('fake.test');
    $this->assertSame(BreakerState::Open, $record->state);
    $this->assertSame(2, $record->trips);
    $this->assertSame($this->now + 120, $record->nextProbe);
    // The probe is not a call of the source.
    $this->assertCount(3, $fake->calls);
  }

  /**
   * A probe that succeeds lets calls through, and a success closes the breaker.
   */
  public function testProbeSucceedsAndBreakerCloses(): void {
    $fake = $this->downSource();
    $source = $this->guard($fake);
    for ($i = 0; $i < 3; $i++) {
      $this->attempt($source);
    }

    $fake->down = NULL;
    $this->now += 60;
    $this->assertNull($this->attempt($source));

    $record = $this->store->get('fake.test');
    $this->assertSame(BreakerState::Closed, $record->state);
    $this->assertSame(0, $record->failures);
    $this->assertSame(0, $record->trips);
  }

  /**
   * A failure right after the probe opens the breaker again at once.
   */
  public function testFailureWhileHalfOpenOpensAgain(): void {
    $fake = $this->downSource();
    $source = $this->guard($fake);
    for ($i = 0; $i < 3; $i++) {
      $this->attempt($source);
    }
    $this->store->probeSucceeded('fake.test', $this->now);
    $this->assertSame(BreakerState::HalfOpen, $this->store->get('fake.test')->state);

    $this->assertSame('timeout', $this->attempt($source));

    $record = $this->store->get('fake.test');
    $this->assertSame(BreakerState::Open, $record->state);
    $this->assertSame(2, $record->trips);
    $this->assertSame($this->now + 120, $record->nextProbe);
  }

  /**
   * Only one process gets to probe.
   */
  public function testOnlyOneProber(): void {
    $this->store->recordFailure('fake.test', 1, 60, $this->now);

    $this->assertFalse($this->store->claimProbe('fake.test', $this->now), 'not due yet');
    $this->assertTrue($this->store->claimProbe('fake.test', $this->now + 60));
    $this->assertFalse($this->store->claimProbe('fake.test', $this->now + 61), 'someone is probing');
    // A prober that died: the claim runs out.
    $this->assertTrue($this->store->claimProbe('fake.test', $this->now + 60 + BreakerStore::PROBE_LEASE + 1));
  }

  /**
   * Imports that read from the same server share the breaker.
   */
  public function testSharedPerServer(): void {
    $down = $this->downSource();
    $other = new FakeSource($this->pagesOf(1, 1));
    $elsewhere = new FakeSource($this->pagesOf(1, 1));
    $elsewhere->endpoint = 'other.test';
    for ($i = 0; $i < 3; $i++) {
      $this->attempt($this->guard($down));
    }

    // Another import on the same server is refused without a call.
    $this->assertStringContainsString('is open', (string) $this->attempt($this->guard($other)));
    $this->assertCount(0, $other->calls);
    // A different server is not affected.
    $this->assertNull($this->attempt($this->guard($elsewhere)));
  }

  /**
   * A breaker opened by hand stays open until it is reset.
   */
  public function testManualTripAndReset(): void {
    $fake = new FakeSource($this->pagesOf(1, 1));
    $source = $this->guard($fake);
    $this->store->trip('fake.test', $this->now);

    $this->now += 100000;
    $this->assertStringContainsString('opened by hand', (string) $this->attempt($source));
    $this->assertCount(0, $fake->calls);

    $this->assertTrue($this->store->reset('fake.test', $this->now));
    $this->assertNull($this->attempt($source));
    $this->assertFalse($this->store->reset('fake.test', $this->now));
  }

  /**
   * An import without a breaker reads its source directly.
   */
  public function testDisabledBreaker(): void {
    $fake = new FakeSource($this->pagesOf(1, 1));

    $this->assertSame($fake, $this->guard($fake, ['enabled' => FALSE]));
  }

  /**
   * The cooldown doubles with each trip, up to a cap.
   */
  public function testCooldownGrowsToCap(): void {
    $this->assertSame(60, BreakerStore::cooldown(60, 1));
    $this->assertSame(120, BreakerStore::cooldown(60, 2));
    $this->assertSame(480, BreakerStore::cooldown(60, 4));
    $this->assertSame(BreakerStore::MAX_COOLDOWN, BreakerStore::cooldown(60, 5));
    $this->assertSame(BreakerStore::MAX_COOLDOWN, BreakerStore::cooldown(60, 5000));
  }

  /**
   * An outage interrupts the extraction; it resumes when the source is back.
   */
  public function testExtractionSurvivesAnOutage(): void {
    $definition = $this->definition();
    $run = $this->starter->start($definition, Trigger::Drush);
    $fake = new FakeSource($this->pagesOf(3, 2));
    $source = $this->guard($fake, ['threshold' => 2]);

    // One page comes in, then the source goes down.
    $first = $this->stage->extract($run, $definition, $source, 1);
    $this->assertSame('more_to_do', $first->status->value);
    $fake->down = SourceException::transient('timeout');
    $this->assertSame('interrupted', $this->stage->extract($run, $definition, $source)->status->value);
    $this->assertSame('interrupted', $this->stage->extract($run, $definition, $source)->status->value);
    $this->assertSame(BreakerState::Open, $this->store->get('fake.test')->state);

    // While it is open the source is left alone.
    $calls = count($fake->calls);
    $refused = $this->stage->extract($run, $definition, $source);
    $this->assertSame('interrupted', $refused->status->value);
    $this->assertStringContainsString('circuit breaker', (string) $refused->message);
    $this->assertCount($calls, $fake->calls);

    // It comes back: the probe passes and the run carries on where it was.
    $fake->down = NULL;
    $this->now += 60;
    $done = $this->stage->extract($run, $definition, $source);
    $this->assertSame('complete', $done->status->value);
    $this->assertSame(BreakerState::Closed, $this->store->get('fake.test')->state);
    $this->assertSame(6, $this->items->countByState((int) $run->id())['pending']);
  }

}
