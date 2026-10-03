<?php

/**
 * @file
 * Config settings for this site, included from its sites/<site>/settings.php.
 *
 * Kept outside web/sites so Drupal's installer, which rewrites that file and
 * makes it read-only, cannot override them. Include it last.
 */

// Absolute path: Drupal's installer may append its own value to settings.php.
$settings['config_sync_directory'] = __DIR__ . '/sync';
