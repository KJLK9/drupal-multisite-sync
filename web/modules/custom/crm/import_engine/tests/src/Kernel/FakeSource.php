<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Source\SourceCheck;
use Drupal\import_engine\Source\SourceException;
use Drupal\import_engine\Source\SourcePage;
use Drupal\import_engine\Source\SourcePluginBase;

/**
 * A source for tests that returns scripted pages.
 *
 * The cursor is the index of the next page, or, with a sequence, the number of
 * the next call, so resuming works like with a real source. A sequence names
 * which page each call returns, which lets a test make a source that repeats
 * pages or cycles. Failures can be scripted per page.
 */
final class FakeSource extends SourcePluginBase {

  /**
   * The cursors of the calls made, NULL for the first page.
   *
   * @var list<string|null>
   */
  public array $calls = [];

  /**
   * The server this source talks to, for the circuit breaker.
   */
  public string $endpoint = 'fake.test';

  /**
   * When set, the server is down: every call and every probe throws this.
   */
  public ?SourceException $down = NULL;

  /**
   * Exceptions to throw, per page index; each is thrown once.
   *
   * @var array<int, list<\Drupal\import_engine\Source\SourceException>>
   */
  private array $failures = [];

  /**
   * Constructs the source.
   *
   * @param list<list<array<string, mixed>>> $pages
   *   The pages, each a list of items.
   * @param list<int>|null $sequence
   *   The page index each call returns, instead of reading in order.
   */
  public function __construct(
    private readonly array $pages,
    private readonly ?array $sequence = NULL,
  ) {
    parent::__construct([], 'fake', []);
  }

  /**
   * Makes a page fail once with an exception.
   */
  public function failOnce(int $index, SourceException $exception): self {
    $this->failures[$index][] = $exception;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchPage(?string $cursor = NULL): SourcePage {
    $this->calls[] = $cursor;
    if ($this->down !== NULL) {
      throw $this->down;
    }
    $call = $cursor === NULL ? 0 : (int) $cursor;
    $index = $this->sequence === NULL ? $call : $this->sequence[$call];

    if (!empty($this->failures[$index])) {
      throw array_shift($this->failures[$index]);
    }
    $last = $this->sequence === NULL ? count($this->pages) - 1 : count($this->sequence) - 1;
    return new SourcePage($this->pages[$index], $call < $last ? (string) ($call + 1) : NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function getEndpoint(): string {
    return $this->endpoint;
  }

  /**
   * {@inheritdoc}
   *
   * A probe is not a call of fetchPage(): it does not read a page.
   */
  public function probe(): void {
    if ($this->down !== NULL) {
      throw $this->down;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function check(): SourceCheck {
    return new SourceCheck();
  }

}
