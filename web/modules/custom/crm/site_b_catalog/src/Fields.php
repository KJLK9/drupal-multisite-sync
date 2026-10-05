<?php

declare(strict_types=1);

namespace Drupal\site_b_catalog;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The kinds of base field the catalog entities are made of.
 *
 * Every field says how it is edited and shown, so the entities need no
 * display configuration to be usable in the interface.
 */
final class Fields {

  /**
   * A line of text.
   */
  public static function text(TranslatableMarkup $label, int $weight, bool $required = FALSE, int $length = 255): BaseFieldDefinition {
    return BaseFieldDefinition::create('string')
      ->setLabel($label)
      ->setRequired($required)
      ->setSetting('max_length', $length)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => $weight])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => $weight])
      ->setDisplayConfigurable('view', TRUE);
  }

  /**
   * Formatted text.
   */
  public static function longText(TranslatableMarkup $label, int $weight): BaseFieldDefinition {
    return BaseFieldDefinition::create('text_long')
      ->setLabel($label)
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => $weight])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'text_default', 'weight' => $weight])
      ->setDisplayConfigurable('view', TRUE);
  }

  /**
   * An amount with a currency.
   */
  public static function money(TranslatableMarkup $label, int $weight, bool $required = FALSE): BaseFieldDefinition {
    return BaseFieldDefinition::create('money_field')
      ->setLabel($label)
      ->setRequired($required)
      ->setDisplayOptions('form', ['type' => 'money_field', 'weight' => $weight])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'money_field', 'weight' => $weight])
      ->setDisplayConfigurable('view', TRUE);
  }

  /**
   * A reference to an entity of another catalog type.
   */
  public static function reference(TranslatableMarkup $label, string $targetType, int $weight): BaseFieldDefinition {
    return BaseFieldDefinition::create('entity_reference')
      ->setLabel($label)
      ->setRequired(TRUE)
      ->setSetting('target_type', $targetType)
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => $weight])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => $weight])
      ->setDisplayConfigurable('view', TRUE);
  }

  /**
   * When the entity was created.
   */
  public static function created(): BaseFieldDefinition {
    return BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'timestamp', 'weight' => 90])
      ->setDisplayConfigurable('view', TRUE);
  }

  /**
   * When the entity was last changed.
   */
  public static function changed(): BaseFieldDefinition {
    return BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'timestamp', 'weight' => 91])
      ->setDisplayConfigurable('view', TRUE);
  }

}
