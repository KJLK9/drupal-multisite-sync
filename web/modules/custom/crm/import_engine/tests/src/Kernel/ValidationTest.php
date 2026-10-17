<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\Storage\EventType;
use Drupal\import_engine\Storage\ItemState;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the validators of a mapping row, and what happens to an invalid value.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class ValidationTest extends NodeTestBase {

  /**
   * Builds the values of an import whose code field has validators.
   *
   * @param list<array{plugin: string, settings: array<string, mixed>}> $validators
   *   The validators of the code field.
   * @param string|null $on_invalid
   *   What happens to a value that is not valid; none for the default.
   * @param string $mapper
   *   The mapper of the code field.
   *
   * @return array<string, mixed>
   *   The values.
   */
  protected function withValidators(array $validators, ?string $on_invalid = NULL, string $mapper = 'string'): array {
    $values = $this->accounts();
    $row = [
      'target_field' => 'field_code',
      'mapper' => ['plugin' => $mapper, 'sources' => ['value' => 'code'], 'settings' => []],
      'validators' => $validators,
    ];
    if ($on_invalid !== NULL) {
      $row['on_invalid'] = $on_invalid;
    }
    $values['mapping'][1] = $row;
    return $values;
  }

  /**
   * The email validator, as the mapping row of the code field.
   *
   * @return list<array{plugin: string, settings: array<string, mixed>}>
   *   The validators.
   */
  protected function email(): array {
    return $this->one('email', []);
  }

  /**
   * One validator.
   *
   * @param string $plugin
   *   The validator.
   * @param array<string, mixed> $settings
   *   Its settings.
   *
   * @return list<array{plugin: string, settings: array<string, mixed>}>
   *   The validators.
   */
  protected function one(string $plugin, array $settings): array {
    return [['plugin' => $plugin, 'settings' => $settings]];
  }

  /**
   * A phone validator.
   *
   * @param array<string, mixed> $settings
   *   Its settings.
   *
   * @return list<array{plugin: string, settings: array<string, mixed>}>
   *   The validators.
   */
  protected function phone(array $settings): array {
    return $this->one('phone', $settings);
  }

  /**
   * Items: two with an address, one with something else.
   *
   * @return list<array<string, mixed>>
   *   The items.
   */
  protected function items(): array {
    return [
      ['id' => 1, 'name' => 'Acme', 'code' => 'info@acme.nl'],
      ['id' => 2, 'name' => 'Globex', 'code' => 'not an address'],
      ['id' => 3, 'name' => 'Initech', 'code' => 'hello@initech.nl'],
    ];
  }

  /**
   * By default an item with an invalid value fails, and the reason is told.
   */
  public function testInvalidValueFailsTheItem(): void {
    $run = $this->extractRows($this->items(), $this->withValidators($this->email()));

    $result = $this->process->process('w', 10, 'default', 600, 1000);

    $this->assertSame(2, $result->created);
    $this->assertSame(1, $result->failed);
    $dead = $this->items->listItems([(int) $run->id()], ItemState::Dead, 10);
    $this->assertCount(1, $dead);
    $this->assertSame('Field "field_code": the value is not a valid email address.', $dead[0]->error);
    $this->assertStringNotContainsString('not an address', (string) $dead[0]->error);
    $this->assertCount(2, Node::loadMultiple());
  }

  /**
   * Leaving the field empty imports the item without that field, and says so.
   */
  public function testInvalidValueCanBeLeftEmpty(): void {
    $run = $this->extractRows($this->items(), $this->withValidators($this->email(), 'empty'));

    $result = $this->process->process('w', 10, 'default', 600, 1000);

    $this->assertSame(3, $result->created);
    $this->assertSame(0, $result->failed);
    $globex = array_values(array_filter(Node::loadMultiple(), static fn (Node $node): bool => $node->label() === 'Globex'));
    $this->assertCount(1, $globex);
    $this->assertTrue($globex[0]->get('field_code')->isEmpty());
    $this->assertSame('info@acme.nl', Node::load(1)?->get('field_code')->value);
    $events = array_values(array_filter(
      $this->container->get('import_engine.event_log')->forRun((int) $run->id()),
      static fn ($event): bool => $event->event === EventType::Invalid,
    ));
    $this->assertCount(1, $events);
    $this->assertSame('Field "field_code": the value is not a valid email address; left empty.', $events[0]->message);
    $this->assertStringNotContainsString('not an address', (string) $events[0]->message);
  }

  /**
   * An item that was left empty is unchanged the next time, not rewritten.
   */
  public function testEmptiedValueIsStableBetweenRuns(): void {
    $values = $this->withValidators($this->email(), 'empty');
    $first = $this->extractRows($this->items(), $values);
    $this->process->process('w', 10, 'default', 600, 1000);
    $first->transitionTo(RunStatus::Finishing)->transitionTo(RunStatus::Completed)->save();

    $this->extractAll(new FakeSource([$this->items()]), $values);
    $result = $this->process->process('w', 10, 'default', 600, 2000);

    $this->assertSame(3, $result->unchanged);
  }

  /**
   * Skipping the item leaves it out and is not a failure.
   *
   * What was imported for it is kept; the sweep does not take it for gone.
   */
  public function testInvalidItemCanBeSkipped(): void {
    $good = $this->items();
    $good[1]['code'] = 'globex@example.nl';
    $values = $this->withValidators($this->email(), 'skip_item');
    $first = $this->extractRows($good, $values);
    $this->process->process('w', 10, 'default', 600, 1000);
    $first->transitionTo(RunStatus::Finishing)->transitionTo(RunStatus::Completed)->save();
    $globex_before = array_values(array_filter(Node::loadMultiple(), static fn (Node $node): bool => $node->label() === 'Globex'));
    $this->assertCount(1, $globex_before);

    // Now Globex has an invalid address at the source.
    $second = $this->extractAll(new FakeSource([$this->items()]), $values);
    $result = $this->process->process('w', 10, 'default', 600, 2000);

    $this->assertSame(1, $result->skipped);
    $this->assertSame(0, $result->failed);
    $this->assertCount(3, Node::loadMultiple(), 'Nothing was removed or added.');
    $this->assertSame('globex@example.nl', $globex_before[0]->get('field_code')->value);
    $this->assertCount(0, $this->container->get('import_engine.mapping_store')->notSeenSince('customers', (int) $second->id(), 10), 'The skipped item is still in the source, so it is not swept.');
    $events = array_values(array_filter(
      $this->container->get('import_engine.event_log')->forRun((int) $second->id()),
      static fn ($event): bool => $event->event === EventType::Skipped,
    ));
    $this->assertCount(1, $events);
    $this->assertStringContainsString('the item was not imported', (string) $events[0]->message);
  }

  /**
   * A telephone number is written in one form.
   */
  public function testValidatorCanNormalize(): void {
    $validators = [['plugin' => 'phone', 'settings' => ['normalize' => TRUE, 'default_country_code' => '31']]];
    $this->extractRows([
      ['id' => 1, 'name' => 'Acme', 'code' => '06-12345678'],
      ['id' => 2, 'name' => 'Globex', 'code' => '+31 (0)20 123 4567'],
    ], $this->withValidators($validators));

    $result = $this->process->process('w', 10, 'default', 600, 1000);

    $this->assertSame(2, $result->created);
    $this->assertSame('+31612345678', Node::load(1)?->get('field_code')->value);
    $this->assertSame('+31201234567', Node::load(2)?->get('field_code')->value);
  }

  /**
   * An empty value is not judged by a format, but is by "has a value".
   */
  public function testEmptyValueIsOnlyJudgedByNotBlank(): void {
    $items = [['id' => 1, 'name' => 'Acme', 'code' => '']];

    $this->extractRows($items, $this->withValidators($this->email()));
    $format = $this->process->process('w', 10, 'default', 600, 1000);
    $this->assertSame(1, $format->created, 'An empty value passes a format.');
  }

  /**
   * A check for a value fails an item without one.
   */
  public function testNotBlankFailsAnEmptyValue(): void {
    $this->extractRows(
      [['id' => 1, 'name' => 'Acme', 'code' => '']],
      $this->withValidators([['plugin' => 'not_blank', 'settings' => []]]),
    );

    $result = $this->process->process('w', 10, 'default', 600, 1000);

    $this->assertSame(1, $result->failed);
  }

  /**
   * Several validators of one row are all applied, in order.
   */
  public function testValidatorsAreAppliedInOrder(): void {
    $validators = [
      ['plugin' => 'length', 'settings' => ['min' => 0, 'max' => 20]],
      ['plugin' => 'email', 'settings' => []],
    ];
    $run = $this->extractRows([
      ['id' => 1, 'name' => 'Acme', 'code' => 'info@acme.nl'],
      ['id' => 2, 'name' => 'Globex', 'code' => 'a.very.long.address@example.com'],
      ['id' => 3, 'name' => 'Initech', 'code' => 'nope'],
    ], $this->withValidators($validators));

    $this->process->process('w', 10, 'default', 600, 1000);

    $errors = array_map(static fn ($item): string => (string) $item->error, $this->items->listItems([(int) $run->id()], ItemState::Dead, 10));
    sort($errors);
    $this->assertSame([
      'Field "field_code": the value is longer than 20 characters.',
      'Field "field_code": the value is not a valid email address.',
    ], $errors);
  }

  /**
   * A validator that does not fit, or does not exist, is a definition problem.
   */
  public function testDefinitionWithAnUnfitValidatorFailsItsItems(): void {
    $values = $this->withValidators([['plugin' => 'range', 'settings' => ['min' => NULL, 'max' => NULL]]]);
    $this->definition($values)->save();

    $violations = $this->violations(ImportDefinition::load('customers'));
    // The row is valid for its schema except that the validator does not fit.
    $this->assertContains('mapping.1.validators.0.plugin', $violations);
  }

  /**
   * Returns the property paths of what is wrong with an import.
   *
   * @return list<string>
   *   The paths.
   */
  protected function violations(?ImportDefinition $definition): array {
    $this->assertNotNull($definition);
    $paths = [];
    foreach ($definition->getTypedData()->validate() as $violation) {
      // The mapping is what is under test; the source of the helper is bare.
      if (str_starts_with($violation->getPropertyPath(), 'mapping')) {
        $paths[] = $violation->getPropertyPath();
      }
    }
    return $paths;
  }

  /**
   * The preview tells per row what would happen, and never throws.
   */
  public function testPreviewTellsPerRow(): void {
    $item = ['id' => 1, 'name' => 'Acme', 'code' => 'nonsense'];
    $preview = function (string $onInvalid) use ($item): array {
      $values = $this->withValidators($this->email(), $onInvalid);
      $plan = $this->container->get('import_engine.plan_factory')->create($this->definition($values));
      return $plan->preview($item);
    };

    $fail = $preview('fail');
    $this->assertSame(['title', 'field_code'], array_column($fail, 'field'));
    $this->assertSame('ok', $fail[0]['status']);
    $this->assertSame('failed', $fail[1]['status']);
    $this->assertStringContainsString('is not a valid email address', $fail[1]['message']);
    $this->assertStringNotContainsString('nonsense', $fail[1]['message']);

    $empty = $preview('empty');
    $this->assertSame('left_empty', $empty[1]['status']);
    $this->assertNull($empty[1]['value']);

    $this->assertSame('skipped', $preview('skip_item')[1]['status']);
  }

  /**
   * The schema accepts a row with validators, and refuses what is wrong.
   */
  public function testSchemaOfValidators(): void {
    $make = function (array $validators, ?string $on_invalid = NULL): array {
      /** @var list<array{plugin: string, settings: array<string, mixed>}> $validators */
      return $this->violations($this->definition($this->withValidators($validators, $on_invalid)));
    };

    $this->assertSame([], $make($this->email(), 'empty'));
    $this->assertSame([], $make($this->phone(['normalize' => TRUE, 'default_country_code' => '31'])));
    $this->assertSame([], $make([['plugin' => 'regex', 'settings' => ['pattern' => '/^[A-Z]+$/']]]));
    $this->assertContains('mapping.1.on_invalid', $make($this->email(), 'explode'));
    $this->assertContains('mapping.1.validators.0.plugin', $make([['plugin' => 'nonsense', 'settings' => []]]));
    $this->assertContains('mapping.1.validators.0.settings.pattern', $make($this->one('regex', ['pattern' => '/[/'])));
    $this->assertContains(
      'mapping.1.validators.0.settings.default_country_code',
      $make($this->phone(['normalize' => TRUE, 'default_country_code' => 'NL'])),
    );
    $this->assertContains(
      'mapping.1.validators.0.settings.allowed',
      $make($this->one('choice', ['allowed' => [], 'case_sensitive' => FALSE])),
    );
  }

  /**
   * Changing the validators makes the pages be read again.
   */
  public function testValidatorsAreInTheFingerprintOfTheMapping(): void {
    $fingerprints = $this->container->get('import_engine.processing_fingerprint');
    $plain = $this->definition($this->accounts());
    $validated = $this->definition($this->withValidators($this->email()));
    $other = $this->definition($this->withValidators($this->email(), 'empty'));

    $this->assertNotSame($fingerprints->of($plain), $fingerprints->of($validated));
    $this->assertNotSame($fingerprints->of($validated), $fingerprints->of($other), 'What happens to an invalid value counts too.');
  }

  /**
   * A reference that is not there: wait, fail at once, or leave it empty.
   */
  public function testReferenceBehaviors(): void {
    $row = static fn (string $when): array => [
      'target_field' => 'field_item',
      'mapper' => [
        'plugin' => 'reference',
        'sources' => ['id' => 'item'],
        'settings' => ['definition' => 'items', 'when_missing' => $when],
      ],
    ];
    $outcomes = [];
    foreach (['wait', 'error', 'ignore'] as $when) {
      $values = $this->accounts(['id' => 'ref_' . $when, 'label' => $when]);
      $values['mapping'] = [$values['mapping'][0], $row($when)];
      $values['resilience']['max_attempts'] = 3;
      $definition = $this->definition($values);
      $definition->save();
      $run = $this->starter->start($definition, Trigger::Drush);
      $item = ['id' => 1, 'name' => 'Acme', 'code' => 'A', 'item' => 'P-404'];
      $this->stage->extract($run, $definition, new FakeSource([[$item]]));
      $result = $this->process->process('w', 10, 'default', 600, 1000);
      $outcomes[$when] = [$result->created, $result->retried, $result->failed];
    }

    $this->assertSame([0, 1, 0], $outcomes['wait'], 'Waits: the item is tried again later.');
    $this->assertSame([0, 0, 1], $outcomes['error'], 'Fails at once, without waiting.');
    $this->assertSame([1, 0, 0], $outcomes['ignore'], 'Is imported with the field empty.');
  }

}
