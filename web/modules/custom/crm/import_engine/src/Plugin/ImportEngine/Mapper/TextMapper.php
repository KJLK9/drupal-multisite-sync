<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Mapper;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportMapper;
use Drupal\import_engine\Mapper\MapperPluginBase;
use Drupal\import_engine\Target\TargetField;

/**
 * Maps a source value to a formatted text field.
 *
 * Setting: format, the text format of the imported text. Anything that is not
 * plain text should be given a format that filters it safely.
 */
#[ImportMapper(
  id: 'text',
  label: new TranslatableMarkup('Formatted text'),
  field_types: ['text', 'text_long', 'text_with_summary'],
  sources: ['value' => TRUE, 'summary' => FALSE],
  description: new TranslatableMarkup('Text with a text format, and for some fields a summary.'),
)]
final class TextMapper extends MapperPluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default settings.
   */
  public function defaultConfiguration(): array {
    return ['format' => 'plain_text'];
  }

  /**
   * {@inheritdoc}
   */
  public function map(array $sources, TargetField $field): mixed {
    $value = $this->text($sources['value'] ?? NULL);
    if ($value === NULL || $value === '') {
      return NULL;
    }
    $result = ['value' => $value, 'format' => (string) $this->configuration['format']];
    $summary = $this->text($sources['summary'] ?? NULL);
    if ($field->type === 'text_with_summary' && $summary !== NULL) {
      $result['summary'] = $summary;
    }
    return $result;
  }

}
