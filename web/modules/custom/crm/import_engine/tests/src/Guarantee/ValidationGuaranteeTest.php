<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use Drupal\import_engine\Storage\EventType;
use Drupal\import_engine\Storage\ItemState;
use Drupal\node\Entity\Node;
use Drupal\Tests\import_engine\Kernel\FakeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Every validator, with every way of handling a value that is not valid.
 *
 * The promises: a valid value is saved (as the validator writes it), a value
 * that is not valid is handled as the row says and as nothing else, and what
 * the engine writes down about it never contains the value itself, since the
 * events are kept for a year and a person who may only look can read them.
 */
#[Group('guarantee')]
#[RunTestsInSeparateProcesses]
class ValidationGuaranteeTest extends GuaranteeTestBase {

  /**
   * The validators, each with a value that passes and one that does not.
   *
   * @return array<string, array{string, array<string, mixed>, string, string, mixed, mixed, string}>
   *   The validator, its settings, the mapper and the target field, the value
   *   that is valid and what is saved of it, and the value that is not valid.
   */
  public static function validators(): array {
    return [
      'email' => [
        'email', [], 'string', 'field_code',
        'info@example.org', 'info@example.org', 'secret-not-an-address',
      ],
      'url' => [
        'url', [], 'string', 'field_code',
        'https://example.org/a', 'https://example.org/a', 'secret ftp://nowhere',
      ],
      'phone, normalized' => [
        'phone', ['normalize' => TRUE, 'default_country_code' => '31'], 'string', 'field_code',
        '06 1234 5678', '+31612345678', 'secret-call-me',
      ],
      'regex' => [
        'regex', ['pattern' => '/^[A-Z]{2}[0-9]{3}$/'], 'string', 'field_code',
        'AB123', 'AB123', 'secret-ab-12',
      ],
      'length' => [
        'length', ['min' => 2, 'max' => 5], 'string', 'field_code',
        'abcd', 'abcd', 'secret-far-too-long',
      ],
      'choice' => [
        'choice', ['allowed' => ['gold', 'silver'], 'case_sensitive' => FALSE], 'string', 'field_code',
        'GOLD', 'gold', 'secret-bronze',
      ],
      'not_blank' => ['not_blank', [], 'string', 'field_code', 'x', 'x', '   '],
      'range' => ['range', ['min' => 1, 'max' => 10], 'number', 'field_count', '5', '5', '99999'],
    ];
  }

  /**
   * Builds the import: the title from the name, and the checked field.
   *
   * @param string $plugin
   *   The validator.
   * @param array<string, mixed> $settings
   *   Its settings.
   * @param string $mapper
   *   The mapper of the checked field.
   * @param string $field
   *   The checked field.
   * @param string $onInvalid
   *   What to do with a value that is not valid.
   *
   * @return array<string, mixed>
   *   The values of the definition.
   */
  protected function checked(string $plugin, array $settings, string $mapper, string $field, string $onInvalid): array {
    $values = $this->accounts();
    $values['mapping'] = [
      $values['mapping'][0],
      [
        'target_field' => $field,
        'mapper' => ['plugin' => $mapper, 'sources' => ['value' => 'value'], 'settings' => []],
        'validators' => [['plugin' => $plugin, 'settings' => $settings]],
        'on_invalid' => $onInvalid,
      ],
    ];
    return $values;
  }

  /**
   * Three items: a valid one, one that is not, and a valid one.
   *
   * @return list<array<string, mixed>>
   *   The items.
   */
  protected function threeItems(mixed $valid, mixed $invalid): array {
    return [
      ['id' => 1, 'name' => 'First', 'value' => $valid],
      ['id' => 2, 'name' => 'Second', 'value' => $invalid],
      ['id' => 3, 'name' => 'Third', 'value' => $valid],
    ];
  }

  /**
   * Reads the saved value of an account.
   */
  protected function savedValue(string $field, string $title): mixed {
    foreach (Node::loadMultiple() as $node) {
      if ($node->label() === $title) {
        return $node->get($field)->isEmpty() ? NULL : $node->get($field)->value;
      }
    }
    return 'no such account';
  }

