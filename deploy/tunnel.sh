#!/bin/bash
# Runs the sites on this machine and makes them reachable from the internet
# through a Cloudflare Tunnel (docs/tunnel.md). Needs a domain on Cloudflare
# and a tunnel token.
#
#   CLOUDFLARE_TUNNEL_TOKEN=... TUNNEL_DOMAIN=example.com deploy/tunnel.sh up
#   deploy/tunnel.sh passwords    show the passwords again
#   deploy/tunnel.sh down         remove the cluster
#
# The sites are then at https://site-a-import-engine.<domain> and
# https://site-b-import-engine.<domain>: one level below the domain, since
# the free certificate of Cloudflare covers *.<domain> and no deeper. Other
# names: set SITE_A_HOST and SITE_B_HOST.
#
#   deploy/tunnel.sh hosts        apply other hostnames to the running cluster
set -euo pipefail
cd "$(dirname "$0")/.."

# Where a user-level install of k3d goes.
export PATH="$HOME/.local/bin:$PATH"

CLUSTER=import-engine
IMAGE=import-engine:local
CTX="k3d-${CLUSTER}"
K="kubectl --context ${CTX} -n import-engine"

# The hostnames: from the environment, or derived from the domain.
set_hosts() {
  if [ -z "${SITE_A_HOST:-}" ] || [ -z "${SITE_B_HOST:-}" ]; then
    : "${TUNNEL_DOMAIN:?Set TUNNEL_DOMAIN, for example example.com (or SITE_A_HOST and SITE_B_HOST)}"
  fi
  export SITE_A_HOST="${SITE_A_HOST:-site-a-import-engine.${TUNNEL_DOMAIN:-}}"
  export SITE_B_HOST="${SITE_B_HOST:-site-b-import-engine.${TUNNEL_DOMAIN:-}}"
}

write_overlay() {
  mkdir -p deploy/k8s/overlays/tunnel-generated
  envsubst '${SITE_A_HOST} ${SITE_B_HOST}' < deploy/k8s/overlays/tunnel/kustomization.tpl \
    > deploy/k8s/overlays/tunnel-generated/kustomization.yaml
}

secret_value() {
  $K get secret bootstrap-secret -o jsonpath="{.data.$1}" | base64 -d
}

case "${1:-up}" in
  up)
    : "${CLOUDFLARE_TUNNEL_TOKEN:?Set CLOUDFLARE_TUNNEL_TOKEN (see docs/tunnel.md)}"
    set_hosts
    if k3d cluster get "$CLUSTER" >/dev/null 2>&1; then
      echo "The cluster ${CLUSTER} exists. The passwords of a cluster cannot be changed afterwards:" >&2
      echo "run deploy/local.sh down (or deploy/tunnel.sh down) first." >&2
      exit 1
    fi

    docker build -f deploy/Dockerfile -t "$IMAGE" .
    # No port on this machine: the tunnel goes outward.
    k3d cluster create "$CLUSTER" --wait
    k3d image import "$IMAGE" -c "$CLUSTER"

    kubectl --context "$CTX" create namespace import-engine
    rand() { openssl rand -hex "$1"; }
    # One secret per workload, with only what it needs. The values are made
    # once, so that where a variable is in more than one they are the same.
    ROOT_PW="$(rand 16)"; A_DB="$(rand 16)"; B_DB="$(rand 16)"
    A_SALT="$(rand 32)"; B_SALT="$(rand 32)"; API_KEY="$(rand 24)"
    ADMIN_PW="$(rand 12)"; DEMO_PW="$(rand 6)"
    $K create secret generic mariadb-secret \
      --from-literal=MARIADB_ROOT_PASSWORD="$ROOT_PW" \
      --from-literal=SITE_A_DB_PASSWORD="$A_DB" \
      --from-literal=SITE_B_DB_PASSWORD="$B_DB"
    $K create secret generic web-secret \
      --from-literal=SITE_A_DB_PASSWORD="$A_DB" \
      --from-literal=SITE_B_DB_PASSWORD="$B_DB" \
      --from-literal=SITE_A_HASH_SALT="$A_SALT" \
      --from-literal=SITE_B_HASH_SALT="$B_SALT" \
      --from-literal=SITE_A_API_KEY="$API_KEY"
    $K create secret generic site-b-secret \
      --from-literal=SITE_B_DB_PASSWORD="$B_DB" \
      --from-literal=SITE_B_HASH_SALT="$B_SALT" \
      --from-literal=SITE_A_API_KEY="$API_KEY"
    $K create secret generic bootstrap-secret \
      --from-literal=SITE_A_DB_PASSWORD="$A_DB" \
      --from-literal=SITE_B_DB_PASSWORD="$B_DB" \
      --from-literal=SITE_A_HASH_SALT="$A_SALT" \
      --from-literal=SITE_B_HASH_SALT="$B_SALT" \
      --from-literal=SITE_A_API_KEY="$API_KEY" \
      --from-literal=ADMIN_PASSWORD="$ADMIN_PW" \
      --from-literal=DEMO_PASSWORD="$DEMO_PW"
    $K create secret generic tunnel-token --from-literal=token="$CLOUDFLARE_TUNNEL_TOKEN"

    write_overlay
    kubectl --context "$CTX" apply -k deploy/k8s/overlays/tunnel-generated
    $K rollout status statefulset/mariadb --timeout=300s
    $K wait --for=condition=complete job/bootstrap --timeout=900s
    $K rollout status deployment/web --timeout=300s
    $K rollout status deployment/cloudflared --timeout=120s
    echo
    echo "Site A: https://${SITE_A_HOST}"
    echo "Site B: https://${SITE_B_HOST}"
    "$0" passwords
    ;;
  hosts)
    # New hostnames for a cluster that runs: the sites are not installed again,
    # the pods only need to know their names.
    set_hosts
    write_overlay
    kubectl --context "$CTX" apply -k deploy/k8s/overlays/tunnel-generated
    $K rollout restart deployment/web deployment/import-worker
    $K rollout status deployment/web --timeout=300s
    echo "Site A: https://${SITE_A_HOST}"
    echo "Site B: https://${SITE_B_HOST}"
    ;;
  passwords)
    echo "admin: $(secret_value ADMIN_PASSWORD)   (can change everything)"
    echo "demo:  $(secret_value DEMO_PASSWORD)   (can only look; give this one to others)"
    ;;
  down)
    k3d cluster delete "$CLUSTER"
    rm -rf deploy/k8s/overlays/tunnel-generated
    ;;
  *)
    echo "Usage: $0 up|hosts|passwords|down" >&2
    exit 1
    ;;
esac
