<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Mapper;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportMapper;
use Drupal\import_engine\Mapper\MapperPluginBase;
use Drupal\import_engine\Mapper\MappingException;
use Drupal\import_engine\Target\TargetField;

/**
 * Maps a source value to a timestamp field.
 *
 * Accepts a Unix timestamp (a number or text of digits) or a date and time as
 * text. Setting: format, a PHP date format that the text must follow; empty to
 * accept anything PHP understands, such as ISO 8601.
 */
#[ImportMapper(
  id: 'timestamp',
  label: new TranslatableMarkup('Date and time'),
  field_types: ['timestamp', 'created', 'changed'],
  sources: ['value' => TRUE],
  description: new TranslatableMarkup('A date and time, as a timestamp or as text.'),
)]
final class TimestampMapper extends MapperPluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default settings.
   */
  public function defaultConfiguration(): array {
    return ['format' => ''];
  }

  /**
   * {@inheritdoc}
   */
  public function map(array $sources, TargetField $field): mixed {
    $text = $this->text($sources['value'] ?? NULL);
    if ($text === NULL || trim($text) === '') {
      return NULL;
    }
    $text = trim($text);
    if (ctype_digit($text)) {
      return (int) $text;
    }
    try {
      $format = (string) $this->configuration['format'];
      $date = $format === '' ? new \DateTimeImmutable($text) : \DateTimeImmutable::createFromFormat('!' . $format, $text);
    }
    catch (\Exception $exception) {
      throw new MappingException(sprintf('"%s" is not a date and time.', $text), 0, $exception);
    }
    if ($date === FALSE) {
      throw new MappingException(sprintf('"%s" does not follow the format "%s".', $text, $this->configuration['format']));
    }
    return $date->getTimestamp();
  }

}
