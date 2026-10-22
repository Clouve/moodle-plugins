#!/usr/bin/env bash
# Tests for bin/check, with a fake docker on the PATH. They cover the options, the
# reports and the clean-up, not the Moodle checks themselves: bin/check on a real
# plugin is the test for those.
set -euo pipefail
# shellcheck source=tests/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

# The fake docker logs every call and keeps track of the containers and networks
# that exist, so a test can see what bin/check left behind. Environment variables
# make it fail on request:
#   STUB_NO_IMAGE=1    the runner image does not exist until it is built
#   STUB_DB_HEALTH=x   the health status the database reports
#   STUB_FAIL=step     "docker exec ... moodle-plugin-ci <step>" exits 1
#   STUB_HANG=step     the same command waits until the state dir holds "stop"
#   STUB_HANG_BUILD=1  docker build waits in the same way, and notes it if it gets SIGTERM
#   STUB_INFO_FAIL=1   the Docker daemon does not answer
#   STUB_RUN_FAIL=db   docker run fails for the container whose name ends in "db" (or "ci")
stub_dir=$TMP/stub
mkdir -p "$stub_dir"
cat >"$stub_dir/docker" <<'STUB'
#!/usr/bin/env bash
state=${STUB_STATE:?}
printf '%s\n' "$*" >>"$state/calls"
case ${1:-} in
  info)
    if [ -n "${STUB_INFO_FAIL:-}" ]; then exit 1; fi
    exit 0
    ;;
  image)
    if [ -n "${STUB_NO_IMAGE:-}" ] && [ ! -e "$state/image-built" ]; then exit 1; fi
    exit 0
    ;;
  build)
    cat >"$state/dockerfile"
    if [ -n "${STUB_HANG_BUILD:-}" ]; then
      trap 'touch "$state/build-terminated"; exit 143' TERM
      touch "$state/building"
      for _ in $(seq 1 40); do
        if [ -e "$state/stop" ]; then break; fi
        sleep 1
      done
    fi
    touch "$state/image-built"
    exit 0
    ;;
  network)
    case ${2:-} in
      create) touch "$state/network-${3:?}" ;;
      rm) rm -f "$state/network-${3:?}" ;;
    esac
    exit 0
    ;;
  run)
    name=''
    previous=''
    for arg in "$@"; do
      if [ "$previous" = --name ]; then name=$arg; fi
      previous=$arg
    done
    case $name in
      *"${STUB_RUN_FAIL:-no such container}") exit 125 ;;
    esac
    touch "$state/container-$name"
    echo "0123456789abcdef"
    exit 0
    ;;
  inspect)
    echo "running/${STUB_DB_HEALTH:-healthy}"
    exit 0
    ;;
  logs)
    echo "stub: database log"
    exit 0
    ;;
  cp) exit 0 ;;
  exec)
    step=''
    seen=''
    for arg in "$@"; do
      if [ -n "$seen" ] && [ -z "$step" ]; then step=$arg; fi
      if [ "$arg" = moodle-plugin-ci ]; then seen=1; fi
    done
    if [ "$step" = "${STUB_FAIL:-}" ]; then
      echo "stub: $step fails" >&2
      exit 1
    fi
    if [ "$step" = "${STUB_HANG:-}" ]; then
      touch "$state/hanging"
      for _ in $(seq 1 40); do
        if [ -e "$state/stop" ]; then break; fi
        sleep 1
      done
    fi
    echo "stub: $step ok"
    exit 0
    ;;
  rm)
    shift
    for arg in "$@"; do
      case $arg in
        -*) ;;
        *) rm -f "$state/container-$arg" ;;
      esac
    done
    exit 0
    ;;
esac
echo "stub docker: unexpected call: $*" >&2
exit 99
STUB
chmod +x "$stub_dir/docker"

export STUB_STATE=$TMP/state
export PATH="$stub_dir:$PATH"

# fresh_state: forget earlier calls and containers.
fresh_state() {
  rm -rf "$STUB_STATE"
  mkdir -p "$STUB_STATE"
}

