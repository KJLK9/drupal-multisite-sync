# Template: deploy/tunnel.sh fills in the domain and writes the result to
# ../tunnel-generated/kustomization.yaml (not in git).
apiVersion: kustomize.config.k8s.io/v1beta1
kind: Kustomization
namespace: import-engine
resources:
  - ../../base
  - ../tunnel/cloudflared
patches:
  - target: {kind: ConfigMap, name: site-config}
    patch: |-
      - op: replace
        path: /data/SITE_A_HOST
        value: ${SITE_A_HOST}
      - op: replace
        path: /data/SITE_B_HOST
        value: ${SITE_B_HOST}
  # Cloudflare sends the traffic to the web service, so there is no ingress.
  - target: {kind: Ingress, name: sites}
    patch: |-
      $patch: delete
      apiVersion: networking.k8s.io/v1
      kind: Ingress
      metadata:
        name: sites
