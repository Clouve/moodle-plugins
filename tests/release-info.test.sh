#!/usr/bin/env bash
# Tests for bin/release-info.
set -euo pipefail
# shellcheck source=tests/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

# line <n> <text>: line n of a text.
line() {
  printf '%s\n' "$2" | sed -n "${1}p"
}

# line_count <text>: how many lines a text has.
line_count() {
  printf '%s\n' "$1" | awk 'END { print NR }'
}

section "release-info: a release with notes"

repo=$(make_repo)
add_plugin "$repo" local/demo local_demo 2.1.0
cat >"$repo/local/demo/CHANGES.md" <<'EOF'
# Changes

## 2.1.0

- Added the thing.
- Fixed the other thing.

### Notes

Read this before upgrading.

## 2.0.0

- Old feature.
EOF

run "$repo/bin/release-info" local_demo-v2.1.0
assert_status "the tag local_demo-v2.1.0 exits 0" 0
assert_eq "prints path, component, release and zip" "path=local/demo
component=local_demo
release=2.1.0
zip=local_demo-2.1.0.zip" "$(printf '%s\n' "$OUT" | sed -n 1,4p)"
assert_eq "prints five lines" "5" "$(line_count "$OUT")"

notes_line=$(line 5 "$OUT")
case $notes_line in
  notes=/*) pass "the fifth line is notes=<absolute path>" ;;
  *)
    fail "the fifth line is notes=<absolute path>"
    detail "actual" "$notes_line"
    ;;
esac
notes=${notes_line#notes=}
assert_file "the notes file exists" "$notes"
assert_eq "the notes file holds the 2.1.0 section only, without its heading" "- Added the thing.
- Fixed the other thing.

### Notes

Read this before upgrading." "$(cat "$notes")"
assert_eq "the notes file starts with text, not a blank line" \
  "- Added the thing." "$(head -n 1 "$notes")"
assert_eq "the notes file ends with text, not a blank line" \
  "Read this before upgrading." "$(tail -n 1 "$notes")"
assert_lacks "the notes file does not repeat the release heading" "$(cat "$notes")" "## 2.1.0"
assert_lacks "the notes file has nothing from 2.0.0" "$(cat "$notes")" "Old feature"

run "$repo/bin/release-info" refs/tags/local_demo-v2.1.0
assert_status "a full ref name refs/tags/... works too" 0
assert_eq "and gives the same path" "path=local/demo" "$(line 1 "$OUT")"

section "release-info: tags that must be refused"

run "$repo/bin/release-info" local_demo-v9.9.9
assert_status "a release that differs from version.php exits 1" 1
assert_contains "and names the tag release" "$ERR" "9.9.9"
assert_contains "and the release in version.php" "$ERR" "2.1.0"
assert_eq "and prints nothing on stdout" "" "$OUT"

run "$repo/bin/release-info" local_demo-v2.0.0
assert_status "an older release that has notes but is not in version.php exits 1" 1

run "$repo/bin/release-info" local_nope-v1.0.0
assert_status "an unknown component exits 1" 1
assert_contains "and names it" "$ERR" "local_nope"
assert_eq "and prints nothing on stdout" "" "$OUT"

run "$repo/bin/release-info" nonsense
assert_status "a tag that is not <component>-v<release> exits 1" 1
assert_contains "and names it" "$ERR" "nonsense"

run "$repo/bin/release-info" v1.0.0
assert_status "a tag without a component exits 1" 1

run "$repo/bin/release-info" local_demo-v
assert_status "a tag without a release exits 1" 1

run "$repo/bin/release-info"
assert_status "no argument exits 2" 2
assert_contains "and prints the usage" "$ERR" "Usage: bin/release-info"

run "$repo/bin/release-info" a b
assert_status "two arguments exit 2" 2

section "release-info: CHANGES.md problems"

repo=$(make_repo)
add_plugin "$repo" local/nosection local_nosection 3.0.0
printf '# Changes\n\n## 2.9.0\n\n- Something.\n' >"$repo/local/nosection/CHANGES.md"
add_plugin "$repo" local/nochanges local_nochanges 1.0.0
add_plugin "$repo" local/prefix local_prefix 1.0
printf '# Changes\n\n## 1.0.1\n\n- Later.\n' >"$repo/local/prefix/CHANGES.md"
add_plugin "$repo" local/empty local_empty 1.0.0
printf '# Changes\n\n## 1.0.0\n\n## 0.9.0\n\n- Beta.\n' >"$repo/local/empty/CHANGES.md"

run "$repo/bin/release-info" local_nosection-v3.0.0
assert_status "a CHANGES.md without the section exits 1" 1
assert_contains "and names the missing heading" "$ERR" "## 3.0.0"
assert_eq "and prints nothing on stdout" "" "$OUT"

run "$repo/bin/release-info" local_nochanges-v1.0.0
assert_status "a plugin without CHANGES.md exits 1" 1
assert_contains "and names the file" "$ERR" "local/nochanges/CHANGES.md"

run "$repo/bin/release-info" local_prefix-v1.0
assert_status "a heading for 1.0.1 is not a section for 1.0" 1
assert_contains "and names the missing heading" "$ERR" "## 1.0"

run "$repo/bin/release-info" local_empty-v1.0.0
assert_status "a heading with no notes under it exits 1" 1
assert_contains "and says the section is empty" "$ERR" "empty"

section "release-info: other heading and file styles"

repo=$(make_repo)
add_plugin "$repo" local/dated local_dated 1.0.0
printf '# Changes\r\n\r\n## 1.0.0 (2026-10-21)\r\n\r\n- First release.\r\n\r\n## 0.9.0\r\n\r\n- Beta.\r\n' \
  >"$repo/local/dated/CHANGES.md"
add_plugin "$repo" local/pre local_pre 1.0.0-rc.1
printf '# Changes\n\n## 1.0.0-rc.1\n\n- Candidate.\n\n## 0.9.0\n\n- Beta.\n' >"$repo/local/pre/CHANGES.md"
add_plugin "$repo" local/last local_last 1.0.0
printf '# Changes\n\n## 1.0.0\n\n- Only release.\n\n\n' >"$repo/local/last/CHANGES.md"
add_plugin "$repo" local/spaced local_spaced 1.0.0
printf '# Changes\n\n## 1.0.0\n\n\n\n- Spaced out.\n\n## 0.9.0\n\n- Beta.\n' >"$repo/local/spaced/CHANGES.md"
add_plugin "$repo" local/tight local_tight 1.0.0
printf '# Changes\n\n## 1.0.0\nRight under the heading.\n\n## 0.9.0\n\n- Beta.\n' >"$repo/local/tight/CHANGES.md"

run "$repo/bin/release-info" local_dated-v1.0.0
assert_status "a heading with a date and CRLF line endings exits 0" 0
notes=$(line 5 "$OUT")
notes=${notes#notes=}
assert_eq "and the notes hold that section, without its heading" "- First release." "$(cat "$notes")"
carriage_returns=$(grep -c "$(printf '\r')" "$notes" || true)
assert_eq "and have no carriage returns" "0" "$carriage_returns"

run "$repo/bin/release-info" local_pre-v1.0.0-rc.1
assert_status "a release with a hyphen exits 0" 0
assert_eq "and keeps the whole release" "release=1.0.0-rc.1" "$(line 3 "$OUT")"
assert_eq "and names the ZIP with it" "zip=local_pre-1.0.0-rc.1.zip" "$(line 4 "$OUT")"

run "$repo/bin/release-info" local_last-v1.0.0
assert_status "the last section of the file exits 0" 0
notes=$(line 5 "$OUT")
notes=${notes#notes=}
assert_eq "and its notes end with text" "- Only release." "$(tail -n 1 "$notes")"

run "$repo/bin/release-info" local_spaced-v1.0.0
assert_status "blank lines under the heading: exits 0" 0
notes=$(line 5 "$OUT")
notes=${notes#notes=}
assert_eq "and the notes start with text" "- Spaced out." "$(cat "$notes")"

run "$repo/bin/release-info" local_tight-v1.0.0
assert_status "text right under the heading: exits 0" 0
notes=$(line 5 "$OUT")
notes=${notes#notes=}
assert_eq "and the notes hold that text" "Right under the heading." "$(cat "$notes")"

section "release-info: two folders with one component"

repo=$(make_repo)
add_plugin "$repo" local/demo local_demo 1.0.0
add_plugin "$repo" local/other local_demo 1.0.0

run "$repo/bin/release-info" local_demo-v1.0.0
assert_status "a component set by two folders exits 1" 1
assert_contains "and names both" "$ERR" "local/demo local/other"

finish