# calls: everything bin/check asked docker to do, one call per line.
calls() {
  cat "$STUB_STATE/calls" 2>/dev/null || true
}

# leftovers: containers and networks the fake docker still holds.
leftovers() {
  local item
  for item in "$STUB_STATE"/container-* "$STUB_STATE"/network-*; do
    if [ -e "$item" ]; then
      basename "$item"
    fi
  done
}

# exec_steps: the moodle-plugin-ci commands that were run, in order.
exec_steps() {
  calls | sed -n 's/^exec .* moodle-plugin-ci \([a-z]*\).*/\1/p'
}

# exec_line <step>: the docker exec call that ran a moodle-plugin-ci command.
exec_line() {
  calls | grep -E " moodle-plugin-ci $1( |\$)" || true
}

# wait_for_file <path>: wait up to 10 seconds for a file to appear.
wait_for_file() {
  local _
  for _ in $(seq 1 100); do
    if [ -e "$1" ]; then
      return 0
    fi
    sleep 0.1
  done
  return 1
}

# wait_for_exit <pid>: wait up to 5 seconds for a process to end.
wait_for_exit() {
  local _
  for _ in $(seq 1 50); do
    if ! kill -0 "$1" 2>/dev/null; then
      return 0
    fi
    sleep 0.1
  done
  return 1
}

repo=$(make_repo)
add_plugin "$repo" local/demo local_demo 1.0.0
add_plugin "$repo" admin/tool/other tool_other 1.0.0
check=$repo/bin/check
all_steps="install
phplint
phpcs
phpdoc
validate
savepoints
mustache
grunt
phpunit"

section "check: options that are refused"

fresh_state
run "$check" --help
assert_status "--help exits 0" 0
assert_contains "and describes the options" "$OUT" "--moodle"
assert_contains "and the registry variable" "$OUT" "CHECK_REGISTRY"
assert_contains "and says which CI checks it leaves out" "$(printf '%s' "$OUT" | tr '\n' ' ')" \
  "except the PHP Mess Detector and Behat"

run "$check" --moodle 9.9
assert_status "an unknown Moodle release exits 2" 2
assert_contains "and lists the ones it knows" "$ERR" "4.5, 5.1, 5.2 or 5.3"

run "$check" --php 7.4
assert_status "an unknown PHP version exits 2" 2
assert_contains "and lists the ones it knows" "$ERR" "8.1, 8.2, 8.3 or 8.4"

run "$check" --db oracle
assert_status "an unknown database exits 2" 2
assert_contains "and lists the ones it knows" "$ERR" "pgsql, mysqli or mariadb"

run "$check" --bogus
assert_status "an unknown option exits 2" 2

run "$check" lint
assert_status "an unknown step exits 2" 2
assert_contains "and lists the ones it knows" "$ERR" "phplint phpcs phpdoc validate savepoints mustache grunt phpunit"

run "$check" --moodle
assert_status "an option without a value exits 2" 2

run "$check" --plugin local/nope
assert_status "a plugin folder that does not exist exits 2" 2
assert_contains "and names it" "$ERR" "local/nope"

mkdir -p "$repo/local/noversion"
run "$check" --plugin local/noversion
assert_status "a folder without version.php exits 2" 2

assert_eq "none of these called docker" "" "$(calls)"

section "check: a run where every step passes"

fresh_state
run "$check"
assert_status "exits 0" 0
assert_eq "reports PASS for every step of every plugin" "== admin/tool/other: Moodle 5.3, PHP 8.3, pgsql
PASS phplint
PASS phpcs
PASS phpdoc
PASS validate
PASS savepoints
PASS mustache
PASS grunt
PASS phpunit
== local/demo: Moodle 5.3, PHP 8.3, pgsql
PASS phplint
PASS phpcs
PASS phpdoc
PASS validate
PASS savepoints
PASS mustache
PASS grunt
PASS phpunit
All checks passed." "$OUT"
assert_eq "installs, then runs the steps in order, for both plugins" \
  "$all_steps
