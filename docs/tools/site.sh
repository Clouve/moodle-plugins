#!/usr/bin/env bash
# Run a throwaway Moodle with the three plugins of this repo in Docker, to walk a
# workflow or to retake the screenshots in docs/user. See usage() below and
# docs/tools/README.md.
set -euo pipefail

usage() {
  cat <<'EOF'
Usage: docs/tools/site.sh <command> [args]

Runs a test Moodle (5.3, PHP 8.3, PostgreSQL 17) with Academic Transcript, Student
Records and the Student Records theme installed, and a Chrome to drive it.

Commands:
  up                 Start the containers, install Moodle and the plugins, build
                     site 1, and save it as the snapshot "fresh", which has the
                     plugins and no data. Takes a few minutes.
  seed               Put "fresh" on site 1, fill it with the made-up school from
                     docs/tools/seed.php, and save it as the snapshot "seed".
  snapshot <name>    Save the site named by SITE as a snapshot.
  restore [<name>]   Put a snapshot on the site named by SITE (default "seed").
                     Makes the site if it doesn't exist yet, and starts its web
                     server.
  serve              Start the web server of the site named by SITE.
  php <file> [args]  Run a PHP file from your computer on the site named by SITE.
  sh <command>       Run a shell command in the Moodle folder, with SITE set.
  cron               Run Moodle's cron once on the site named by SITE.
  adhoc              Run the waiting ad-hoc tasks on the site named by SITE.
  sync [plugin...]   Copy plugin folders from this repo into the running site and
                     run the upgrade. Default: all three.
  phpunit [args]     Run PHPUnit with these arguments, on the plugin code in the
                     site. Run sync first if you changed it.
  status             Show the containers.
  down               Remove every container. All data is lost.

Environment:
  SITE               Which site, 1 to 9 (default 1). Each site has its own
                     database and data folder, and shares the Moodle code.
  CHECK_REGISTRY     Prefix for the database image, as in bin/check.

The site answers at http://riverside.example.com, which only the Chrome in the
Selenium container can reach: docs/tools/wd.py tells that Chrome where it is. For
curl, use http://127.0.0.1:1800<SITE>/ with the header
"Host: riverside.example.com". WebDriver is at http://127.0.0.1:14444.

Run bin/check once first, because it builds the image moodle-plugins-ci:8.3.
EOF
}

die() {
  echo "site.sh: $*" >&2
  exit 1
}

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
php_version=8.3
branch=MOODLE_503_STABLE
image=moodle-plugins-ci:$php_version
registry=${CHECK_REGISTRY:-}
db_image=${registry:+${registry%/}/}postgres:17
browser_image=selenium/standalone-chrome:latest
holder=mpdocs-net
db=mpdocs-db
web=mpdocs-moodle
browser=mpdocs-selenium
site=${SITE:-1}
case $site in
  [1-9]) ;;
  *) die "SITE must be 1 to 9" ;;
esac
port=$((18000 + site))
adminpass=${ADMINPASS:-Admin@123}
plugins='local/academictranscript local/studentrecords theme/studentrecords'

run_php() {
  docker exec -e SITE="$site" -w /work/moodle "$web" "$@"
}

psql_cmd() {
  docker exec "$db" psql -h 127.0.0.1 -U postgres -v ON_ERROR_STOP=1 -q -c "$1"
}

cmd_up() {
  docker image inspect "$image" >/dev/null 2>&1 || die "no image $image. Run bin/check once to build it"

  # One small container holds the network, and the others join it. Starting and
  # stopping the others then adds no network interface to the computer, and they
  # reach each other on 127.0.0.1.
  docker run -d --name "$holder" \
    -p 127.0.0.1:14444:4444 -p 127.0.0.1:18001-18009:18001-18009 \
    --entrypoint sleep "$image" infinity >/dev/null
  docker run -d --name "$db" --network "container:$holder" \
    -e POSTGRES_HOST_AUTH_METHOD=trust --tmpfs /var/lib/postgresql/data \
    "$db_image" -c listen_addresses=127.0.0.1 -c fsync=off -c max_connections=200 >/dev/null
  docker run -d --name "$web" --network "container:$holder" \
    --entrypoint sleep "$image" infinity >/dev/null
  docker run -d --name "$browser" --network "container:$holder" --shm-size=2g \
    -e SE_NODE_MAX_SESSIONS=8 -e SE_NODE_OVERRIDE_MAX_SESSIONS=true \
    -e SE_NODE_SESSION_TIMEOUT=3600 -e SE_SESSION_REQUEST_TIMEOUT=600 \
    "$browser_image" >/dev/null

  docker exec "$web" mkdir -p /work/extra /work/data
  docker cp -q "$repo/local/academictranscript" "$web:/work/plugin"
  docker cp -q "$repo/local/studentrecords" "$web:/work/extra/local_studentrecords"
  docker cp -q "$repo/theme/studentrecords" "$web:/work/extra/theme_studentrecords"

  for _ in $(seq 1 60); do
    docker exec "$db" pg_isready -q -h 127.0.0.1 -U postgres && break
    sleep 1
  done

  docker exec -w /work "$web" moodle-plugin-ci install --plugin ./plugin --extra-plugins ./extra \
    --db-type pgsql --db-host 127.0.0.1 --db-user postgres --db-pass '' --branch "$branch"

  # config.php loads the file below before Moodle starts. A web request picks its
  # site by port and a command by SITE. A command with no SITE keeps the settings
  # of moodle-plugin-ci, which PHPUnit needs.
  docker exec -i "$web" sh -c 'cat > /work/site-config.php' <<'PHP'
<?php
$docssite = getenv('SITE');
if ($docssite === false && !empty($_SERVER['SERVER_PORT'])) {
    $docssite = (int)$_SERVER['SERVER_PORT'] - 18000;
}
if ($docssite) {
    $CFG->dbname = 'site' . (int)$docssite;
    $CFG->dataroot = '/work/data/site' . (int)$docssite;
    $CFG->wwwroot = 'http://riverside.example.com';
    $CFG->noemailever = true;
    $CFG->debug = 0;
    $CFG->debugdisplay = 0;
    $CFG->passwordsaltmain = 'docs-test-site';
}
PHP
  docker exec "$web" sed -i "/lib\/setup.php/i require('/work/site-config.php');" /work/moodle/config.php

  site=1
  port=18001
  psql_cmd 'CREATE DATABASE site1'
  docker exec "$web" mkdir -p /work/data/site1
  run_php php admin/cli/install_database.php --agree-license --adminpass="$adminpass" \
    --adminemail=admin@example.com --fullname='Riverside College' --shortname=riverside
  # A first sign-in as admin would stop at Moodle's page to register the site.
  run_php php admin/cli/cfg.php --name=registrationpending --set=0
  cmd_serve
  cmd_snapshot fresh
  echo "site.sh: site 1 is up. Run: docs/tools/site.sh seed"
}

