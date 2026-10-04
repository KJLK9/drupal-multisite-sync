<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

/**
 * Packs an item payload for storage: JSON, compressed.
 *
 * JSON compresses five to ten times, and a payload is only kept while its item
 * is not done, so this keeps the queue small.
 */
final class PayloadCodec {

  /**
   * Packs a payload.
   *
   * @param array<mixed> $payload
   *   The decoded source item.
   */
  public function encode(array $payload): string {
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    $packed = gzcompress($json, 6);
    if ($packed === FALSE) {
      throw new \RuntimeException('Could not compress the payload.');
    }
    return $packed;
  }

  /**
   * Unpacks a payload.
   *
   * @return array<mixed>
   *   The decoded source item.
   */
  public function decode(string $packed): array {
    $json = gzuncompress($packed);
    if ($json === FALSE) {
      throw new \RuntimeException('Could not decompress the payload.');
    }
    $payload = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
      throw new \RuntimeException('The payload is not an object or a list.');
    }
    return $payload;
  }

}