$all_steps" "$(exec_steps)"
assert_contains "installs Moodle 5.3" "$(exec_line install)" "--branch MOODLE_503_STABLE"
assert_contains "on PostgreSQL" "$(exec_line install | head -n 1)" "--db-type pgsql --db-host"
assert_lacks "and initialises PHPUnit, because phpunit is a step" "$(calls)" "--no-init"
assert_contains "phpcs fails on warnings" "$(exec_line phpcs | head -n 1)" "phpcs --max-warnings 0"
assert_contains "phpdoc fails on warnings" "$(exec_line phpdoc | head -n 1)" "phpdoc --max-warnings 0"
assert_contains "grunt fails on lint warnings" "$(exec_line grunt | head -n 1)" "grunt --max-lint-warnings 0"
assert_contains "phpunit fails on warnings" "$(exec_line phpunit | head -n 1)" "phpunit --fail-on-warning"
assert_contains "uses postgres:17" "$(calls)" " postgres:17"
assert_eq "a database container and a runner container for each plugin" "4" \
  "$(calls | grep -c '^run -d --name')"
assert_eq "leaves no container or network behind" "" "$(leftovers)"
assert_eq "puts the progress and the tools' output on stderr" "stub: install ok" "$(printf '%s\n' "$ERR" | grep -m 1 'stub: install ok')"

names=$(calls | sed -n 's/^run -d --name \([^ ]*\) .*/\1/p')
others=$(printf '%s\n' "$names" | grep -v '^moodle-plugins-check-' || true)
assert_eq "gives every container a moodle-plugins-check- name" "" "$others"
networks=$(calls | sed -n 's/.* --network \([^ ]*\).*/\1/p' | sort -u)
case $networks in
  moodle-plugins-check-*) pass "uses one network of its own" ;;
  *)
    fail "uses one network of its own"
    detail "networks" "$networks"
    ;;
esac
assert_eq "touches nothing of the mpdev or mpci environments" "" "$(calls | grep -E 'mpdev|--network mpci|rm .* mpci' || true)"

first_names=$names
fresh_state
run "$check" --plugin local/demo
assert_status "a second run: exits 0" 0
second_names=$(calls | sed -n 's/^run -d --name \([^ ]*\) .*/\1/p')
if [ "$first_names" != "$second_names" ] && [ -n "$second_names" ]; then
  pass "a second run uses other container names, so two runs can coexist"
else
  fail "a second run uses other container names, so two runs can coexist"
  detail "first run" "$first_names"
  detail "second run" "$second_names"
fi

section "check: options pick the environment"

fresh_state
run "$check" --moodle 4.5 --php 8.1 --db mysqli --plugin local/demo
assert_status "Moodle 4.5, PHP 8.1, MySQL: exits 0" 0
assert_contains "runs the PHP 8.1 image" "$(calls)" "moodle-plugins-ci:8.1"
assert_contains "starts mysql:8.4" "$(calls)" " mysql:8.4 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci"
assert_contains "with an empty root password allowed" "$(calls)" "-e MYSQL_ALLOW_EMPTY_PASSWORD=yes"
assert_contains "installs with the MySQL driver and root" "$(exec_line install)" "--db-type mysqli --db-host"
assert_contains "and branch MOODLE_405_STABLE" "$(exec_line install)" "--db-user root --db-pass  --branch MOODLE_405_STABLE"

fresh_state
run "$check" --moodle=5.1 --php=8.2 --db=mariadb --plugin=local/demo
assert_status "Moodle 5.1, PHP 8.2, MariaDB with --option=value: exits 0" 0
assert_contains "starts mariadb:11" "$(calls)" " mariadb:11 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci"
assert_contains "installs with the MariaDB driver" "$(exec_line install)" "--db-type mariadb"
assert_contains "and branch MOODLE_501_STABLE" "$(exec_line install)" "--branch MOODLE_501_STABLE"

fresh_state
run "$check" --moodle 5.2 --plugin local/demo
assert_contains "Moodle 5.2 is MOODLE_502_STABLE" "$(exec_line install)" "--branch MOODLE_502_STABLE"

