#!/usr/bin/env bash
# Tests for bin/check-docs, and the check of the real docs.
set -euo pipefail
# shellcheck source=tests/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

# good_repo: a throwaway repo with one small plugin and docs that pass every check.
good_repo() {
  local repo
  repo=$(make_repo)
  add_plugin "$repo" local/demo local_demo 1.0.0
  mkdir -p "$repo/local/demo/db" "$repo/local/demo/classes/local" "$repo/local/demo/classes/task"
  printf '<?php\n' >"$repo/local/demo/index.php"
  cat >"$repo/local/demo/db/access.php" <<'PHP'
<?php
$capabilities = [
    'local/demo:view' => [
        'captype' => 'read',
    ],
];
PHP
  cat >"$repo/local/demo/db/install.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<XMLDB PATH="local/demo/db" VERSION="20260101">
  <TABLES>
    <TABLE NAME="local_demo_thing" COMMENT="Things">
      <FIELDS>
        <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="name" TYPE="char" LENGTH="20" NOTNULL="true" SEQUENCE="false"/>
      </FIELDS>
    </TABLE>
  </TABLES>
</XMLDB>
XML
  cat >"$repo/local/demo/classes/local/helper.php" <<'PHP'
<?php
namespace local_demo\local;
class helper {
    const LIMIT = 3;
    public static function total(): int {
        return 1;
    }
}
PHP
  printf '<?php\nnamespace local_demo\\task;\nclass tidy {\n}\n' >"$repo/local/demo/classes/task/tidy.php"
  cat >"$repo/local/demo/settings.php" <<'PHP'
<?php
$p = 'local_demo';
$settings->add(new admin_setting_heading("$p/top", 'Top', ''));
$settings->add(new admin_setting_configtext("$p/colour", 'Colour', '', ''));
PHP
  mkdir -p "$repo/docs/user/images" "$repo/docs/developer"
  printf 'PNG' >"$repo/docs/user/images/home.png"
  cat >"$repo/docs/README.md" <<'MD'
# Docs

Read the [user guide](user/README.md) or the [developer guide](developer/README.md#the-code).
MD
  cat >"$repo/docs/user/README.md" <<'MD'
# User guide

Open `index.php` and set `colour`. You need `local/demo:view`.

![The home page with one card](images/home.png)

See the [developer guide](../developer/README.md).
MD
  cat >"$repo/docs/developer/README.md" <<'MD'
# Developer guide

## The code

`local/demo/classes/local/helper.php` holds `helper::total()` and `helper::LIMIT`. The task is `tidy`.

### Table `local_demo_thing`

Columns: `id` and `name`.

Back to the [docs](../README.md).
MD
  printf '%s\n' "$repo"
}

# check <repo> [args...]: run check-docs from a repo.
check() {
  local repo=$1
  shift
  run_in "$repo" bin/check-docs "$@"
}

section "check-docs on the real repo"

run "$REAL_REPO/bin/check-docs"
assert_status "the real docs pass" 0

section "check-docs on good docs"

repo=$(good_repo)
check "$repo"
assert_status "exits 0" 0
assert_contains "reports the pages" "$OUT" "3 pages, 0 problems"

section "check-docs: options"

check "$repo" --only nothing
assert_status "an unknown check exits 2" 2
check "$repo" --help
assert_status "--help exits 0" 0
assert_contains "--help lists the checks" "$OUT" "coverage"
rm -rf "$repo/docs"
check "$repo"
assert_status "no docs folder exits 2" 2

repo=$(good_repo)
printf '#!/usr/bin/env bash\necho "broken" >&2\nexit 1\n' >"$repo/bin/list-plugins"
check "$repo"
assert_status "a failing bin/list-plugins exits 2" 2
assert_contains "and says so" "$ERR" "bin/list-plugins failed: broken"

section "check-docs: links"

repo=$(good_repo)
printf '\nSee [nothing](nothing.md).\n' >>"$repo/docs/user/README.md"
check "$repo" --only links
assert_status "a missing file fails" 1
assert_contains "names the target" "$OUT" "link target not found: nothing.md"

repo=$(good_repo)
printf '\nSee [the code](../developer/README.md#no-such-part).\n' >>"$repo/docs/user/README.md"
check "$repo" --only links
assert_status "a missing anchor fails" 1
assert_contains "names the anchor" "$OUT" "anchor not found"

repo=$(good_repo)
printf '# Alone\n' >"$repo/docs/alone.md"
check "$repo" --only links
assert_status "a page nothing links to fails" 1
assert_contains "names the page" "$OUT" "docs/alone.md: no link leads here"

repo=$(good_repo)
printf '\n```\n[not a link](nothing.md)\n```\n\nCode: `[nor this](nothing.md)`\n' >>"$repo/docs/user/README.md"
check "$repo" --only links
assert_status "links in code don't count" 0

section "check-docs: images"

repo=$(good_repo)
printf '\n![](images/home.png)\n' >>"$repo/docs/user/README.md"
check "$repo" --only images
assert_status "an image with no alt text fails" 1
assert_contains "says so" "$OUT" "image has no alt text"

repo=$(good_repo)
printf '\n![A missing picture](images/missing.png)\n\n<img src="images/gone.png" alt="A gone picture">\n' >>"$repo/docs/user/README.md"
check "$repo" --only images
assert_status "a missing image fails" 1
assert_contains "names it" "$OUT" "image not found: images/missing.png"
assert_contains "and one in an img tag" "$OUT" "image not found: images/gone.png"

repo=$(good_repo)
printf 'PNG' >"$repo/docs/user/images/unused.png"
check "$repo" --only images
assert_status "an unused image fails" 1
assert_contains "names it" "$OUT" "unused.png: no page uses this image"

repo=$(good_repo)
head -c 400000 /dev/zero >"$repo/docs/user/images/home.png"
check "$repo" --only images
assert_status "a large image fails" 1
assert_contains "says how large" "$OUT" "image is 390 KB"

section "check-docs: paths and symbols"

repo=$(good_repo)
printf '\nSee `local/demo/classes/local/gone.php`.\n' >>"$repo/docs/user/README.md"
check "$repo" --only paths
assert_status "a missing repo path fails" 1
assert_contains "names it" "$OUT" "path not found: local/demo/classes/local/gone.php"

repo=$(good_repo)
printf '\nSee `/local/demo/index.php?id=2`, `public/local/demo/index.php`, `classes/local/helper.php` and `<moodle>/lib/setup.php`.\n' >>"$repo/docs/user/README.md"
check "$repo" --only paths
assert_status "URLs, public paths, plugin paths and placeholders pass" 0

repo=$(good_repo)
printf '\nCall `helper::gone()`.\n' >>"$repo/docs/user/README.md"
check "$repo" --only symbols
assert_status "a missing method fails" 1
assert_contains "names it" "$OUT" "helper has no gone"

repo=$(good_repo)
printf '\nCall `context_system::instance()`.\n' >>"$repo/docs/user/README.md"
check "$repo" --only symbols
assert_status "a class of Moodle is left alone" 0

section "check-docs: coverage"

repo=$(good_repo)
printf '<?php\n' >"$repo/local/demo/report.php"
check "$repo" --only coverage
assert_status "a page with no docs fails" 1
assert_contains "names the page" "$OUT" "the docs name no page report.php"

repo=$(good_repo)
sed -i.bak "s#'local/demo:view'#'local/demo:edit'#" "$repo/local/demo/db/access.php"
rm "$repo/local/demo/db/access.php.bak"
check "$repo" --only coverage
assert_status "a capability with no docs fails" 1
assert_contains "names the capability" "$OUT" "the docs name no capability local/demo:edit"

repo=$(good_repo)
sed -i.bak 's#"$p/colour"#"$p/shade"#' "$repo/local/demo/settings.php"
rm "$repo/local/demo/settings.php.bak"
check "$repo" --only coverage
assert_status "a setting with no docs fails" 1
assert_contains "names the setting" "$OUT" "the docs name no setting shade"
assert_lacks "a heading is not a setting" "$OUT" "setting top"

repo=$(good_repo)
printf '<?php\nnamespace local_demo\\task;\nclass sweep {\n}\n' >"$repo/local/demo/classes/task/sweep.php"
check "$repo" --only coverage
assert_status "a task with no docs fails" 1
assert_contains "names the task" "$OUT" "the docs name no task sweep"

repo=$(good_repo)
mkdir -p "$repo/local/demo/classes/form"
printf '<?php\nnamespace local_demo\\form;\nclass editor {\n}\n' >"$repo/local/demo/classes/form/editor.php"
check "$repo" --only coverage
assert_status "a class outside classes/local with no docs fails" 1
assert_contains "names the class" "$OUT" "the docs name no class editor"

repo=$(good_repo)
sed -i.bak 's#<FIELD NAME="name"#<FIELD NAME="label"#' "$repo/local/demo/db/install.xml"
rm "$repo/local/demo/db/install.xml.bak"
check "$repo" --only coverage
assert_status "a column with no docs fails" 1
assert_contains "names the column" "$OUT" "the section of table local_demo_thing names no column label"

repo=$(good_repo)
sed -i.bak 's#local_demo_thing#local_demo_other#' "$repo/local/demo/db/install.xml"
rm "$repo/local/demo/db/install.xml.bak"
check "$repo" --only coverage
assert_status "a table with no section fails" 1
assert_contains "names the table" "$OUT" "docs/developer has no heading for table local_demo_other"

section "check-docs: writing"

for bad in "A long dash \xe2\x80\x94 here." "From 1 \xe2\x80\x93 2." "A \xe2\x80\x9cquote\xe2\x80\x9d." "It\xe2\x80\x99s here." "Great work!" "See line 42." "See index.php:42."; do
  repo=$(good_repo)
  printf "\n$bad\n" >>"$repo/docs/user/README.md"
  check "$repo" --only writing
  assert_status "fails on: $(printf "$bad")" 1
done

repo=$(good_repo)
printf '\n```\nGreat work! \xe2\x80\x94 line 42\n```\n\nCode: `a != b` and ![An image](images/home.png).\n' >>"$repo/docs/user/README.md"
check "$repo" --only writing
assert_status "code and images don't count" 0

repo=$(good_repo)
printf '\n## How To Post Final Grades\n' >>"$repo/docs/user/README.md"
check "$repo" --only writing
assert_status "a heading in title case fails" 1
assert_contains "names the heading" "$OUT" "not in sentence case: How To Post Final Grades"

repo=$(good_repo)
printf '\n## Post final grades in Student Records with Moodle\n' >>"$repo/docs/user/README.md"
check "$repo" --only writing
assert_status "names in a heading pass" 0

repo=$(good_repo)
printf '\n## One\n\n## Two\n\n## Three\n\n## Four\n\n## Five\n' >>"$repo/docs/user/README.md"
check "$repo" --only writing
assert_status "a long page with no contents fails" 1
assert_contains "says so" "$OUT" 'no "## Contents" heading'

repo=$(good_repo)
printf '\n## Contents\n\n- [One](#one)\n- [Two](#two)\n- [Three](#three)\n- [Four](#four)\n\n## One\n\n## Two\n\n## Three\n\n## Four\n\n## Five\n' >>"$repo/docs/user/README.md"
check "$repo" --only writing
assert_status "contents that miss a section fail" 1
assert_contains "names the section" "$OUT" "Contents has no link to: Five"

section "check-docs: scope"

repo=$(good_repo)
printf '\nGreat work!\n' >>"$repo/docs/developer/README.md"
check "$repo" --only writing docs/user
assert_status "a path limits the check" 0
check "$repo" --only writing docs/developer
assert_status "and finds what is in that path" 1

finish
