# Contributing

Bug reports, fixes and new plugins are welcome. This page says how to check your work and what the maintainers expect.

## Before you start

- For anything bigger than a small fix, open an issue first and say what you want to change.
- Transcripts are official records. Open an issue before you change how grades, GPA, quality points or repeat rules are worked out.
- Report security problems in private, as [SECURITY.md](SECURITY.md) describes. Don't open an issue for them.

## What you need

- Docker, for `bin/check`.
- Bash and `zip`, for the scripts in `bin/`.
- Git, only to clone the repo and to tag a release. None of the scripts in `bin/` runs it on your computer.

## Run the checks

Run the checks before you open a pull request:

```bash
bin/check --plugin local/academictranscript
```

That runs every step on Moodle 5.3, PHP 8.3 and PostgreSQL. If you can, run another combination too, for example `--moodle 4.5 --php 8.1 --db mysqli`, because code that works on Moodle 5.3 can break on 4.5. The [README](README.md) lists the options. If you change something in `bin/`, run `tests/run.sh` as well.

`bin/check` runs PHPUnit as root, so it shows one skipped test: the uninstall test for a signing-key folder that can't be deleted needs a user who isn't root. GitHub Actions runs it.

GitHub Actions runs the checks on every push and pull request, on these combinations:

| Moodle | PHP | Database |
|---|---|---|
| 4.5 | 8.1 | PostgreSQL 17 |
| 4.5 | 8.3 | MySQL 8.4 |
| 5.1 | 8.2 | MariaDB 11 |
| 5.2 | 8.3 | MySQL 8.4 |
| 5.3 | 8.3 | PostgreSQL 17 |
| 5.3 | 8.4 | MySQL 8.4 |

The steps are PHP lint, the PHP Mess Detector (it only informs), `phpcs` and `phpdoc` with no warnings allowed, validate, savepoints, mustache, grunt, `phpunit` with warnings counted as failures, and Behat (it passes when a plugin has no features). A separate job runs `tests/run.sh`, builds the ZIP of every plugin, and keeps the ZIPs for 14 days as a build artifact. A pull request is ready when every job is green.

Every plugin runs on the same six combinations. A plugin that supports other versions needs a change to `.github/workflows/ci.yml`.

## Coding rules

- Write PHP 8.1 syntax only. Moodle 4.5 runs on PHP 8.1.
- Follow Moodle's coding style. `phpcs` (moodle-cs) and `phpdoc` must pass with no warnings.
- Start every PHP file with the standard Moodle GPL header and a docblock with `@package`, `@copyright` and `@license`. Copy them from an existing file, such as `local/academictranscript/version.php`.
- Add your own `@copyright` line next to Clouve's in a file that you write or change substantially, as Moodle core does. For example, put a second line `@copyright  2027 Jane Doe <jane@example.com>` under `@copyright  2026 Clouve Inc.`. Leave the `@license` line as it is.
- Put every text a person reads in a language string, including date formats. Keep the strings in `lang/en/` sorted by key. moodle-cs checks that.
- Write docs and language strings in plain words and short sentences, in sentence case. Leave out em and en dashes, curly quotes and exclamation marks.
- Check the user's capability on every page, and the sesskey on every action that changes data. Use placeholders in every SQL query.
- Store the names of courses, programs and marks as plain text, and escape them once, where they are shown. In Academic Transcript, `local\text::plain()` makes the plain text.
- Don't rename a component, a page, a table, a capability, a setting or a course field short name. Sites keep data under those names, and the address of the verify page is printed on every Official transcript.
- Keep bundled third-party code as upstream ships it. Declare it in `thirdpartylibs.xml` and describe it in a `readme_moodle.txt` next to it.

## Tests

Tests are PHPUnit tests in the plugin's `tests/` folder. They run on PHPUnit 9.6 (Moodle 4.5) and 11.5 (Moodle 5.3), so each test carries both the docblock annotation and the PHP attribute. moodle-cs checks most of these rules.

