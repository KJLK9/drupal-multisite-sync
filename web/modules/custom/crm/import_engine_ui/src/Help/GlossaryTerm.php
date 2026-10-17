<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Help;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * One word of the engine, with what it means in plain language.
 */
final readonly class GlossaryTerm {

  /**
   * Constructs a term.
   *
   * @param string $id
   *   The ID, used as the anchor on the help page.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $term
   *   The word as the engine uses it (the jargon).
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $meaning
   *   What it means, in a sentence or two, without other jargon.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $example
   *   An example of a value, or NULL.
   * @param list<string> $fields
   *   The names of the form elements that get an info icon with this term.
   * @param bool $onHelpPage
   *   Whether the term is listed in the glossary of the help page.
   */
  public function __construct(
    public string $id,
    public TranslatableMarkup $term,
    public TranslatableMarkup $meaning,
    public ?TranslatableMarkup $example = NULL,
    public array $fields = [],
    public bool $onHelpPage = TRUE,
  ) {
  }

}
