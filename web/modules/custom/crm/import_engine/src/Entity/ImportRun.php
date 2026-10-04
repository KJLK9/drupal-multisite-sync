<?php

declare(strict_types=1);

namespace Drupal\import_engine\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Run\ImportRunAccessControlHandler;
use Drupal\import_engine\Run\ImportRunInterface;
use Drupal\import_engine\Run\ImportRunStorageSchema;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\views\EntityViewsData;

/**
 * Defines the import run entity.
 *
 * There are few runs and they are looked at in the interface, so a content
 * entity fits. Items, pages and events are plain tables: there are many.
 */
#[ContentEntityType(
  id: 'import_run',
  label: new TranslatableMarkup('Import run'),
  label_collection: new TranslatableMarkup('Import runs'),
  label_singular: new TranslatableMarkup('import run'),
  label_plural: new TranslatableMarkup('import runs'),
  entity_keys: [
    'id' => 'id',
  ],
  handlers: [
    'access' => ImportRunAccessControlHandler::class,
    'storage_schema' => ImportRunStorageSchema::class,
    'views_data' => EntityViewsData::class,
  ],
  admin_permission: 'administer import runs',
  base_table: 'import_run',
  label_count: [
    'singular' => '@count import run',
    'plural' => '@count import runs',
  ],
)]
class ImportRun extends ContentEntityBase implements ImportRunInterface {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['definition_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Import definition'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64);

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32)
      ->setDefaultValue(RunStatus::Queued->value)
      ->addPropertyConstraints('value', ['Choice' => ['callback' => [RunStatus::class, 'values']]]);

    $fields['trigger'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Started by'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 16)
      ->addPropertyConstraints('value', ['Choice' => ['callback' => [Trigger::class, 'values']]]);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('User'))
      ->setDescription(new TranslatableMarkup('The user who started the run, if a person did.'))
      ->setSetting('target_type', 'user');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Queued'));

    $fields['started'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Started'));

    $fields['finished'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Finished'));

    $fields['extract_complete'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('All pages fetched'))
      ->setDefaultValue(FALSE);

    $fields['cursor'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Extraction position'));

    $fields['pages_read'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Pages read'))
      ->setDefaultValue(0);

    $labels = [
      'items_extracted' => new TranslatableMarkup('Items extracted'),
      'created' => new TranslatableMarkup('Created'),
      'updated' => new TranslatableMarkup('Updated'),
      'unchanged' => new TranslatableMarkup('Unchanged'),
      'skipped' => new TranslatableMarkup('Skipped'),
      'failed' => new TranslatableMarkup('Failed'),
      'dead' => new TranslatableMarkup('Dead'),
      'deleted' => new TranslatableMarkup('Deleted'),
    ];
    foreach (self::COUNTERS as $counter) {
      $fields[$counter] = BaseFieldDefinition::create('integer')
        ->setLabel($labels[$counter])
        ->setDefaultValue(0);
    }

    $fields['summary'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Summary'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitionId(): string {
    return (string) $this->get('definition_id')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): RunStatus {
    return RunStatus::from((string) $this->get('status')->value);
  }

  /**
   * {@inheritdoc}
   */
  public function transitionTo(RunStatus $status, ?int $now = NULL): static {
    $current = $this->getStatus();
    if (!$current->canMoveTo($status)) {
      throw new \LogicException(sprintf('A run cannot move from "%s" to "%s".', $current->value, $status->value));
    }
    $now ??= \Drupal::time()->getRequestTime();
    $this->set('status', $status->value);
    if ($status === RunStatus::Extracting) {
      $this->set('started', $now);
    }
    if ($status->isFinal()) {
      $this->set('finished', $now);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getTrigger(): Trigger {
    return Trigger::from((string) $this->get('trigger')->value);
  }

  /**
   * {@inheritdoc}
   */
  public function isExtractComplete(): bool {
    return (bool) $this->get('extract_complete')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setExtractComplete(bool $complete): static {
    $this->set('extract_complete', $complete);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCursor(): ?string {
    $cursor = $this->get('cursor')->value;
    return $cursor === NULL || $cursor === '' ? NULL : (string) $cursor;
  }

  /**
   * {@inheritdoc}
   */
  public function setCursor(?string $cursor): static {
    $this->set('cursor', $cursor);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPagesRead(): int {
    return (int) $this->get('pages_read')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setPagesRead(int $pages): static {
    $this->set('pages_read', $pages);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCounters(): array {
    $counters = [];
    foreach (self::COUNTERS as $counter) {
      $counters[$counter] = (int) $this->get($counter)->value;
    }
    return $counters;
  }

  /**
   * {@inheritdoc}
   */
  public function setCounters(array $counters): static {
    foreach ($counters as $name => $value) {
      if (!in_array($name, self::COUNTERS, TRUE)) {
        throw new \InvalidArgumentException(sprintf('"%s" is not a run counter.', $name));
      }
      $this->set($name, $value);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary(): string {
    return (string) $this->get('summary')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setSummary(string $summary): static {
    $this->set('summary', $summary);
    return $this;
  }

}
