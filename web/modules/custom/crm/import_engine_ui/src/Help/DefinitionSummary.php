<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Help;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\DeletePolicy;
use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine\Schedule\ScheduleRules;
use Drupal\import_engine\Validator\OnInvalid;
use Drupal\import_engine\Validator\ValidatorPluginManager;

/**
 * Tells in plain sentences what an import will do.
 *
 * Meant for the last step of the wizard, so that a person who has filled in
 * five steps can read the whole import as a story and see a mistake in it.
 * Values from the import are put in the text as plain strings; the caller
 * escapes them when it prints.
 */
class DefinitionSummary {

  use StringTranslationTrait;

  /**
   * The most mapped fields that are listed one by one.
   */
  private const MAX_FIELDS = 12;

  /**
   * Constructs the summary.
   */
  public function __construct(protected ValidatorPluginManager $validators) {
  }

  /**
   * Returns the sentences.
   *
   * @param \Drupal\import_engine\ImportDefinitionInterface $definition
   *   The import, complete or not.
   *
   * @return list<string>
   *   Sentences, in the order of the steps.
   */
  public function sentences(ImportDefinitionInterface $definition): array {
    // The import may be half filled in: what cannot be told is left out.
    $parts = [
      fn (): array => [$this->source($definition)],
      fn (): array => [$this->key($definition)],
      fn (): array => [$this->target($definition)],
      fn (): array => $this->mapping($definition),
      fn (): array => [$this->disappearing($definition)],
      fn (): array => [$this->retries($definition)],
      fn (): array => [$this->breaker($definition)],
      fn (): array => [$this->schedule($definition)],
      fn (): array => [$this->state($definition)],
    ];
    $lines = [];
    foreach ($parts as $part) {
      try {
        array_push($lines, ...$part());
      }
      catch (\Throwable) {
        continue;
      }
    }
    return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
  }

  /**
   * Says where the data comes from.
   */
  private function source(ImportDefinitionInterface $definition): string {
    $configuration = (array) ($definition->getSource()['configuration'] ?? []);
    $connection = $definition->getConnection();
    $url = (string) ($configuration['url'] ?? '');
    $where = match (TRUE) {
      $connection !== NULL && $connection !== '' => $this->t('the connection "@name"', ['@name' => $connection]),
      $url !== '' => $this->withoutSecrets($url),
      default => $this->t('a @plugin source', ['@plugin' => $definition->getSource()['plugin']]),
    };
    $path = (string) ($configuration['items_path'] ?? '');
    return (string) ($path === ''
      ? $this->t('Reads items from @where.', ['@where' => $where])
      : $this->t('Reads items from @where, from "@path" in the answer.', ['@where' => $where, '@path' => $path]));
  }

