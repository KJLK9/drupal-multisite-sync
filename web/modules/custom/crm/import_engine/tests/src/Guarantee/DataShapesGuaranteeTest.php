<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use Drupal\import_engine\Run\RunStatus;
use Drupal\node\Entity\Node;
use Drupal\Tests\import_engine\Kernel\FakeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Data as it really comes: odd characters, spaces, long and empty values.
 *
 * The promise is not that every value is accepted, but that no value stops the
 * run, corrupts a neighbour or is changed on its way in.
 */
#[Group('guarantee')]
#[RunTestsInSeparateProcesses]
class DataShapesGuaranteeTest extends GuaranteeTestBase {

  /**
   * Names that are saved as they are.
   *
   * @return array<string, array{string}>
   *   A name.
   */
  public static function names(): array {
    return [
      'plain' => ['Acme'],
      'accents' => ['Zoë Müller-Åström'],
      'emoji' => ['Acme 🚀 BV'],
      'cyrillic' => ['Ромашка'],
      'cjk' => ['株式会社テスト'],
      'a quote and an ampersand' => ['O\'Neil & "Sons"'],
      'markup' => ['<b>Bold</b> & <script>alert(1)</script>'],
      'a percent and a backslash' => ['100% \\ done'],
      'a long name' => [str_repeat('x', 255)],
      'a number' => ['12345'],
    ];
  }

  /**
   * A name arrives as it was, down to the last character.
   */
  #[DataProvider('names')]
  public function testNameArrivesUnchanged(string $name): void {
    $definition = $this->saveImport();

    $run = $this->runToEnd($definition, new FakeSource([[['id' => 1, 'name' => $name, 'code' => 'C1']]]));

    $this->assertSame(RunStatus::Completed, $run->getStatus());
    $this->assertSame($name, $this->accountsOnSite()['C1']['title']);
  }

  /**
   * A name that is too long for the target fails that item only.
   */
  public function testTooLongNameFailsOnlyItsItem(): void {
    $definition = $this->saveImport();
    $items = $this->accountItems(1, 5);
    $items[2]['name'] = str_repeat('x', 300);

    $run = $this->runToEnd($definition, new FakeSource([$items]));

    $this->assertSame(RunStatus::CompletedWithErrors, $run->getStatus());
    $this->assertSame(4, $run->getCounters()['created']);
    $this->assertSame(1, $run->getCounters()['dead']);
    $this->assertCount(4, Node::loadMultiple());
  }

  /**
   * Empty and odd values: what a source really does.
   *
   * @return array<string, array{mixed, bool}>
   *   The value of the name, and whether the account is made.
   */
  public static function oddValues(): array {
    return [
      'null' => [NULL, FALSE],
      'an empty string' => ['', FALSE],
      'only spaces' => ['   ', FALSE],
      'a zero' => [0, TRUE],
      // A boolean is written as the word, by design.
      'false' => [FALSE, TRUE],
      'a float' => [1.5, TRUE],
    ];
  }

  /**
   * A value that is nothing is not made into an account; the rest is.
   */
  #[DataProvider('oddValues')]
  public function testOddValues(mixed $name, bool $made): void {
    $definition = $this->saveImport();
    $items = [
      ['id' => 1, 'name' => 'Before', 'code' => 'C1'],
      ['id' => 2, 'name' => $name, 'code' => 'C2'],
      ['id' => 3, 'name' => 'After', 'code' => 'C3'],
    ];

    $run = $this->runToEnd($definition, new FakeSource([$items]));

    $this->assertContains($run->getStatus(), [RunStatus::Completed, RunStatus::CompletedWithErrors]);
    $accounts = $this->accountsOnSite();
    $this->assertArrayHasKey('C1', $accounts, 'The item before is there.');
    $this->assertArrayHasKey('C3', $accounts, 'The item after is there.');
    $this->assertSame($made, isset($accounts['C2']));
  }

  /**
   * An item that appears twice in one source is one account, the first one.
   */
  public function testDuplicateKeysInOneRunAreOneAccount(): void {
    $definition = $this->saveImport();
    $items = $this->accountItems(1, 6);
    $items[] = ['id' => 3, 'name' => 'Duplicate of three', 'code' => 'C3'];

    $run = $this->runToEnd($definition, new FakeSource($this->pagesOfItems($items, 4)));

    $this->assertCount(6, $this->accountsOnSite());
    $this->assertSame('Account 3', $this->accountsOnSite()['C3']['title']);
    $this->assertSame(RunStatus::Completed, $run->getStatus());
  }

  /**
   * Keys are compared as exact text: '1' and 1 are one, '01' and 'A/B' are not.
   */
  public function testKeysAreComparedAsExactText(): void {
    $definition = $this->saveImport();
    $items = [
      ['id' => '1', 'name' => 'String one', 'code' => 'A'],
      ['id' => 1, 'name' => 'Integer one', 'code' => 'B'],
      ['id' => '01', 'name' => 'Zero one', 'code' => 'C'],
      ['id' => 'a/b', 'name' => 'Slash', 'code' => 'D'],
      ['id' => 'A/B', 'name' => 'Capital slash', 'code' => 'E'],
    ];

    $this->runToEnd($definition, new FakeSource([$items]));

    // The key '1' and 1 are the same text in the key; '01' is another, and so
    // are two keys that differ only in case (the database compares them
    // exactly: update 10002). The second '1' is the duplicate and is left out.
    $this->assertSame(['A', 'C', 'D', 'E'], array_keys($this->accountsOnSite()));
  }

}
