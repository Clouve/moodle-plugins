# Conventions

This page lists the coding rules of the repo: those in `CONTRIBUTING.md`, and those the code follows without saying so. The examples are copied from the repo. New code should read like the code around it.

## Contents

- [The rules in short](#the-rules-in-short)
- [File headers](#file-headers)
- [File layout](#file-layout)
- [How a page is built](#how-a-page-is-built)
- [Classes](#classes)
- [Forms](#forms)
- [Language strings](#language-strings)
- [Escaping and plain text](#escaping-and-plain-text)
- [Database access](#database-access)
- [Access checks](#access-checks)
- [Errors](#errors)
- [Transactions, locks and messages](#transactions-locks-and-messages)
- [Dates and money](#dates-and-money)
- [Naming](#naming)
- [HTML and styles](#html-and-styles)
- [Writing](#writing)
- [Related pages](#related-pages)

## The rules in short

From `CONTRIBUTING.md`:

- Write PHP 8.1 syntax only. Moodle 4.5 runs on PHP 8.1.
- Follow Moodle's coding style. `phpcs` (moodle-cs) and `phpdoc` must pass with no warnings.
- Put every text a person reads in a language string, including date formats. Keep the strings in `lang/en/` sorted by key.
- Check the user's capability on every page, and the sesskey on every action that changes data. Use placeholders in every SQL query.
- Store the names of courses, programs and marks as plain text, and escape them once, where they are shown.
- Don't rename a component, a page, a table, a capability, a setting or a course field short name. Sites keep data under those names, and the address of the verify page is printed on every Official transcript.
- Keep bundled third-party code as upstream ships it, declare it in `thirdpartylibs.xml`, and describe it in a `readme_moodle.txt` next to it. The transcript fonts are the one exception: their license reserves the upstream name, so they are renamed.
- A plugin stays optional for the others. Check that a sibling is installed before you call it, and keep the plugin working without it.
- Commit a transaction after its `try` block, with `records::commit()` in Student Records and `logger::commit()` in Academic Transcript.

## File headers

A page or script of a local plugin starts with one docblock. Plugins under the MIT License have no Moodle GPL header:

```php
<?php

/**
 * Terms: dates and deadlines.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');
```

That is `local/studentrecords/terms.php`. A class file puts the same tags on the class docblock, after the `namespace` line:

```php
<?php

namespace local_studentrecords\local;

/**
 * Who may open which record.
 *
 * Staff with viewstudents or managestudents at system level see every student. A teacher sees a student only
 * when the teacher holds viewstudents in a course the student is actively enrolled in. Students may see their own
 * record.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class access {
```

That is `local/studentrecords/classes/local/access.php`.

`bin/check` and CI leave out `moodle.Files.BoilerplateComment` for this reason. Don't run `phpcbf` on the local plugins: it writes the GPL header in.

The theme is different. Each of its PHP files has the Moodle GPL header, and `@license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later`. The files adapted from Boost keep Boost's `@copyright` line first. See [Why the theme stays GPL](theme/extending.md#why-the-theme-stays-gpl).

A contributor adds their own `@copyright` line under Clouve's in a file they write or change substantially, and leaves the `@license` line alone.

`defined('MOODLE_INTERNAL') || die();` appears in `version.php`, `settings.php`, and in a class file that needs a `require_once` first, such as a form. A page doesn't need it, because it requires `config.php`. A class file with nothing but a class has neither.

## File layout

| What | Where | Named |
|---|---|---|
| A page | The plugin's top folder | A lower-case word: `terms.php`, `gradechange.php` |
| A class with rules | `classes/local/` | After its subject, with no suffix: `billing`, `registration`, `scale` |
| A form | `classes/form/` | After what it edits: `term`, `gradreview` |
| A scheduled or ad-hoc task | `classes/task/` | After what it does: `billing_daily` |
| A test | `tests/`, mirroring `classes/` | `<class>_test.php` |
| A test helper | `tests/fixtures/`, `tests/generator/` | |

A class is autoloaded from its namespace, so `\local_studentrecords\local\billing` is `local/studentrecords/classes/local/billing.php`.

## How a page is built

A page has this order: require `config.php`, read parameters, check access, set up `$PAGE`, handle actions, print. A page never prints before it has checked access, because a form submit ends in a redirect.

### A staff page of Student Records

`staffarea::setup()` does the login, the capability, the layout, the title and the breadcrumbs, from the list `staffarea::PAGES`. From `local/studentrecords/terms.php`:

```php
$edit = optional_param('edit', -1, PARAM_INT);
require_login(null, false);
\local_studentrecords\local\staffarea::setup('terms');
require_capability('local/studentrecords:configure', context_system::instance());
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/terms.php');
$PAGE->set_url($url);

if ($edit >= 0) {
    $form = new \local_studentrecords\form\term(new moodle_url($url, ['edit' => $edit]));
    if ($edit) {
        $form->set_data($DB->get_record('local_studentrecords_term', ['id' => $edit], '*', MUST_EXIST));
    }
    if ($form->is_cancelled()) {
        redirect($url);
    }
```

After saving it logs the action and redirects with a notice:

```php
$id = records::save('local_studentrecords_term', $data);
\local_studentrecords\local\audit::log($edit ? 'update' : 'create', 'term', $id);
redirect($url, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
```

Then `echo staffarea::header();` prints the header and the inline menu, the page prints with `html_writer`, and `$OUTPUT->footer()` ends it. A new staff page needs a line in `staffarea::PAGES`.

### An admin page of Academic Transcript

These hang in the admin tree. From `local/academictranscript/log.php`:

```php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_academictranscript_log');
\local_academictranscript\local\access::require_setup();
$studentid = optional_param('studentid', 0, PARAM_INT);
```

The capability comes from the `admin_externalpage` in `local/academictranscript/settings.php`.

### An action

An action that changes data is a POST or a link with a sesskey, and the page checks it first. From `local/academictranscript/requests.php`:

```php
$do = optional_param('do', '', PARAM_ALPHA);
if ($do !== '') {
    require_sesskey();
    $rid = required_param('request', PARAM_INT);
    try {
```

The action redirects back to the page, so a reload doesn't repeat it.

## Classes

Classes in `classes/local/` hold static methods, with three exceptions: `calculator`, which is built from a scale, `pdf\document`, which extends Moodle's `\pdf`, and Student Records' `importer`, which builds up the items of one file. Some static classes keep state for the request in static properties, such as a cache (`scale::$grades`, `shell::$groups`), the locks held (`registration::$locks`) and the work waiting for a lock to end (`billing::$afterlock`). Tests must reset what they depend on.

- Table names are class constants when a class owns a table: `billing::TXN`, `registration::REG`, `messages::LOG`, `requests::TABLE`.
- Fixed lists are class constants: `billing::TYPES`, `registration::STATUSES`, `catalog::SCHOOL`.
- Rules that need no database go at the top of the class under `// Rules that need no database.`, and take plain values, so a `\basic_testcase` can test them. `registration::window_problem()` takes Y-m-d strings and not term records for that reason.
- A request cache is a protected static, with a reset method for tests: `scale::reset_cache()`, `shell::reset()`, `home::reset_cache()`.
- A public method takes the lock, and a protected one does the work. `registration::add()` calls `add_seat()`, and `requests::issue()` calls `issue_request()`. The protected method's docblock says "The caller holds the lock".

Docblocks say why, in plain words, and give each `@param` and `@return`. Comments in the code say why, not what.

## Forms

A form is a `\moodleform` in `classes/form/`. From `local/studentrecords/classes/form/term.php`:

```php
namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * A term: dates and deadlines.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class term extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('text', 'name', get_string('termname', $p), ['size' => 40]);
        $m->setType('name', PARAM_TEXT);
        $m->addRule('name', null, 'required', null, 'client');
```

- Student Records writes `$m` and `$p`. Academic Transcript writes `$mform`.
- Every text and hidden element has `setType()`.
- `validation()` starts with `parent::validation()` and returns field name to message. A rule that must also hold outside the form belongs in the class, and the form calls it.
- A value the form needs from the page comes in `$this->_customdata`.
- A form that a page shows many times, or a small action, may be built by hand in the page with `html_writer` and hidden `sesskey` fields, as `local/academictranscript/requests.php` does.
- A form with a field named `action` hides `form.action` from JavaScript. Use another name.

## Language strings

Strings are in `lang/en/`, in English only, sorted by key. `local/studentrecords/tests/lang_test.php` fails on a string out of order, on a double, and on a capability with no `studentrecords:<cap>` string.

Keys show their family by prefix:

| Prefix | Use | Plugin |
|---|---|---|
| `err_` | An error from a class or a form | Both |
| `set_` | A school setting | Student Records |
| `nav_` | A menu item | Student Records |
| `card_`, `srcard_` | The cards | Student Records |
| `msgdef_<key>_subject`, `msgdef_<key>_body`, `msgname_<key>`, `msgwhen_<key>` | An automatic message | Student Records |
| `msgstatus_<status>` | A status in the message log | Student Records |
| `status_<status>`, `holdtype_<type>` | A stored value, shown to a person | Student Records, and Academic Transcript reads them |
| `obj_<type>`, `action_<action>` | The audit log. `local/studentrecords/log.php` needs a string for each object type | Student Records |
| `logaction_<action>` | The activity log. `test_every_action_has_a_language_string` holds it | Academic Transcript |
| `problem_` | Why an Official transcript can't be issued | Academic Transcript |
| `req_`, `queue_` | Requests and the request queue | Academic Transcript |
| `settings_`, `key_`, `col_`, `step_` | Settings, the transcript key, PDF columns, wizard steps | Academic Transcript |

A stored value is never shown as it is: it goes through a string, built from the prefix, such as `get_string('status_' . $student->status, 'local_studentrecords')`. So a new status needs a new string.

The placeholders of a message are `{school}`, `{firstname}` and the others in `messages::PLACEHOLDERS`, not `{$a}`, because `messages::fill()` replaces them, and a school can edit the text. No message holds a grade or an amount, and `test_messages_hold_no_amounts_or_grades` holds that.

A string can run over several lines: `ai_prompt` in `local/academictranscript/lang/en/local_academictranscript.php` does. A tool that reads the file by line silently drops the rest. Split on `^\$string[`.

## Escaping and plain text

Names are stored as typed and escaped once, where they are shown. Two helpers make plain text:

```php
public static function plain(string $text, \context $context): string {
    $formatted = format_string($text, true, ['context' => $context, 'escape' => false]);
    return html_entity_decode(strip_tags($formatted), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
```

That is `text::plain()` in `local/academictranscript/classes/local/text.php`. `format_string()` with `escape` false alone isn't enough, because with the site setting `formatstringstriptags` off Moodle ignores that option. In Student Records, `reports::plain()` is for downloads and for code that escapes for itself.

- `s()` escapes plain text for HTML. `format_string()` escapes and runs filters, for a name a person typed.
- A method that returns HTML says so. `catalog::programs()` says "escaped for HTML: print it as it is", and `faculty::qualified_courses()` does the same. Don't run `s()` on those.
- Menu labels from `scale::teacher_marks()` and `srsource::teacher_marks()` are escaped already, because a select shows a label as it is.
- Transcript data is plain text, and the renderer of each format escapes it. `test_build_keeps_names_as_plain_text` holds that.
- A download goes through `reports::download()`, which makes the cells safe. `\core\dataformat::download_data()` called directly doesn't.

The page tests check that a name with an ampersand shows escaped once. Add such a name to a page test of new output.

## Database access

Use `$DB` with placeholders. Table names go in braces. A list goes through `get_in_or_equal()`. From `registration::taken()`:

```php
[$in, $params] = $DB->get_in_or_equal(self::ACTIVE);
return $DB->count_records_select(self::REG, "offerid = ? AND status $in", array_merge([$offerid], $params));
```

- Writes to the record tables of Student Records go through `records::save()`, so they keep a history. A change that matters to the record shouldn't write directly.
- `records::save()` drops keys that aren't columns, silently, so a typo in a field name is no error.
- Nothing is deleted from the record: status columns and `active` flags retire a row. `scale::replace_grades()` sets old rows to `active` 0.
- A table name of Student Records is at most 28 characters and starts with `local_studentrecords_`. Column names avoid reserved words. `local/studentrecords/tests/schema_test.php` holds both, and `local/academictranscript/tests/schema_test.php` the second. A reserved word installs fine and then breaks the first insert.
- New user data needs a line in the privacy provider. `test_metadata_declares_every_user_column` fails without it.

## Access checks

Check at the top of the page, and again in the class when a rule must hold on every path.

- `require_login()`, then `require_capability()` or a helper of `access`.
- A rule that isn't a capability belongs in the class, not only in the page: `transcript::issue()` refuses a person's own transcript whichever page calls it, as its comment says. The page may hide the button as well.
- A check on whether a person may act on a record uses `access::is_own()` in Student Records. Keep it in the class that writes.

## Errors

- A failure a person can meet is a `\moodle_exception` with a string of the plugin: `throw new \moodle_exception('err_planexists', 'local_studentrecords');`.
- A programmer's mistake is a `\coding_exception`.
- Refusing access is a `\required_capability_exception`.
- A failure in a loop over students must not stop the others. `local/academictranscript/post.php` goes on after a failed posting, so `posting::save_posted()` must not leave its transaction open.
- `debugging()` at `DEBUG_DEVELOPER` is for what a site can live with: a card that didn't load, a certificate that couldn't be read.

## Transactions, locks and messages

The rules are in [Pitfalls](pitfalls.md), with the reasons. The pattern of a write is:

```php
$transaction = $DB->start_delegated_transaction();
try {
    $result = $work();
} catch (\Throwable $e) {
    $transaction->rollback($e);
}
records::commit($transaction);
return $result;
```

That is `billing::transaction()`. `registration::add_seat()` has the same shape, and passes the student to `records::commit()` for the log. In Academic Transcript the commit is `logger::commit($transaction, $userid);`.

A lock goes outside the transaction, and read-then-write work on an account goes through `billing::one_at_a_time()`. Nothing slow runs under a lock.

## Dates and money

- Term dates are days in the school's time zone. `catalog::date_to_time()` gives noon of a Y-m-d date, `catalog::day_start()` the start of the day, and `catalog::time_to_date()` the Y-m-d of a timestamp. Compare days as Y-m-d text.
- Money is compared in whole cents through `billing::cents()`, or with a tolerance of `0.004` on a rounded balance. Multiply through `billing::times()`.
- Grades and hours are added as whole numbers: hours in hundredths, quality points in ten-thousandths.
- Never call `round()` on a float product for an official figure. See [Exact rounding](pitfalls.md#exact-rounding-on-every-php-version).
- Date formats are strings: `strftimetermdate` in Academic Transcript, and Moodle's `strftimedate`.

## Naming

- Tables: `local_<plugin>_<word>`, with a short word: `local_studentrecords_stuplan`, `local_studentrecords_grchg`.
- Columns: lower case with no underscores, `<name>id` for a link to another table. `timecreated`, `timemodified` and `usermodified` are the bookkeeping columns, and `records::save()` fills them.
- Methods: lower case with underscores. Variables: lower case with no underscores, as moodle-cs asks.
- Stored values are lower-case words: `registered`, `completed`, `pending`.
- A short local for the component: `$p` in Student Records, `$at` or `$p` in Academic Transcript.
- Tests are sentences: `test_a_hold_cannot_be_overridden`, `test_staff_correct_a_grade_until_the_freeze`.

## HTML and styles

- The local plugins have no Mustache templates. HTML comes from `html_writer` in classes such as `html_view` and `shell`, or in the page. The theme has templates.
- `shell` holds the shared page parts of Student Records: `shell::tile()`, `shell::tag()`, `shell::card()`, `shell::attention()`, `shell::quick_links()`, `shell::empty_state()`.
- Classes are `sr-` in both plugins, and `at-` for what only Academic Transcript has. `local/academictranscript/styles.css` repeats the `sr-` rules it needs, so its pages keep their look without Student Records.
- Colours are custom properties named `--srp-` on `:root`, with dark values under `:root[data-bs-theme="dark"]`. The theme's are `--sr-`.
- Status colours (green, amber, red) are used only for status.
- There's no `amd/` folder. A small script goes through `$PAGE->requires->js_amd_inline()`, as `local/academictranscript/index.php` does with `request_view::script()`.

## Writing

Docs and language strings follow one voice, from `CONTRIBUTING.md`: plain words, short sentences, sentence case. Leave out em and en dashes, curly quotes and exclamation marks. `bin/check-docs` checks the docs.

## Related pages

- [Testing](testing.md)
- [Pitfalls](pitfalls.md)
- [Architecture](architecture.md)
- [Compatibility](compatibility.md)
- [AI agents](ai-agents.md)
