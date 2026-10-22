# Moodle plugins

This repo holds the open-source Moodle plugins of Clouve Inc. Each plugin has its own folder, version and ZIP file for Moodle's plugin installer.

The folders follow Moodle's own layout, so a plugin has the same path here as in a Moodle code folder. The one difference is `public/`. Moodle 5.1 and later keep the plugin at `public/local/academictranscript`. Earlier versions keep it at `local/academictranscript`.

## Plugins

| Plugin | Component | Folder | Moodle | What it does |
|---|---|---|---|---|
| [Academic Transcript](local/academictranscript/README.md) | `local_academictranscript` | `local/academictranscript` | 4.5 to 5.3 | Official and Unofficial PDF transcripts with verification codes and digital signatures. Teachers post final grades, and later changes need an admin's approval. |

The repo holds:

- `local/academictranscript/`: the Academic Transcript plugin.
- `bin/`: the scripts for checks, ZIP files and releases.
- `tests/`: the tests of the scripts in `bin/`.
- `.github/workflows/`: the CI and release workflows.

## Install a plugin from a release ZIP

1. Open the [releases page](https://github.com/Clouve/moodle-plugins/releases). Each release is for one plugin and has one ZIP file, for example `local_academictranscript-1.0.0.zip`.
2. Download the ZIP file of the plugin you want. Don't unzip it.
3. Sign in to Moodle as a site administrator and go to Site administration > Plugins > Install plugins.
4. Drop the ZIP file into "ZIP package" and select "Install plugin from the ZIP file". Moodle detects the plugin type, so leave the other fields alone.
5. Check the validation result and select "Continue".
6. Select "Upgrade Moodle database now" and follow the screens. On the "New settings" page, select "Save changes".

The plugin's README says what to do next.

If Moodle says the plugin installer is disabled, or that the plugin type location is not writable, install the plugin by hand.

## Install a plugin by hand

1. Get the plugin folder: unzip a release ZIP, or copy the folder from a clone of this repo. Use the plugin folder only, not the whole repo.
2. Put it in the matching place in your Moodle code folder. The folder `local/academictranscript` goes here:
   - `<moodle>/public/local/academictranscript` on Moodle 5.1 and later.
   - `<moodle>/local/academictranscript` on Moodle 5.0 and earlier.
3. Make sure the web server can read the files.
4. Sign in as a site administrator and open Site administration > Notifications, or run `php admin/cli/upgrade.php` in the Moodle folder as the web server user. Follow the screens.

## Check a plugin on your computer

`bin/check` runs the CI checks of this repo in Docker, except the PHP Mess Detector and Behat, so Docker is all you need to install.

```bash
bin/check                                                  # every plugin, on Moodle 5.3, PHP 8.3 and PostgreSQL
bin/check --moodle 4.5 --php 8.1 --db mysqli               # another combination
bin/check --plugin local/academictranscript phpcs phpdoc   # one plugin, two steps
```

For each plugin it starts a database container and a container with PHP and moodle-plugin-ci, installs the plugin into a fresh Moodle, runs the steps, and removes both containers. It prints PASS or FAIL for each step on stdout. The output of the tools goes to stderr.

Options:

- `--moodle` is 4.5, 5.1, 5.2 or 5.3. The default is 5.3.
- `--php` is 8.1, 8.2, 8.3 or 8.4. The default is 8.3.
- `--db` is `pgsql` (PostgreSQL 17), `mysqli` (MySQL 8.4) or `mariadb` (MariaDB 11). The default is `pgsql`.
- `--plugin <path>` checks one plugin folder, given from the current folder or from the repo root. Repeat it for several plugins. The default is every plugin.

The steps are `phplint`, `phpcs`, `phpdoc`, `validate`, `savepoints`, `mustache`, `grunt` and `phpunit`, in that order. Name some of them to run only those. They run as in CI, which allows no warnings from `phpcs`, `phpdoc`, `grunt` or `phpunit`. `bin/check` leaves out the PHP Mess Detector, which only informs in CI, and Behat.

The exit status is 0 when every step passed, 1 when a step failed, and 2 when the checks can't run (bad options, no Docker, a container that won't start).

The first run for a PHP version builds a Docker image named `moodle-plugins-ci:<php>`, which takes a few minutes. Every run clones Moodle again, which takes a few minutes too. Remove the image with `docker rmi` to get a newer moodle-plugin-ci on the next run. If Docker Hub limits your pulls, set `CHECK_REGISTRY` to a mirror of the database images, for example `CHECK_REGISTRY=mirror.gcr.io/library bin/check`.

GitHub Actions runs these checks plus the PHP Mess Detector and Behat on every push and pull request, on six combinations of Moodle, PHP and database. It also builds the ZIP of every plugin and keeps it for 14 days as a build artifact, so you can install a pull request's build and try it. [CONTRIBUTING.md](CONTRIBUTING.md) has the details.

## Tools in bin

| Command | What it does |
|---|---|
| `bin/list-plugins [--json]` | Lists the plugin folders, one per line, or as a JSON array with `--json`. A plugin folder holds a `version.php` that sets `$plugin->component`. Folders inside another plugin, and `dist/`, `tests/` and `.git/`, are skipped. |
| `bin/build-zip <plugin path> [<out dir>]` | Builds `<out dir>/<component>-<release>.zip` and prints its path. The out dir defaults to `dist/`. The ZIP holds only the plugin folder, which is the layout Moodle's plugin installer expects. |
| `bin/release-info <tag>` | Reads a tag such as `local_academictranscript-v1.0.0` and prints the plugin folder, the component, the release, the ZIP name and the path of a temporary file with the release notes. It fails when the tag, `version.php` and `CHANGES.md` don't agree. |
| `bin/check [options] [steps]` | Runs the CI checks in Docker, as described above. It leaves out the PHP Mess Detector and Behat. |

`tests/run.sh` runs the tests of these scripts. It needs no Docker. Run it when you change something in `bin/`.

## Release a plugin

A release is a tag named `<component>-v<release>`, for example `local_academictranscript-v1.0.0`. Pushing the tag builds the plugin's ZIP and publishes a GitHub release.

1. In the plugin's `version.php`, raise `$plugin->version` and set `$plugin->release`.
2. In the plugin's `CHANGES.md`, add a section headed `## <release>` above the older ones, and list what changed.
3. Merge the change and wait for the checks to pass.
4. Check the tag before you push it: `bin/release-info local_academictranscript-v1.0.0`. It fails when the tag, `version.php` and `CHANGES.md` disagree. Delete the temporary notes file it prints.
5. To try the ZIP first, run `bin/build-zip local/academictranscript`. It writes `dist/local_academictranscript-1.0.0.zip`.
6. Tag the merged commit and push the tag:

   ```bash
   git tag local_academictranscript-v1.0.0
   git push origin local_academictranscript-v1.0.0
   ```

The release workflow runs `bin/release-info` on the tag, builds the ZIP with `bin/build-zip`, and creates a GitHub release named `<component> <release>`. The ZIP is attached, and the `CHANGES.md` section is the release notes.

## Help and contributions

- Report a bug or ask a question on the [issue tracker](https://github.com/Clouve/moodle-plugins/issues).
- To send a change, read [CONTRIBUTING.md](CONTRIBUTING.md) first.
- To report a security problem, follow [SECURITY.md](SECURITY.md). Don't open a public issue for it.

## License

The plugins are free software under the GNU General Public License, version 3 or later. See [LICENSE](LICENSE). Code that a plugin bundles keeps its own license, and each plugin's README lists it.

[Clouve Inc.](https://clouve.com) maintains this repo. Copyright 2026 Clouve Inc.
