<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Unit;

use Drupal\import_engine\Decoder\DecodeException;
use Drupal\import_engine\Decoder\ResponseDecoder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests decoding JSON, XML and CSV into one data structure.
 */
#[CoversClass(ResponseDecoder::class)]
#[Group('import_engine')]
class ResponseDecoderTest extends UnitTestCase {

  /**
   * Objects and lists decode; scalars and invalid JSON are rejected.
   */
  public function testJson(): void {
    $decoder = new ResponseDecoder();

    $this->assertSame(['a' => ['b' => 1]], $decoder->decode('{"a": {"b": 1}}', 'application/json'));
    $this->assertSame([['id' => 1], ['id' => 2]], $decoder->decode('[{"id": 1}, {"id": 2}]', 'application/json'));
    $this->assertSame([], $decoder->decode('[]', 'application/json'));

    foreach (['{"a": ', '"just a string"', '42'] as $body) {
      try {
        $decoder->decode($body, 'application/json');
        $this->fail('Expected a DecodeException for ' . $body);
      }
      catch (DecodeException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * XML becomes nested arrays; repeated elements become lists.
   */
  public function testXml(): void {
    $xml = '<customers><customer id="1"><label>ACME</label></customer><customer id="2"><label>Globex</label></customer></customers>';

    $this->assertSame([
      'customers' => [
        'customer' => [
          ['@attributes' => ['id' => '1'], 'label' => 'ACME'],
          ['@attributes' => ['id' => '2'], 'label' => 'Globex'],
        ],
      ],
    ], (new ResponseDecoder())->decode($xml, 'application/xml'));
  }

  /**
   * One element is an object, not a list: the ambiguity of XML.
   */
  public function testXmlSingleElementIsNotList(): void {
    $data = (new ResponseDecoder())->decode('<customers><customer><label>ACME</label></customer></customers>', 'text/xml');

    $this->assertSame(['customers' => ['customer' => ['label' => 'ACME']]], $data);
  }

  /**
   * Text next to attributes goes under #text; CDATA is plain text.
   */
  public function testXmlTextAttributesAndCdata(): void {
    $data = (new ResponseDecoder())->decode(
      '<price currency="EUR">9.95</price>',
      'application/xml',
    );
    $this->assertSame(['price' => ['@attributes' => ['currency' => 'EUR'], '#text' => '9.95']], $data);

    $data = (new ResponseDecoder())->decode('<note><![CDATA[a < b & c]]></note>', 'application/xml');
    $this->assertSame(['note' => 'a < b & c'], $data);
  }

  /**
   * Invalid XML is rejected, and external entities are never read.
   */
  public function testXmlRejectsInvalidAndDoesNotReadExternalEntities(): void {
    $decoder = new ResponseDecoder();
    try {
      $decoder->decode('<a><b></a>', 'application/xml');
      $this->fail('Expected a DecodeException.');
    }
    catch (DecodeException $exception) {
      $this->assertStringContainsString('not valid XML', $exception->getMessage());
    }

    $xxe = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>';
    try {
      $data = $decoder->decode($xxe, 'application/xml');
      $this->assertStringNotContainsString('root:', json_encode($data, JSON_THROW_ON_ERROR));
    }
    catch (DecodeException) {
      // Rejecting it is just as good.
      $this->addToAssertionCount(1);
    }
  }

  /**
   * CSV rows become associative arrays of strings.
   */
  public function testCsv(): void {
    $csv = "id,label,notes\n1,ACME,\"two\nlines\"\n\n2,\"Glob,ex\",\n";

    $this->assertSame([
      ['id' => '1', 'label' => 'ACME', 'notes' => "two\nlines"],
      ['id' => '2', 'label' => 'Glob,ex', 'notes' => ''],
    ], (new ResponseDecoder())->decode($csv, 'text/csv'));
  }

  /**
   * A different delimiter and a byte order mark are handled.
   */
  public function testCsvDelimiterAndByteOrderMark(): void {
    $csv = "\xEF\xBB\xBFid;label\n1;ACME\n";

    $this->assertSame(
      [['id' => '1', 'label' => 'ACME']],
      (new ResponseDecoder())->decode($csv, 'text/csv', 'csv', ';'),
    );
    $this->assertSame([], (new ResponseDecoder())->decode('', 'text/csv'));
  }

  /**
   * Broken CSV is reported with the line number or the problem.
   */
  #[DataProvider('invalidCsvProvider')]
  public function testInvalidCsv(string $csv, string $expected_message): void {
    $this->expectException(DecodeException::class);
    $this->expectExceptionMessage($expected_message);
    (new ResponseDecoder())->decode($csv, 'text/csv');
  }

  /**
   * Data provider.
   *
   * @return array<string, array{string, string}>
   *   The CSV and the expected message.
   */
  public static function invalidCsvProvider(): array {
    return [
      'a row with too few columns' => ["id,label\n1\n", 'CSV line 2 has 1 columns, the header has 2'],
      'duplicate column names' => ["id,id\n1,2\n", 'duplicate column names'],
      'an empty column name' => ["id,\n1,2\n", 'empty column name'],
    ];
  }

  /**
   * The format is detected from the content type, then from the body.
   */
  public function testDetection(): void {
    $decoder = new ResponseDecoder();

    $this->assertSame(['a' => 1], $decoder->decode('{"a":1}', 'application/vnd.api+json; charset=utf-8'));
    $this->assertSame(['a' => 'x'], $decoder->decode('<a>x</a>', 'application/atom+xml'));
    // No usable content type: look at the first character.
    $this->assertSame(['a' => 1], $decoder->decode("  \n{\"a\":1}", 'text/plain'));
    $this->assertSame(['a' => 'x'], $decoder->decode('<a>x</a>', NULL));
    // An explicit format wins over the content type.
    $this->assertSame(['a' => 1], $decoder->decode('{"a":1}', 'text/plain', 'json'));
  }

  /**
   * A body that cannot be recognised needs an explicit format.
   */
  public function testUndetectableFormat(): void {
    $this->expectException(DecodeException::class);
    $this->expectExceptionMessage('set the format of the source');
    (new ResponseDecoder())->decode('id,label', 'text/plain');
  }

}
