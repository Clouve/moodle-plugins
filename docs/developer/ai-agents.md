# AI agents

This page says how an AI coding agent should work in this repo: where to look first, the rules it must not break, how to run the checks, how to prove a change, and what a change must update. `AGENTS.md` at the repo root points here.

## Contents

- [Where to look first](#where-to-look-first)
- [Rules you must not break](#rules-you-must-not-break)
- [How to read this repo](#how-to-read-this-repo)
- [How to run the checks](#how-to-run-the-checks)
- [How to prove a change](#how-to-prove-a-change)
- [What a change must update](#what-a-change-must-update)
- [Ask before you do these](#ask-before-you-do-these)
- [Writing docs](#writing-docs)
- [Related pages](#related-pages)

## Where to look first

1. [Architecture](architecture.md), for what each plugin owns and which calls which.
2. [Pitfalls](pitfalls.md), for what breaks without an error.
3. [Decisions](decisions.md), before you change anything that looks like a mistake. An earlier review "fixed" a design and it had to be put back.
4. The code map of the plugin: [Academic Transcript](academic-transcript/README.md), [Student Records](student-records/README.md), the [theme](theme/README.md).
5. The test of the class you're changing, in `tests/` under the same path as in `classes/`. Test names are sentences that state the rule.

## Rules you must not break

Each of these has a reason in [Pitfalls](pitfalls.md) or [Decisions](decisions.md). Don't work around one to make a test pass.

Numbers:

- Never replace an exact rounding helper with `round()`, and never work out an official figure in floats.
- Don't change how grades, GPA, quality points or repeat rules are worked out without an issue. Transcripts are official records.
- Keep the record and the transcript in step. A change to a figure on one side needs the other side and a `--together` run.
- `$plugin->version` only goes up, and stays above the numbers in [Release](release.md#the-floor-under-version).

Writes:

- Commit after the `try` block, with `records::commit()` or `logger::commit()`.
- A lock goes outside the transaction, and outlasts the commit. Section before registration, and nothing under `accounts`.
- Don't call `billing::one_at_a_time()` or `students::save()` from inside a transaction you started.
- Nothing slow in a transaction or under a lock. Send through `messages::student()` or `messages::staff()`.
- An account line is never changed or removed. A mistake gets a reversing line.
- An issued transcript never changes.

Access:

- Check the capability on every page, the sesskey on every action that changes data, and use placeholders in every SQL query.
- Keep the own-record rules in the class, so every path meets them.
- With Student Records as the source, a hold stops every way of issuing an Official transcript.

Boundaries:

- A plugin stays optional. Put a check in front of every call to a sibling, and keep the plugin working without it.
- From the theme, call Student Records only through `records::call()`.
- Don't rename a component, a page, a table, a capability, a setting or a course field short name.
- Don't add a table, a capability or user data to the theme.

Text and files:

- Never run `phpcbf` on `local/academictranscript` or `local/studentrecords`. It writes the GPL header in.
- Keep the GPL header and the authors in the theme's files.
- Write PHP 8.1 syntax only.
- Put every text a person reads in a language string, sorted by key.
- Store a name as plain text, and escape it once where it's shown.
- Keep bundled code as upstream ships it.
- Never commit a secret, a real student's data, or the name of a school, a person or a test account. The repo is public.

## How to read this repo

- The docs describe the code as released, but the code wins. If a page, a README or a docblock disagrees with the code, trust the code and say so.
- Follow a page into `classes/local/`. Pages hold access checks and output. The rules are in the classes.
- Some calls can't be found by searching for the method name. `local/studentrecords/index.php` calls `homes::$view()`, and strings and classes are built from prefixes such as `'status_' . $status`.
- Classes share short names across plugins: two named `records`, one `provider` and one `access` in each local plugin, and a form named `term` in both. Check the namespace.
- Static caches outlive a test's database reset. See [Testing](testing.md#phpunit-layout).
- Moodle's own source differs by version, and a plugin file has `public/` in front of it on 5.1 and later. Don't assume one version's API from memory. Read it on 4.5 and on 5.3.

## How to run the checks

| Check | Command | Needs | Time |
|---|---|---|---|
| The docs | `bin/check-docs` | Python 3 | Seconds |
| The scripts in `bin/` | `tests/run.sh` | Bash, `zip`, Python 3 | About a minute |
| One PHPUnit test | `docs/tools/site.sh sync <plugin>`, then `docs/tools/site.sh phpunit --filter <test>` | The test site running | Seconds after the `sync` |
| All the steps for a plugin | `bin/check --plugin <path>` | Docker | Several minutes |
| Another version | `bin/check --plugin <path> --moodle 4.5 --php 8.1 --db mysqli` | Docker | Several minutes |
| With the other plugins | `bin/check --plugin <path> --together` | Docker | Several minutes |

`bin/check` starts Docker networks and containers, and clones Moodle on each run. If you can't use Docker, say so, and say what you ran instead. [Setup](setup.md) and [Testing](testing.md) have the details.

Behat runs only in CI.

## How to prove a change

A change is done when you can show it works, not when it looks right.

1. Write a test that fails first, when the change is a bug fix or a new rule. Name it as a sentence that states the rule.
2. Run that test, then the plugin's tests.
3. Run `bin/check --plugin <path>`, because `phpcs`, `phpdoc` and the others fail on warnings.
4. Run the oldest row too, `--moodle 4.5 --php 8.1 --db mysqli`, when the change touches templates, Moodle APIs or SQL.
5. Run `--together` when two plugins are involved. Without it, a sibling test is skipped and proves nothing.
6. For a page, run it. A test can't see a style, and a page test can't run a page that calls `die()`. On the test site, `docs/tools/wd.py` drives Chrome.
7. Run `bin/check-docs`.
8. Say what you ran, on which Moodle, PHP and database, and what you didn't run.

A skipped test isn't a pass. A green run on PHP 8.3 proves nothing about rounding on 8.4.

## What a change must update

`bin/check-docs` fails when a page, capability, setting, task, table, column, message type, event or class has no mention in the docs.

| You add or change | Also update |
|---|---|
| Behaviour a person sees | The plugin's `CHANGES.md`, its `README.md`, and the user docs. Screenshots: see [Maintaining the docs](../MAINTAINING.md) |
| A page | The code map, a page test, and for a staff page of Student Records a line in `staffarea::PAGES` |
| A table or a column | `db/install.xml` and its `VERSION`, a step in `db/upgrade.php`, `$plugin->version`, the privacy provider's metadata, the data model page |
| A capability | `db/access.php`, `$plugin->version`, its string, such as `studentrecords:<cap>`, the access page |
| A setting | `settings.php` or `catalog::SCHOOL`, its strings, the settings page |
| A task | `db/tasks.php`, a `task_` string, `$plugin->version`, the tasks page |
| An automatic message | `messages::STUDENT` or `messages::STAFF`, the strings `msgdef_<key>_subject`, `msgdef_<key>_body`, `msgname_<key>`, `msgwhen_<key>`, the messages page |
| An audit or activity log action | `audit::ACTIONS` and `action_<action>`, a new object type with `obj_<type>`, or `logger::ACTIONS` and `logaction_<action>` |
| A status or a type stored in a table | Its string, such as `status_<status>`, and the schema comment |
| A call between plugins | The check in front of it, a test that skips without the sibling, [the contract](architecture.md#the-contract-between-the-plugins) |
| A class | Its test, and the code map |
| Something in `bin/` | Its test in `tests/`, and the table in `README.md` |
| The checks or the matrix | `bin/check`, `.github/workflows/ci.yml`, `CONTRIBUTING.md`, [Testing](testing.md#the-ci-matrix) |
| A supported Moodle or PHP version | [Compatibility](compatibility.md#add-support-for-a-new-moodle-version) has the list |
| Bundled code | `thirdpartylibs.xml`, `readme_moodle.txt`, the plugin's README |

## Ask before you do these

- Change how a grade, GPA, quality point or repeat rule is worked out.
- Change a decision in [Decisions](decisions.md), or something in [Known and left alone](pitfalls.md#known-and-left-alone).
- Change the licence of a file, or copy code between the theme and the local plugins.
- Add a dependency between plugins, or a third-party library.
- Publish a release, or push a tag. A pushed tag publishes a GitHub release.
- Report a security problem in public. Follow `SECURITY.md`.

## Writing docs

- Base every statement on the code, or on something you saw run. Don't write what a feature must do.
- Name files by their repo path, and code by its symbol. Don't use line numbers, which go stale.
- Copy code samples from the repo.
- Follow the writing rules in `CONTRIBUTING.md`: plain words, short sentences, sentence case, no em or en dashes, no curly quotes, no exclamation marks.
- Give no legal, tax, FERPA or federal aid advice. The Return of aid worksheet and the loan checks are helpers.
- Run `bin/check-docs`.

## Related pages

- [Developer docs](README.md)
- [Pitfalls](pitfalls.md)
- [Decisions](decisions.md)
- [Testing](testing.md)
- [Conventions](conventions.md)
