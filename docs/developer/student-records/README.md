# Student Records code map

What's where in `local/studentrecords` (`local_studentrecords` 1.4.6), and the fastest way into it. Use this page to find the file, and the pages it links to for how each part works.

## Contents

- [Pages in this folder](#pages-in-this-folder)
- [The fastest way in](#the-fastest-way-in)
- [Folder layout](#folder-layout)
- [Top-level pages](#top-level-pages)
- [Classes in classes/local](#classes-in-classeslocal)
- [Records and rules](#records-and-rules)
- [Students and staff](#students-and-staff)
- [Registration and the academic record](#registration-and-the-academic-record)
- [Money](#money)
- [Federal aid](#federal-aid)
- [Messages](#messages)
- [Reports and import](#reports-and-import)
- [Pages and menus](#pages-and-menus)
- [Other classes](#other-classes)
- [Form classes](#form-classes)
- [Language strings](#language-strings)
- [Tests](#tests)
- [Related pages](#related-pages)

## Pages in this folder

| Page | What it covers |
|---|---|
| [Code map](README.md) | This page |
| [Data model](data-model.md) | All 52 tables and every column |
| [Access](access.md) | Capabilities, menu groups, roles, and the rules that aren't capabilities |
| [Settings](settings.md) | Every setting and config key |
| [Tasks and messages](tasks-and-messages.md) | Scheduled and ad-hoc tasks, message providers, the 22 messages |
| [Flows: admissions and records](flows-admissions-and-records.md) | From inquiry to student: decisions, the checklist, status, student numbers, the file, holds, FERPA, letters, faculty records, import |
| [Flows: registration and grades](flows-registration-and-grades.md) | From seats to final grades, then attendance, the degree audit, graduation and standing, with each lock |
| [Flows: money](flows-money.md) | The price list, charges, payments, receipts, reversals, plans, late fees, refunds, payouts, recovery |
| [Flows: aid](flows-aid.md) | Awards, disbursements, aid lines, the Return of aid worksheet, the loan checks, the 90/10 worksheet |
| [Calculations](calculations.md) | How each figure is worked out, and which test protects it |
| [Integration](integration.md) | Academic Transcript and the theme, with and without each, and the Moodle APIs used |
| [Privacy](privacy.md) | What the provider exports, deletes and keeps |
| [Extending](extending.md) | How to add a page, capability, setting, table, message, report, task or status, and what is safe to change |

## The fastest way in

Every page is a script at the plugin root. It logs in, checks access, builds a form or a list, and calls static methods in `classes/local/`. The rules live in those classes, and the page holds access checks and form wiring. This is how a staff page starts, from `local/studentrecords/terms.php`:

```php
$edit = optional_param('edit', -1, PARAM_INT);
require_login(null, false);
\local_studentrecords\local\staffarea::setup('terms');
require_capability('local/studentrecords:configure', context_system::instance());
```

Most classes hold static methods and no state. Many keep the rules that need no database at the top, under a comment that says so, so that a `\basic_testcase` can test them without a site.

Six helpers carry the rest of the code. Learn these first.

| Helper | Why it matters |
|---|---|
| `records::save()` | Writes to a record table, with a history row for each changed field. A few tables are written straight to the database: see [Change history](flows-admissions-and-records.md#change-history). |
| `audit::log()` | One row in the audit log for each action. |
| `registration::with_lock()` | The one way to take a lock: seats, grades, accounts, numbers. |
| `billing::one_at_a_time()` | Read-then-write work on an account, under the lock `accounts` and in one transaction. |
| `messages::student()` | Every message to a student, and `messages::staff()` for staff. It goes at once, or waits for cron when a transaction or lock is open. |
| `catalog::setting()` | Every setting read. `catalog::date_to_time()` and `catalog::time_to_date()` are its neighbours for dates. |

Where to start for a change:

| You want to change | Start at |
|---|---|
| A rule about seats, drops or final grades | `registration`, then [Flows: registration and grades](flows-registration-and-grades.md) |
| Prices, payments, plans or refunds | `billing`, `payments`, `plans`, `refunds`, then [Flows: money](flows-money.md) |
| How GPA, hours or pass or fail are worked out | `registration::totals()`, `grading`, then [Calculations](calculations.md) |
| Who sees a page or a field | [Access](access.md) |
| The Student Records menu | `shell::groups()` |
| A setting | [Settings](settings.md) |
| A message or a scheduled job | [Tasks and messages](tasks-and-messages.md) |
| A table or a column | [Data model](data-model.md) |
| What gets deleted on a privacy request | [Privacy](privacy.md) |
| What the import reads | `importer`, `drafts`, `sheet_reader` |

## Folder layout

| Path | Holds |
|---|---|
| `local/studentrecords/*.php` | 43 pages, plus `lib.php`, `settings.php` and `version.php` |
| `local/studentrecords/classes/local/` | 41 classes with the rules and the shared page parts |
| `local/studentrecords/classes/form/` | 53 form classes |
| `local/studentrecords/classes/task/` | 4 scheduled tasks and 1 ad-hoc task |
| `local/studentrecords/classes/privacy/provider.php` | The privacy provider |
| `local/studentrecords/classes/payment/service_provider.php` | Callbacks of Moodle's payment system |
| `local/studentrecords/classes/hook_callbacks.php` | Hook callbacks |
| `local/studentrecords/db/` | `install.xml`, `install.php`, `upgrade.php`, `uninstall.php`, `access.php`, `tasks.php`, `messages.php`, `hooks.php` |
| `local/studentrecords/lang/en/local_studentrecords.php` | 2,284 strings in A-Z order |
| `local/studentrecords/styles.css` | All styles, with `sr-` classes |
| `local/studentrecords/tests/` | PHPUnit, four Behat features, a generator and a fixture |

Some folders you might look for don't exist. There are no templates, renderers or AMD modules: pages build HTML with `html_writer`, and the shared parts are in `shell`, `homes`, `cards`, `student_file`, `student_sections` and `record_view`. There is no CLI script, web service, event or cache definition. The plugin observes no Moodle event and raises none: its record of who did what is `local_studentrecords_log` and `local_studentrecords_history`.

`lib.php` holds seven callbacks: `local_studentrecords_extend_navigation_course()`, `local_studentrecords_myprofile_navigation()`, `local_studentrecords_after_require_login()`, `local_studentrecords_pluginfile()`, and for the theme `local_studentrecords_theme_drawer()`, `local_studentrecords_theme_card()` and `local_studentrecords_theme_topbar_note()`. `settings.php` adds one link to `staff.php` under Local plugins. There are no admin settings: see [Settings](settings.md).

## Top-level pages

"Gate" is what lets a person in. A name in `staffarea::PAGES` means the page calls `staffarea::setup()` with it. [Access](access.md#staff-pages) has the capability of each.

| Page | Gate | Job |
|---|---|---|
| `account.php` | `billing::can_view()` | A student account: lines, plans, payments, charges, credits, reversals, payouts |
| `admissions.php` | `admissions` | Applicants by state with their decision buttons, and admissions reports |
| `aid.php` | `aid` | Federal aid: student awards, disbursement runs, Return of aid, verification, reports |
| `aidletter.php` | Own record, or `local/studentrecords:viewaid` | Award letter of a student and award year, ready to print |
| `attendance.php` | `attendance::can_take()` | Class meetings of an offering, taking attendance |
| `billing.php` | `billing` | Student accounts list, and charging a term |
| `billingreports.php` | `billingreports` | Money reports: owed, payments, refunds, late payments, 1098-T |
| `billingsetup.php` | `billingsetup` | Billing settings, price list, plans, refund rules, the Finance role |
| `checklist.php` | `checklist` | Application checklist setup |
| `courses.php` | `courses` | Course catalog |
| `degreeaudit.php` | `access::sees_all_students()`, or own record | Degree audit, substitutions, approval of a graduation application |
| `faculty.php` | `faculty` | Faculty and staff list |
| `facultyrecord.php` | `faculty` | One faculty or staff record, credentials, qualifications |
| `ferpa.php` | `local/studentrecords:viewown` | The annual FERPA notice for a student to acknowledge |
| `gradechange.php` | `gradechanges` | Request, list and decide grade changes |
| `grading.php` | `grading` | Grading scale, levels, pass rules, academic rules |
| `graduation.php` | `graduation` | Graduation applications: review, confer, cancel |
| `import.php` | `import` | Upload a spreadsheet or CSV file, review the drafts |
| `index.php` | `homes::views()` | Home page by role, and the single-course redirect |
| `letter.php` | `local/studentrecords:managestudents` | Issue and download an enrollment verification letter |
| `log.php` | `log` | Audit log |
| `messages.php` | `messages` | Automatic messages: switches, text, test mode, message log |
| `mydocuments.php` | `local/studentrecords:viewown` | A student's own files and checklist |
| `myregistration.php` | `local/studentrecords:viewown` | Self-registration: pick classes, cart, waitlists, confirm |
| `offerings.php` | `offerings` | Course offerings per term, rosters, pulling final grades from the gradebook |
| `outsidereports.php` | `outsidereports` | Saved exports, headcount and completions counts, Clearinghouse file |
| `pay.php` | Own record only | Pay online through Moodle's payment window |
| `programs.php` | `programs` | Programs and their outlines |
| `receipt.php` | `billing::can_view()` | Receipt PDF of a payment |
| `refunds.php` | `refunds` | Refund list, decide a refund, figure it again |
| `register.php` | `local/studentrecords:managestudents` | Register one student, drop, withdraw, enter a final grade |
| `reports.php` | `reports` | Enrollment, hours, completion, graduation and attendance reports |
| `roster.php` | `roster` | Faculty roster, bulk qualifications |
| `saprules.php` | `saprules` | Standing rules |
| `setup.php` | `setup` | The setup wizard, 11 steps |
| `staff.php` | `staffarea::pages()` | Entry page of the staff area |
| `standing.php` | `standing` | Run and confirm academic standing |
| `statement.php` | `billing::can_view()` | Account statement PDF |
| `student.php` | `access::require_view_student()` | One student file: tabs for staff, own view for the student, teacher view |
| `studentpart.php` | `local/studentrecords:managestudents` | Edit one part of a file: decision, checklist, education, contacts, demographics, hold, transfer credit, graduation application, release, paper FERPA |
| `students.php` | `access::sees_all_students()`, or `local/studentrecords:viewstudents` in the course | Student list, or the students of one course |
| `terms.php` | `terms` | Terms |
| `viewas.php` | `students` | View a student's home and record as the student sees them |

## Classes in classes/local

All 41, in the namespace `\local_studentrecords\local`. The sections below give each one's main methods. "Test" is its file under `local/studentrecords/tests/local/`, unless the text says otherwise.

| Class | Job | Test |
|---|---|---|
| [`academic`](#academic) | Holds and grade changes | `academic_test.php` |
| [`access`](#access) | Who may open which record or file | `access_test.php` |
| [`admissions`](#admissions) | Status history, checklist, decisions, student IDs, IPEDS categories | `admissions_test.php` |
| [`aid`](#aid) | Federal aid awards, disbursements, reports | `aid_test.php` |
| [`aidhelp`](#aidhelp) | Aid helper math: need, loan limits, runs, the Return of aid worksheet | `aidhelp_test.php` |
| [`attendance`](#attendance) | Class meetings, marks, rates, last date of attendance | `attendance_test.php` |
| [`audit`](#audit) | The audit log | Through the page tests |
| [`billing`](#billing) | Price list, account lines, balance, locks on accounts, statement | `billing_test.php` |
| [`cards`](#cards) | Cards for the theme: Dashboard strip, course cards | `shell_test.php` |
| [`catalog`](#catalog) | Lookups, settings, dates in the school's time zone | `homes_test.php`, `registration_test.php` |
| [`dates`](#dates) | Date rules that need no database | `dates_test.php` |
| [`degreeaudit`](#degreeaudit) | The degree audit and substitutions | `degreeaudit_test.php` |
| [`drafts`](#drafts) | Imported items waiting for review, and applying them | `drafts_test.php` |
| [`faculty`](#faculty) | Faculty and staff records, credentials, qualifications | `faculty_test.php` |
| [`ferpa`](#ferpa) | FERPA notice and release authorizations | `ferpa_test.php` |
| [`grading`](#grading) | Scale and pass rules, with no database | `grading_test.php` |
| [`graduation`](#graduation) | Applications, review, conferral, honors | `graduation_test.php` |
| [`history_view`](#history_view) | Change history in words people read | Through the page tests |
| [`home`](#home) | Who gets the courses link, and No LMS staff | `home_test.php` |
| [`homes`](#homes) | Home pages by role | `homes_test.php` |
| [`importer`](#importer) | Turns sheets into draft items, with no database | `importer_test.php` |
| [`letters`](#letters) | Enrollment verification letters | `letters_test.php` |
| [`messages`](#messages-1) | Automatic messages | `messages_test.php` |
| [`moneyreports`](#moneyreports) | Aging, payments by day, late payments, 1098-T | `moneyreports_test.php` |
| [`outside`](#outside) | Saved exports, counts, Clearinghouse file | `outside_test.php` |
| [`payments`](#payments) | Online and recorded payments, receipts, course access, recovery | `payments_test.php` |
| [`plans`](#plans) | Payment plans and the daily work on them | `plans_test.php` |
| [`record_view`](#record_view) | "My academic record" | `selfreg_test.php` |
| [`records`](#records) | Saving with change history, commits, settings | `records_test.php` |
| [`refunds`](#refunds) | Refund rules, figuring, deciding, payouts | `refunds_test.php` |
| [`registration`](#registration) | Offerings, seats, enrolment, final grades, history, locks | `registration_test.php`, `registration_pages_test.php` |
| [`reports`](#reports) | Enrollment, completion and graduation reports, safe downloads | `reports_test.php` |
| [`selfreg`](#selfreg) | Self-registration cart and rules | `selfreg_test.php` |
| [`sheet_reader`](#sheet_reader) | Reads .xlsx and CSV into rows | `sheet_reader_test.php` |
| [`shell`](#shell) | The menu by role and small page parts | `shell_test.php`, `shell_inline_menu_test.php` |
| [`staffarea`](#staffarea) | Staff pages, their capabilities, page setup | `staffarea_test.php` |
| [`standing`](#standing) | Academic standing and SAP | `standing_test.php` |
| [`student_file`](#student_file) | The staff view of a file: header, tabs, profile | `page_rules_test.php` |
| [`student_sections`](#student_sections) | The sections of a file | Through the page tests |
| [`students`](#students) | The student record, own-record rule, privacy erase | `students_test.php` |
| [`waitlist`](#waitlist) | Waitlists and seat offers | `waitlist_test.php` |

Nine static properties keep state for one request. Five are caches, which can go stale inside it: `admissions::$docs`, `shell::$groups`, `shell::$offerings`, `home::$nolms` and `history_view::$names`. The other four hold the state of the work: `registration::$locks`, `billing::$running`, `billing::$afterlock` and `records::$lastchanged`. Tests call `shell::reset()` and `home::reset_cache()`. Nothing calls `admissions::reset_cache()`.

## Records and rules

### `records`

Saves a row and keeps the change history. `records::save()` drops keys that aren't columns, writes a history row for each changed field, and sets `records::$lastchanged`, so read that straight after. `records::commit()` ends a transaction after its `try` block: see [Tasks and messages](tasks-and-messages.md#commit-after-the-try-block). `records::set_setting()` changes a setting and writes history with table name `config`. `records::same()` compares numbers as numbers and keeps text such as `007` apart from `7`.

### `audit`

`audit::log()` writes one row and cuts `info` to 255 characters. `audit::ACTIONS` lists the actions. A new object type needs an `obj_<type>` string, because `local/studentrecords/log.php` has no fallback. `test_log_page_names_every_kind_of_record` in `local/studentrecords/tests/records_pages_test.php` protects that.

### `access`

`access::is_own()`, `access::sees_all_students()`, `access::can_manage_students()`, `access::shared_courses()`, `access::can_view_student()`, `access::require_view_student()`, `access::can_read_file()`, `access::hide_directory()`. [Access](access.md) explains each.

### `catalog`

Lookups for levels, programs, courses, terms and the scale, and the school settings. `catalog::SCHOOL` lists the settings of the wizard. `catalog::setting()` reads any key, and `catalog::module_on()` reads a yes or no. `catalog::date_to_time()` is noon, `catalog::day_start()` is the start of a day, `catalog::time_to_date()` gives Y-m-d, and `catalog::show_date()` a date to print. `catalog::repeat_rule()` and `catalog::round_percent()` read the settings of Academic Transcript when it is installed. `catalog::programs()` returns escaped HTML: print it as it is.

### `dates`

No database. `dates::parts_to_ymd()` and `dates::ymd_to_parts()` for the three date menus, `dates::withdraw_ymd()` for the calculated last day to withdraw, `dates::percent_in_text()` for a rule in words. `dates::WITHDRAW_PERCENT` is the default 60.

### `grading`

No database: callers pass the rows in. `grading::effective_rule()` picks a pass rule, `grading::passes()` applies it, `grading::letter_for_pct()` finds a letter, `grading::check_scale()` finds problems with a scale, `grading::parse_program_rules()` reads rules in words, and `grading::normalise_code()` cleans a course code. `grading::divide_rounded()` is the exact division for official figures: never replace it with `round()`.

## Students and staff

### `students`

`students::TABLE`. `students::save()` creates or updates a file, under the lock `studentno` when it has to make an ID, so don't call it from inside a transaction. `students::write()` does the writing, and `students::check_own_record()` stops a person changing `students::DECIDING` on their own file. `students::display_name()` builds the name. `students::DIRECTORY`, `students::PERSONAL`, `students::PERSONALPARTS` and `students::F1` are the field lists. `students::erase_personal()` is the student half of a privacy deletion.

### `admissions`

`admissions::set_status()` changes a status with its history, `admissions::record_decision()` records a decision, and `admissions::next_student_id()` works out an ID. `admissions::checklist()`, `admissions::missing()` and `admissions::save_checklist()` run the checklist, and `admissions::applies()` tests a document's scope. `admissions::ipeds_category()` gives a race or ethnicity category. `admissions::status_problems()` runs only in the form.

### `faculty`

`faculty::save()`, `faculty::save_credential()`, `faculty::add_qualifications()`, `faculty::remove_qualifications()`, `faculty::is_qualified()`, `faculty::advisees()`, `faculty::roster()`, `faculty::erase_personal()`. `faculty::qualified_courses()` returns escaped HTML.

### `ferpa`

`ferpa::acknowledge()`, `ferpa::add_release()`, `ferpa::revoke_release()`, `ferpa::release_active()`, `ferpa::release_check()`, and `ferpa::academic_year()` with `ferpa::current_year()`.

### `letters`

`letters::facts()` reads the record now, `letters::issue()` keeps a copy under the lock `letterno`, and `letters::pdf()` prints from the copy. `letters::logo_path()` serves the statement and the receipt as well.

### `history_view`

Display only. `history_view::row()` turns a history row into table cells, with `history_view::label()` and `history_view::value()`. `history_view::LABELS`, `history_view::DATES`, `history_view::YESNO` and `history_view::CODED` say how each field reads.

## Registration and the academic record

### `registration`

The largest rules class, with the lock helpers `registration::with_lock()` and `registration::holds_lock()`. `registration::add()` and `registration::end()` take and end a seat, through `registration::add_seat()` and `registration::end_seat()`. `registration::add_problems()` lists what stops a registration, and `registration::window_problem()` tests the term dates. `registration::enrol()` and `registration::suspend()` touch the Moodle course. `registration::set_grade()` is the only way to write a final grade. `registration::grades_from_moodle()` and `registration::teacher_post()` feed it. `registration::history()` builds the academic history, `registration::totals()` adds it up, and `registration::mark_repeats()` applies the repeat rule. `registration::result_passes()` says if a letter passes. `registration::copy_sections()` copies sections between terms. [Flows: registration and grades](flows-registration-and-grades.md) traces them.

### `selfreg`

`selfreg::add()`, `selfreg::remove()` and `selfreg::wait()` work the cart, and `selfreg::confirm()` registers it. `selfreg::require_allowed()` and `selfreg::closed_notice()` say who may. `selfreg::open_terms()`, `selfreg::problems()`, `selfreg::is_full()`, `selfreg::clashes()`. `selfreg::STATUSES` lists who may register themselves.

### `waitlist`

`waitlist::join()`, `waitlist::leave()`, `waitlist::registered()`, `waitlist::seat_opened()` and `waitlist::daily()`. `waitlist::held_seats()` counts seats held for others. `waitlist::HOLDDAYS` is how long a seat is held.

### `attendance`

`attendance::add_meeting()`, `attendance::add_weekly()`, `attendance::save_marks()`, `attendance::summary()`, `attendance::for_reg()`, `attendance::can_take()`, `attendance::fill_last_attended()`, `attendance::report()`.

### `academic`

Holds: `academic::place_hold()`, `academic::release_hold()`, `academic::hold_active()`, `academic::blocking_holds()`. Grade changes: `academic::request_change()`, `academic::decide_change()`, `academic::can_decide()`, `academic::changes()`.

### `standing`

`standing::run()` proposes a standing per student, `standing::confirm()` confirms it, and `standing::evaluate()`, `standing::measures()` and `standing::compute()` work it out. `standing::rule()` finds the rule, and `standing::suggestions()` reads rules out of the academic rules.

### `degreeaudit`

`degreeaudit::run()` audits a student, and `degreeaudit::evaluate()` does the comparison with no database. `degreeaudit::add_substitution()`, `degreeaudit::group_hours()`, `degreeaudit::resident_check()`.

### `graduation`

`graduation::apply()`, `graduation::review()`, `graduation::confer()`, `graduation::cancel()`, `graduation::require_not_own()`, `graduation::parse_honors()`, `graduation::honors_for()`.

## Money

### `billing`

`billing::FEES` and `billing::TXN` are the tables. `billing::one_at_a_time()`, `billing::transaction()` and `billing::after_lock()` are the lock and transaction helpers. `billing::add_line()` writes a line and brings plans and course access up to date, and `billing::insert_line()` writes a line and nothing else. `billing::reverse()` reverses one. `billing::balance()`, `billing::lines()`, `billing::accounts()`, `billing::estimate()`. `billing::charge_registration()` and `billing::charge_term()` add charges, from `billing::registration_charges()`. `billing::cents()`, `billing::divide()` and `billing::times()` are the exact money helpers. `billing::statement_pdf()`. `billing::create_finance_role()`.

### `payments`

`payments::record()` records a payment and `payments::deliver()` takes one from Moodle's payment system. `payments::recover()` finds what never arrived. `payments::create_request()` and `payments::open_request()` run a request. `payments::access_ok()`, `payments::grant()`, `payments::release()` and `payments::settle()` give course access. `payments::next_receipt()`, `payments::receipt_pdf()`.

### `plans`

`plans::save_template()`, `plans::create()`, `plans::cancel()`, `plans::sync()`, `plans::paid_toward()`, `plans::daily()`, `plans::plan_ok()`, `plans::next_due()`, `plans::send_agreement()`. `plans::due_days()`, `plans::amounts()`, `plans::allocate()` and `plans::is_late()` need no database.

### `refunds`

`refunds::propose()`, `refunds::figure()`, `refunds::refigure()`, `refunds::decide()`, `refunds::payout()`, `refunds::save_rule()`, `refunds::listing()`. `refunds::rule_fits()`, `refunds::pick_rule()`, `refunds::percent()` and `refunds::business_days()` need no database.

### `moneyreports`

`moneyreports::owed()`, `moneyreports::payments()`, `moneyreports::refunds()`, `moneyreports::late()`, `moneyreports::tax1098()`. `moneyreports::aging()` and `moneyreports::tax_amounts()` need no database. The 1098-T file is data for a school's tax software or tax adviser, and the code gives no tax advice.

## Federal aid

### `aid`

`aid::enabled()` and `aid::require_enabled()` test the module. `aid::save_award()`, `aid::disburse()`, `aid::awards()`, `aid::lines()`, `aid::create_role()`, and the reports `aid::recipients()`, `aid::disbursements()`, `aid::enrollment()`, `aid::withdrawals()` and `aid::ninety_ten_worksheet()`.

### `aidhelp`

Helpers that staff check against the official worksheets and the current federal handbook. They give no legal or aid advice. `aidhelp::need()`, `aidhelp::loan_check()`, `aidhelp::run()`, `aidhelp::send_ready()`, `aidhelp::worksheet()`, `aidhelp::r2t4()`, `aidhelp::send_return()`, `aidhelp::save_year()`, `aidhelp::send_letter()`.

## Messages

### `messages`

`messages::student()` and `messages::staff()` send, `messages::send_queued()` runs in the ad-hoc task, and `messages::send_test()` runs from the setup page. `messages::is_on()`, `messages::text()`, `messages::fill()`, `messages::test_mode()`, `messages::recent()`, `messages::last_status()`. [Tasks and messages](tasks-and-messages.md) traces them.

## Reports and import

### `reports`

`reports::enrollment()`, `reports::completion()`, `reports::graduation()`, `reports::rate()`. `reports::download()` sends rows with every cell safe to open in a spreadsheet: use it, not `\core\dataformat::download_data()`. `reports::plain()` gives a stored name as plain text.

### `outside`

`outside::run()` runs a saved export and `outside::row()` builds one student's values. `outside::save_export()`, `outside::add_starters()`, `outside::population()`, `outside::headcount()`, `outside::completions()`, `outside::term_load()`. The Clearinghouse file is `outside::nsc_rows()`, `outside::nsc_status()` and `outside::nsc_problems()`.

### `importer`

No database. `importer::build()` is the entry point. It recognises each sheet by its headers (`importer::recognise()`), then runs a `read_` method per type. `importer::TYPES`, `importer::COLUMNS` and `importer::SCHOOLFIELDS` say what it reads.

### `sheet_reader`

`sheet_reader::read_xlsx()` and `sheet_reader::read_csv()`. It uses the values Excel saved and doesn't recalculate formulas. Over `sheet_reader::MAXROWS` or another limit it refuses the file instead of leaving rows out.

### `drafts`

`drafts::store()`, `drafts::approve()`, `drafts::reject()`, `drafts::fix()`, `drafts::apply()`, `drafts::existing()`, `drafts::link_prereqs()`.

## Pages and menus

### `staffarea`

`staffarea::PAGES` maps each staff page to its file, string and capability. `staffarea::can_open()`, `staffarea::setup()`, `staffarea::start_page()`, `staffarea::header()`, `staffarea::pages()`.

### `shell`

`shell::groups()` builds the menu by role, and `shell::menu()`, `shell::drawer()` and `shell::inline_menu()` print it. `shell::my_offerings()` lists a teacher's offerings. The rest are page parts: `shell::tile()`, `shell::tiles()`, `shell::tag()`, `shell::attention()`, `shell::quick_links()`, `shell::empty_state()`.

### `home`

`home::is_nolms()`, `home::is_lms_page()`, `home::shows_courses()`, `home::single_course_target()`, `home::is_staff_user()`. `home::URL` is the home page.

### `homes`

One method per view: `homes::student()`, `homes::staff()`, `homes::finance()`, `homes::aid()`, `homes::teacher()`, `homes::atrisk()`. `local/studentrecords/index.php` calls them as `homes::$view()`, so a search for a method's name finds no caller. `homes::views()` and `homes::pick()` choose the view.

### `cards`

`cards::for_page()` is what the theme asks for. `cards::dashboard_strip()`, `cards::staff_strip()`, `cards::student_card()`, `cards::teacher_card()`, `cards::student_numbers()`, `cards::current_term()`.

### `student_file`

`student_file::header()`, `student_file::tabs()`, `student_file::profile()`, `student_file::glance()`, `student_file::pick_tab()`. `student_file::TABS` lists the tabs.

### `student_sections`

One method per section of a file. `student_sections::render()` prints them all, for a student's own view and for staff who open a record from a course. Staff in the staff area see one tab at a time instead, and `local/studentrecords/student.php` calls the methods for that tab.

### `record_view`

`record_view::render()` prints "My academic record".

## Other classes

| Class | File | Job |
|---|---|---|
| `hook_callbacks` | `local/studentrecords/classes/hook_callbacks.php` | Four hooks: the start page option, the top menu, the course tabs, and No LMS redirects on pages that don't call `require_login()`. `local/studentrecords/db/hooks.php` lists them. |
| `service_provider` | `local/studentrecords/classes/payment/service_provider.php` | Moodle's payment callbacks: `service_provider::get_payable()`, `service_provider::get_success_url()`, `service_provider::deliver_order()`. |
| `provider` | `local/studentrecords/classes/privacy/provider.php` | The privacy provider. See [Privacy](privacy.md). |
| `billing_daily` | `local/studentrecords/classes/task/billing_daily.php` | Scheduled: plan reminders and late payments. |
| `erase_removed_accounts` | `local/studentrecords/classes/task/erase_removed_accounts.php` | Scheduled: finished deletion requests of removed accounts. |
| `recover_payments` | `local/studentrecords/classes/task/recover_payments.php` | Scheduled: online payments that never reached the account. |
| `send_message` | `local/studentrecords/classes/task/send_message.php` | Ad-hoc: one message that waited for cron. |
| `waitlist_daily` | `local/studentrecords/classes/task/waitlist_daily.php` | Scheduled: lapsed seat offers and free seats. |
| `local_studentrecords_generator` | `local/studentrecords/tests/generator/lib.php` | Test data. |
| `recording_lock_factory` | `local/studentrecords/tests/fixtures/recording_lock_factory.php` | Real locks that note the state of a test each time one is taken and released. |

## Form classes

`classes/form/` holds 53 classes in the namespace `\local_studentrecords\form`. Each extends `\moodleform`, is one file, and is named after the table or action it edits. Each has a `definition()`, most have a `validation()`, and a few take custom data such as `mode`, `text` or `item`. The page does the saving: a form only collects and checks. A rule that must hold on every path goes in a class, not in a form's `validation()`, and `admissions::status_problems()` shows what happens otherwise: it runs only in the form, so `students::save()` accepts a withdrawal with no dates.

Most forms belong to one page. `plan` serves `billingsetup.php` (mode `template`) and `account.php` (mode `student`), and `reverse` serves `account.php` and `aid.php`. Two pages build the class name at run time, so a search for the name finds no caller: `grading.php` makes `grade`, `level` and `minpass` from `what`, and `studentpart.php` makes `educ` and `emerg` from `part`.

| Page | Form classes |
|---|---|
| `account.php` | `cancelplan`, `grantaccess`, `payment`, `payout`, `plan`, `reverse`, `txn` |
| `aid.php` | `aidaward`, `reverse` |
| `attendance.php` | `meeting` |
| `billingsetup.php` | `billingsettings`, `fee`, `plan`, `refrule` |
| `checklist.php` | `reqdoc` |
| `courses.php` | `course` |
| `facultyrecord.php` | `credential`, `faculty`, `qualify`, `unqualify` |
| `gradechange.php` | `gradechange` |
| `grading.php` | `grade`, `level`, `minpass` |
| `graduation.php` | `gradreview` |
| `import.php` | `draft_fix`, `import_upload` |
| `letter.php` | `letter` |
| `messages.php` | `msgtext` |
| `offerings.php` | `offer` |
| `outsidereports.php` | `exportdef` |
| `pay.php` | `payamount` |
| `programs.php` | `progcourse`, `program` |
| `refunds.php` | `refigure`, `refunddecide` |
| `register.php` | `endreg`, `register`, `regrade` |
| `roster.php` | `bulkqualify` |
| `saprules.php` | `saprule` |
| `setup.php` | `school` |
| `student.php` | `student` |
| `studentpart.php` | `checklist`, `decision`, `demog`, `educ`, `emerg`, `ferpapaper`, `gradapp`, `hold`, `holdrelease`, `relauth`, `xfer` |
| `terms.php` | `term` |

## Language strings

All text is in `local/studentrecords/lang/en/local_studentrecords.php`, in A-Z order. `test_order` in `local/studentrecords/tests/lang_test.php` holds that, so a new string goes in its place. `test_capability_names` asks for a name for every capability. A language file has multi-line strings: a line regex drops them, so split on `^\$string[` when you read it with a script.

Many keys are built at run time, so a search for a key can find nothing. When you add a value to a constant, add its string.

| Key | Values come from |
|---|---|
| `status_<x>` | `catalog::STATUSES` |
| `regstatus_<x>` | `registration::STATUSES` |
| `offerstatus_<x>` | `registration::OFFERSTATUSES` |
| `holdtype_<x>` | `academic::HOLDTYPES` |
| `decision_<x>`, `docstate_<x>` | `admissions::DECISIONS`, `admissions::DOCSTATES` |
| `race_<x>`, `sex_<x>`, `hispanic_<x>` | `admissions::RACES`, `admissions::SEX`, `admissions::HISPANIC` |
| `gradstate_<x>` | `graduation::STATES` |
| `standing_<x>` | `standing::LADDER` |
| `waitstatus_<x>` | `waitlist::STATUSES` |
| `attend_<x>` | `attendance::STATUSES` |
| `feekind_<x>`, `feebasis_<x>`, `scope_<x>` | `billing::KINDS`, `billing::BASES`, `billing::SCOPES` |
| `chargedesc_<basis>` | `billing::AUTO` |
| `linetype_<x>`, `creditkind_<x>` | `billing::TYPES`, `billing::CREDITKINDS` and `billing::AIDKINDS` |
| `paymethod_<x>`, `access_<x>` | `payments::METHODS` plus `online`, `payments::ACCESS` |
| `payout_<x>`, `ruletype_<x>`, `rulesummary_<x>` | `refunds::PAYOUTS`, `refunds::RULETYPES` |
| `frequency_<x>`, `downtype_<x>`, `duemode_<x>` | `plans::FREQUENCIES`, `plans::DOWNTYPES`, `plans::DUEMODES` |
| `fund_<x>`, `aidstatus_<x>` | `aid::FUNDS`, `aid::STATUSES` |
| `msgname_<key>`, `msgwhen_<key>`, `msgdef_<key>_subject`, `msgdef_<key>_body` | `messages::keys()` |
| `msgstatus_<x>` | The statuses of [`local_studentrecords_msglog`](data-model.md#local_studentrecords_msglog) |
| `set_<x>`, `set_<x>_help` | `catalog::SCHOOL` and the billing settings |
| `obj_<type>`, `action_<x>` | The object types of `audit::log()`, `audit::ACTIONS` |
| `xf_<field>` | `outside::FIELDS` |
| `purpose_<x>`, `population_<x>` | `outside::PURPOSES`, `outside::POPULATIONS` |
| `err_teacherpost_<state>` | The states of `registration::teacher_post_target()` |
| `file_tab_<x>` | `student_file::TABS` |
| `credtype_<x>`, `cred_<x>` | `faculty::CREDTYPES`, `importer::CREDENTIALS` |

## Tests

`local/studentrecords/tests/` holds 48 PHPUnit files. `tests/local/` has a file for most classes, plus `registration_pages_test.php`, `page_rules_test.php` and `shell_inline_menu_test.php`. The Test column of the class table says where the others are covered. `tests/privacy/provider_test.php`, `tests/task/` and `tests/form/school_test.php` follow the classes they test. At the top, `money_pages_test.php`, `records_pages_test.php`, `hook_callbacks_test.php`, `lang_test.php`, `schema_test.php`, `upgrade_test.php` and `uninstall_test.php`.

The page tests run a page the way Moodle does: they set `$_GET` or `$_POST` and collect the output, and a redirect or error ends in an exception. They cover who gets in, wrong session keys, and names escaped once. [Testing](../testing.md) says how to run them.

`recording_lock_factory` is set as `$CFG->lock_factory` to prove what happens under a lock. Tests that need Academic Transcript or the theme call `markTestSkipped()` when it isn't installed.

## Related pages

- [Architecture](../architecture.md)
- [Conventions](../conventions.md)
- [Pitfalls](../pitfalls.md)
- [Data model](data-model.md)
- [Access](access.md)
- [Extending](extending.md)
