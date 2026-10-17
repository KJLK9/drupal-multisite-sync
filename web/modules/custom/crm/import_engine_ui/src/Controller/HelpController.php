<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Url;
use Drupal\import_engine_ui\Help\Glossary;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The help page: the big picture, a first import, and the words explained.
 */
class HelpController extends ControllerBase {

  use AutowireTrait;

  /**
   * Constructs the controller.
   */
  public function __construct(
    #[Autowire(service: 'import_engine_ui.glossary')]
    protected Glossary $glossary,
  ) {
  }

  /**
   * Builds the page.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(): array {
    $flow = [
      [$this->t('Source'), $this->t('Where the data is. An API, with a login if needed.')],
      [$this->t('Import'), $this->t('The recipe: what to read and how to turn it into content.')],
      [$this->t('Run'), $this->t('One go of the recipe. Start it by hand, or let a schedule do it.')],
      [$this->t('Content'), $this->t('Every item of the source becomes a piece of content, and is updated next time.')],
    ];
    $flowItems = '';
    foreach ($flow as [$title, $text]) {
      $flowItems .= '<li><strong>' . $title . '</strong>' . $text . '</li>';
    }

    $steps = [
      $this->t('Open <a href=":new">Add import</a> and give it a name.', [':new' => Url::fromRoute('import_engine_ui.definition_add')->toString()]),
      $this->t('Step 1 and 2: say where the data is and, if needed, how to log in and how to ask for the next page. Use <em>Try the source</em> to see that it works.'),
      $this->t('Step 3: choose what identifies an item and what content it becomes.'),
      $this->t('Step 4: for each field of the content, give the path of the value in the source. <em>Suggest a mapping</em> fills in what it recognizes.'),
      $this->t('Step 5: decide what happens when things go wrong, and save. A new import is switched off: when you are happy, switch it on.'),
      $this->t('Start a run from the list of imports, and open the run to see its items and events.'),
    ];

    $glossary = ['#type' => 'html_tag', '#tag' => 'dl'];
    foreach ($this->glossary->terms() as $id => $term) {
      if (!$term->onHelpPage) {
        continue;
      }
      $text = Html::escape((string) $term->meaning);
      if ($term->example !== NULL) {
        $text .= ' ' . $this->t('Example:') . ' <code>' . Html::escape((string) $term->example) . '</code>';
      }
      $glossary[$id] = [
        'term' => [
          '#type' => 'html_tag',
          '#tag' => 'dt',
          '#attributes' => ['id' => $id],
          '#value' => (string) $term->term,
        ],
        'meaning' => ['#type' => 'html_tag', '#tag' => 'dd', '#value' => $text],
      ];
    }

    return [
      '#attached' => ['library' => ['import_engine_ui/wizard']],
      '#prefix' => '<div class="import-help">',
      '#suffix' => '</div>',
      'intro' => ['#markup' => '<p>' . $this->t('The import engine reads items from another system on a schedule or on demand, and keeps content up to date from them. This page explains the words and the steps; the <em>i</em> icons in the forms link here.') . '</p>'],
      'flow_title' => ['#markup' => '<h2>' . $this->t('The big picture') . '</h2>'],
      'flow' => ['#markup' => '<ol class="import-help__flow">' . $flowItems . '</ol>'],
      'first_title' => ['#markup' => '<h2>' . $this->t('Your first import') . '</h2>'],
      'first' => [
        '#theme' => 'item_list',
        '#list_type' => 'ol',
        '#items' => array_map(static fn (mixed $step): array => ['#markup' => $step], $steps),
      ],
      'see_title' => ['#markup' => '<h2>' . $this->t('Where do I see what happened?') . '</h2>'],
      'see' => [
        '#markup' => '<p>' . $this->t('Under <a href=":runs">Import runs</a>: every run with its items and events, and why an item failed. Items that ran out of attempts wait in the <a href=":dead">Dead letter queue</a>, where you can fix and retry them.', [
          ':runs' => Url::fromRoute('import_engine_ui.runs')->toString(),
          ':dead' => Url::fromRoute('import_engine_ui.dead_letter')->toString(),
        ]) . '</p>',
      ],
      'words_title' => ['#markup' => '<h2>' . $this->t('The words') . '</h2>'],
      'words' => $glossary,
    ];
  }

}