fresh_state
run env CHECK_REGISTRY=mirror.gcr.io/library "$check" --plugin local/demo
assert_contains "CHECK_REGISTRY prefixes the database image" "$(calls)" " mirror.gcr.io/library/postgres:17"

fresh_state
run env CHECK_REGISTRY=mirror.gcr.io/library/ "$check" --db mysqli --plugin local/demo
assert_contains "a trailing slash in CHECK_REGISTRY does no harm" "$(calls)" " mirror.gcr.io/library/mysql:8.4 "

fresh_state
run "$check" --plugin "$repo/local/demo" --plugin admin/tool/other
assert_eq "--plugin takes absolute paths, repo paths and several plugins" "== local/demo: Moodle 5.3, PHP 8.3, pgsql
$(printf 'PASS %s\n' phplint phpcs phpdoc validate savepoints mustache grunt phpunit)
== admin/tool/other: Moodle 5.3, PHP 8.3, pgsql
$(printf 'PASS %s\n' phplint phpcs phpdoc validate savepoints mustache grunt phpunit)
All checks passed." "$OUT"

section "check: choosing steps"

fresh_state
run "$check" --plugin local/demo phpcs phplint
assert_status "two steps: exits 0" 0
assert_eq "reports just those, in the order given" "== local/demo: Moodle 5.3, PHP 8.3, pgsql
PASS phpcs
PASS phplint
All checks passed." "$OUT"
assert_eq "and runs only those, after the install" "install
phpcs
phplint" "$(exec_steps)"
assert_contains "and skips the PHPUnit set-up when phpunit is not a step" "$(exec_line install)" "--no-init"

section "check: a failing step"

fresh_state
run env STUB_FAIL=phpcs "$check" --plugin local/demo
assert_status "exits 1" 1
assert_eq "reports FAIL for that step and goes on with the others" "== local/demo: Moodle 5.3, PHP 8.3, pgsql
PASS phplint
FAIL phpcs
PASS phpdoc
PASS validate
PASS savepoints
PASS mustache
PASS grunt
PASS phpunit
Some checks failed." "$OUT"
assert_contains "the failing tool's output is on stderr" "$ERR" "stub: phpcs fails"
assert_eq "leaves no container or network behind" "" "$(leftovers)"

fresh_state
run env STUB_FAIL=install "$check" --plugin local/demo
assert_status "a failing install exits 1" 1
assert_eq "reports FAIL install and runs no step" "== local/demo: Moodle 5.3, PHP 8.3, pgsql
FAIL install
Some checks failed." "$OUT"
assert_eq "and ran nothing after the install" "install" "$(exec_steps)"
assert_eq "and leaves nothing behind" "" "$(leftovers)"

fresh_state
run env STUB_FAIL=phpunit "$check" --plugin local/demo --plugin admin/tool/other
assert_status "a step that fails for the first plugin: exits 1" 1
assert_eq "and the second plugin still gets checked" "2" "$(printf '%s\n' "$OUT" | grep -c '^== ')"

section "check: problems with the environment"

fresh_state
run env STUB_DB_HEALTH=unhealthy "$check" --plugin local/demo
assert_status "a database that does not start exits 2" 2
assert_contains "and says so" "$ERR" "database"
assert_eq "and runs no step" "" "$(exec_steps)"
assert_eq "and leaves nothing behind" "" "$(leftovers)"

fresh_state
run env STUB_RUN_FAIL=db "$check" --plugin local/demo
assert_status "a database container that cannot be started exits 2" 2
assert_contains "and points to CHECK_REGISTRY" "$ERR" "CHECK_REGISTRY"
assert_eq "and runs no step" "" "$(exec_steps)"
assert_eq "and leaves nothing behind" "" "$(leftovers)"

fresh_state
run env STUB_RUN_FAIL=ci "$check" --plugin local/demo
assert_status "a PHP container that cannot be started exits 2" 2
assert_eq "and leaves nothing behind" "" "$(leftovers)"

