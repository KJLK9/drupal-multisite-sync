#!/usr/bin/env bash
# Stop: block "done" while phpcs or phpstan are red (only if PHP changed).
input=$(cat)
if python3 -c 'import json,sys; sys.exit(0 if json.loads(sys.argv[1]).get("stop_hook_active") else 1)' "$input"; then
  exit 0
fi
cd "$CLAUDE_PROJECT_DIR" || exit 0
git status --porcelain -- web/modules | grep -qE '\.(php|module|inc|install|yml)$' || exit 0
out=$(ddev composer phpcs 2>&1 && ddev composer phpstan 2>&1) || {
  echo "phpcs/phpstan failed. Fix before finishing:" >&2
  echo "$out" | tail -40 >&2
  exit 2
}