cmd_serve() {
  docker exec -e PHP_CLI_SERVER_WORKERS=4 -d -w /work/moodle/public "$web" \
    sh -c "php -d upload_max_filesize=100M -d post_max_size=100M -d max_input_vars=20000 -S 0.0.0.0:$port > /tmp/php-$port.log 2>&1"
}

cmd_snapshot() {
  local name=${1:?a snapshot needs a name}
  psql_cmd "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'site$site' AND pid <> pg_backend_pid()" >/dev/null
  psql_cmd "DROP DATABASE IF EXISTS snap_$name"
  psql_cmd "CREATE DATABASE snap_$name TEMPLATE site$site"
  docker exec "$web" rm -rf "/work/data/snap-$name"
  docker exec "$web" cp -a "/work/data/site$site" "/work/data/snap-$name"
  echo "site.sh: saved site $site as the snapshot $name"
}

cmd_restore() {
  local name=${1:-seed}
  psql_cmd "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'site$site' AND pid <> pg_backend_pid()" >/dev/null
  psql_cmd "DROP DATABASE IF EXISTS site$site"
  psql_cmd "CREATE DATABASE site$site TEMPLATE snap_$name"
  docker exec "$web" rm -rf "/work/data/site$site"
  docker exec "$web" cp -a "/work/data/snap-$name" "/work/data/site$site"
  if ! docker exec "$web" php -r "exit(@fsockopen('127.0.0.1', $port) ? 0 : 1);"; then
    cmd_serve
  fi
  echo "site.sh: site $site holds the snapshot $name"
}

cmd_php() {
  [ $# -ge 1 ] || die "php needs a file"
  local file=$1 name
  shift
  name=/tmp/run-$site-$$-$(basename "$file")
  docker cp -q "$file" "$web:$name"
  run_php php "$name" "$@"
  docker exec "$web" rm -f "$name"
}

cmd_sync() {
  local list=${*:-$plugins} pub=/work/moodle/public p
  for p in $list; do
    docker exec "$web" rm -rf "$pub/$p"
    docker cp -q "$repo/$p" "$web:$pub/$p"
  done
  run_php php admin/cli/upgrade.php --non-interactive
  run_php php admin/cli/purge_caches.php
}

cmd_phpunit() {
  # One run at a time, because every site shares PHPUnit's tables.
  docker exec -w /work/moodle "$web" sh -c '
    exec 9>/tmp/phpunit.lock
    flock 9
    php public/admin/tool/phpunit/cli/util.php --buildconfig >/dev/null
    vendor/bin/phpunit "$@"' sh "$@"
}

[ $# -ge 1 ] || {
  usage >&2
  exit 2
}
command=$1
shift
case $command in
  up) cmd_up ;;
  seed)
    site=1
    port=18001
    cmd_restore fresh
    docker exec "$web" rm -rf /tmp/seed-assets
    docker cp -q "$repo/docs/tools/assets" "$web:/tmp/seed-assets"
    cmd_php "$repo/docs/tools/seed.php"
    run_php php admin/cli/purge_caches.php
    cmd_snapshot seed
    ;;
  snapshot) cmd_snapshot "$@" ;;
  restore) cmd_restore "$@" ;;
  serve) cmd_serve ;;
  php) cmd_php "$@" ;;
  sh) docker exec -e SITE="$site" -w /work/moodle "$web" sh -c "$*" ;;
  cron) run_php php admin/cli/cron.php --keep-alive=0 ;;
  adhoc) run_php php admin/cli/adhoc_task.php --execute ;;
  sync) cmd_sync "$@" ;;
  phpunit) cmd_phpunit "$@" ;;
  status) docker ps --filter name=mpdocs- --format '{{.Names}}\t{{.Status}}' ;;
  down) docker rm -f -v "$browser" "$web" "$db" "$holder" >/dev/null ;;
  -h | --help) usage ;;
  *)
    usage >&2
    exit 2
    ;;
esac
