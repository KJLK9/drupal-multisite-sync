<?php

declare(strict_types=1);

namespace Drupal\import_engine\Http;

use Drupal\Core\Site\Settings;
use Drupal\import_engine\Source\SourceException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Keeps a source from calling addresses it has no business with.
 *
 * Whoever edits a definition chooses the address, so an import could be made to
 * ask the metadata service of a cloud server for its credentials and show the
 * answer in the sample. The addresses that are never a source are refused: the
 * link-local range (where cloud metadata lives) and addresses that are not
 * addresses of a host. Private networks are allowed, because that is where
 * sources often are (a DDEV site, a service in the same cluster); the ranges
 * can be changed in settings.php:
 *
 *   $settings['import_engine_blocked_networks'] = ['169.254.0.0/16', ...];
 *
 * The host name is looked up before the request. A name that changes its
 * answer between that and the request is not caught; that needs a network
 * policy, which is for the place the engine runs in.
 */
final class TargetGuard {

  /**
   * The ranges that are refused unless settings say otherwise.
   */
  public const DEFAULT_BLOCKED = [
    // Link-local: where the metadata service of a cloud server is.
    '169.254.0.0/16',
    'fe80::/10',
    // "This" network and the unspecified address.
    '0.0.0.0/8',
    '::/128',
    // The metadata address of some clouds over IPv6.
    'fd00:ec2::254/128',
  ];

  /**
   * Checks the address of a request.
   *
   * @param string $url
   *   The URL.
   *
   * @throws \Drupal\import_engine\Source\SourceException
   *   A permanent one, when the URL is not for http or https or points at a
   *   refused address.
   */
  public static function assertAllowed(string $url): void {
    $parts = parse_url($url);
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
    $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
    if (!in_array($scheme, ['http', 'https'], TRUE) || $host === '') {
      throw SourceException::permanent('The address is not an http or https address.');
    }
    $blocked = (array) Settings::get('import_engine_blocked_networks', self::DEFAULT_BLOCKED);
    foreach (self::addresses(trim($host, '[]')) as $address) {
      if (IpUtils::checkIp($address, array_map('strval', $blocked))) {
        throw SourceException::permanent('The address is in a range that sources may not use.');
      }
    }
  }

  /**
   * Returns the addresses a host stands for: itself, or what its name gives.
   *
   * @return list<string>
   *   The addresses; none when a name does not resolve (the request then
   *   fails by itself).
   */
  private static function addresses(string $host): array {
    if (filter_var($host, FILTER_VALIDATE_IP) !== FALSE) {
      return [$host];
    }
    $found = gethostbynamel($host);
    return $found === FALSE ? [] : $found;
  }

}
