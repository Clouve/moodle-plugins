# Release

This page says how a release works: the version rules, `bin/release-info`, tags, ZIPs and the workflows. Only the maintainers publish releases.

## Contents

- [What a release is](#what-a-release-is)
- [The two numbers](#the-two-numbers)
- [The floor under version](#the-floor-under-version)
- [A database change](#a-database-change)
- [A capability or a setting](#a-capability-or-a-setting)
- [Steps](#steps)
- [bin/release-info](#binrelease-info)
- [bin/build-zip](#binbuild-zip)
- [bin/list-plugins](#binlist-plugins)
- [The workflows](#the-workflows)
- [What to update](#what-to-update)
- [Related pages](#related-pages)

## What a release is

A release is a tag named `<component>-v<release>`, for example `local_academictranscript-v1.4.3`. Each release is for one plugin and has one ZIP. Pushing the tag builds the ZIP and publishes a GitHub release.

The tags so far:

| Tag | Plugin version |
|---|---|
| `local_academictranscript-v1.0.0` | `2026102100` |
| `local_academictranscript-v1.4.3` | `2026111607` |
| `local_studentrecords-v1.4.6` | `2026111607` |
| `theme_studentrecords-v1.0.3` | `2026110304` |

## The two numbers

Each plugin's `version.php` has two numbers:

- `$plugin->version` is Moodle's build number, written `YYYYMMDDNN`: a date and a two-digit counter for the day. Moodle runs a plugin's upgrade only when this number goes up.
- `$plugin->release` is the number people see, written `major.minor.patch`. The tag, the ZIP name and the `CHANGES.md` heading all use it, and they must agree.

Raise `$plugin->version` when a change needs an upgrade, such as a new or changed capability in `db/access.php` or a change in `db/install.xml`. In Student Records every raise needs a savepoint too. See [A database change](#a-database-change).

## The floor under version

`$plugin->version` must stay above the builds that schools ran before the first public release, or those sites can't upgrade. Moodle refuses to go down.

| Plugin | Highest earlier build | Now |
|---|---|---|
| `local_academictranscript` | `2026111604` | `2026111607` |
| `local_studentrecords` | `2026111606` | `2026111607` |
| `theme_studentrecords` | `2026110303` | `2026110304` |

So these numbers look like future dates on purpose. The next build of a plugin is above its number now, even when today's date is lower: after `2026111607` comes `2026111608`, whatever the day. Once the calendar passes that date, the usual rule is back. Never lower a number to make it match today.

The theme's number is lower than the others because each plugin counts on its own.

## A database change

1. Change `db/install.xml`, and its `VERSION` attribute (a date, `YYYYMMDD`).
2. Add a step to `db/upgrade.php` that ends with `upgrade_plugin_savepoint()`. The `savepoints` step of `bin/check` looks for it.
3. Make the step safe to run again: check with `field_exists()`, `table_exists()` or `index_exists()` first, as the existing steps do. A site from an earlier build may already have the column.
4. Raise `$plugin->version` to the number of the step.
5. Test the step. `local/studentrecords/tests/upgrade_test.php` shows the pattern: put the site in the old state, run the step, and run it a second time.

`test_version_reaches_the_last_step` in `local/studentrecords/tests/upgrade_test.php` asserts that the highest savepoint equals `$plugin->version`, and it names the number in full. So every raise in Student Records needs a step with a savepoint, even with no database work, and that test changes with it.

Sites of earlier builds can hold columns that today's `db/install.xml` doesn't have. The step for `2026111607` of Academic Transcript drops `program` and `admissiondate` only when they hold nothing else.

## A capability or a setting

- A new or changed capability in `db/access.php` needs a raise of `$plugin->version`, or Moodle doesn't read it.
- In Student Records it also needs its `studentrecords:<cap>` string, or `local/studentrecords/tests/lang_test.php` fails. Add it to the docs too, or `bin/check-docs` fails.
- A new setting needs no raise. Moodle offers it on the "New settings" page.
- Never rename a capability or a setting. Sites keep data under those names.

## Steps

1. In the plugin's `version.php`, raise `$plugin->version` and set `$plugin->release`.
2. In the plugin's `CHANGES.md`, add a section headed `## <release>` above the older ones, and list what changed. A date or other text may follow the release on that line.
3. Merge the change and wait for the checks to pass.
4. Check the tag before you push it. It fails when the tag, `version.php` and `CHANGES.md` disagree. Delete the temporary notes file it prints.

   ```bash
   bin/release-info local_academictranscript-v1.4.4
   ```

5. To try the ZIP first, run `bin/build-zip local/academictranscript`. It writes `dist/local_academictranscript-<release>.zip`.
6. Tag the merged commit and push the tag:

   ```bash
   git tag local_academictranscript-v1.4.4
   git push origin local_academictranscript-v1.4.4
   ```

## bin/release-info

`bin/release-info <tag>` reads a tag and prints, one per line, the lines the release workflow appends to `$GITHUB_OUTPUT`:

```text
path=local/academictranscript
component=local_academictranscript
release=1.4.3
zip=local_academictranscript-1.4.3.zip
notes=/tmp/release-notes.qDUaYX
```

The notes file holds the section of `CHANGES.md` for that release, without its heading, because the release title already says the release. The caller removes the file.

It fails, and prints nothing to stdout, in these cases:

- The tag isn't `<component>-v<release>`.
- No plugin folder sets that component, or more than one does.
- The release differs from `version.php`. For example, `bin/release-info theme_studentrecords-v9.9.9` says the tag says release 9.9.9 and `theme/studentrecords/version.php` says 1.0.3.
- `CHANGES.md` is missing, or has no section for the release, or an empty one.

## bin/build-zip

`bin/build-zip <plugin path> [<out dir>]` builds `<out dir>/<component>-<release>.zip` and prints its path. The out dir defaults to `dist/`, which is git-ignored.

- The ZIP holds only the plugin folder, which is the layout Moodle's plugin installer expects: `studentrecords/version.php`, not `theme/studentrecords/version.php`.
- It leaves out `.DS_Store`, `*.swp` and `.git*` files.
- The folder must be named after the plugin: `foo` for `block_foo`.
- The release can hold only letters, digits and `. _ + -`.
- It builds in a temp folder and moves the finished file into place, so a failed build leaves no half file.

Everything else in the plugin folder goes in, tests included. The docs are at the repo root, so screenshots stay out of the ZIPs.

## bin/list-plugins

`bin/list-plugins [--json]` lists the plugin folders. A plugin folder holds a `version.php` that sets `$plugin->component`. Folders inside another plugin, `dist/`, `tests/` and hidden folders are skipped.

```text
local/academictranscript
local/studentrecords
theme/studentrecords
```

CI and the release workflow use it, so they keep no list of plugins.

## The workflows

`.github/workflows/ci.yml` runs on every push and pull request. See [The CI matrix](testing.md#the-ci-matrix). It also builds a ZIP of every plugin and keeps them for 14 days, so anyone can install a pull request's build and try it.

`.github/workflows/release.yml` runs on a pushed tag that matches `*-v*`:

1. `bin/release-info "$GITHUB_REF_NAME" >> "$GITHUB_OUTPUT"` reads the tag. It stops the job if the tag doesn't match `version.php` or `CHANGES.md`.
2. `bin/build-zip "$PLUGIN_PATH"` builds the ZIP.
3. `gh release create` publishes it with `--title "$COMPONENT $RELEASE"`, `--notes-file` and `--verify-tag`.

The workflow has `contents: write`. It doesn't run the tests, so wait for CI on the merged commit first.

## What to update

Before you tag a release, check that these agree:

| What | Where |
|---|---|
| Build number | `$plugin->version` in `version.php`, and the last savepoint in `db/upgrade.php` |
| Release | `$plugin->release`, the `CHANGES.md` heading, and the tag |
| Schema | `VERSION` in `db/install.xml`, when the database changed |
| Moodle versions | `$plugin->requires`, `$plugin->supported`, the plugin table in `README.md`, the matrix in `.github/workflows/ci.yml` |
| The docs | `bin/check-docs` passes. `docs/developer/README.md` and the other pages name the versions they describe |
| Bundled code | `thirdpartylibs.xml` and `readme_moodle.txt`, when a library or font changed |

## Related pages

- [Testing](testing.md)
- [Compatibility](compatibility.md)
- [Pitfalls](pitfalls.md#upgrades)
- [Decisions](decisions.md)
