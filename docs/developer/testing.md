# Testing

This page says what tests the repo has, how to run them, and what to run for a change: PHPUnit, the generators, `bin/check`, Behat, the CI matrix, the repo's own tests and `bin/check-docs`.

## Contents

- [What to run for a change](#what-to-run-for-a-change)
- [PHPUnit layout](#phpunit-layout)
- [Kinds of test](#kinds-of-test)
- [Generators](#generators)
- [Run one test quickly](#run-one-test-quickly)
- [bin/check](#bincheck)
- [Behat](#behat)
- [The CI matrix](#the-ci-matrix)
- [tests/run.sh and the repo tests](#testsrunsh-and-the-repo-tests)
- [bin/check-docs](#bincheck-docs)
- [Related pages](#related-pages)

## What to run for a change

| You changed | Run |
|---|---|
| A class or a page of a plugin | Its test on the test site, then `bin/check --plugin <path>` |
| Anything a Moodle version could break | Also `bin/check --plugin <path> --moodle 4.5 --php 8.1 --db mysqli` |
| How two plugins work together | Also `--together`, on 4.5 and 5.3 |
| The theme | `theme/studentrecords/tests/pages_test.php` and `theme/studentrecords/tests/scss_test.php` on 4.5 and 5.3, and on 5.2 for the drawers |
| Something in `bin/` or `tests/` | `tests/run.sh` |
| Anything in `docs/`, or a name the docs use | `bin/check-docs` |
| The grade arithmetic | Open an issue first. Then both plugins, with `--together` |

`bin/check-docs` also fails when code changes and the docs don't: a page, capability, setting, task, table, column, message type, event or class with no mention in the docs is a problem.

## PHPUnit layout

| Plugin | Test files | `test_` methods | Behat features |
|---|---|---|---|
| `local/academictranscript` | 27 | 350 | 0 |
| `local/studentrecords` | 48 | 602 | 4 |
| `theme/studentrecords` | 8 | 47 | 0 |

A method with a data provider counts once here.

The rules, from `CONTRIBUTING.md`:

- Tests are in the plugin's `tests/` folder. A test file is named after the class it tests, with the suffix `_test`, in a folder that matches the namespace. `tests/local/pdf/builder_test.php` tests `local_academictranscript\local\pdf\builder`.
- The test class is `final`, and every test method returns `void`.
- Tests run on PHPUnit 9.6 (Moodle 4.5) and 11.5 (Moodle 5.1 and later). PHPUnit 9 ignores PHP attributes, so each test carries both the docblock annotation and the attribute. `#[DataProvider]` alone gives a test with no data on Moodle 4.5.
- Data providers are `public static`, are named `*_provider` and have `@return array`.
- `setUp()` is `protected function setUp(): void` and calls `parent::setUp()` once. `tearDown()` follows the same rule.
- Use `@covers` with `#[CoversClass]`, `@covers ::name` with `#[CoversFunction('name')]` for a global function, and `@coversNothing` with `#[CoversNothing]` for a test with no covered unit.
- The class attribute goes above the class docblock. Method attributes go between the docblock and the method.
- Extend `\basic_testcase` when the test uses no database, and `\advanced_testcase` when it does. A database test calls `resetAfterTest()` in `setUp()`.

Also from the code:

- Call `scale::reset_cache()` in `setUp()` of every test whose code reads the grading scale or the marks in Academic Transcript. The cache outlives the database reset of a test.
- Call `shell::reset()` and `home::reset_cache()` in tests of Student Records that read the menu or the No LMS flag, for the same reason.
- Moodle keeps the admin tree in the global `$ADMIN`, and a test reset doesn't clear it. Page tests set `$ADMIN = null` in `tearDown()`.
- `$CFG->upgraderunning` survives a test reset too, so `theme/studentrecords/tests/pages_test.php` unsets it.
- On PostgreSQL Moodle runs each test inside a transaction it rolls back, and a message in a transaction waits for cron. So a test that needs the real commit calls `preventResetByRollback()`. `local/studentrecords/tests/local/messages_test.php` does it in `setUp()`.

This is the shape of a test, from `local/academictranscript/tests/local/calculator_test.php`:

```php
namespace local_academictranscript\local;

#[\PHPUnit\Framework\Attributes\CoversClass(calculator::class)]
/**
 * Tests for the grade calculator.
 *
 * The calculator is pure arithmetic, so these tests build the standard 4.0 scale by hand and use no database.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_academictranscript\local\calculator
 */
final class calculator_test extends \basic_testcase {
    /**
     * With rounding on, the percentage is rounded to a whole number and then matched to a letter.
     *
     * @dataProvider letter_provider
     * @param float $percent The course total
     * @param string $expected The letter
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('letter_provider')]
    public function test_letter_rounds_then_matches(float $percent, string $expected): void {
        $this->assertSame($expected, $this->make_calculator()->letter($percent));
    }
```

## Kinds of test

| Kind | Base class | Where | What it's for |
|---|---|---|---|
| Pure logic | `\basic_testcase` | `calculator_test.php`, `grading_test.php`, `dates_test.php` | Rules that need no database. Many classes keep these in static methods so they test without a site |
| Database | `\advanced_testcase` | Most class tests | Rules with their tables |
| Page tests | `\advanced_testcase` | `local/academictranscript/tests/pages_test.php`, `local/studentrecords/tests/money_pages_test.php`, `local/studentrecords/tests/records_pages_test.php`, `local/studentrecords/tests/local/registration_pages_test.php`, `theme/studentrecords/tests/pages_test.php` | A page run the way Moodle runs it: who gets in, wrong sesskeys, names escaped once |
| Privacy | `\core_privacy\tests\provider_testcase` | `tests/privacy/provider_test.php` | Export, delete, and that every user column is declared |
| Install and upgrade | `\advanced_testcase` | `upgrade_test.php`, `uninstall_test.php`, `install_test.php` | Each step, a second run, and that the versions agree |
| Language and schema | `\basic_testcase` | `local/studentrecords/tests/lang_test.php`, `schema_test.php` | Capability names, string order, reserved words, table name length |
| Locks | `\advanced_testcase` | `requests_test.php`, `payments_test.php`, `srpost_test.php` | What happens under a lock and when it ends |

### Page tests

A page test sets `$_GET` or `$_POST`, requires the page and collects the output. A redirect or an error ends in an exception, as every page does in a PHPUnit test, so a test reads `errorcode` from it. `run_page()` in `local/academictranscript/tests/pages_test.php` says what can't be run this way: a page that calls `die()` ends the whole PHPUnit run, and a page that declares a function can be required once only. `local/academictranscript/codes.php` and `local/academictranscript/post.php` call `die()` for a course without students, so a test gives the course a student first.

On Moodle 4.5 test data can set up the theme of the page, and `require_login()` with a course then refuses to run. `local/studentrecords/tests/records_pages_test.php` sets `$OUTPUT = new \bootstrap_renderer()` for that case, and `local/academictranscript/tests/pages_test.php` uses `redirectMessages()`.

### Lock fixtures

- `local/studentrecords/tests/fixtures/recording_lock_factory.php` extends Moodle's `\core\lock\db_record_lock_factory`. Set `$CFG->lock_factory` to it. It notes `taken` and `released` with the state of the test, and `$busy` names a resource someone else holds. A test can then prove that a lock outlasted a commit.
- `local/academictranscript/tests/fixtures/recording_lock_factory.php` notes the same events for Academic Transcript. It can also play the other request: a test can't run two requests at once, so `$first` runs when the lock is asked for, as the request that got there first.
- `local/academictranscript/tests/fixtures/refusing_lock_factory.php` gives no lock, to make a write fail part way through. A lock taken in the test itself is no good for that, because MySQL and MariaDB give the same session the same lock again.

### Tests that need a sibling

A test that needs a sibling plugin calls `markTestSkipped()` when the plugin isn't there:

```php
if (!class_exists('\\local_studentrecords\\local\\registration')) {
    $this->markTestSkipped('Student Records is not installed.');
}
```

So these run only with `--together`, and in the two CI rows that install the other plugins.

## Generators

Get a generator with `$this->getDataGenerator()->get_plugin_generator('<component>')`.

`local/studentrecords/tests/generator/lib.php` makes records through the plugin's own code:

| Method | Makes |
|---|---|
| `set_settings()` | School settings, name to value |
| `create_scale()` | A grading scale: A to F, and the marks P and I |
| `create_term()`, `create_program()`, `create_course()` | The catalog |
| `create_student()`, `create_user_student()` | A student file, for an existing user or with a new one |
| `create_offering()`, `create_registration()` | A section and a seat |
| `create_fee()`, `create_charge()` | Prices and account lines |
| `create_meeting()` | A class meeting |

`local/academictranscript/tests/generator/lib.php`:

| Method | Makes |
|---|---|
| `create_term()`, `create_program()`, `create_catalog_course()` | Own data |
| `create_student_record()` | Student details |
| `create_course_with_fields()` | A Moodle course with the Transcript fields. It returns the course |
| `create_transfer()`, `create_posted_grade()`, `create_code_row()` | Rows. These refuse a column that the table doesn't have, so a typo can't pass |
| `set_course_total()` | A course total in the gradebook |

## Run one test quickly

On the test site, with the code copied in:

```bash
docs/tools/site.sh sync local/academictranscript
docs/tools/site.sh phpunit --testsuite local_academictranscript_testsuite --filter test_gpa_rounds_a_half_up
docs/tools/site.sh phpunit public/local/academictranscript/tests/local/calculator_test.php
docs/tools/site.sh phpunit --testsuite theme_studentrecords_testsuite
```

Run `sync` after each change, because `phpunit` runs on the copy in the site. `phpunit` takes PHPUnit's own arguments, rebuilds `phpunit.xml`, and runs one PHPUnit at a time, because every site shares PHPUnit's tables.

Each plugin has a test suite named `<component>_testsuite`. Naming the suite or the file keeps a run to about a second. `--filter` alone works too, but it loads the tests of all of Moodle first, and it also matches their names. The whole theme suite takes about 15 seconds.

The sites run Moodle 5.3 with all three plugins installed, so a sibling test isn't skipped there.

## bin/check

`bin/check` runs the steps of CI in Docker. It prints `PASS <step>` or `FAIL <step>` on stdout, and the output of the tools goes to stderr.

Options:

| Option | Values | Default |
|---|---|---|
| `--moodle` | 4.5, 5.1, 5.2, 5.3 | 5.3 |
| `--php` | 8.1, 8.2, 8.3, 8.4 | 8.3 |
| `--db` | `pgsql` (PostgreSQL 17), `mysqli` (MySQL 8.4), `mariadb` (MariaDB 11) | `pgsql` |
| `--plugin <path>` | A plugin folder, from the current folder or the repo root. Repeat it for several | Every plugin |
| `--together` | Installs the other plugins next to the plugin being checked | Off |

The steps, in order: `phplint`, `phpcs`, `phpdoc`, `validate`, `savepoints`, `mustache`, `grunt`, `phpunit`. Name some to run only those, in the order given. When `phpunit` isn't a step, the install skips PHPUnit's test site, which is the slow part.

| Step | Run as |
|---|---|
| `phpcs` | `--max-warnings 0 --exclude moodle.Files.BoilerplateComment` |
| `phpdoc` | `--max-warnings 0` |
| `grunt` | `--max-lint-warnings 0` |
| `phpunit` | `--fail-on-warning` |

`--moodle` and `--php` have to fit. `bin/check` accepts any pair, but each Moodle has a minimum PHP. [Compatibility](compatibility.md#moodle-and-php-versions) lists them.

Exit status: 0 when every step passed, 1 when a step failed, and 2 when the checks can't run (bad options, no Docker, a container that won't start).

`bin/check` runs PHPUnit as root, so one more test is skipped there, besides the tests that need a sibling: the uninstall test for a signing-key folder that can't be deleted needs a user who isn't root. GitHub Actions runs it.

### With the other plugins

`--together` copies the other plugins into `/work/extra` and installs them with `--extra-plugins`. PHPUnit still runs the plugin being checked, so a sibling test is no longer skipped. Use it for any change near [the contract between the plugins](architecture.md#the-contract-between-the-plugins).

## Behat

`local/studentrecords/tests/behat/` has four features. Academic Transcript and the theme have none.

| Feature | Scenarios |
|---|---|
| `local/studentrecords/tests/behat/attendance_teacher.feature` | 1 |
| `local/studentrecords/tests/behat/billing_access.feature` | 3 |
| `local/studentrecords/tests/behat/setup_access.feature` | 2 |
| `local/studentrecords/tests/behat/student_home.feature` | 2 |

Behat runs in CI only, with `moodle-plugin-ci behat --profile chrome --scss-deprecations`. `bin/check` leaves it out. The step passes when a plugin has no features. When it fails, CI uploads the Behat fail dump as an artifact for 7 days.

`local/studentrecords/tests/generator/behat_local_studentrecords_generator.php` gives these entities: `settings`, `terms`, `programs`, `catalog courses`, `students`, `offerings`, `registrations`, `fees`, `meetings`. Use them as `local_studentrecords > students`:

```gherkin
Given the following "local_studentrecords > students" exist:
  | user     | studentid |
  | student1 | TST-0001  |
```

## The CI matrix

`.github/workflows/ci.yml` runs on every push and pull request. Three jobs:

- `plugins` lists the plugin folders with `bin/list-plugins --json`.
- `test` runs once for each plugin and each row below, with `fail-fast: false`.
- `package` runs `tests/run.sh`, builds the ZIP of every plugin, and keeps the ZIPs for 14 days as the artifact `plugin-zips`.

| Moodle | PHP | Database | Other plugins |
|---|---|---|---|
| 4.5 | 8.1 | PostgreSQL 17 | No |
| 4.5 | 8.3 | MySQL 8.4 | No |
| 5.1 | 8.2 | MariaDB 11 | No |
| 5.2 | 8.3 | MySQL 8.4 | No |
| 5.3 | 8.3 | PostgreSQL 17 | No |
| 5.3 | 8.4 | MySQL 8.4 | No |
| 4.5 | 8.1 | MySQL 8.4 | Yes |
| 5.3 | 8.3 | PostgreSQL 17 | Yes |

Each test job runs: PHP lint, the PHP Mess Detector (it only informs), `phpcs`, `phpdoc`, validate, savepoints, mustache, grunt, PHPUnit and Behat. Every step runs even when an earlier one failed. A pull request is ready when every job is green.

`bin/list-plugins` finds the plugins, so a new plugin needs no change to the workflow. A plugin that supports other versions needs a change to the matrix.

## tests/run.sh and the repo tests

`tests/run.sh` runs every `tests/*.test.sh` file, and needs no Docker. Run it when you change something in `bin/`.

| File | What it tests |
|---|---|
| `tests/list-plugins.test.sh` | `bin/list-plugins` |
| `tests/build-zip.test.sh` | `bin/build-zip` |
| `tests/release-info.test.sh` | `bin/release-info` |
| `tests/check.test.sh` | `bin/check`, with a fake `docker` on the `PATH`. It covers the options, the reports and the clean-up, not the Moodle checks |
| `tests/docs.test.sh` | `bin/check-docs`, and the check of the real docs |
| `tests/lib.sh` | Shared helpers. A test builds a throwaway repo in a temp folder, and the real repo is only read |

`tests/docs.test.sh` runs `bin/check-docs` on the real repo, so CI fails when the docs and the code drift apart.

## bin/check-docs

`bin/check-docs` checks the Markdown files in `docs/`, `AGENTS.md` and `CLAUDE.md` against the code. It needs Python 3 and nothing else. It prints one line for each problem, and exits 0 when there are none, 1 when there are some, and 2 when it can't run.

| Check | What it looks at |
|---|---|
| `links` | Every local link and anchor resolves, and every page can be reached from `docs/README.md` |
| `images` | Every image exists, has alt text and is under the size limit, the docs folder is under its limit, and no image outside `docs/tools/` is unused |
| `paths` | Every path in backticks that starts with a folder of the repo or of a plugin, such as `local/` or `classes/`, exists |
| `symbols` | Every `class::method()` in backticks exists, for classes of the plugins |
| `coverage` | Every page, capability, setting, task, table, column, message type, event and class of the plugins is named in the docs |
| `writing` | No em or en dashes, curly quotes or exclamation marks, no line numbers, sentence case headings, a table of contents on a long page |

```bash
bin/check-docs
bin/check-docs --only links,paths,symbols,writing docs/developer/theme
```

A path after the options limits the checks to that folder, apart from `coverage`, which always reads the whole repo. Two things to know when you write a page:

- A path of Moodle that starts like a repo path, such as Boost's `config.php` under `theme/`, fails `paths`. Write it with `<moodle>/` in front: `<moodle>/theme/boost/config.php`.
- `symbols` knows only the classes of the plugins. `theme_config::layout_file()` passes because `theme_config` isn't one of them, so a wrong method on a Moodle class isn't caught.

## Related pages

- [Setup](setup.md)
- [Conventions](conventions.md)
- [Compatibility](compatibility.md)
- [Debugging](debugging.md)
- [Release](release.md)