  /**
   * Shortens an address to its scheme and host.
   *
   * A path or query may hold a key (?api_key=...), and a summary is read by
   * more people than the secret was meant for.
   */
  private function withoutSecrets(string $url): string {
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['host'])) {
      return (string) $this->t('an address');
    }
    return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/…';
  }

  /**
   * Says how an item is recognised.
   */
  private function key(ImportDefinitionInterface $definition): string {
    $keys = $definition->getSourceKey();
    if ($keys === []) {
      return '';
    }
    return (string) $this->t('An item is recognised by @keys. A second run updates the item it finds instead of adding it again.', [
      '@keys' => implode(' + ', $keys),
    ]);
  }

  /**
   * Says what an item becomes.
   */
  private function target(ImportDefinitionInterface $definition): string {
    $target = $definition->getTarget();
    $configuration = (array) ($target['configuration'] ?? []);
    if (empty($configuration['entity_type'])) {
      return '';
    }
    return (string) $this->t('Each item becomes one @type of the kind "@bundle".', [
      '@type' => (string) $configuration['entity_type'],
      '@bundle' => (string) ($configuration['bundle'] ?? ''),
    ]);
  }

  /**
   * Says which fields are filled from where, and what is checked.
   *
   * @return list<string>
   *   Sentences.
   */
  private function mapping(ImportDefinitionInterface $definition): array {
    $rows = $definition->getMapping();
    if ($rows === []) {
      return [(string) $this->t('No field is filled yet: add a source to at least one field in step 4.')];
    }
    $lines = [(string) $this->formatPlural(count($rows), '1 field is filled:', '@count fields are filled:')];
    foreach (array_slice($rows, 0, self::MAX_FIELDS) as $row) {
      $sources = implode(', ', $row['mapper']['sources']);
      $line = (string) $this->t('@field from @sources', ['@field' => $row['target_field'], '@sources' => $sources]);
      foreach ($row['validators'] ?? [] as $check) {
        $label = (string) ($this->validators->getDefinition($check['plugin'], FALSE)['label'] ?? $check['plugin']);
        $line .= ' ' . $this->t('(checked: @check; if not valid: @action)', [
          '@check' => $label,
          '@action' => $this->onInvalid($row['on_invalid'] ?? ''),
        ]);
      }
      $lines[] = '• ' . $line;
    }
    if (count($rows) > self::MAX_FIELDS) {
      $lines[] = '• ' . $this->t('and @count more', ['@count' => (string) (count($rows) - self::MAX_FIELDS)]);
    }
    return [implode("\n", $lines)];
  }

  /**
   * Says what happens to an item that fails its check.
   */
  private function onInvalid(string $value): TranslatableMarkup {
    return match (OnInvalid::tryFrom($value) ?? OnInvalid::Fail) {
      OnInvalid::Fail => $this->t('the item fails'),
      OnInvalid::Empty => $this->t('the field is left empty'),
      OnInvalid::SkipItem => $this->t('the item is skipped'),
    };
  }

  /**
   * Says what happens to items that are gone from the source.
   */
  private function disappearing(ImportDefinitionInterface $definition): string {
    $limit = $definition->getDeleteThresholdPercent();
    $what = match ($definition->getDeletePolicy()) {
      DeletePolicy::Unpublish => $this->t('the content is unpublished (and published again if the item comes back)'),
      DeletePolicy::Delete => $this->t('the content is deleted'),
      DeletePolicy::Ignore => $this->t('the content is left as it is'),
    };
    return (string) $this->t('When an item is no longer in the source, @what. If more than @limit% of the items disappear in one run, nothing is changed and the run reports an error.', [
      '@what' => $what,
      '@limit' => (string) $limit,
    ]);
  }

  /**
   * Says what happens when an item fails.
   */
  private function retries(ImportDefinitionInterface $definition): string {
    return (string) ($definition->isDlqEnabled()
      ? $this->t('An item that fails for a reason that may pass is tried up to @n times, then waits in the dead letter queue for a person to fix it.', ['@n' => (string) $definition->getMaxAttempts()])
      : $this->t('An item that fails for a reason that may pass is tried up to @n times, then counted as failed.', ['@n' => (string) $definition->getMaxAttempts()]));
  }

  /**
   * Says what happens when the source is down.
   */
  private function breaker(ImportDefinitionInterface $definition): string {
    $breaker = $definition->getBreaker();
    if (!$breaker['enabled']) {
      return (string) $this->t('The source is called again however often it fails.');
    }
    return (string) $this->t('After @n failures in a row the source is left alone for @s seconds.', [
      '@n' => (string) $breaker['threshold'],
      '@s' => (string) $breaker['cooldown'],
    ]);
  }

  /**
   * Says when the import runs.
   */
  private function schedule(ImportDefinitionInterface $definition): string {
    $when = ScheduleRules::describe($definition->getSchedule());
    return (string) ($when === ''
      ? $this->t('It does not run by itself: start it from the list of imports.')
      : $this->t('It runs by itself @when.', ['@when' => $when]));
  }

  /**
   * Says whether the import is on.
   */
  private function state(ImportDefinitionInterface $definition): string {
    return (string) match (TRUE) {
      $definition->isTemplate() => $this->t('This is a template: it never runs, it is only copied.'),
      $definition->status() => $this->t('It is switched on.'),
      default => $this->t('It is switched off. Nothing runs until you switch it on.'),
    };
  }

}
