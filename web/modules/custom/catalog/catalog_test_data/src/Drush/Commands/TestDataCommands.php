<?php

declare(strict_types=1);

namespace Drupal\catalog_test_data\Drush\Commands;

use Drupal\catalog_test_data\TestDataGenerator;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands to generate catalog test data.
 */
final class TestDataCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs a TestDataCommands object.
   *
   * @param \Drupal\catalog_test_data\TestDataGenerator $generator
   *   The test data generator.
   */
  public function __construct(
    #[Autowire(service: 'catalog_test_data.generator')]
    private readonly TestDataGenerator $generator,
  ) {
    parent::__construct();
  }

  /**
   * Generates customers, products and price agreements.
   *
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'generate:test-data')]
  #[CLI\Option(name: 'customers', description: 'Number of customers to generate.')]
  #[CLI\Option(name: 'products', description: 'Number of products to generate.')]
  #[CLI\Option(name: 'coverage', description: 'Percentage (0-100) of customer/product combinations that get a price agreement.')]
  #[CLI\Usage(name: 'drush generate:test-data --customers=5 --products=10 --coverage=50', description: 'Generate 5 customers, 10 products and prices for about half of the pairs.')]
  public function generateTestData(array $options = ['customers' => 10, 'products' => 20, 'coverage' => 70]): void {
    $result = $this->generator->generate(
      (int) $options['customers'],
      (int) $options['products'],
      (int) $options['coverage'],
    );

    $this->io()->success(sprintf(
      'Created %d customers, %d products and %d price agreements.',
      $result['customers'],
      $result['products'],
      $result['prices'],
    ));
  }

}
