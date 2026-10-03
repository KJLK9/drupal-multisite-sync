#!/usr/bin/env bash
# PostToolUse: auto-fix and lint the PHP file Claude just edited.
file=$(python3 -c 'import json,sys; print(json.load(sys.stdin).get("tool_input",{}).get("file_path",""))')
case "$file" in
  */web/modules/custom/*) ;;
  *) exit 0 ;;
esac
case "$file" in
  *.php|*.module|*.inc|*.install|*.yml) ;;
  *) exit 0 ;;
esac
rel="${file#"$CLAUDE_PROJECT_DIR"/}"
ddev exec vendor/bin/phpcbf --standard=phpcs.xml.dist "$rel" >/dev/null 2>&1
if ! out=$(ddev exec vendor/bin/phpcs --standard=phpcs.xml.dist "$rel" 2>&1); then
  echo "$out" >&2
  exit 2
fi
