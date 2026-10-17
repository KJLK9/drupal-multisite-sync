<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Help;

use Drupal\Component\Utility\Html;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * The words of the engine in plain language, for info icons and the help page.
 *
 * The jargon stays on the screen, since people who know it find it faster; an
 * icon next to it says what it means. All text goes through t(), so it follows
 * the languages that are enabled; English is the source.
 */
class Glossary {

  use StringTranslationTrait;

  /**
   * The terms, by ID.
   *
   * @var array<string, \Drupal\import_engine_ui\Help\GlossaryTerm>|null
   */
  private ?array $terms = NULL;

  /**
   * Returns all terms, by ID.
   *
   * @return array<string, \Drupal\import_engine_ui\Help\GlossaryTerm>
   *   The terms.
   */
  public function terms(): array {
    return $this->terms ??= $this->define();
  }

  /**
   * Returns the term for the form element with a name, if there is one.
   *
   * @param string $name
   *   The name of the element (the last of its parents).
   */
  public function forField(string $name): ?GlossaryTerm {
    foreach ($this->terms() as $term) {
      if (in_array($name, $term->fields, TRUE)) {
        return $term;
      }
    }
    return NULL;
  }

  /**
   * Returns a label with an info icon after it.
   *
   * The icon is a button, so that it can be reached and read with a keyboard
   * and a screen reader; the text shows on hover and on focus.
   *
   * @param string|\Stringable $label
   *   The label.
   * @param \Drupal\import_engine_ui\Help\GlossaryTerm $term
   *   The term.
   */
  public function labelWithIcon(string|\Stringable $label, GlossaryTerm $term): MarkupInterface {
    $tip = Html::escape((string) $term->meaning);
    if ($term->example !== NULL) {
      $tip .= ' <em>' . Html::escape((string) $this->t('Example:')) . '</em> <code>' . Html::escape((string) $term->example) . '</code>';
    }
    $id = Html::getUniqueId('info-' . $term->id);
    $more = Html::escape((string) $this->t('More about this'));
    $url = Url::fromRoute('import_engine_ui.help', [], ['fragment' => $term->id])->toString();
    $name = Html::escape((string) $this->t('What is @term?', ['@term' => (string) $term->term]));
    $markup = Markup::create(
      Html::escape((string) $label)
      . ' <span class="import-info">'
      . '<button type="button" class="import-info__icon" aria-label="' . $name . '" aria-describedby="' . $id . '">i</button>'
      . '<span class="import-info__tip" role="tooltip" id="' . $id . '">' . $tip
      . ' <a href="' . Html::escape((string) $url) . '">' . $more . '</a></span>'
      . '</span>'
    );
    assert($markup instanceof MarkupInterface);
    return $markup;
  }

