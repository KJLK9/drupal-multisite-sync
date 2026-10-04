<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Unit;

use Drupal\import_engine\Http\RequestSpec;
use Drupal\import_engine\Secret\MissingSecretException;
use Drupal\import_engine\Secret\SecretResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the request description and the secret resolver.
 */
#[CoversClass(RequestSpec::class)]
#[CoversClass(SecretResolver::class)]
#[Group('import_engine')]
class RequestAndSecretTest extends UnitTestCase {

  /**
   * Changing a request returns a changed copy and leaves the original alone.
   */
  public function testRequestIsImmutable(): void {
    $original = new RequestSpec('GET', 'https://example.test/api', ['limit' => 10]);

    $changed = $original
      ->withQueryParameter('offset', 20)
      ->withHeader('api-key', 'k')
      ->withBody(['variables' => ['offset' => 20]])
      ->withUrl('https://example.test/other');

    $this->assertSame(['limit' => 10], $original->query);
    $this->assertSame([], $original->headers);
    $this->assertNull($original->body);
    $this->assertSame('https://example.test/api', $original->url);
    $this->assertSame(['offset' => 20, 'limit' => 10], $changed->query);
    $this->assertSame(['api-key' => 'k'], $changed->headers);
    $this->assertSame(['variables' => ['offset' => 20]], $changed->body);
    $this->assertSame('https://example.test/other', $changed->url);
  }

  /**
   * Only the parts that are set become Guzzle options.
   */
  public function testGuzzleOptions(): void {
    $this->assertSame([], (new RequestSpec('GET', 'https://example.test'))->toGuzzleOptions());

    $options = (new RequestSpec('POST', 'https://example.test', ['a' => 1], ['h' => 'v'], ['q' => 'x']))->toGuzzleOptions();
    $this->assertSame(['query' => ['a' => 1], 'headers' => ['h' => 'v'], 'json' => ['q' => 'x']], $options);
  }

  /**
   * A secret is read from the environment; a missing one names the variable.
   */
  public function testSecrets(): void {
    $name = 'IMPORT_ENGINE_TEST_SECRET';
    $resolver = new SecretResolver();

    putenv($name . '=s3cret');
    try {
      $this->assertSame('s3cret', $resolver->get($name));
    }
    finally {
      putenv($name);
    }

    try {
      $resolver->get($name);
      $this->fail('Expected a MissingSecretException.');
    }
    catch (MissingSecretException $exception) {
      $this->assertStringContainsString($name, $exception->getMessage());
    }

    putenv($name . '=');
    try {
      $resolver->get($name);
      $this->fail('An empty value counts as missing.');
    }
    catch (MissingSecretException) {
      $this->addToAssertionCount(1);
    }
    finally {
      putenv($name);
    }
  }

}
