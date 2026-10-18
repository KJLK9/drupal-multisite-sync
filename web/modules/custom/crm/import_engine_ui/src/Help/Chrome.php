<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Help;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;

/**
 * The parts that the screens of the engine have in common, as markup.
 *
 * A line that says which item a page is about, and a panel at its side that
 * says in plain words what it shows. The text is given already translated; the
 * markup is made here, so that a form and a controller build the same thing.
 */
final class Chrome {

  /**
   * Builds the line above a page: the name, labels, and a few facts.
   *
   * @param string $name
   *   The name of the item.
   * @param list<array{string, string}> $pills
   *   Labels as pairs of a style (on, off, template, new, failed, live or a
   *   name of your own) and the text.
   * @param string $meta
   *   A line of facts, or an empty string.
   * @param string $hint
   *   A short hint on the right, or an empty string.
   *
   * @return array<string, mixed>
   *   The element.
   */
  public static function header(string $name, array $pills, string $meta = '', string $hint = ''): array {
    $markup = '<div class="import-wizard-header">'
      . '<span class="import-wizard-header__name">' . Html::escape($name) . '</span>';
    foreach ($pills as [$style, $text]) {
      $markup .= '<span class="import-pill import-pill--' . Html::escape($style) . '">' . Html::escape($text) . '</span>';
    }
    if ($meta !== '') {
      $markup .= '<span class="import-wizard-header__meta">' . Html::escape($meta) . '</span>';
    }
    if ($hint !== '') {
      $markup .= '<span class="import-wizard-header__hint">' . Html::escape($hint) . '</span>';
    }
    return ['#markup' => Markup::create($markup . '</div>'), '#weight' => -30];
  }

  /**
   * Builds the panel at the side of a page that says what it shows.
   *
   * @param string $id
   *   The HTML ID of the panel.
   * @param string $title
   *   The title.
   * @param string $lead
   *   A line under the title.
   * @param list<string> $sentences
   *   The sentences, made by t() so that what was put in them is escaped.
   *
   * @return array<string, mixed>
   *   The element.
   */
  public static function panel(string $id, string $title, string $lead, array $sentences): array {
    return [
      '#type' => 'container',
      '#weight' => 90,
      '#prefix' => '<aside id="' . Html::escape($id) . '" class="import-wizard-panel">',
      '#suffix' => '</aside>',
      'box' => [
        '#markup' => '<h3 class="import-wizard-panel__title">' . Html::escape($title) . '</h3>'
        . '<p class="import-wizard-panel__lead">' . Html::escape($lead) . '</p>',
        'list' => [
          '#theme' => 'item_list',
          '#items' => array_map(
            static fn (string $line): array => ['#markup' => nl2br($line)],
            $sentences,
          ),
        ],
      ],
    ];
  }

}
