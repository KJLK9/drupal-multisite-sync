<?php

/**
 * @file
 * Lets Infection find the classes of the modules, which Drupal registers itself.
 */

declare(strict_types=1);

$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('Drupal\\import_engine\\', __DIR__ . '/../web/modules/custom/crm/import_engine/src');