fresh_state
run env STUB_INFO_FAIL=1 "$check" --plugin local/demo
assert_status "a Docker daemon that does not answer exits 2" 2
assert_contains "and says so" "$ERR" "Docker daemon"
assert_eq "and starts nothing" "info" "$(calls)"

fresh_state
run env STUB_NO_IMAGE=1 "$check" --plugin local/demo --php 8.2 phplint
assert_status "a missing runner image: exits 0 once it is built" 0
assert_contains "builds moodle-plugins-ci:8.2" "$(calls)" "build --tag moodle-plugins-ci:8.2 --build-arg PHP_VERSION=8.2 -"
dockerfile=$(cat "$STUB_STATE/dockerfile" 2>/dev/null || true)
assert_contains "from the Dockerfile in the script, which starts from moodlehq's image" \
  "$dockerfile" "FROM moodlehq/moodle-php-apache:\${PHP_VERSION}"
assert_contains "and has the database clients" "$dockerfile" "postgresql-client default-mysql-client"
assert_contains "and the en_AU locale" "$dockerfile" "en_AU.UTF-8"
assert_contains "and max_input_vars" "$dockerfile" "max_input_vars=5000"
node_line=$(printf '%s\n' "$dockerfile" | grep -n 'nvm install' | head -n 1 | cut -d: -f1)
ci_line=$(printf '%s\n' "$dockerfile" | grep -n 'composer create-project' | head -n 1 | cut -d: -f1)
if [ -n "$node_line" ] && [ -n "$ci_line" ] && [ "$node_line" -lt "$ci_line" ]; then
  pass "and installs Node before moodle-plugin-ci"
else
  fail "and installs Node before moodle-plugin-ci"
  detail "line of nvm install" "$node_line"
  detail "line of composer create-project" "$ci_line"
fi

fresh_state
run "$check" --plugin local/demo phplint
assert_lacks "an existing runner image is not built again" "$(calls)" "build "

section "check: stopping a run"

fresh_state
env STUB_HANG=phpcs "$check" --plugin local/demo >"$TMP/hang.out" 2>"$TMP/hang.err" &
pid=$!
wait_for_file "$STUB_STATE/hanging" || true
assert_file "a step is running when the signal comes" "$STUB_STATE/hanging"
kill -TERM "$pid"
# The script has to stop at once, not when the step it is waiting for ends.
if wait_for_exit "$pid"; then
  pass "SIGTERM while a step runs stops the script within 5 seconds"
else
  fail "SIGTERM while a step runs stops the script within 5 seconds"
  kill -KILL "$pid" 2>/dev/null || true
fi
STATUS=0
wait "$pid" || STATUS=$?
touch "$STUB_STATE/stop"
assert_eq "and the exit status is 143" "143" "$STATUS"
assert_eq "and the containers and the network are removed" "" "$(leftovers)"

fresh_state
env STUB_NO_IMAGE=1 STUB_HANG_BUILD=1 "$check" --plugin local/demo >"$TMP/hang.out" 2>"$TMP/hang.err" &
pid=$!
wait_for_file "$STUB_STATE/building" || true
assert_file "the image build is running when the signal comes" "$STUB_STATE/building"
kill -TERM "$pid"
if wait_for_exit "$pid"; then
  pass "SIGTERM during the image build stops the script within 5 seconds"
else
  fail "SIGTERM during the image build stops the script within 5 seconds"
  kill -KILL "$pid" 2>/dev/null || true
fi
STATUS=0
wait "$pid" || STATUS=$?
wait_for_file "$STUB_STATE/build-terminated" || true
touch "$STUB_STATE/stop"
assert_eq "and the exit status is 143" "143" "$STATUS"
assert_file "and the build is stopped too" "$STUB_STATE/build-terminated"
assert_eq "and the Dockerfile reached docker build on its standard input" "1" \
  "$(grep -c '^FROM moodlehq/moodle-php-apache' "$STUB_STATE/dockerfile" 2>/dev/null || true)"

finish
