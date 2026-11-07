# Extending Student Records

This page says how to add a page, a capability, a setting, a table, a message, a report, an export field, a menu entry, a fee kind, a task and a student status. It says what is safe to change, what isn't and why, and which tests to run.

Read [the conventions page](../conventions.md) first, for file headers, forms, language strings, escaping and database access. Those rules hold everywhere here.

## Contents

- [What is safe to change](#what-is-safe-to-change)
- [What isn't safe to change](#what-isnt-safe-to-change)
- [Add a page](#add-a-page)
- [Add a capability](#add-a-capability)
- [Add a setting](#add-a-setting)
- [Add a table](#add-a-table)
- [Add a message](#add-a-message)
- [Add a report](#add-a-report)
- [Add an export field](#add-an-export-field)
- [Add a menu entry](#add-a-menu-entry)
- [Add a fee kind](#add-a-fee-kind)
- [Add a task](#add-a-task)
- [Add a student status](#add-a-student-status)
- [Work that reads and then writes](#work-that-reads-and-then-writes)
- [Which tests to run](#which-tests-to-run)
- [Related pages](#related-pages)

## What is safe to change

| Change | Why it is safe | Take care with |
|---|---|---|
| The wording of a language string | Pages read it fresh | Keep the key and the `{$a}` fields. Text already stored stays as it was, such as the description of an account line. A school's own message text doesn't follow a new default |
| The default text of a message | `messages::text()` reads a school's own text first | The text says only that there is something new: no grades or amounts. `test_messages_hold_no_amounts_or_grades` holds this |
| Page layout and `local/studentrecords/styles.css` | Nothing reads them | The theme and Boost both show these pages, so check both |
| A new key on a row of `registration::history()` | The sibling reads keys by name | Add only. Don't rename or remove |
| A new report, export field or menu entry | Each stands alone | A new field may show demographics. See [add an export field](#add-an-export-field) |
| A new page that only reads | No lock or transaction needed | Check the capability, and log the view of a record |

## What isn't safe to change

| Don't change | Why | What protects it |
|---|---|---|
| The name of a component, page, table, column, capability, setting key or status value | Sites keep data under those names | `CONTRIBUTING.md`, and the upgrade path |
| `$plugin->version` downward | It stays above the builds schools ran before the first public release, or those sites can't upgrade. So it looks like a future date on purpose | `test_version_reaches_the_last_step` |
| The rounding helpers | A record must give the same figure on every PHP version. See [calculations](calculations.md#what-must-never-change) | `test_totals_are_exact`, `test_times` |
| The sign of an account line, or an existing line | Statements, reports and the 1098-T data add lines up. A mistake gets a reversing line | `test_reverse` |
| The lock order, or a lock that ends before its commit | Two requests would read the same seat, grade, balance or number. See [locks and transactions](flows-registration-and-grades.md#locks-and-transactions) | `test_a_drop_holds_the_lock_on_the_registration`, `test_course_access_is_given_after_the_lock_on_the_accounts` |
| Where `records::commit()` sits | A failed send after the data is saved must not reach the `catch` | `local/studentrecords/tests/local/records_test.php` |
| `messages::deliver()` as the one way out | Nothing may send mail in a transaction or a lock | `test_any_message_under_a_lock_waits_for_cron` |
| `registration::KEPTSOURCES` and the kept lists in `registration::write_grade()` | They decide whose grade stands. Staff correcting a grade until the freeze is a design | `test_kept_grades_stay_whoever_writes`, `test_staff_correct_a_grade_until_the_freeze` |
| `students::DECIDING`, and the other checks on one's own record | Nobody decides their own record | `test_nobody_changes_what_decides_a_degree_on_their_own_record`, and [the access page](access.md#nobody-acts-on-their-own-record) |
| The row shape of `registration::history()`, the states of `registration::teacher_post_target()`, and the rest of the shared contract | Academic Transcript reads them. See [what must stay stable](integration.md#what-must-stay-stable) | `local/academictranscript/tests/local/srsource_test.php`, `local/academictranscript/tests/local/srpost_test.php` |
| `recoverfrom` moving back | Old payments with no line would look lost, though Finance may have entered them by hand | `test_recover_leaves_payments_from_before_it_started` |
| `reports::download()` as the way to send rows | It makes every cell safe to open in a spreadsheet. `\core\dataformat::download_data()` alone doesn't | `test_safe_cell` |
| The values in the federal aid helpers, without checking the official rules | They are helpers a school checks. See [the federal aid page](flows-aid.md#what-to-take-care-with) | `local/studentrecords/tests/local/aidhelp_test.php` |

## Add a page

1. Make `local/studentrecords/<name>.php`, with the file docblock of the plugin: `@package`, `@copyright` and `@license`, and no GPL header.
2. Add it to `staffarea::PAGES`, as `name => [file, language string, capability]`.
3. Start it with `staffarea::setup()`, which does the login, the capability check, the layout, the title and the breadcrumbs.
4. Print `staffarea::header()`, which adds the menu on a theme with no drawer.
5. For an action, call `require_sesskey()`, and check the capability again if the page opens to more people than the action.
6. Add the strings. `staffpage_<name>` adds a line to `local/studentrecords/staff.php`.

```php
// local/studentrecords/saprules.php
require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\catalog;
use local_studentrecords\local\records;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\standing;

$edit = optional_param('edit', -1, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$p = 'local_studentrecords';
require_login(null, false);
staffarea::setup('saprules');
$url = new moodle_url('/local/studentrecords/saprules.php');
```

A page for students has no `staffarea::setup()`. `local/studentrecords/mydocuments.php` shows the shape: `require_login()`, `local/studentrecords:viewown`, `students::get_by_user()`, then `shell::inline_menu()` after the header.

A rule that matters goes in a class, not only on the page, so that every caller gets it. `billing::reverse()` checks `local/studentrecords:manageaid` itself for that reason.

Add a test that runs the page. `local/studentrecords/tests/local/registration_pages_test.php` and `local/studentrecords/tests/money_pages_test.php` show how: they set `$_GET` or `$_POST` and collect the output. Cover who gets in, a wrong sesskey, and names escaped once.

`bin/check-docs` fails until the docs name the new page.

## Add a capability

1. Add it to `local/studentrecords/db/access.php`, with `riskbitmask`, `captype`, `contextlevel` and `archetypes`. Moodle reads the new capability when the version goes up.
2. Raise `$plugin->version`. A capability needs no upgrade step, but `test_version_reaches_the_last_step` holds the version equal to the highest savepoint, so add an empty step and update that test's number.
3. Add the string `studentrecords:<name>`, in A-Z order. `test_capability_names` and `test_order` in `local/studentrecords/tests/lang_test.php` hold this. The name must be lower-case letters, because that test's pattern reads `[a-z]+`.
4. Use it: in `staffarea::PAGES`, and with `has_capability()` in the system context, or the course context for a teacher's capability.
5. If it makes a person staff, add it to `home::STAFFCAPS`, so they are never sent straight to a course.
6. If it belongs to a role the plugin makes, add it to `billing::FINANCECAPS` or `aid::ROLECAPS`. A role that exists doesn't pick it up, so a school adds it by hand.
7. Add it to [the access page](access.md).

## Add a setting

Settings aren't in `local/studentrecords/settings.php`. [The settings page](settings.md#adding-a-setting) has the steps, the read patterns and every key. In short:

- A school setting goes in `catalog::SCHOOL`, as `name => [type, step]`, with the string `set_<name>`. `local/studentrecords/classes/form/school.php` builds and checks the field, and `local/studentrecords/setup.php` saves it with `records::set_setting()`.
- A billing setting goes in `local/studentrecords/classes/form/billingsettings.php` and `local/studentrecords/billingsetup.php`.
- Read it with `catalog::setting()`, and give the default at the read. Nothing writes defaults at install.

## Add a table

1. Add it to `local/studentrecords/db/install.xml`, and change the `VERSION` attribute. The name starts with `local_studentrecords_` and has at most 28 characters. No column may be a reserved word: `from`, `to` and `order` are on the list in `local/studentrecords/tests/schema_test.php`.
2. Add a step to `local/studentrecords/db/upgrade.php`, ending with `upgrade_plugin_savepoint()`. Raise `$plugin->version` to match, and the number in `test_version_reaches_the_last_step`.
3. Declare it for privacy, in `provider`: `provider::get_metadata()`, and the export and deletion if it holds data about a person. If it only holds school settings, add it to `provider::SETTINGS_TABLES`. `test_metadata_declares_every_user_column` and `test_metadata_declares_every_table_about_a_person` fail otherwise. See [the privacy page](privacy.md).
4. Write through `records::save()`, so the rows keep a change history. Use `audit::log()` for the action. A new object type needs the string `obj_<type>`, or `local/studentrecords/log.php` shows a missing string. `test_log_page_names_every_kind_of_record` finds it. A rule on who may write goes in a class, because `local_studentrecords_xfer`, with no class, can only guard one's own record on the page.
5. Add a helper to `local/studentrecords/tests/generator/lib.php` if tests will need rows.
6. Add a heading to [the data model page](data-model.md) that names the table, with every column in backticks. `bin/check-docs` looks for them.

```php
// local/studentrecords/db/upgrade.php, the end of the last step
if (!get_config('local_studentrecords', 'recoverfrom')) {
    set_config('recoverfrom', time(), 'local_studentrecords');
}
upgrade_plugin_savepoint(true, 2026111607, 'local', 'studentrecords');
```

Moodle drops the tables on uninstall by itself. A table is added once. Don't rename it later.

## Add a message

1. Add the key to `messages::STUDENT`, with its provider (`registration`, `billing` or `records`), or to `messages::STAFF`, with the capability of those who get it. A staff message goes through the provider `staffalerts`, so Moodle sends it only to those who also hold `local/studentrecords:viewbilling`. The four providers are in `local/studentrecords/db/messages.php`.
2. Add the strings `msgdef_<key>_subject`, `msgdef_<key>_body`, `msgname_<key>` and `msgwhen_<key>`. `local/studentrecords/messages.php` lists the key by itself.
3. Call `messages::student()` or `messages::staff()`. Never call `message_send()`.

```php
// local/studentrecords/classes/local/registration.php, add_seat()
messages::student('regadded', $student, ['course' => $offer->code, 'term' => $offer->termname]);
```

- A message is on until a school turns it off: `messages::is_on()` is true for anything but `0`.
- Keep grades and amounts out of the text. The message says there is something new and asks to log in.
- `messages::fill()` replaces only `messages::PLACEHOLDERS`. Anything else stays as typed.
- `messages::student()` returning false doesn't mean it failed: a queued message returns false. Use `messages::last_status()`.
- The body of a queued message is fixed when it is queued, and travels in the task's data. The switch, test mode and the person's account are read again when it goes.

[Tasks and messages](tasks-and-messages.md) has the whole path.

## Add a report

Pick the page by who reads it: `local/studentrecords/reports.php` for records staff, `local/studentrecords/billingreports.php` for Finance, `local/studentrecords/aid.php` for Financial Aid, `local/studentrecords/outsidereports.php` for a state or an accreditor.

1. Put the query in a class, with plain text in the rows: `reports::enrollment()` is the shape. Use `reports::plain()` for stored names, because `format_string()` alone gives HTML.
2. Add the key to the page's list of reports, and build `$head` and `$rows`.
3. Add the strings: on `reports.php`, `report_<name>` and `report_<name>_intro`.
4. The page escapes the cells once, with `s()`.
5. Send a download through `reports::download()`, after `audit::log()` with `export`.

A report with sex, ethnicity or race needs `local/studentrecords:viewdemographics`. A report of a person's name must honour `access::hide_directory()` for people who don't manage students.

Add a test. `test_reports_open` in `local/studentrecords/tests/money_pages_test.php` and `test_report_escapes_names_once` show two.

## Add an export field

Saved exports let a school pick fields for a state agency or accreditor.

1. Add the field to `outside::FIELDS`.
2. Add the string `xf_<field>`.
3. Give it a value in `outside::row()`, unless it is a column of the student table, which the `default` case reads.
4. If it shows sex, ethnicity or race, add it to `outside::DEMOGRAPHICS`, so `outside::has_demographics()` asks for the capability.

Adding it to a starter in `outside::STARTERS` changes only what `outside::add_starters()` makes from then on. `test_run_a_saved_export` and `test_has_demographics` hold the fields.

## Add a menu entry

`shell::groups()` builds the menu by role, in this order: student, records staff or approver, finance, financial aid, teacher, setup. Each item is `key => [moodle_url, label]`.

1. Add the item in its group, behind the capability it needs. For a staff page, `staffarea::can_open()` is the check, and the loop over names in the records and setup groups only needs the name and a label.
2. Add the `nav_<key>` string.
3. `shell::best_match()` marks the item for the page: the same path, with all of the item's own parameters equal to the page's. The item with the most matching parameters wins.
4. A new group needs a key in `homes::GROUPVIEW`, if it has a home view.

The menu is cached for the request. Tests call `shell::reset()`. `test_best_match` and `local/studentrecords/tests/local/staffarea_test.php` hold this.

The theme's drawer and `shell::inline_menu()` show the same menu, so nothing else needs a change.

## Add a fee kind

Look first at which list you mean.

| To add | Lists | Strings | Also decide |
|---|---|---|---|
| A way of charging | `billing::BASES`, `billing::AUTO` when it charges by itself, `billing::HOURLY` when it is per hour | `feebasis_<basis>`, `chargedesc_<basis>` | In `billing::registration_charges()`, `$perreg` and `$where` say what counts as already charged. `billing::estimate()` has the same logic |
| A kind of price list item | `billing::KINDS` | `feekind_<kind>` | `billing::pick_tuition()` looks only for `tuition` and `billing::auto_fees()` only for `fee`, so a third kind is never charged by itself until they learn it. `refunds::percent()` gives anything but `tuition` the fee percent |
| A kind of credit | `billing::CREDITKINDS` | `creditkind_<kind>` | Whether it is money: `moneyreports::SCHOLARSHIPS`, `moneyreports::PAIDCREDITS`, and the list in `aid::ninety_ten_worksheet()` |
| A way to pay | `payments::METHODS` | `paymethod_<method>` | `moneyreports::payments()` groups by it |
| A way to pay back | `refunds::PAYOUTS` | `payout_<method>` | |

`local/studentrecords/classes/form/fee.php` builds its menus from the lists, and refuses a tuition item with the basis `manual` or `late`.

A new line type isn't on this list. `billing::TYPES` is read by the balance, plans, refunds, every report and the 1098-T data, so one needs a design first.

Add to `test_line_amount`, `test_auto_fees` and `test_pick_tuition` in `local/studentrecords/tests/local/billing_test.php`.

## Add a task

1. Make `local/studentrecords/classes/task/<name>.php`, extending `\core\task\scheduled_task`. Keep it short: `get_name()` and an `execute()` that calls a method of a `local` class.
2. Add it to `local/studentrecords/db/tasks.php`.
3. Add the string `task_<name>`.
4. Raise `$plugin->version`, so Moodle registers it. As for [a capability](#add-a-capability), add an empty upgrade step and update the number in `test_version_reaches_the_last_step`.
5. Add a test in `local/studentrecords/tests/task/`.

```php
// local/studentrecords/classes/task/waitlist_daily.php
public function execute() {
    mtrace('Student Records waitlists: ' . \local_studentrecords\local\waitlist::daily() . ' lapsed offer(s)');
}
```

A task must be safe to run twice, and safe to stop halfway:

- Save each step and its mark in one transaction, as `plans::daily()` does, so a step isn't done twice.
- Make a second run find nothing to do. `test_daily_twice_does_each_thing_once` and `test_running_twice_offers_the_seat_once` hold that for the two daily tasks.
- Say what happens on an error. `waitlist::daily()` goes on and throws the first error at the end. `aidhelp::send_ready()` and `plans::daily()` stop where they are.
- `$USER` is the user cron runs as, so don't read it as a person who acted. A warning through `\core\notification` goes to the task's session, where nobody sees it.

For one job that must run outside a request, use an ad-hoc task. `send_message` is the example: it is queued in the same transaction as the work, so a rollback drops it.

## Add a student status

`student.status` is stored text, up to 20 characters, and many places read it by name. Add the value, then go through this list.

| Place | Decide |
|---|---|
| `catalog::STATUSES` | Add it. The record form and the filter on `local/studentrecords/students.php` read this list |
| `status_<status>` | The string. `get_string()` is built from the value in many places |
| `admissions::PIPELINE` | Is it before enrolment? `admissions::record_decision()` moves only those, and `reports::graduation()` leaves them out |
| `local/studentrecords/admissions.php` | The applicants query and the pipeline report name statuses in the code |
| `selfreg::STATUSES` | May this person register themselves? |
| `outside::nsc_status()`, `outside::population()` | How outside reports count it |
| `reports::graduation()` | Which group it joins |
| `student_file::header()`, `record_view::program_card()` | The colour of its tag |
| `admissions::status_problems()` | Whether it needs dates |
| `aidhelp::run()`, `aid::withdrawals()` | Whether aid stops |
| `requests::waiting()` in Academic Transcript | Reads `graduated` only |

The comments in `local/studentrecords/db/install.xml` lag behind for this column, so don't take that list as complete. `srsource::collect()` checks that the string exists, so the transcript copes with a new value.

## Work that reads and then writes

Most new features come to this. Pick the lock by what you read, and keep to the shape.

| You read | Take | Example |
|---|---|---|
| Seats of a section | `registration::with_lock()` on `offer<id>` | `registration::add()` |
| The grade or status of a registration | `reg<id>` | `registration::set_grade()` |
| A balance, what is left of a charge or award, a receipt number | `billing::one_at_a_time()` | `refunds::payout()` |

The order of a request is: the lock, then the checks, then the transaction, then the commit after the `try`, then the unlock, and only then anything slow. Take section before registration before accounts, and take nothing under `accounts`. Inside `billing::one_at_a_time()`, leave work on other locks to `payments::settle()`, as `payments::release()` does through `billing::after_lock()`.

Use `billing::add_line()` for a line, unless you run `payments::settle()` yourself. Use `recording_lock_factory` to test the order: `test_course_access_is_given_after_the_lock_on_the_accounts` is the example.

## Which tests to run

[The testing page](../testing.md) says how to run them. For Student Records, these cover each area.

| You changed | Run |
|---|---|
| Seats, windows, enrolment, grades | `local/studentrecords/tests/local/registration_test.php`, `local/studentrecords/tests/local/registration_pages_test.php` |
| The cart, waitlists | `local/studentrecords/tests/local/selfreg_test.php`, `local/studentrecords/tests/local/waitlist_test.php`, `local/studentrecords/tests/task/waitlist_daily_test.php` |
| A charge, a line, a reversal | `local/studentrecords/tests/local/billing_test.php`, `local/studentrecords/tests/money_pages_test.php` |
| Payments, recovery, course access | `local/studentrecords/tests/local/payments_test.php`, `local/studentrecords/tests/task/recover_payments_test.php` |
| Plans | `local/studentrecords/tests/local/plans_test.php` |
| Refunds | `local/studentrecords/tests/local/refunds_test.php` |
| Aid | `local/studentrecords/tests/local/aid_test.php`, `local/studentrecords/tests/local/aidhelp_test.php` |
| The audit, graduation, standing | `local/studentrecords/tests/local/degreeaudit_test.php`, `local/studentrecords/tests/local/graduation_test.php`, `local/studentrecords/tests/local/standing_test.php` |
| Messages | `local/studentrecords/tests/local/messages_test.php`, `local/studentrecords/tests/local/records_test.php` |
| Access or a page | The page test of that area, and `local/studentrecords/tests/local/access_test.php` |
| A table or a column | `local/studentrecords/tests/schema_test.php`, `local/studentrecords/tests/upgrade_test.php`, `local/studentrecords/tests/privacy/provider_test.php` |
| A language string or a capability | `local/studentrecords/tests/lang_test.php` |
| Import | `local/studentrecords/tests/local/importer_test.php`, `local/studentrecords/tests/local/sheet_reader_test.php`, `local/studentrecords/tests/local/drafts_test.php` |
| Anything in [the shared contract](integration.md#the-shared-contract) | All of the above, and `local/academictranscript/tests/local/srsource_test.php` and `local/academictranscript/tests/local/srpost_test.php`, with `bin/check --together` |

A test that writes under a real lock or needs the real commit calls `preventResetByRollback()`. A test of the sibling's side calls `markTestSkipped()` when the plugin isn't installed.

## Related pages

- [Conventions](../conventions.md)
- [Testing](../testing.md)
- [Data model](data-model.md)
- [Access](access.md)
- [Settings](settings.md)
- [Tasks and messages](tasks-and-messages.md)
- [Calculations](calculations.md)
- [Integration](integration.md)
- [Privacy](privacy.md)
