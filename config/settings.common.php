<?php

/**
 * @file
 * Settings shared by all sites, required from config/<site>/settings.php.
 */

// Only the site's own DDEV hostname is trusted: site_a -> site-a.ddev.site.
// There is no production environment; add hosts here if that ever changes.
$settings['trusted_host_patterns'] = [
  '^' . preg_quote(str_replace('_', '-', basename($site_path)), '/') . '\.ddev\.site$',
];
