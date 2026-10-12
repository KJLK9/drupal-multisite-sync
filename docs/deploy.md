# Running it on a server, in Kubernetes

Everything for this is in `deploy/`:

| | |
|---|---|
| `deploy/Dockerfile` | One image for both sites: Apache, PHP 8.4, the code, drush. |
| `deploy/docker/` | The PHP settings and `bootstrap.sh`, which installs the sites from their exported configuration and seeds site A. |
| `deploy/k8s/base` | MariaDB, the web pods, an ingress, the bootstrap job, the cron job of the run set and a worker. |
| `deploy/k8s/overlays/local` | For a k3d cluster on a development machine. |
| `deploy/k8s/overlays/production` | For a real server (k3s, Traefik, cert-manager). |
| `deploy/tunnel.sh` | The same cluster on this machine, reachable from the internet through a Cloudflare Tunnel: [tunnel.md](tunnel.md). |
| `deploy/local.sh` | Builds, creates a cluster and deploys, in one command. |
| `.github/workflows/image.yml` | Builds the image for amd64 and arm64 and pushes it to ghcr.io. |

The design is in [scheduling.md](scheduling.md); this is how it is run.

## How it fits together

- **Not as root.** Apache listens on 8080 and runs as `www-data` (user 33),
  like drush in the jobs, with no extra privileges and no capabilities. The
  service still answers on port 80.
- **One image, two sites.** The site is chosen by the hostname
  (`web/sites/sites.php`, which reads `SITE_A_HOST` and `SITE_B_HOST`).
  Nothing that differs between environments is in the image: the database,
  hash salts and API key come from a Secret (`config/settings.env.php`), the
  hostnames from a ConfigMap.
- **The sites install themselves.** The `bootstrap` job runs
  `drush site:install --existing-config` for both sites, so what is in
  `config/<site>/sync` (the connection, the three imports and the run set of
  site B included) is what the sites are. Site A gets test data and an account
  `catalog_reader` whose key is the one site B sends, so the two agree without
  copying anything. Run it again and an installed site only gets the configuration of the
  image imported (and its updates run); the data is left alone.
- **Imports run on their own.** A CronJob runs the run set `catalog` every
  hour, and a worker handles queued items. That is the design of
  [scheduling.md](scheduling.md), running.
- **Behind TLS.** The ingress ends TLS. Drupal trusts the forwarded headers
  only from `REVERSE_PROXY_ADDRESSES` (the pod ranges of the cluster; narrow
  it to the ingress or tunnel pod where the cluster allows) and only answers to
  the hosts in `SITE_A_HOST` and `SITE_B_HOST` (the DDEV names only in DDEV).

## On this machine, with k3d

