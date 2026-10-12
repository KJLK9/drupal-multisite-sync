#!/bin/bash
# Runs the whole thing in Kubernetes on this machine, with k3d (k3s in Docker):
# builds the image, creates a cluster, applies the local overlay and waits for
# the sites to be installed.
#
#   deploy/local.sh up      build, create and deploy
#   deploy/local.sh down    remove the cluster and everything in it
#   deploy/local.sh status  show the pods and the jobs
#
# The sites answer on http://site-a.import-engine.localhost:8080 and
# http://site-b.import-engine.localhost:8080 (admin / admin-local).
set -euo pipefail
cd "$(dirname "$0")/.."

# Where a user-level install of k3d goes.
export PATH="$HOME/.local/bin:$PATH"

CLUSTER=import-engine
IMAGE=import-engine:local
K="kubectl --context k3d-${CLUSTER} -n import-engine"

case "${1:-up}" in
  up)
    docker build -f deploy/Dockerfile -t "$IMAGE" .
    k3d cluster get "$CLUSTER" >/dev/null 2>&1 || \
      k3d cluster create "$CLUSTER" --port "8080:80@loadbalancer" --wait
    k3d image import "$IMAGE" -c "$CLUSTER"
    # A Job cannot be changed once it exists: replace it.
    $K delete job bootstrap --ignore-not-found
    kubectl --context "k3d-${CLUSTER}" apply -k deploy/k8s/overlays/local
    $K rollout status statefulset/mariadb --timeout=300s
    $K wait --for=condition=complete job/bootstrap --timeout=900s
    $K rollout status deployment/web --timeout=300s
    echo
    echo "Site A: http://site-a.import-engine.localhost:8080"
    echo "Site B: http://site-b.import-engine.localhost:8080 (admin / admin-local)"
    ;;
  down)
    k3d cluster delete "$CLUSTER"
    ;;
  status)
    $K get pods,jobs,cronjobs,ingress
    ;;
  *)
    echo "Usage: $0 up|down|status" >&2
    exit 1
    ;;
esac
