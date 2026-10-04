<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Mapper;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportMapper;
use Drupal\import_engine\Mapper\MapperPluginBase;
use Drupal\import_engine\Mapper\MappingException;
use Drupal\import_engine\Target\TargetField;

/**
 * Maps a source value to an integer, a decimal or a float field.
 *
 * The value must be a number: a number, or text that is one. A whole number
 * field refuses a value with decimals instead of rounding it.
 */
#[ImportMapper(
  id: 'number',
  label: new TranslatableMarkup('Number'),
  field_types: ['integer', 'decimal', 'float'],
  sources: ['value' => TRUE],
  description: new TranslatableMarkup('A whole number, a decimal or a float.'),
)]
final class NumberMapper extends MapperPluginBase {

  /**
   * {@inheritdoc}
   */
  public function map(array $sources, TargetField $field): mixed {
    $text = $this->text($sources['value'] ?? NULL);
    if ($text === NULL || trim($text) === '') {
      return NULL;
    }
    $text = trim($text);
    if (!is_numeric($text)) {
      throw new MappingException(sprintf('"%s" is not a number.', $text));
    }
    return match ($field->type) {
      'integer' => $this->integer($text),
      'float' => (float) $text,
      // A decimal keeps the exact text, so no precision is lost.
      default => $text,
    };
  }

  /**
   * Converts a numeric text to an integer, refusing decimals.
   */
  private function integer(string $text): int {
    if (preg_match('/^[+-]?\d+$/', $text) === 1) {
      return (int) $text;
    }
    // Something like "3.0" or "1e3" is whole, "3.5" is not.
    $number = (float) $text;
    if (floor($number) !== $number) {
      throw new MappingException(sprintf('"%s" is not a whole number.', $text));
    }
    return (int) $number;
  }

}
