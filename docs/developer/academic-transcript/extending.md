# Extending

This page says how to add a page, a setting, a capability, a table, a mark, a PDF block and a log action to Academic Transcript, what is safe to change and what isn't, and which tests to run. Every sample is copied from the plugin and names its file.

## Contents

- [Before you start](#before-you-start)
- [Add a page](#add-a-page)
- [Add a setting](#add-a-setting)
- [Add a capability](#add-a-capability)
- [Add a table or a column](#add-a-table-or-a-column)
- [Add a mark](#add-a-mark)
- [Add a block to the PDF](#add-a-block-to-the-pdf)
- [Add a value to a transcript](#add-a-value-to-a-transcript)
- [Add a log action](#add-a-log-action)
- [Add a kind of draft](#add-a-kind-of-draft)
- [Work with Student Records](#work-with-student-records)
- [What is safe to change and what isn't](#what-is-safe-to-change-and-what-isnt)
- [Which tests to run](#which-tests-to-run)
- [Moodle versions](#moodle-versions)
- [Related pages](#related-pages)

## Before you start

- Open an issue first for anything that changes how grades, GPA, quality points or repeat rules are worked out. Transcripts are official records.
- The plugin supports Moodle 4.5 to 5.3 and PHP 8.1 to 8.4, so write PHP 8.1 syntax only.
- Follow `CONTRIBUTING.md`. `phpcs` and `phpdoc` must pass with no warnings.
- The plugin is under the MIT License and has no GPL header. Never run `phpcbf` on it: it writes the GPL header in.
- Every text a person reads goes in `local/academictranscript/lang/en/local_academictranscript.php`, sorted by key.
- Store names as plain text, and escape them once, where they print. `text::plain()` makes the plain text. Menus are the exception: `scale::teacher_marks()`, `terms::menu()` and `programs::menu()` return escaped labels, because a select shows a label as it is.
- Every class in `classes/local/` belongs on [the code map](README.md), and `bin/check-docs` checks that.

## Add a page

A page is a top-level PHP file. There is no renderer and no template, so a page builds its output with `html_writer` and `html_table`.

The top of an admin page, from `local/academictranscript/coursecheck.php`:

```php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_academictranscript\local\coursefields;

admin_externalpage_setup('local_academictranscript_coursecheck');
\local_academictranscript\local\access::require_setup();
$PAGE->set_url(new moodle_url('/local/academictranscript/coursecheck.php'));
```

The line that adds it to the admin tree, from the `$pages` array in `local/academictranscript/settings.php`:

```php
    'coursecheck' => ['coursecheck.php', 'coursecheck', 'local/academictranscript:viewissued'],
```

A course page starts as `local/academictranscript/post.php` does, with `require_login($course)` and `require_capability()` in the course context, and it gets its link in `local_academictranscript_extend_navigation_course()`.

The steps:

1. Pick a guard from [the guard of each page](access.md#the-guard-of-each-page). Check the capability on the page itself. Don't rely on a link being hidden.
2. Call `access::require_setup()` when the page needs a finished setup.
3. Check the sesskey on every action that changes data. Prefer `require_sesskey()`, which refuses with an error. `data_submitted() && confirm_sesskey()` ignores a bad post without a word.
4. Put the logic in a class under `classes/local/`, so a test can reach it. Keep rules that must hold on every path in the method every path goes through.
5. Test the page in `tests/pages_test.php` with its `run_page()` helper. A page that calls `die()` ends the whole PHPUnit run, so give `codes.php` and `post.php` a course with a student. A page that declares a function or class can be required once only.
6. Add the page to [the code map](README.md#every-page) and to [the guard table](access.md#the-guard-of-each-page). `bin/check-docs` fails when the docs name no page of the plugin.

The body class of a page of this plugin is `path-local-academictranscript`, and `local/academictranscript/styles.css` scopes most rules with it.

## Add a setting

See [how to add a setting](settings.md#how-to-add-a-setting).

## Add a capability

This is the shape, from `local/academictranscript/db/access.php`:

```php
    'local/academictranscript:viewissued' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => ['manager' => CAP_ALLOW],
    ],
```

1. Add the entry. Use `CONTEXT_COURSE` only when a teacher should hold it per course, and then check it in the course, as `access::can_issue()` does. Use `CONTEXT_SYSTEM` otherwise.
2. Add the string `academictranscript:<name>`.
3. Raise `$plugin->version`, because Moodle reads `db/access.php` only on an upgrade.
4. Check it on the page. A capability in the admin tree only guards that entry.
5. Add it to [the capabilities](access.md#the-capabilities), with a heading that says where it is checked.

Never rename a capability. Sites keep their role definitions under that name. Student Records names three of them: see [the contract](integration.md#the-contract-in-one-list).

## Add a table or a column

1. Edit `local/academictranscript/db/install.xml`, and change its `VERSION` attribute to the date.
2. Add a step to `local/academictranscript/db/upgrade.php` that ends with `upgrade_plugin_savepoint()`. Guard it, so it is safe on a site that has the change already.
3. Raise `$plugin->version` to the number of the step.
4. Add the column to `provider::get_metadata()`, or to `NOT_PERSONAL` in the test. See [privacy](privacy.md#when-you-add-a-table-or-a-column).
5. Add it to [the data model](data-model.md). `bin/check-docs` fails when a table has no heading or a column isn't under it.

This is the shape of a step, from `local/academictranscript/db/upgrade.php`:

```php
    if ($oldversion < 2026111606) {
        // Which registration in Student Records got a posted grade.
        $table = new xmldb_table('local_academictranscript_posted');
        $field = new xmldb_field('srregid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026111606, 'local', 'academictranscript');
    }
```

For a new table, the step at `2026111600` uses `install_one_table_from_xmldb_file()`.

Rules:

- `$plugin->version` must stay above the builds schools already ran before the first public release, or those sites can't upgrade. The number in `local/academictranscript/version.php` looks like a future date on purpose. Always go up from it, and never reset it to today's date.
- A table or column name must not be a reserved word. `schema_test::test_no_reserved_names()` checks the column names.
- Drop a column only when it holds nothing the others don't, as the step at `2026111607` shows.
- MySQL's unique indexes ignore case, so a test can't make `bio 101` beside `BIO 101` where `code` is unique.

## Add a mark

A school adds its own marks on `local/academictranscript/marks.php`, with no code. A mark is a row of `local_academictranscript_mark`, and the code names only `TR`, `IP` and, on the Student Records path, `W`.

To add a mark to the standard set, for new sites, change this line of `scale::standard_marks()` in `local/academictranscript/classes/local/scale.php`. The number says whether the mark earns the hours:

```php
        foreach (['P' => 1, 'NP' => 0, 'I' => 0, 'NC' => 0, 'W' => 0, 'TR' => 1, 'IP' => 0] as $code => $earns) {
```

1. Add the strings `mark_<code>` and `markdesc_<code>`, with the code in lower case.
2. An existing site gets it when an admin presses `addstandardmarks` in the wizard, which runs `scale::add_missing_standard_marks()`. That method leaves existing rows alone, even retired ones.
3. Update the tests that count the marks: `install_test::test_install_loads_standard_scale_and_marks()`, and `transcript_test::test_issue_freezes_snapshot_and_code()`, which counts the rows of the key.

A system mark is one the plugin sets and teachers can't give. There are two, `scale::TRANSFER` and `scale::INPROGRESS`, and a new one touches much more:

- `scale::standard_marks()` and `scale::save_marks()` set `systemmark` from those two constants.
- `srsource::teacher_marks()` leaves them out by name.
- `srsource::key_rows()` always prints `W`, `IP` and `TR`.
- `srsource::is_state()` treats `W` and `IP` as states.
- `transcript::build()` treats `scale::INPROGRESS` as no grade yet.

## Add a block to the PDF

Blocks that flow in the two columns go through `$blocks` in `builder::render()`. The Degree box is the smallest example, from `local/academictranscript/classes/local/pdf/builder.php`:

```php
        if (trim((string)$data['qualification']) !== '') {
            $html = self::degree_html($data);
            $blocks[] = ['html' => $html, 'h' => self::measure($html, $colw)];
        }
```

1. Write a protected method that returns the HTML. Follow `builder::degree_html()`.
2. Add it to `$blocks` at the point it should print. Blocks print in order.
3. Measure at the width you will draw at. `builder::measure()` takes the column width for a block, and `builder::$w` for the full width.
4. Escape every value with `s()`, and use only the three registered font families. `builder_test::test_html_styles_name_registered_font_families()` fails on any other.
5. Keep a block under 150 mm, or give it rows and let `builder::split_block()` cut it. A block never splits at a page.
6. Check Letter and A4, a transcript that runs to a second page, and both an Official and an Unofficial copy.

For a line in the signature block, edit `builder::signature_block()`. It places everything by offset inside `builder::SIGH`, so check that nothing overlaps. For a text section on the key, add the key to the list in `builder::key_page()`, the strings `<key>_default` and `<key>_title`, and the setting in `local/academictranscript/settings.php`.

## Add a value to a transcript

A value on the transcript must come from the data, or it isn't frozen.

1. Add the key in `transcript::build()`. For a value that comes from Student Records too, add it to `header` in `srsource::collect()`.
2. Print it in `html_view` and `builder`, escaped.
3. Read it with a default in `builder`, such as `$data['x'] ?? ''`. Old snapshots don't have it, and `builder::render()` draws those too.
4. Add it to [the keys of the snapshot](data-model.md#keys-of-the-snapshot).
5. Leave `transcript::DATA_VERSION` alone unless the layout changes in a way `transcript::stored()` must handle, and add a case there when it does.

`local/academictranscript/verify.php` decodes the snapshot itself, so a new row there needs the same care.

## Add a log action

1. Add the name to `logger::ACTIONS`.
2. Add the string `logaction_<name>`. `local/academictranscript/log.php` prints it, and `logger_test::test_every_action_has_a_language_string()` checks it.
3. Call `logger::log()`. Keep `info` free of secrets, because a privacy export includes it.
4. Add it to [the table of log actions](data-model.md#what-the-log-info-holds).

The column `action` holds 30 characters.

## Add a kind of draft

The wizard has four item types. A fifth touches `drafts::FIELDS`, `ai_extractor::TYPES`, the shapes in `ai_extractor::prompt()`, the map of JSON keys in `ai_extractor::parse_response()`, `ai_extractor::clean_item()`, the number fields that `local/academictranscript/drafts.php` cleans, `csv::TEMPLATES`, the branch in `drafts::approve()`, `$steptypes` in `local/academictranscript/setup.php`, and the strings `itemtype_<type>` and `field_<field>`.

## Work with Student Records

- Check before you call: put a new call behind `srsource::enabled()` or `srpost::active()`.
- Only call methods. This plugin reads a few tables of that plugin and writes none.
- Find the attempt of a posted row with `srpost::posted_target()`. `srpost::target()` answers where the next grade goes.
- Start a transaction inside `srpost::with_lock()`, never around it. The lock must outlast the commit.
- Don't send mail while a transaction or a lock is open.
- For a change to a method on [the contract list](integration.md#the-contract-in-one-list), run the checks with `--together`, and keep older callers working with a `method_exists()` check, as `srpost::with_lock()` does.

## What is safe to change and what isn't

| Change | Safe? | Why | What protects it |
|---|---|---|---|
| A language string's wording | Yes, with care | Sites can't lose data. A default such as `footer_default` only reaches a site that hasn't saved that setting | `moodle-cs` for the order of the keys |
| A new setting, page, class or test | Yes | Nothing depends on it | `bin/check-docs` for the docs |
| Styles in `local/academictranscript/styles.css` | Yes | Most rules are scoped to the plugin's pages | Nothing |
| PDF layout | Yes, with care | Old copies redraw in the new layout, because only the data is frozen | `tests/local/pdf/builder_test.php` |
| The name of a component, page, table, capability, setting, or course field short name | No | Sites keep data under those names, and Student Records names some of them | `CONTRIBUTING.md` |
| The address of `local/academictranscript/verify.php` | No | It is printed on every Official copy and is in every QR code | Nothing |
| `calculator::divide_rounded()` or the whole-number sums, for `round()` | No | PHP 8.4 gives other results, and a GPA would change between PHP versions | `calculator_test::test_half_cent_gpa_rounds_up()`, `calculator_test::test_half_cent_quality_points_round_up()` |
| The double `round()` in `calculator::letter()` | No | A float error at a half would give the wrong letter | `calculator_test::test_letter_rounds_course_totals_that_end_in_a_half()` |
| Averaging term GPAs for the overall GPA | No | The overall GPA comes from exact quality points | `calculator_test::test_total_gpa_with_fractional_hours_uses_the_exact_quality_points()` |
| The own-record checks in `transcript::issue()`, `posting::preview()`, `posting::decide_problem()` | No | They hold because every path goes through them | The tests in [the rules table](access.md#rules-that-arent-capabilities) |
| The hold check in `transcript::issue()` | No | The student is told a hold stops every way of issuing | `requests_test::test_a_hold_blocks_issue_and_reissue()` |
| A transaction around `requests::one_at_a_time()` or `srpost::with_lock()` | No | The lock would end before the commit | `requests_test::test_issue_holds_the_lock_past_the_commit()`, `srpost_test::test_the_lock_on_the_registration_outlasts_the_commit()` |
| `logger::commit()` moved inside the `try` block | No | A failed send after the commit would reach the `catch` and try to roll back a finished transaction | `srpost_test::test_a_posting_stands_when_its_message_fails()`, `logger_test::test_commit_logs_a_failure_after_the_data_is_saved()` |
| `srpost::target()` used for a posted row | No | A course can run twice, and it would find the wrong attempt | `srpost_test::test_each_posted_row_finds_its_own_attempt()` |
| Reading the snapshot layout from `version` | No | The numbers don't sort. See [versions of a snapshot](data-model.md#versions-of-a-snapshot) | `transcript_test::test_stored_brings_an_older_copy_up_to_date()` |
| Removing a key from the snapshot | No | Issued copies, the verify page and old layouts depend on them | `transcript_test::test_issue_freezes_snapshot_and_code()` |
| `optionindex` of a term | No | Courses store that number | `terms_test::test_save_appends_option_index()` |
| `$plugin->version` going down | No | Sites of older builds couldn't upgrade | Moodle refuses a downgrade |
| Files in `lib/pdfparser` | No | Bundled code stays as upstream ships it | `local/academictranscript/lib/pdfparser/readme_moodle.txt` |
| The font names | No | The license reserves the upstream name, and the families are named in `builder` | `tests/local/pdf/document_test.php` |
| A posted grade on a transcript that doesn't print | Never drop it quietly | A problem must stop the Official copy | `srsource_test::test_a_posted_grade_missing_from_student_records_is_a_problem()` |

## Which tests to run

`bin/check --plugin local/academictranscript` runs every step. For a quick loop, run one class. `tests/` follows the namespaces, so `tests/local/pdf/builder_test.php` tests `builder`. How to run them is on [the testing page](../testing.md).

| You changed | Run |
|---|---|
| `calculator`, `scale` | `tests/local/calculator_test.php`, `tests/local/scale_test.php`, `tests/local/transcript_test.php`, `tests/local/srsource_test.php` |
| `transcript` | `tests/local/transcript_test.php`, `tests/local/srsource_test.php`, `tests/local/pdf/builder_test.php` |
| `posting`, `srpost` | `tests/local/posting_test.php`, `tests/local/srpost_test.php`, and the post and change cases in `tests/pages_test.php` |
| `requests`, `request_view` | `tests/local/requests_test.php`, and the queue cases in `tests/pages_test.php` |
| `srsource` | `tests/local/srsource_test.php`, and with both plugins installed the tests of Student Records |
| `builder`, `document`, `signer` | The tests in `tests/local/pdf/` |
| The import classes | The tests in `tests/local/import/` |
| A page | `tests/pages_test.php` |
| `db/` | `tests/install_test.php`, `tests/uninstall_test.php`, `tests/upgrade_test.php`, `tests/schema_test.php` |
| `provider` or the schema | `tests/privacy/provider_test.php` |
| `coursefields`, `terms` | `tests/local/coursefields_test.php`, `tests/local/terms_test.php`, `tests/uninstall_test.php` |

Test support:

- `local_academictranscript_generator` in `tests/generator/lib.php` makes terms, programs, catalog rows, student rows, courses with their fields, transfer rows, posted grades, code rows and course totals.
- `tests/fixtures/recording_lock_factory.php` records when locks are taken and released, and can play "the other request". `tests/fixtures/refusing_lock_factory.php` gives no lock. Set `$CFG->lock_factory` to use either.
- Call `scale::reset_cache()` in `setUp()` of a test that reads the scale. The class caches the rows for the request, and that cache outlives the database reset of a test.
- A test that needs Student Records calls `markTestSkipped()` when the class isn't there.
- PHPUnit 9 on Moodle 4.5 ignores attributes, so a data provider needs `@dataProvider` in the docblock too.
- `tests/uninstall_test.php` has a test that needs a user who isn't root, so it shows as skipped under `bin/check`.

When you change how the two plugins work together, run `bin/check --plugin local/academictranscript --together`.

## Moodle versions

- `requires` is Moodle 4.5, and `supported` is `[405, 503]`.
- The custom field, grade and file calls here work the same on all of them.
- `ai_extractor::available()` goes through `\core\di` because `is_action_available()` changed from static to instance.
- `local/academictranscript/settings.php` looks for `localplugins` because Moodle 4.5 adds that node only for site administrators.
- Moodle 5.1 and later keep the web root in `public/`, and `admin/cli/` stays at the Moodle root. The plugin's own code doesn't depend on that, and `local/academictranscript/cli/set_course_fields.php` requires `config.php` by a relative path.
- `:root[data-bs-theme="dark"]` in `local/academictranscript/styles.css` follows the dark mode of Moodle 5.3. Earlier versions never set that attribute.

## Related pages

- [Code map](README.md)
- [Access](access.md)
- [Data model](data-model.md)
- [Settings](settings.md)
- [Testing](../testing.md)
- [Conventions](../conventions.md)
- [Compatibility](../compatibility.md)
- [Pitfalls](../pitfalls.md)
