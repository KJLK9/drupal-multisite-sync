<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Mapper;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportMapper;
use Drupal\import_engine\Mapper\MapperPluginBase;
use Drupal\import_engine\Target\TargetField;

/**
 * Maps a source value to a plain text field.
 *
 * Settings: trim, remove surrounding white space; empty_as_null, treat an
 * empty text as no value.
 */
#[ImportMapper(
  id: 'string',
  label: new TranslatableMarkup('Text'),
  field_types: ['string', 'string_long', 'list_string', 'email', 'uri', 'telephone'],
  sources: ['value' => TRUE],
  description: new TranslatableMarkup('Plain text.'),
)]
final class StringMapper extends MapperPluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default settings.
   */
  public function defaultConfiguration(): array {
    return ['trim' => TRUE, 'empty_as_null' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function map(array $sources, TargetField $field): mixed {
    $text = $this->text($sources['value'] ?? NULL);
    if ($text !== NULL && $this->configuration['trim']) {
      $text = trim($text);
    }
    return $text === '' && $this->configuration['empty_as_null'] ? NULL : $text;
  }

}
