#!/usr/bin/env bash
# Tests for bin/build-zip.
set -euo pipefail
# shellcheck source=tests/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

# entries <zip>: the entry names of a ZIP, one per line. Prints nothing when the
# ZIP is missing, so the assertions fail one by one instead of aborting the file.
entries() {
  unzip -Z1 "$1" 2>/dev/null || true
}

section "build-zip: the real plugin"

repo=$(make_repo)
copy_real_plugin "$repo"
plugin=$repo/local/academictranscript
release=$(plugin_release "$REAL_REPO/local/academictranscript/version.php")

# Junk that must stay out of the ZIP.
touch "$plugin/.DS_Store" "$plugin/lib/.DS_Store" "$plugin/classes/local/.calculator.php.swp" \
  "$plugin/.gitignore" "$plugin/.gitattributes"
mkdir "$plugin/.git"
echo "ref: refs/heads/main" >"$plugin/.git/HEAD"

run_in "$repo" bin/build-zip local/academictranscript
zip=$repo/dist/local_academictranscript-$release.zip
assert_status "exits 0" 0
assert_eq "prints the path of the ZIP" "$zip" "$OUT"
assert_file "writes dist/local_academictranscript-<release>.zip" "$zip"

list=$(entries "$zip")
outside=$(printf '%s\n' "$list" | grep -v '^academictranscript/' || true)
assert_eq "every entry starts with academictranscript/" "" "$outside"

has_version=$(printf '%s\n' "$list" | grep -cx 'academictranscript/version.php' || true)
assert_eq "includes academictranscript/version.php" "1" "$has_version"

# The bundled pdfparser is LGPL-3.0, which asks for the GNU GPL text to travel with
# the combined work. The ZIP holds only the plugin folder, so the folder needs its
# own copy of the repo's LICENSE.
has_license=$(printf '%s\n' "$list" | grep -cx 'academictranscript/LICENSE' || true)
assert_eq "includes academictranscript/LICENSE" "1" "$has_license"
if unzip -p "$zip" academictranscript/LICENSE 2>/dev/null | cmp -s - "$REAL_REPO/LICENSE"; then
  pass "and it is the LICENSE of the repo, byte for byte"
else
  fail "and it is the LICENSE of the repo, byte for byte"
fi

junk=$(printf '%s\n' "$list" | grep -E '(^|/)\.DS_Store$|\.swp$|(^|/)\.git' || true)
assert_eq "has no .DS_Store, *.swp or .git* entries" "" "$junk"

expected=$(cd "$REAL_REPO/local" && find academictranscript -type f \
  ! -name .DS_Store ! -name '*.swp' ! -name '.git*' | LC_ALL=C sort)
actual=$(printf '%s\n' "$list" | LC_ALL=C sort)
assert_eq "holds exactly the files of the plugin folder" "$expected" "$actual"

run unzip -tq "$zip"
assert_status "passes unzip's integrity test" 0

echo "temporary" >"$plugin/stale.txt"
run_in "$repo" bin/build-zip local/academictranscript
assert_contains "a new file shows up in the ZIP" "$(entries "$zip")" "academictranscript/stale.txt"
rm "$plugin/stale.txt"
run_in "$repo" bin/build-zip local/academictranscript
assert_lacks "building again replaces the ZIP, so a removed file is gone" "$(entries "$zip")" "stale.txt"

run_in "$repo" bin/build-zip local/academictranscript "$TMP/out/nested"
assert_status "an out dir argument: exits 0" 0
assert_eq "an out dir argument is created and used" \
  "$TMP/out/nested/local_academictranscript-$release.zip" "$OUT"
assert_file "and the ZIP is there" "$TMP/out/nested/local_academictranscript-$release.zip"

run_in / "$repo/bin/build-zip" "$plugin/" "$TMP/out2"
assert_status "an absolute path with a trailing slash, from another folder: exits 0" 0
assert_eq "and the ZIP goes to the given out dir" \
  "$TMP/out2/local_academictranscript-$release.zip" "$OUT"

section "build-zip: a small fixture plugin"

repo=$(make_repo)
add_plugin "$repo" local/demo local_demo 2.1.0
mkdir -p "$repo/local/demo/lang/en" "$repo/local/demo/with space"
echo "<?php" >"$repo/local/demo/lang/en/local_demo.php"
echo "x" >"$repo/local/demo/with space/note file.txt"

run_in "$repo" bin/build-zip local/demo
assert_status "exits 0" 0
assert_eq "names the ZIP <component>-<release>.zip" "$repo/dist/local_demo-2.1.0.zip" "$OUT"
assert_eq "holds only demo/..., including names with spaces" \
  "demo/lang/en/local_demo.php
demo/version.php
demo/with space/note file.txt" "$(entries "$OUT" | LC_ALL=C sort)"

section "build-zip: folders that must be refused"

repo=$(make_repo)
mkdir -p "$repo/local/noversion"
echo "<?php" >"$repo/local/noversion/lib.php"
add_plugin "$repo" local/wrongname local_demo 1.0.0
add_plugin "$repo" local/norelease local_norelease
mkdir -p "$repo/local/nocomponent"
printf "<?php\n\$plugin->release = '1.0.0';\n" >"$repo/local/nocomponent/version.php"
add_plugin "$repo" local/withspace local_withspace "1.0 beta"
add_plugin "$repo" local/withslash local_withslash "../1.0"

run_in "$repo" bin/build-zip local/noversion
assert_status "a folder without version.php exits 1" 1
assert_contains "and says so" "$ERR" "version.php"

run_in "$repo" bin/build-zip local/wrongname
assert_status "a folder named wrongname exits 1" 1
assert_contains "and names the folder" "$ERR" "wrongname"
assert_contains "and the folder name the component needs" "$ERR" '"demo"'

run_in "$repo" bin/build-zip local/norelease
assert_status "a version.php without a release exits 1" 1
assert_contains "and says which setting is missing" "$ERR" 'plugin->release'

run_in "$repo" bin/build-zip local/nocomponent
assert_status "a version.php without a component exits 1" 1
assert_contains "and says which setting is missing" "$ERR" 'plugin->component'

run_in "$repo" bin/build-zip local/withspace
assert_status "a release with a space exits 1" 1
assert_contains "and says it is not a usable release" "$ERR" "1.0 beta"

run_in "$repo" bin/build-zip local/withslash
assert_status "a release with a slash exits 1" 1

run_in "$repo" bin/build-zip local/nope
assert_status "a folder that does not exist exits 1" 1
assert_contains "and names it" "$ERR" "local/nope"

run_in "$repo" bin/build-zip
assert_status "no argument exits 2" 2
assert_contains "and prints the usage" "$ERR" "Usage: bin/build-zip"

run_in "$repo" bin/build-zip a b c
assert_status "too many arguments exits 2" 2

zips=$(find "$repo" -name '*.zip')
assert_eq "none of these wrote a ZIP" "" "$zips"

finish
