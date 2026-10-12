<?php

/**
 * @file
 * Settings shared by all sites, required from config/<site>/settings.php.
 */

// Hosts that are trusted: in DDEV the site's own hostname (site_a ->
// site-a.ddev.site), and the host in <SITE>_HOST (for example
// SITE_A_HOST=site-a.import-engine.example.com), which is also how
// web/sites/sites.php finds the site.
$settings['trusted_host_patterns'] = [];
if (getenv('IS_DDEV_PROJECT')) {
  $settings['trusted_host_patterns'][] = '^' . preg_quote(str_replace('_', '-', basename($site_path)), '/') . '\.ddev\.site$';
}
$site_host = getenv(strtoupper(basename($site_path)) . '_HOST');
if ($site_host !== FALSE && $site_host !== '') {
  $settings['trusted_host_patterns'][] = '^' . preg_quote($site_host, '/') . '$';
}
unset($site_host);

// Behind a reverse proxy (an ingress that ends TLS): trust its forwarded
// headers, but only from the addresses (or CIDR ranges) in
// REVERSE_PROXY_ADDRESSES, comma separated. Without it nothing is trusted.
$proxies = array_filter(array_map('trim', explode(',', (string) getenv('REVERSE_PROXY_ADDRESSES'))));
if ($proxies !== []) {
  $settings['reverse_proxy'] = TRUE;
  $settings['reverse_proxy_addresses'] = array_values($proxies);
}
unset($proxies);

// Site B reads site A at the address in its connection (in git: the DDEV one).
// Where the two sites are in the same cluster, SITE_A_API_URL says how to reach
// site A from inside it, and the request names site A by its host so that the
// right site answers. The setting is an override: the connection in the
// interface shows what is saved.
$api_url = getenv('SITE_A_API_URL');
$api_host = getenv('SITE_A_HOST');
if (basename($site_path) === 'site_b' && $api_url !== FALSE && $api_url !== '' && $api_host !== FALSE && $api_host !== '') {
  $config['import_engine.import_connection.site_a']['source']['configuration']['url'] = $api_url;
  $config['import_engine.import_connection.site_a']['source']['configuration']['headers'] = ['Host' => $api_host];
}
unset($api_url, $api_host);

// A cache that is shared between sites (Redis, Memcached, APCu) keeps what is
// in it apart by this prefix. Nothing like that is in use: every site has a
// database of its own, and so its own cache tables. The prefix is here so that
// adding a shared cache later cannot mix up the sites.
$settings['cache_prefix'] = basename($site_path);