  /**
   * Failing ends the item dead, and the others are saved.
   *
   * @param string $plugin
   *   The validator.
   * @param array<string, mixed> $settings
   *   The settings of the validator.
   * @param string $mapper
   *   The mapper of the checked field.
   * @param string $field
   *   The checked field.
   * @param mixed $valid
   *   A value that passes.
   * @param mixed $saved
   *   What is saved of it.
   * @param mixed $invalid
   *   A value that does not pass.
   */
  #[DataProvider('validators')]
  public function testFailEndsTheItem(string $plugin, array $settings, string $mapper, string $field, mixed $valid, mixed $saved, mixed $invalid): void {
    $values = $this->checked($plugin, $settings, $mapper, $field, 'fail');
    $definition = $this->saveImport($values);

    $run = $this->runToEnd($definition, new FakeSource([$this->threeItems($valid, $invalid)]));

    $this->assertSame(2, $run->getCounters()['created']);
    $this->assertSame(1, $run->getCounters()['dead']);
    $this->assertSame('no such account', $this->savedValue($field, 'Second'), 'Nothing was written for the item.');
    $this->assertEquals($saved, $this->savedValue($field, 'First'), 'The valid value is saved as the validator writes it.');
    $this->assertStringNotContainsString(trim((string) $invalid) ?: 'never-empty-needle', $this->writtenDown($run), 'The value is not in what is written down.');
  }

  /**
   * Emptying saves the item without the field, and says so.
   *
   * @param string $plugin
   *   The validator.
   * @param array<string, mixed> $settings
   *   The settings of the validator.
   * @param string $mapper
   *   The mapper of the checked field.
   * @param string $field
   *   The checked field.
   * @param mixed $valid
   *   A value that passes.
   * @param mixed $saved
   *   What is saved of it.
   * @param mixed $invalid
   *   A value that does not pass.
   */
  #[DataProvider('validators')]
  public function testEmptyKeepsTheItem(string $plugin, array $settings, string $mapper, string $field, mixed $valid, mixed $saved, mixed $invalid): void {
    $values = $this->checked($plugin, $settings, $mapper, $field, 'empty');
    $definition = $this->saveImport($values);

    $run = $this->runToEnd($definition, new FakeSource([$this->threeItems($valid, $invalid)]));

    $this->assertSame(3, $run->getCounters()['created']);
    $this->assertNull($this->savedValue($field, 'Second'), 'The field is empty.');
    $this->assertEquals($saved, $this->savedValue($field, 'Third'));
    $events = array_filter(
      $this->events->forRun((int) $run->id()),
      static fn ($event): bool => $event->event === EventType::Invalid,
    );
    $this->assertCount(1, $events, 'One note about it.');
    $this->assertStringNotContainsString(trim((string) $invalid) ?: 'never-empty-needle', $this->writtenDown($run));
  }

  /**
   * Skipping leaves the item out, and is not a failure.
   *
   * @param string $plugin
   *   The validator.
   * @param array<string, mixed> $settings
   *   The settings of the validator.
   * @param string $mapper
   *   The mapper of the checked field.
   * @param string $field
   *   The checked field.
   * @param mixed $valid
   *   A value that passes.
   * @param mixed $saved
   *   What is saved of it.
   * @param mixed $invalid
   *   A value that does not pass.
   */
  #[DataProvider('validators')]
  public function testSkipLeavesTheItemOut(string $plugin, array $settings, string $mapper, string $field, mixed $valid, mixed $saved, mixed $invalid): void {
    $values = $this->checked($plugin, $settings, $mapper, $field, 'skip_item');
    $definition = $this->saveImport($values);

    $run = $this->runToEnd($definition, new FakeSource([$this->threeItems($valid, $invalid)]));

    $counters = $run->getCounters();
    $this->assertSame(2, $counters['created']);
    $this->assertSame(1, $counters['skipped']);
    $this->assertSame(0, $counters['failed'] + $counters['dead']);
    $this->assertSame('no such account', $this->savedValue($field, 'Second'));
    $this->assertSame(0, $this->items->countByState((int) $run->id())[strtolower(ItemState::Dead->name)]);
    $this->assertStringNotContainsString(trim((string) $invalid) ?: 'never-empty-needle', $this->writtenDown($run));
  }

  /**
   * An item that was skipped is not taken for one that left the source.
   */
  public function testSkippedItemIsNotSweptAsGone(): void {
    $values = $this->checked('email', [], 'string', 'field_code', 'skip_item');
    $values['delete_threshold_percent'] = 0;
    $definition = $this->saveImport($values);
    $items = $this->threeItems('info@example.org', 'not-an-address');
    $this->runToEnd($definition, new FakeSource([$items]));

    $again = $this->runToEnd($definition, new FakeSource([$items]), TRUE);

    $this->assertSame(0, $again->getCounters()['deleted'], 'The skipped item is still seen by the source.');
    $this->assertSame(1, $again->getCounters()['skipped']);
  }

}