  /**
   * Builds the terms.
   *
   * @return array<string, \Drupal\import_engine_ui\Help\GlossaryTerm>
   *   The terms, by ID.
   */
  private function define(): array {
    $list = [
      new GlossaryTerm(
        'import',
        $this->t('Import'),
        $this->t('A saved recipe: where the data comes from, which item becomes which content, and how to behave when something goes wrong. It does nothing until someone starts it or it is scheduled.'),
      ),
      new GlossaryTerm(
        'run',
        $this->t('Run'),
        $this->t('One go of an import, from start to end. Every run keeps its own list of items and a log of what happened.'),
      ),
      new GlossaryTerm(
        'item',
        $this->t('Item'),
        $this->t('One record in the source, for example one customer. Each item becomes one piece of content.'),
      ),
      new GlossaryTerm(
        'source',
        $this->t('Source'),
        $this->t('The place the data is read from, usually the address of an API.'),
        $this->t('https://example.com/api/customers'),
        ['url'],
      ),
      new GlossaryTerm(
        'connection',
        $this->t('Connection'),
        $this->t('An address with its login, saved once so that several imports can share it. Change it in one place and all of them follow.'),
        NULL,
        ['connection'],
      ),
      new GlossaryTerm(
        'items_path',
        $this->t('Items path'),
        $this->t('Where in the answer of the source the list of items is. Leave it empty when the answer is the list itself.'),
        $this->t('data.items'),
        ['items_path'],
      ),
      new GlossaryTerm(
        'pagination',
        $this->t('Pagination'),
        $this->t('Large lists come in pages. This says how to ask for the next page, so that every item is read.'),
        NULL,
        [
          'pagination',
          'page_size',
          'page_param',
          'size_param',
          'offset_param',
          'limit_param',
          'next_path',
          'total_path',
          'last_page_path',
          'first_page',
          'stop_on_short_page',
        ],
      ),
      new GlossaryTerm(
        'authentication',
        $this->t('Authentication'),
        $this->t('How the import proves to the source that it may read. The secret itself is never typed here: only the name of the setting that holds it.'),
        NULL,
        ['authentication', 'header', 'env_var'],
      ),
      new GlossaryTerm(
        'source_key',
        $this->t('Key of an item'),
        $this->t('The value that tells items apart, like a customer number. It is how a second run knows an item is the same one: it is updated, not added twice.'),
        $this->t('customer_code'),
        ['source_key'],
      ),
      new GlossaryTerm(
        'dotted_path',
        $this->t('Dotted path'),
        $this->t('The way to a value inside an item, with dots between the steps.'),
        $this->t('address.city'),
        [],
      ),
      new GlossaryTerm(
        'mapping',
        $this->t('Mapping'),
        $this->t('Which value of the source goes into which field of the content. One row per field; a field without a source is left alone.'),
        NULL,
        [],
      ),
      new GlossaryTerm(
        'mapper',
        $this->t('Mapper'),
        $this->t('How a value is turned into what the field needs: as text, as a number, as a date, as a reference to other content.'),
        NULL,
        [],
      ),
      new GlossaryTerm(
        'validator',
        $this->t('Check (validator)'),
        $this->t('A test on the value before it is saved, such as "is this an email address". Optional.'),
        NULL,
        [],
      ),
      new GlossaryTerm(
        'on_invalid',
        $this->t('If it is not valid'),
        $this->t('What to do with a value that fails its check: fail the whole item, leave only that field empty, or skip the item. Messages never repeat the value itself.'),
        NULL,
        [],
      ),
      new GlossaryTerm(
        'when_missing',
        $this->t('When the target is missing'),
        $this->t('For a reference to other content that is not there (yet): wait and try again later, fail the item, or ignore it and leave the field empty.'),
        NULL,
        [],
      ),
      new GlossaryTerm(
        'delete_policy',
        $this->t('Items that disappear'),
        $this->t('What happens to content whose item is no longer in the source: unpublish, delete, or leave it.'),
        NULL,
        ['delete_policy'],
      ),
      new GlossaryTerm(
        'delete_threshold',
        $this->t('Safety limit for disappearing items'),
        $this->t('A source that suddenly returns almost nothing is probably broken, not empty. Above this percentage nothing is removed and the run reports an error.'),
        NULL,
        ['delete_threshold_percent'],
      ),
      new GlossaryTerm(
        'retries',
        $this->t('Retries (backoff)'),
        $this->t('An item that fails for a reason that may pass (a slow server, a missing reference) is tried again after a wait. Backoff means the wait grows with every attempt.'),
        NULL,
        ['resilience', 'max_attempts', 'backoff', 'retry_delay'],
      ),
      new GlossaryTerm(
        'dead_letter',
        $this->t('Dead letter queue'),
        $this->t('Where items go that ran out of attempts, with their data, so that a person can fix them and try again.'),
        NULL,
        ['dlq_enabled'],
      ),
      new GlossaryTerm(
        'breaker',
        $this->t('Circuit breaker'),
        $this->t('When a source keeps failing, the import stops calling it for a while instead of making it worse. After the pause it tries again.'),
        NULL,
        ['breaker', 'threshold', 'cooldown'],
      ),
      new GlossaryTerm(
        'pool',
        $this->t('Worker pool'),
        $this->t('A group of background workers. Putting a heavy import in its own pool keeps it from slowing down the others.'),
        NULL,
        ['pool'],
      ),
      new GlossaryTerm(
        'reports',
        $this->t('Reports'),
        $this->t('A message after a run about how it went, for example in the log or by email.'),
        NULL,
        ['reporters'],
      ),
      new GlossaryTerm(
        'schedule',
        $this->t('Schedule'),
        $this->t('Runs the import by itself, every so many minutes from a time of day (UTC). Never more often than every 15 minutes.'),
        NULL,
        ['schedule'],
      ),
      new GlossaryTerm(
        'enabled',
        $this->t('Enabled'),
        $this->t('Only an enabled import can be started or runs on its schedule. A new import is off until you switch it on.'),
        NULL,
        ['status'],
      ),
      new GlossaryTerm(
        'run_set',
        $this->t('Run set'),
        $this->t('A named list of imports that run one after the other, for example first the customers and then the orders that refer to them.'),
      ),
      new GlossaryTerm(
        'regex',
        $this->t('Regular expression'),
        $this->t('A pattern a text must fit. It is written between slashes.'),
        $this->t('/^[A-Z]{2}[0-9]{4}$/'),
        ['pattern'],
      ),
    ];
    $terms = [];
    foreach ($list as $term) {
      $terms[$term->id] = $term;
    }
    return $terms;
  }

}