You need Docker, `kubectl` and [k3d](https://k3d.io).

```bash
deploy/local.sh up        # builds, creates the cluster, deploys, waits
deploy/local.sh status
deploy/local.sh down      # removes it all
```

Then, in a browser or with curl (`*.localhost` points at this machine):

- Site B: http://site-b.import-engine.localhost:8080 (`admin` / `admin-local`),
  the interface under Configuration, System, Import engine.
- Site A: http://site-a.import-engine.localhost:8080

Run the catalog by hand instead of waiting for the hour:

```bash
kubectl --context k3d-import-engine -n import-engine create job --from=cronjob/import-catalog run-1
kubectl --context k3d-import-engine -n import-engine logs -f job/run-1
```

The passwords and keys of this overlay are fixed and public. The cluster
listens on 127.0.0.1 only, so it is for this machine alone; do not publish
the port.

## On a server

1. **A server.** Any small Linux server with enough memory for the pods below
   (see "How much it needs"). Install k3s, which brings Traefik:
   `curl -sfL https://get.k3s.io | sh -`.
2. **Names.** Two DNS records that point at the server, for example
   `site-a.import-engine.<your domain>` and `site-b.import-engine.<your domain>`.
   Use hyphens: an underscore is not allowed in a hostname and certificates
   refuse it. A subdomain costs nothing; a domain is a few euros a year. A
   free DuckDNS name works too (wildcard subdomains included).
3. **cert-manager**, for the certificates:
   `kubectl apply -f https://github.com/cert-manager/cert-manager/releases/latest/download/cert-manager.yaml`,
   then edit your address into `deploy/k8s/overlays/production/cluster-issuer.yaml`
   and apply it.
4. **The image.** The workflow `Image` pushes `ghcr.io/<owner>/import-engine`.
   Make the package public, or give the cluster a pull secret. Put the name in
   the production overlay.
5. **The secrets**, which are not in git: one per workload, each with only
   what that workload needs, so the web pod has neither the root password of
   the database nor the admin password, and the worker and the cron job have
   only site B's.

   ```bash
   kubectl create namespace import-engine
   r() { openssl rand -hex "$1"; }
   ROOT=$(r 16); A_DB=$(r 16); B_DB=$(r 16)
   A_SALT=$(r 32); B_SALT=$(r 32); KEY=$(r 24)
   N="kubectl -n import-engine create secret generic"
   $N mariadb-secret --from-literal=MARIADB_ROOT_PASSWORD=$ROOT \
     --from-literal=SITE_A_DB_PASSWORD=$A_DB --from-literal=SITE_B_DB_PASSWORD=$B_DB
   $N web-secret --from-literal=SITE_A_DB_PASSWORD=$A_DB \
     --from-literal=SITE_B_DB_PASSWORD=$B_DB --from-literal=SITE_A_HASH_SALT=$A_SALT \
     --from-literal=SITE_B_HASH_SALT=$B_SALT --from-literal=SITE_A_API_KEY=$KEY
   $N site-b-secret --from-literal=SITE_B_DB_PASSWORD=$B_DB \
     --from-literal=SITE_B_HASH_SALT=$B_SALT --from-literal=SITE_A_API_KEY=$KEY
   $N bootstrap-secret --from-literal=SITE_A_DB_PASSWORD=$A_DB \
     --from-literal=SITE_B_DB_PASSWORD=$B_DB --from-literal=SITE_A_HASH_SALT=$A_SALT \
     --from-literal=SITE_B_HASH_SALT=$B_SALT --from-literal=SITE_A_API_KEY=$KEY \
     --from-literal=ADMIN_PASSWORD="choose-a-long-one"
   ```

   The two sites share one web pod, so that pod has the passwords of both.
6. **Replace the hostnames** in `deploy/k8s/overlays/production/kustomization.yaml`
   and apply: `kubectl apply -k deploy/k8s/overlays/production`.
7. **Wait** for `kubectl -n import-engine wait --for=condition=complete job/bootstrap`
   and open site B.

Changed the configuration of a site? Export it (`drush cex`), commit, let the
workflow build a new image, then `kubectl -n import-engine delete job bootstrap`
and apply again: the bootstrap imports what changed.

## How much it needs

Measured on a k3d cluster (k3s in Docker, one node) after installing both
sites and running the whole catalog (30 accounts, 40 items, 472 agreements):

| | Memory |
|---|---|
| The whole k3s node (k3s, Traefik, CoreDNS, the pods) | about 1.8 GiB |
| MariaDB (buffer pool 128 MB) | about 155 MiB |
| The web pod (both sites) | about 50 to 115 MiB, more under load |
| The worker | about 45 to 60 MiB |

So a server with 4 GB runs it with room to spare; 2 GB is too little once
k3s and the operating system are counted. The first install takes about a
minute (the bootstrap job), and the image is about 1.2 GB, of which the code
is 220 MB. These are figures for this data set on one machine, not a
promise for more data.

## Two sites in one pod: what keeps their caches apart

Drupal multisite in Kubernetes has a name for going wrong: one site shows the
other's data, or a cache that one site cleared is still full on the other.
Here that cannot happen the usual ways, and this is why (checked in the
running cluster, not only read):

- **Every site has a database of its own** (`site_a`, `site_b`), and the cache
  tables are in it. There is no cache that both sites share: no Redis, no
  Memcached, no APCu (not installed). Clearing the cache of one site clears
  tables of one database.
- **The files are apart as well**: the volume holds `site_a/` and `site_b/`,
  mounted on `sites/site_a/files` and `sites/site_b/files`, so the CSS and JS
  aggregates and the compiled Twig of the sites are in separate directories.
- **The hash salt differs per site** (it is in the environment per site), which
  is what makes cached URLs, form tokens and aggregate names differ.
- **The site is chosen by the host**, from `SITE_A_HOST` and `SITE_B_HOST`
  (`sites.php`), and trusted host patterns are set from the same variables.
  Requests that alternate between the two hosts on the same paths gave
  the answers of the right site every time.
- **A request for any other host is refused** by Apache (403), where it would
  have gone to the site "default" and its installer. Only `/robots.txt` is
  answered for every host, because the probes of Kubernetes ask by the address
  of the pod.
- **Every drush command in a job names its site** with `--uri`. In a pod, drush
  without `--uri` acts on `default`: never leave it out.
- **`cache_prefix` is set per site** in `settings.common.php`, so that if a
  shared cache is added later (Redis, Memcached) the sites keep their entries
  apart without anyone having to remember it.

What would change that picture, and what to do then:

- **More than one web pod.** The files volume must be shared by all of them
  (`ReadWriteMany`, or the aggregates and Twig files written to storage each
  pod can read); today it is `ReadWriteOnce` with one pod. The database caches
  and locks are already shared. Drupal makes a missing CSS or JS aggregate
  again when it is asked for, so a pod that did not write it can serve it.
- **A shared cache backend.** Give it the prefix above, and give each site its
  own database number or key prefix; never let two sites write one namespace.
- **Caching in front of the sites** (Cloudflare "Cache Everything", a CDN):
  the two sites have their own hostnames, which keeps them apart, but HTML of
  pages that depend on a login must not be cached there. The default of
  Cloudflare, which caches static files only, is fine.
- **A deploy.** The compiled container and the Twig files live on the volume
  and in the database; a new image with new code needs the caches rebuilt.
  The bootstrap job does that for both sites (`config:import`, `updatedb`,
  `cache:rebuild`), and the web pods are replaced, not changed in place.

## What was and was not tried

Tried: building the image, creating a k3d cluster, applying the local
overlay, the bootstrap job installing both sites from their exported
configuration, site B reading site A inside the cluster and the cron job
importing all three imports, and rendering the production overlay.

Not tried: a real server, TLS with cert-manager, pushing the image with the
workflow (and the arm64 build), a pull secret, and the production overlay
applied to a cluster. Treat those steps as a careful sketch until they have
been run.

## Demonstrating it

A server that is only needed for a demo can be switched off in between: most
cloud providers bill by the hour, so a server that runs for the days of an
interview costs a few euros. Make a snapshot, delete the server, and create it
again from the snapshot when needed, and keep a short screen recording for the
time in between.

A sequence that shows what the engine is for, about ten minutes:

1. **The overview** (Import engine): the tabs, and the settings for retention.
2. **Connections**: one connection to site A, used by three imports.
3. **An import in the wizard**: the mapping table with suggestions from the
   sample of the source ("Try the source").
4. **Run sets**: accounts, items, agreements in order; run it with the
   progress bar.
5. **A run**: its counters, its items and events; the status of the run.
6. **Something going wrong**: stop the source (scale site A to zero), see the
   circuit breaker open, the dead letter queue, and retry after the source is
   back.
7. **Idempotence**: run it again and see that nothing changed; change a
   customer on site A and run again to see one update.
