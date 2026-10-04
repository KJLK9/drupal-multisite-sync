<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Mapper;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportMapper;
use Drupal\import_engine\Mapper\MapperPluginBase;
use Drupal\import_engine\Mapper\MappingException;
use Drupal\import_engine\Target\TargetField;

/**
 * Maps a source value to a boolean field.
 *
 * Settings: true_values and false_values, the texts that stand for yes and no
 * (compared without regard to case); when_empty, what a missing value means:
 * "false", "true" or "fail".
 */
#[ImportMapper(
  id: 'boolean',
  label: new TranslatableMarkup('Yes or no'),
  field_types: ['boolean'],
  sources: ['value' => TRUE],
  description: new TranslatableMarkup('A boolean, from true or false, 1 or 0, yes or no.'),
)]
final class BooleanMapper extends MapperPluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default settings.
   */
  public function defaultConfiguration(): array {
    return [
      'true_values' => ['1', 'true', 'yes', 'y', 'on'],
      'false_values' => ['0', 'false', 'no', 'n', 'off'],
      'when_empty' => 'false',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function map(array $sources, TargetField $field): mixed {
    $text = $this->text($sources['value'] ?? NULL);
    if ($text === NULL || trim($text) === '') {
      return match ($this->configuration['when_empty']) {
        'true' => TRUE,
        'false' => FALSE,
        default => throw new MappingException('There is no value for the yes or no field.'),
      };
    }
    $text = strtolower(trim($text));
    if (in_array($text, array_map('strtolower', $this->configuration['true_values']), TRUE)) {
      return TRUE;
    }
    if (in_array($text, array_map('strtolower', $this->configuration['false_values']), TRUE)) {
      return FALSE;
    }
    throw new MappingException(sprintf('"%s" is neither a yes nor a no value.', $text));
  }

}
