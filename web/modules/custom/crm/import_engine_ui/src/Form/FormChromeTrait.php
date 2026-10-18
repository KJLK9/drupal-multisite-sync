<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\Core\Render\Element;
use Drupal\import_engine_ui\Help\Chrome;

/**
 * What the forms of the engine have in common above and beside their fields.
 *
 * A line that says which item this is, a panel that says in plain words what
 * it does, and the info icons after the words of jargon. The form that uses
 * this has a `$glossary` (\Drupal\import_engine_ui\Help\Glossary), implements
 * \Drupal\Core\Security\TrustedCallbackInterface and adds
 * `$form['#pre_render'][] = [$this, 'addInfoIcons']` (a method, not a closure:
 * the form is serialized into the form cache).
 */
trait FormChromeTrait {

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['addInfoIcons'];
  }

  /**
   * Builds the line above a form: the name, labels, and a few facts.
   *
   * @param string $name
   *   The name of the item.
   * @param list<array{string, string}> $pills
   *   Labels as pairs of a style (on, off, template, new or a name of your
   *   own) and the text.
   * @param string $meta
   *   A line of facts, or an empty string.
   * @param string $hint
   *   A short hint on the right, or an empty string.
   *
   * @return array<string, mixed>
   *   The element.
   */
  protected function formHeader(string $name, array $pills, string $meta = '', string $hint = ''): array {
    return Chrome::header($name, $pills, $meta, $hint);
  }

  /**
   * Builds the panel at the side of a form that says what it does.
   *
   * @param string $id
   *   The HTML ID of the panel.
   * @param string $lead
   *   A line under the title.
   * @param list<string> $sentences
   *   The sentences, made by t() so that what was put in them is escaped.
   *
   * @return array<string, mixed>
   *   The element.
   */
  protected function plainPanel(string $id, string $lead, array $sentences): array {
    return Chrome::panel($id, (string) $this->t('In plain words'), $lead, $sentences);
  }

  /**
   * Puts an info icon after the label of every element that has a term.
   *
   * Done when the form is rendered, so that the labels the checks and the
   * error messages read are the plain ones. An example of the term becomes
   * the placeholder of an empty text field.
   *
   * @param array<mixed> $element
   *   The form, or a part of it.
   *
   * @return array<mixed>
   *   The same, with the icons.
   */
  public function addInfoIcons(array $element): array {
    foreach (Element::children($element) as $key) {
      $child = &$element[$key];
      if (!is_array($child)) {
        continue;
      }
      $parents = (array) ($child['#parents'] ?? [(string) $key]);
      $name = (string) end($parents);
      $term = $this->glossary->forField($name);
      // Some forms (those of the configuration system) are pre-rendered more
      // than once; an element that has its icon is left alone.
      if ($term !== NULL && isset($child['#title']) && empty($child['#import_info_done'])
        && ($child['#title_display'] ?? '') !== 'invisible') {
        $child['#import_info_done'] = TRUE;
        $child['#title'] = $this->glossary->labelWithIcon($child['#title'], $term);
        if ($term->example !== NULL && in_array($child['#type'] ?? '', ['textfield', 'textarea', 'url'], TRUE)
          && empty($child['#default_value']) && !isset($child['#attributes']['placeholder'])) {
          $child['#attributes']['placeholder'] = (string) $term->example;
        }
      }
      $child = $this->addInfoIcons($child);
    }
    return $element;
  }

}
