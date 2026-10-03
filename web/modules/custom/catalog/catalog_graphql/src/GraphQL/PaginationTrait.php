<?php

declare(strict_types=1);

namespace Drupal\catalog_graphql\GraphQL;

use Drupal\graphql\GraphQL\Resolver\ResolverInterface;
use Drupal\graphql\GraphQL\ResolverBuilder;

/**
 * Resolvers for bounded `limit` and `offset` arguments.
 *
 * Use in any schema extension to keep list fields from being asked for an
 * unbounded number of items. Pair with `limit: Int = 50` / `offset: Int = 0`
 * in the SDL and feed the result to a producer's `limit` / `offset` mapping.
 */
trait PaginationTrait {

  /**
   * The largest page size a list field may return.
   */
  public const MAX_LIMIT = 100;

  /**
   * Resolves the `limit` argument, clamped to 1..MAX_LIMIT.
   *
   * @param \Drupal\graphql\GraphQL\ResolverBuilder $builder
   *   The resolver builder.
   * @param string $argument
   *   The name of the limit argument.
   */
  protected function limitResolver(ResolverBuilder $builder, string $argument = 'limit'): ResolverInterface {
    return $builder->produce('catalog_clamp')
      ->map('value', $builder->fromArgument($argument))
      ->map('min', $builder->fromValue(1))
      ->map('max', $builder->fromValue(self::MAX_LIMIT));
  }

  /**
   * Resolves the `offset` argument, never below 0.
   *
   * @param \Drupal\graphql\GraphQL\ResolverBuilder $builder
   *   The resolver builder.
   * @param string $argument
   *   The name of the offset argument.
   */
  protected function offsetResolver(ResolverBuilder $builder, string $argument = 'offset'): ResolverInterface {
    return $builder->produce('catalog_clamp')
      ->map('value', $builder->fromArgument($argument))
      ->map('min', $builder->fromValue(0))
      ->map('max', $builder->fromValue(PHP_INT_MAX));
  }

}
