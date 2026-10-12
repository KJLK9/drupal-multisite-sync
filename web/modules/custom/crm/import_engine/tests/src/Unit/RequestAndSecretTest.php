<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Unit;

use Drupal\import_engine\Http\RequestSpec;
use Drupal\import_engine\Secret\ForbiddenSecretException;
use Drupal\import_engine\Secret\MissingSecretException;
use Drupal\import_engine\Secret\SecretResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

  /**
   * Only names that say they hold a key for an API may be used.
   *
   * @param string $name
   *   The variable name.
   * @param bool $allowed
   *   Whether it may be used.
   */
  #[DataProvider('names')]
  public function testWhichNamesMayBeUsed(string $name, bool $allowed): void {
    $this->assertSame($allowed, SecretResolver::isAllowedName($name));
  }

  /**
   * Names and whether they may be used as a key.
   *
   * @return array<string, array{string, bool}>
   *   The names.
   */
  public static function names(): array {
    return [
      'a site key' => ['SITE_A_API_KEY', TRUE],
      'a token' => ['PARTNER_TOKEN', TRUE],
      'a secret' => ['CRM_SECRET', TRUE],
      'a plain key' => ['CATALOG_KEY', TRUE],
      'the database password' => ['SITE_B_DB_PASSWORD', FALSE],
      'a hash salt' => ['SITE_B_HASH_SALT', FALSE],
      'the admin password' => ['ADMIN_PASSWORD', FALSE],
      'the root password' => ['MARIADB_ROOT_PASSWORD', FALSE],
      'a key about the database' => ['SITE_A_DB_KEY', FALSE],
      'a key about a password' => ['PASSWORD_API_KEY', FALSE],
      'something else' => ['PATH', FALSE],
      'a name that is not upper case' => ['site_a_api_key', FALSE],
      'only the ending' => ['_KEY', FALSE],
    ];
  }

  /**
   * A forbidden name is refused before the environment is looked at.
   */
  public function testForbiddenNameIsNeverRead(): void {
    putenv('SITE_B_DB_PASSWORD=database-secret');
    try {
      (new SecretResolver())->get('SITE_B_DB_PASSWORD');
      $this->fail('Expected a ForbiddenSecretException.');
    }
    catch (ForbiddenSecretException $exception) {
      $this->assertStringNotContainsString('database-secret', $exception->getMessage());
    }
    finally {
      putenv('SITE_B_DB_PASSWORD');
    }
  }

}