- Name a test file after the class it tests, with the suffix `_test`, in a folder that matches the namespace. `tests/local/pdf/builder_test.php` tests `local_academictranscript\local\pdf\builder`.
- Make the test class `final` and every test method return `void`.
- Make data providers `public static`, name them `*_provider` and give them `@return array`.
- Declare `setUp()` as `protected function setUp(): void` and call `parent::setUp()` once in it. `tearDown()` follows the same rule.
- Use `@covers` with `#[CoversClass]`, and `@dataProvider` with `#[DataProvider]`. A test of a global function uses `@covers ::name` with `#[CoversFunction('name')]`. A test with no covered unit uses `@coversNothing` with `#[CoversNothing]`.
- Put the class attribute above the class docblock. Put method attributes between the docblock and the method.
- Extend `\basic_testcase` when the test uses no database, and `\advanced_testcase` when it does. A database test calls `resetAfterTest()` in `setUp()`.
- Call `scale::reset_cache()` in `setUp()` of every test whose code reads the grading scale or the marks. The `scale` class caches the rows for the request, and that cache outlives the database reset of a test.
- The data generator in `tests/generator/lib.php` creates terms, programs, catalog courses, student records and posted grades.

This is the shape of a test file:

```php
namespace local_academictranscript\local;

#[\PHPUnit\Framework\Attributes\CoversClass(calculator::class)]
/**
 * Tests for the grade calculator.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_academictranscript\local\calculator
 */
final class calculator_test extends \basic_testcase {
    /**
     * Letters for percentages with rounding on.
     *
     * @dataProvider letter_provider
     * @param float $percent The course total
     * @param string $expected The letter
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('letter_provider')]
    public function test_letter_rounds_then_matches(float $percent, string $expected): void {
        // Build the calculator, call letter() and assert the result.
    }

    /**
     * Data for test_letter_rounds_then_matches.
     *
     * @return array
     */
    public static function letter_provider(): array {
        return [
            'full marks' => [100, 'A'],
        ];
    }
}
```

## Version numbers

Each plugin's `version.php` has two numbers.

- `$plugin->version` is Moodle's build number, written `YYYYMMDDNN`: the date and a two-digit counter for the day. Moodle runs a plugin's upgrade only when this number goes up. Raise it when a change needs an upgrade, such as a new or changed capability in `db/access.php` or a change in `db/install.xml`.
- `$plugin->release` is the number people see, written `major.minor.patch`. The release tag, the ZIP name and the `CHANGES.md` heading all use it, and they must agree.

When you change the database, also change the `VERSION` attribute in `db/install.xml` (a date, `YYYYMMDD`), and add a step to `db/upgrade.php` that ends with `upgrade_plugin_savepoint()`. The `savepoints` check looks for it.

When a plugin starts to support other Moodle versions, change `$plugin->requires` or `$plugin->supported`, the plugin table in the [README](README.md), and the CI matrix.

## Add a plugin

1. Put the plugin at its Moodle path, for example `blocks/<name>` or `admin/tool/<name>`. The folder is named after the plugin: `foo` for `block_foo`. Use only letters, digits and `_ . -` in folder names.
2. Give it a `version.php` that sets `$plugin->component`, `$plugin->version`, `$plugin->release`, `$plugin->requires`, `$plugin->supported` and `$plugin->maturity`. `bin/list-plugins` finds a plugin by that file.
3. Add a `README.md` and a `CHANGES.md` with a section headed `## <release>`. The release notes come from that section.
4. Copy the `LICENSE` file of the repo into the plugin folder. The install ZIP holds only that folder, so the license text has to be in it.
5. If the plugin bundles third-party code, declare it in `thirdpartylibs.xml` and add a `readme_moodle.txt` next to the code.
6. Add a row to the plugin table in the [README](README.md).
7. Run `bin/check --plugin <path>`.

CI and the release workflow find the plugin on their own, because they don't keep a list of plugins. `bin/build-zip` needs the folder name to match the name part of `$plugin->component`, and the release to hold only letters, digits and `. _ + -`.

## Releases

Only the maintainers publish releases. A release is a tag named `<component>-v<release>`, and the [README](README.md) lists the steps. Before the tag, the plugin's `CHANGES.md` needs a `## <release>` section and `bin/release-info <tag>` must succeed.

## License of your contribution

The code in this repo is under the GNU General Public License, version 3 or later. By sending a pull request you agree that your change is released under the same license.
