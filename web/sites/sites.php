<?php

/**
 * @file
 * Maps hostnames to site directories.
 */

$sites['site-a.ddev.site'] = 'site_a';
$sites['site-b.ddev.site'] = 'site_b';

// Outside DDEV the hostnames come from the environment: SITE_A_HOST and
// SITE_B_HOST, for example site-a.import-engine.example.com.
foreach (['site_a', 'site_b'] as $directory) {
  $host = getenv(strtoupper($directory) . '_HOST');
  if ($host !== FALSE && $host !== '') {
    $sites[$host] = $directory;
  }
}
unset($directory, $host);
