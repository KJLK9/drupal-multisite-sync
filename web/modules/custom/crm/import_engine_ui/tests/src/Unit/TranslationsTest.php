<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine_ui\Unit;

use Drupal\Component\Gettext\PoItem;
use Drupal\Component\Gettext\PoStreamReader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests that the Dutch translation is readable and goes with the code.
 */
#[Group('import_engine_ui')]
class TranslationsTest extends TestCase {

  /**
   * Reads the translation file.
   *
   * @return array<string, mixed>
   *   The items, by source text.
   */
  private function items(): array {
    $reader = new PoStreamReader();
    $reader->setURI(__DIR__ . '/../../../translations/nl.po');
    $reader->open();
    $items = [];
    while (($item = $reader->readItem()) !== NULL) {
      $source = $item->getSource();
      $translation = $item->getTranslation();
      $items[is_array($source) ? implode(PoItem::DELIMITER, $source) : $source] = is_array($translation)
        ? implode(PoItem::DELIMITER, $translation)
        : $translation;
    }
    return $items;
  }

  /**
   * Every text is translated and keeps the placeholders of its source.
   */
  public function testPlaceholdersSurvive(): void {
    $items = $this->items();
    $this->assertGreaterThan(200, count($items));
    foreach ($items as $source => $translation) {
      $source = (string) $source;
      $sources = explode(PoItem::DELIMITER, $source);
      $translations = explode(PoItem::DELIMITER, (string) $translation);
      $this->assertNotSame([], $translations, $source);
      foreach ($sources as $index => $text) {
        $target = $translations[$index] ?? $translations[0];
        $this->assertNotSame('', $target, $source);
        foreach (['/[@:%][a-z_]+/', '/<[^>]+>/'] as $pattern) {
          preg_match_all($pattern, $text, $in);
          preg_match_all($pattern, $target, $out);
          sort($in[0]);
          sort($out[0]);
          $this->assertSame($in[0], $out[0], 'Placeholders or markup differ in: ' . $text);
        }
      }
    }
  }

  /**
   * No text in the file is left over from code that no longer has it.
   */
  public function testEveryTextIsStillInTheCode(): void {
    $code = '';
    $root = dirname(__DIR__, 3) . '/src';
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
    foreach ($files as $file) {
      if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
        $code .= (string) file_get_contents($file->getPathname());
      }
    }
    // Single-quoted PHP strings keep a backslash before ' and \.
    $code = str_replace(["\\'", '\\\\'], ["'", '\\'], $code);
    foreach (array_keys($this->items()) as $source) {
      foreach (explode(PoItem::DELIMITER, (string) $source) as $text) {
        $this->assertStringContainsString($text, $code, 'Not in the code any more: ' . $text);
      }
    }
  }

}
