#!/usr/bin/env bash
# Shared helpers for the tests in this folder. Source this file from a *.test.sh
# file; it does nothing on its own.
#
# A test file builds a throwaway repo in a temp dir (a copy of bin/ plus fixture
# plugins), runs a tool from that copy and checks the exit status and output.
# The real repo is only read, never written to.

set -euo pipefail

TESTS_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REAL_REPO=$(cd "$TESTS_DIR/.." && pwd)

# Work dir for one test file, removed on exit. The tools put their own temp files
# here too (they honour TMPDIR), so nothing is left behind.
TMP=$(cd "$(mktemp -d "${TMPDIR:-/tmp}/repo-tests.XXXXXX")" && pwd -P)
export TMPDIR=$TMP
trap 'rm -rf "$TMP"' EXIT

PASSED=0
FAILED=0
STATUS=0
OUT=""
ERR=""

# --- Reporting ----------------------------------------------------------------

section() {
  printf '\n%s\n' "$1"
}

pass() {
  PASSED=$((PASSED + 1))
  printf '  ok    %s\n' "$1"
}

fail() {
  FAILED=$((FAILED + 1))
  printf '  FAIL  %s\n' "$1"
}

# detail <label> <text>: print a labelled block under a failure.
detail() {
  printf '          %s:\n' "$1"
  printf '%s\n' "$2" | sed 's/^/            | /'
}

# finish: print the totals and set the exit status.
finish() {
  printf '\n%d passed, %d failed\n' "$PASSED" "$FAILED"
  [ "$FAILED" -eq 0 ]
}

# --- Assertions ---------------------------------------------------------------

# assert_eq <name> <expected> <actual>
assert_eq() {
  if [ "$2" = "$3" ]; then
    pass "$1"
  else
    fail "$1"
    detail "expected" "$2"
    detail "actual" "$3"
  fi
}

# assert_contains <name> <text> <needle>
assert_contains() {
  case $2 in
    *"$3"*) pass "$1" ;;
    *)
      fail "$1"
      detail "looking for" "$3"
      detail "in" "$2"
      ;;
  esac
}

# assert_lacks <name> <text> <needle>
assert_lacks() {
  case $2 in
    *"$3"*)
      fail "$1"
      detail "should not contain" "$3"
      detail "in" "$2"
      ;;
    *) pass "$1" ;;
  esac
}

# assert_status <name> <expected exit status>: checks the status of the last run.
assert_status() {
  if [ "$STATUS" -eq "$2" ]; then
    pass "$1"
  else
    fail "$1"
    detail "expected exit status" "$2"
    detail "actual exit status" "$STATUS"
    detail "stdout" "$OUT"
    detail "stderr" "$ERR"
  fi
}

# assert_file <name> <path>
assert_file() {
  if [ -f "$2" ]; then
    pass "$1"
  else
    fail "$1"
    detail "missing file" "$2"
  fi
}

# assert_no_file <name> <path>
assert_no_file() {
  if [ -e "$2" ]; then
    fail "$1"
    detail "unexpected file" "$2"
  else
    pass "$1"
  fi
}

# --- Running tools ------------------------------------------------------------

# run_in <dir> <command...>: run a command in a folder without aborting the test
# file. Sets STATUS, OUT (stdout) and ERR (stderr).
run_in() {
  local dir=$1
  shift
  STATUS=0
  (cd "$dir" && "$@") >"$TMP/stdout" 2>"$TMP/stderr" || STATUS=$?
  OUT=$(cat "$TMP/stdout")
  ERR=$(cat "$TMP/stderr")
}

# run <command...>: run a command in the current folder (see run_in).
run() {
  run_in "$PWD" "$@"
}

# --- Fixtures -----------------------------------------------------------------

# make_repo: create a throwaway repo holding a copy of bin/ and print its path.
make_repo() {
  local dir
  dir=$(mktemp -d "$TMP/repo.XXXXXX")
  mkdir "$dir/bin"
  if [ -d "$REAL_REPO/bin" ]; then
    cp -R "$REAL_REPO/bin/." "$dir/bin/"
  fi
  printf '%s\n' "$dir"
}

# copy_real_plugin <repo>: put a copy of the real Academic Transcript plugin in a
# throwaway repo.
copy_real_plugin() {
  mkdir -p "$1/local"
  cp -R "$REAL_REPO/local/academictranscript" "$1/local/"
}

# add_plugin <repo> <folder> <component> [<release>]: write a minimal version.php.
# Leave the release empty (or out) to write a version.php without one.
add_plugin() {
  local file="$1/$2/version.php"
  mkdir -p "$1/$2"
  {
    printf '<?php\n'
    printf "defined('MOODLE_INTERNAL') || die();\n\n"
    printf "\$plugin->component = '%s';\n" "$3"
    printf "\$plugin->version   = 2026102100;\n"
    if [ -n "${4:-}" ]; then
      printf "\$plugin->release   = '%s';\n" "$4"
    fi
  } >"$file"
}

# plugin_release <version.php>: the release the real plugin declares, read in a
# way that does not depend on the tools under test.
plugin_release() {
  awk -F"'" '/plugin->release/ { print $2; exit }' "$1"
}
