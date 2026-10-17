<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine_ui\Kernel;

use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine_ui\Controller\HelpController;
use Drupal\import_engine_ui\Help\DefinitionSummary;
use Drupal\import_engine_ui\Help\Glossary;
use Drupal\Tests\import_engine\Kernel\NodeTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the help page and the glossary behind the info icons.
 */
#[Group('import_engine_ui')]
#[RunTestsInSeparateProcesses]
class HelpPageTest extends NodeTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'money_field',
    'import_engine',
    'import_engine_ui',
  ];

  /**
   * The page has the picture, the steps and every word of the glossary.
   */
  public function testHelpPageListsEveryTerm(): void {
    $page = HelpController::create($this->container)->page();
    $html = (string) $this->container->get('renderer')->renderRoot($page);

    $this->assertStringContainsString('The big picture', $html);
    $this->assertStringContainsString('Your first import', $html);
    $glossary = $this->container->get('import_engine_ui.glossary');
    $this->assertInstanceOf(Glossary::class, $glossary);
    foreach ($glossary->terms() as $id => $term) {
      $this->assertStringContainsString('id="' . $id . '"', $html);
      $this->assertStringContainsString((string) $term->term, $html);
    }
  }

  /**
   * The summary names the host of a source, not a key in its address.
   */
  public function testSummaryLeavesOutSecretsInTheAddress(): void {
    $definition = ImportDefinition::create([
      'id' => 'x',
      'label' => 'X',
      'source' => [
        'plugin' => 'http',
        'configuration' => ['url' => 'https://api.example.com/v1/items?api_key=SECRET123'],
      ],
    ]);
    $summary = $this->container->get('import_engine_ui.definition_summary');
    $this->assertInstanceOf(DefinitionSummary::class, $summary);

    $text = implode("\n", $summary->sentences($definition));

    $this->assertStringContainsString('https://api.example.com', $text);
    $this->assertStringNotContainsString('SECRET123', $text);
    $this->assertStringNotContainsString('api_key', $text);
  }

  /**
   * A term names the form elements that get its icon, once each.
   */
  public function testEveryFieldBelongsToOneTerm(): void {
    $glossary = $this->container->get('import_engine_ui.glossary');
    $this->assertInstanceOf(Glossary::class, $glossary);
    $seen = [];
    foreach ($glossary->terms() as $term) {
      foreach ($term->fields as $field) {
        $this->assertArrayNotHasKey($field, $seen, $field . ' is in two terms.');
        $seen[$field] = TRUE;
      }
    }
    $this->assertSame('breaker', $glossary->forField('cooldown')?->id);
    $this->assertNull($glossary->forField('nonsense'));
  }

}
