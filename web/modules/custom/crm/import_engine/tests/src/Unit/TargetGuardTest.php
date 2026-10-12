<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Unit;

use Drupal\Core\Site\Settings;
use Drupal\import_engine\Http\TargetGuard;
use Drupal\import_engine\Source\SourceException;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which addresses a source may call.
 */
#[Group('import_engine')]
#[CoversClass(TargetGuard::class)]
class TargetGuardTest extends UnitTestCase {

  /**
   * Addresses that are never a source are refused, with a permanent error.
   *
   * @param string $url
   *   The URL.
   */
  #[DataProvider('refused')]
  public function testRefusedAddresses(string $url): void {
    new Settings([]);

    try {
      TargetGuard::assertAllowed($url);
      $this->fail('Expected a SourceException for ' . $url);
    }
    catch (SourceException $exception) {
      $this->assertFalse($exception->retryable);
    }
  }

  /**
   * Addresses that are refused.
   *
   * @return array<string, array{string}>
   *   The URLs.
   */
  public static function refused(): array {
    return [
      'the metadata address' => ['http://169.254.169.254/latest/meta-data/'],
      'link-local' => ['http://169.254.10.10/'],
      'link-local over IPv6' => ['http://[fe80::1]/'],
      'the metadata address over IPv6' => ['http://[fd00:ec2::254]/'],
      'this network' => ['http://0.0.0.0:8080/'],
      'a file' => ['file:///etc/passwd'],
      'ftp' => ['ftp://example.test/x'],
      'no scheme' => ['example.test/x'],
      'no host' => ['http:///x'],
    ];
  }

  /**
   * Addresses where sources really are stay allowed.
   *
   * @param string $url
   *   The URL.
   */
  #[DataProvider('allowed')]
  public function testAllowedAddresses(string $url): void {
    new Settings([]);

    TargetGuard::assertAllowed($url);

    $this->addToAssertionCount(1);
  }

  /**
   * Addresses that are allowed.
   *
   * @return array<string, array{string}>
   *   The URLs.
   */
  public static function allowed(): array {
    return [
      'a public address' => ['https://93.184.216.34/api'],
      'a private address, as in a cluster' => ['http://10.43.0.12/graphql'],
      'localhost, as in development' => ['http://127.0.0.1:8080/graphql'],
      'a name that does not resolve' => ['https://name-that-does-not-exist.invalid/api'],
    ];
  }

  /**
   * The ranges can be set in settings.php.
   */
  public function testRangesComeFromSettings(): void {
    new Settings(['import_engine_blocked_networks' => ['10.0.0.0/8']]);

    try {
      TargetGuard::assertAllowed('http://10.43.0.12/graphql');
      $this->fail('Expected a SourceException.');
    }
    catch (SourceException) {
      $this->addToAssertionCount(1);
    }
    // The default ranges are replaced, not added to.
    TargetGuard::assertAllowed('http://169.254.169.254/');
  }

}
