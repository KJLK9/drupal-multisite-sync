<?php

declare(strict_types=1);

namespace Drupal\import_engine\Decoder;

/**
 * Turns a response body (JSON, XML or CSV) into the same data structure.
 *
 * Internally the engine only works with decoded JSON data: lists and
 * associative arrays of strings, numbers, booleans and NULL. XML and CSV are
 * converted to that shape:
 * - XML: an element becomes an associative array of its children; attributes
 *   go under `@attributes` and text next to children under `#text`; a text-only
 *   element becomes a string; an element that repeats becomes a list. The root
 *   element's name is the first path segment. XML cannot tell one item from a
 *   list of one, see PathResolver::items(). Namespaced elements are ignored.
 * - CSV: the first row holds the column names; every row becomes an
 *   associative array of strings. Converting values to numbers or dates is the
 *   job of the field mappers.
 */
final class ResponseDecoder {

  /**
   * The formats that can be configured; "auto" detects the format.
   */
  public const FORMATS = ['auto', 'json', 'xml', 'csv'];

  /**
   * Returns the configurable formats, for use as a schema Choice callback.
   *
   * @return string[]
   *   The format names.
   */
  public static function formats(): array {
    return self::FORMATS;
  }

  /**
   * Decodes a response body.
   *
   * @param string $body
   *   The response body.
   * @param string|null $contentType
   *   The Content-Type header value, used to detect the format.
   * @param string $format
   *   One of FORMATS; "auto" detects it from the content type or the body.
   * @param string $csvDelimiter
   *   The field delimiter, used for CSV.
   *
   * @return array<mixed>
   *   The decoded data.
   *
   * @throws \Drupal\import_engine\Decoder\DecodeException
   *   When the format cannot be determined or the body is not valid.
   */
  public function decode(string $body, ?string $contentType = NULL, string $format = 'auto', string $csvDelimiter = ','): array {
    if ($format === 'auto') {
      $format = $this->detect($body, $contentType);
    }
    return match ($format) {
      'json' => $this->decodeJson($body),
      'xml' => $this->decodeXml($body),
      'csv' => $this->decodeCsv($body, $csvDelimiter),
      default => throw new DecodeException(sprintf('Unknown format "%s".', $format)),
    };
  }

  /**
   * Detects the format from the content type, then from the first character.
   */
  private function detect(string $body, ?string $contentType): string {
    $type = strtolower((string) $contentType);
    if (str_contains($type, 'json')) {
      return 'json';
    }
    if (str_contains($type, 'xml')) {
      return 'xml';
    }
    if (str_contains($type, 'csv')) {
      return 'csv';
    }
    $first = substr(ltrim($body), 0, 1);
    return match ($first) {
      '{', '[' => 'json',
      '<' => 'xml',
      default => throw new DecodeException('The format of the response could not be detected; set the format of the source.'),
    };
  }

  /**
   * Decodes JSON; the document must be an object or a list.
   *
   * @return array<mixed>
   *   The data.
   */
  private function decodeJson(string $body): array {
    try {
      $data = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new DecodeException('The response is not valid JSON: ' . $exception->getMessage(), 0, $exception);
    }
    if (!is_array($data)) {
      throw new DecodeException('The JSON response is not an object or a list.');
    }
    return $data;
  }

  /**
   * Decodes XML into the JSON-shaped structure described on the class.
   *
   * @return array<mixed>
   *   The data, keyed by the name of the root element.
   */
  private function decodeXml(string $body): array {
    $previous = libxml_use_internal_errors(TRUE);
    try {
      // LIBXML_NONET: never fetch anything from the network while parsing.
      $root = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
      if ($root === FALSE) {
        $error = libxml_get_last_error();
        throw new DecodeException('The response is not valid XML' . ($error ? ': ' . trim($error->message) : '.'));
      }
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
    return [$root->getName() => $this->convertElement($root)];
  }

  /**
   * Converts an XML element.
   *
   * @return array<mixed>|string
   *   A string for a text-only element, otherwise an associative array.
   */
  private function convertElement(\SimpleXMLElement $element): array|string {
    $result = [];
    foreach ($element->attributes() ?? [] as $name => $value) {
      $result['@attributes'][$name] = (string) $value;
    }
    foreach ($element->children() as $name => $child) {
      $value = $this->convertElement($child);
      if (!array_key_exists($name, $result)) {
        $result[$name] = $value;
      }
      elseif (is_array($result[$name]) && array_is_list($result[$name])) {
        $result[$name][] = $value;
      }
      else {
        $result[$name] = [$result[$name], $value];
      }
    }
    $text = trim((string) $element);
    if ($result === []) {
      return $text;
    }
    if ($text !== '') {
      $result['#text'] = $text;
    }
    return $result;
  }

  /**
   * Decodes CSV with a header row.
   *
   * @return list<array<string, string>>
   *   One associative array per row.
   */
  private function decodeCsv(string $body, string $delimiter): array {
    if (strlen($delimiter) !== 1) {
      throw new DecodeException('The CSV delimiter must be one character.');
    }
    $handle = fopen('php://temp', 'r+');
    if ($handle === FALSE) {
      throw new DecodeException('Could not open a buffer to read the CSV.');
    }
    fwrite($handle, $body);
    rewind($handle);

    $rows = [];
    $headers = NULL;
    $line = 0;
    // The escape character is empty, as RFC 4180 has none, and passing it
    // explicitly avoids the deprecated default.
    while (($fields = fgetcsv($handle, NULL, $delimiter, '"', '')) !== FALSE) {
      $line++;
      if ($fields === [NULL]) {
        // A blank line.
        continue;
      }
      if ($headers === NULL) {
        $headers = $this->csvHeaders($fields);
        continue;
      }
      if (count($fields) !== count($headers)) {
        fclose($handle);
        throw new DecodeException(sprintf('CSV line %d has %d columns, the header has %d.', $line, count($fields), count($headers)));
      }
      $rows[] = array_combine($headers, array_map(static fn ($value): string => (string) $value, $fields));
    }
    fclose($handle);
    return $rows;
  }

  /**
   * Validates the header row of a CSV and strips a byte order mark.
   *
   * @param array<int, string|null> $fields
   *   The cells of the header row.
   *
   * @return list<string>
   *   The column names.
   */
  private function csvHeaders(array $fields): array {
    $headers = array_map(static fn ($value): string => trim((string) $value), $fields);
    $headers[0] = preg_replace('/^\x{FEFF}/u', '', $headers[0]) ?? $headers[0];
    foreach ($headers as $header) {
      if ($header === '') {
        throw new DecodeException('The CSV header has an empty column name.');
      }
    }
    if (count(array_unique($headers)) !== count($headers)) {
      throw new DecodeException('The CSV header has duplicate column names.');
    }
    return array_values($headers);
  }

}
