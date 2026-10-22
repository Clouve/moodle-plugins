#!/usr/bin/env bash
# Tests for bin/list-plugins.
set -euo pipefail
# shellcheck source=tests/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

section "list-plugins on the real repo"

run "$REAL_REPO/bin/list-plugins"
assert_status "exits 0" 0
assert_eq "lists only the Academic Transcript plugin" "local/academictranscript" "$OUT"

run "$REAL_REPO/bin/list-plugins" --json
assert_status "--json exits 0" 0
assert_eq "--json prints a one-item array" '["local/academictranscript"]' "$OUT"

section "list-plugins with fixture plugins"

repo=$(make_repo)
copy_real_plugin "$repo"
add_plugin "$repo" local/demo local_demo 1.0.0
add_plugin "$repo" admin/tool/demo tool_demo 1.0.0
# Inside local/demo: part of that plugin, not a plugin of its own.
add_plugin "$repo" local/demo/sub local_sub 1.0.0
# Under tests/, dist/ and .git/: never plugins.
add_plugin "$repo" tests/fixtures/plugin local_fixture 1.0.0
add_plugin "$repo" dist/unpacked local_unpacked 1.0.0
add_plugin "$repo" .git/modules/x local_gitmodule 1.0.0
# A version.php that does not set the component, such as a library's.
mkdir -p "$repo/lib/thing"
printf "<?php\n\$library->version = '1.2.3';\n" >"$repo/lib/thing/version.php"
# One that only mentions the component in a comment.
mkdir -p "$repo/local/commented"
printf "<?php\n// \$plugin->component = 'local_commented';\n" >"$repo/local/commented/version.php"

expected="admin/tool/demo
local/academictranscript
local/demo"

run "$repo/bin/list-plugins"
assert_status "exits 0" 0
assert_eq "lists the plugin folders, sorted, without nested or ignored ones" "$expected" "$OUT"

run "$repo/bin/list-plugins" --json
assert_status "--json exits 0" 0
assert_eq "--json prints a JSON array on one line" \
  '["admin/tool/demo","local/academictranscript","local/demo"]' "$OUT"

run_in / "$repo/bin/list-plugins"
assert_eq "finds the repo root from its own location, not the current folder" "$expected" "$OUT"

section "list-plugins: nested folders beside similar names"

repo=$(make_repo)
add_plugin "$repo" local/demo local_demo 1.0.0
add_plugin "$repo" local/demo-two local_demotwo 1.0.0
add_plugin "$repo" local/demo/sub local_sub 1.0.0
add_plugin "$repo" local/demo/sub/deeper local_deeper 1.0.0

run "$repo/bin/list-plugins"
assert_status "exits 0" 0
assert_eq "drops every folder inside a plugin, even when a sibling sorts in between" \
  "local/demo
local/demo-two" "$OUT"

section "list-plugins: no plugins"

repo=$(make_repo)

run "$repo/bin/list-plugins"
assert_status "exits 0" 0
assert_eq "prints nothing" "" "$OUT"

run "$repo/bin/list-plugins" --json
assert_status "--json exits 0" 0
assert_eq "--json prints an empty array" "[]" "$OUT"

section "list-plugins: bad input"

repo=$(make_repo)
add_plugin "$repo" "local/my plugin" local_my 1.0.0

run "$repo/bin/list-plugins"
assert_status "a folder name with a space exits 1" 1
assert_contains "and names the folder" "$ERR" "local/my plugin"

run "$repo/bin/list-plugins" --bogus
assert_status "an unknown option exits 2" 2
assert_contains "and prints the usage" "$ERR" "Usage: bin/list-plugins"

run "$repo/bin/list-plugins" --json extra
assert_status "an extra argument exits 2" 2

run "$repo/bin/list-plugins" --help
assert_status "--help exits 0" 0
assert_contains "and prints the usage" "$OUT" "Usage: bin/list-plugins"

finish
