# Setup

This page says how to get a place to run the code: `bin/check`, the test site in `docs/tools/`, an IDE and a local Moodle. Pick the one that fits the work.

## Contents

- [What you need](#what-you-need)
- [Which one to use](#which-one-to-use)
- [bin/check](#bincheck)
- [The test site](#the-test-site)
- [An IDE](#an-ide)
- [A local Moodle](#a-local-moodle)
- [Related pages](#related-pages)

## What you need

- Docker, for `bin/check` and the test site.
- Bash and `zip`, for the scripts in `bin/`.
- Python 3, for `bin/check-docs` and `docs/tools/wd.py`. Both use only Python's standard library.
- Git, to clone the repo and to tag a release. None of the scripts in `bin/` runs it.

You don't need PHP on your computer. `bin/check` and the test site run it in containers.

## Which one to use

| You want to | Use | What it costs |
|---|---|---|
| Prove a change before a pull request | `bin/check` | A fresh Moodle on each run, so a few minutes per plugin |
| Run one test again and again | The test site, `docs/tools/site.sh phpunit` | A one-time `up`, then seconds per run |
| Open a page, walk a workflow, run PHP on a site with data | The test site | The same |
| Try another Moodle or PHP version | `bin/check --moodle --php` | The test site is Moodle 5.3 and PHP 8.3 only |
| Work with your own server, debugger and browser | A local Moodle | You set it up |

## bin/check

`bin/check` runs the CI checks in Docker, except the PHP Mess Detector and Behat. For each plugin it starts a database container and a container with PHP and moodle-plugin-ci, installs the plugin into a fresh Moodle, runs the steps, and removes both containers. Its data lives in memory, so a run leaves nothing on disk.

```bash
bin/check                                                  # every plugin, on Moodle 5.3, PHP 8.3 and PostgreSQL
bin/check --moodle 4.5 --php 8.1 --db mysqli               # another combination
bin/check --plugin local/academictranscript phpcs phpdoc   # one plugin, two steps
bin/check --together                                       # with the other plugins installed too
```

The first run for a PHP version builds a Docker image named `moodle-plugins-ci:<php>`, from the Dockerfile written in `bin/check`. That takes a few minutes. Every run clones Moodle again, which takes a few minutes too. Remove the image with `docker rmi` to get a newer moodle-plugin-ci on the next run. If Docker Hub limits your pulls, set `CHECK_REGISTRY` to a mirror of the database images.

Each run makes its own Docker network and containers, named `moodle-plugins-check-<pid>-<random>`, and removes only those, so two runs can go side by side. `tests/check.test.sh` holds that with a fake `docker`.

The image has a JRE, because the `mustache` step validates a template's HTML with Java. If you change the Dockerfile in `bin/check`, remove the image with `docker rmi`, so the next run builds it again.

[Testing](testing.md#bincheck) lists the steps, the options and the exit statuses.

## The test site

`docs/tools/site.sh` runs a throwaway Moodle in Docker: Moodle 5.3, PHP 8.3, PostgreSQL 17, all three plugins, and a Chrome in a Selenium container. [The tools page](../tools/README.md) has the whole guide, including how the screenshots are made. The parts a developer needs are here.

### Start it

```bash
bin/check --plugin theme/studentrecords phplint   # once, to build the image moodle-plugins-ci:8.3
docs/tools/site.sh up                             # install Moodle and the plugins, make site 1, save the snapshot "fresh"
docs/tools/site.sh seed                           # fill site 1 with a made-up school, save the snapshot "seed"
docs/tools/site.sh status                         # show the containers
```

`up` needs the image, and stops with a message if it isn't there. It takes a few minutes. The containers are `mpdocs-net`, which holds the network, `mpdocs-db`, `mpdocs-moodle` and `mpdocs-selenium`. The database is in memory, so `docs/tools/site.sh down` loses everything, and so does a restart of `mpdocs-db`.

### The commands

| Command | What it does |
|---|---|
| `up` | Starts the containers, installs Moodle and the plugins, builds site 1, and saves the snapshot `fresh` |
| `seed` | Puts `fresh` on site 1, fills it from `docs/tools/seed.php`, and saves the snapshot `seed` |
| `snapshot <name>` | Saves the site named by `SITE` as a snapshot |
| `restore [<name>]` | Puts a snapshot on the site named by `SITE` (default `seed`). Makes the site if it doesn't exist, and starts its web server |
| `serve` | Starts the web server of the site named by `SITE` |
| `php <file> [args]` | Runs a PHP file from your computer on the site |
| `sh <command>` | Runs a shell command in the Moodle folder, with `SITE` set |
| `cron` | Runs Moodle's cron once |
| `adhoc` | Runs the waiting ad-hoc tasks |
| `sync [plugin...]` | Copies plugin folders from this repo into the site, runs the upgrade and purges the caches. Default: all three |
| `phpunit [args]` | Runs PHPUnit on the plugin code in the site |
| `status` | Shows the containers |
| `down` | Removes every container |

### Sites

`SITE` picks a site, 1 to 9, and the default is 1. Each site has its own database and data folder, and all share the Moodle code. So:

```bash
SITE=3 docs/tools/site.sh restore seed
SITE=3 docs/tools/site.sh php /path/to/try.php
```

`sync` copies code for every site, and runs the upgrade only on the site named by `SITE`. After a change that needs an upgrade, another site is behind until you run `sync` on it too.

### Reach it

The site answers at `http://riverside.example.com`, which only the Chrome in the Selenium container can reach. `docs/tools/wd.py` tells that Chrome where it is, and WebDriver is at `http://127.0.0.1:14444`. For `curl`, use the port of the site, `18000` plus `SITE`, with a `Host` header:

```bash
curl -H 'Host: riverside.example.com' http://127.0.0.1:18001/
```

### Change code and see it

The site runs a copy of the code, not your working folder:

```bash
docs/tools/site.sh sync local/studentrecords       # copy one plugin in, upgrade, purge caches
docs/tools/site.sh phpunit --testsuite local_studentrecords_testsuite --filter test_totals   # run a test on that copy
```

### Run PHP on the site

`php` copies your file into the container and runs it in the Moodle folder, so `require('/work/moodle/config.php')` gives a working Moodle:

```php
<?php
define('CLI_SCRIPT', true);
require('/work/moodle/config.php');
echo $DB->count_records('local_studentrecords_student'), "\n";
```

`/work/site-config.php`, loaded by `config.php`, picks the database and data folder by `SITE`. It also sets `$CFG->noemailever`, so no mail leaves the site, and turns `debug` off. A command with no `SITE` keeps the settings of moodle-plugin-ci, which PHPUnit needs.

## An IDE

Open the repo root. `.idea/` and `.vscode/` are in `.gitignore`, so your IDE's settings stay out of the repo.

- Set the PHP language level to 8.1. `CONTRIBUTING.md` allows PHP 8.1 syntax only, because Moodle 4.5 runs on it.
- The repo holds no Moodle code, so add a Moodle folder as a library if you want core classes to resolve.
- Run `phpcs` through `bin/check`, not from the IDE's own settings. The checks leave out `moodle.Files.BoilerplateComment`, because the two MIT plugins have no GPL header, so a plain `phpcs` run reports that on every PHP file of those plugins.
- Never run `phpcbf` on the local plugins. It writes the GPL header in.

## A local Moodle

The repo's folders follow Moodle's own layout.

1. Copy a plugin folder to the matching place: `<moodle>/public/local/academictranscript` on Moodle 5.1 and later, `<moodle>/local/academictranscript` on Moodle 5.0 and earlier. The theme goes in `theme/studentrecords` the same way.
2. Run `php admin/cli/upgrade.php` in the Moodle folder, as the web server user. On Moodle 5.1 and later `admin/cli/` sits at the root of the Moodle folder, and not in `public/`.
3. For development, add these lines of `config-dist.php` to `config.php`:

```php
$CFG->debug = (E_ALL);
$CFG->debugdisplay = 1;
$CFG->themedesignermode = true;
$CFG->cachejs = false;
$CFG->cachetemplates = false;
$CFG->noemailever = true;
```

They aren't for a live site. With `debugdisplay` on, any debugging output turns a redirect into an interstitial page, so a real browser run can look wrong in a way a test doesn't. See [Debugging](debugging.md#a-page).

Student Records needs cron: most of its messages wait for it, and `recover_payments` runs every 5 minutes. [Tasks and messages](student-records/tasks-and-messages.md) lists the tasks.

For PHPUnit on your own Moodle, set `$CFG->phpunit_prefix` and `$CFG->phpunit_dataroot` in `config.php`, install the dev dependencies with Composer, and initialise the test site:

```bash
php public/admin/tool/phpunit/cli/init.php   # php admin/tool/phpunit/cli/init.php on Moodle 4.5
vendor/bin/phpunit --filter calculator_test
```

## Related pages

- [Testing](testing.md)
- [Debugging](debugging.md)
- [Compatibility](compatibility.md)
- [The test site and its tools](../tools/README.md)
