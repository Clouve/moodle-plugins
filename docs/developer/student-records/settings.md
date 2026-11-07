# Student Records settings

Every setting and config key of `local_studentrecords`: the school settings in `catalog::SCHOOL`, the billing keys, the message keys, `recoverfrom`, and the keys built at run time, with what reads each one and how to add one.

## Contents

- [How settings work](#how-settings-work)
- [All keys at a glance](#all-keys-at-a-glance)
- [School settings](#school-settings)
- [The logo](#the-logo)
- [Billing settings](#billing-settings)
- [Message settings](#message-settings)
- [The start of payment recovery](#the-start-of-payment-recovery)
- [Keys built at run time](#keys-built-at-run-time)
- [Settings of other plugins](#settings-of-other-plugins)
- [Settings from an import](#settings-from-an-import)
- [Adding a setting](#adding-a-setting)
- [Related pages](#related-pages)

## How settings work

Every key sits in `{config_plugins}` under the plugin `local_studentrecords`. There are no `admin_setting` objects: `local/studentrecords/settings.php` adds one link to the staff area, and plugin pages keep the settings.

| Page | Keys | Gate |
|---|---|---|
| `local/studentrecords/setup.php` | `catalog::SCHOOL`, through the form `school` | `local/studentrecords:configure` |
| `local/studentrecords/billingsetup.php` | Billing keys, through the form `billingsettings` | `local/studentrecords:managebilling` |
| `local/studentrecords/messages.php` | Message keys | `local/studentrecords:configure` |
| `local/studentrecords/import.php` | `importer::SCHOOLFIELDS` and `withdrawpct`, once a draft is approved | `local/studentrecords:import` |

Write with `records::set_setting()`, never with `set_config()`. It does nothing when the value is the same, and otherwise it writes a row in `local_studentrecords_history` with table name `config`, record id 0, and the key as the field. No page shows those rows. `recoverfrom` is the one exception, because no person sets it.

Read with `catalog::setting()`. It returns a string, and `''` for a key that isn't set. Nothing writes defaults at install, so each default lives at the read, and each read decides what empty means. Three patterns are in use, and they differ on purpose:

| Pattern | Means | Used for |
|---|---|---|
| `catalog::setting('x') === '1'` | Off unless set | `billing`, `selfreg`, `idauto`, `msgtestmode`, and every `catalog::module_on()` |
| `catalog::setting('x') !== '0'` | On unless turned off | `msgon_<key>`, and `idyear` |
| `catalog::setting('x') !== '' ? catalog::setting('x') : 7` | A number where 0 is a real answer | `reminderdays`, `refunddays`, `minattendance`, `fulltimehours`, `halftime` |

Don't turn the third pattern into `?:`, because 0 would become the default. The numbers 80, 12 and 6 are written at each read: `minattendance` in seven places, `halftime` in five and `fulltimehours` in four, so changing a default means changing all of them.

## All keys at a glance

| Key | Kept by | Type | When not set |
|---|---|---|---|
| [`schoolname`](#schoolname) | wizard, step `school` | text | Empty. The form requires it. |
| [`othernames`](#othernames) | wizard, step `school` | text | Empty. |
| [`legalname`](#legalname) | wizard, step `school` | text | Empty. |
| [`address`](#address) | wizard, step `school` | textarea | Empty. |
| [`campusaddress`](#campusaddress) | wizard, step `school` | textarea | Empty. |
| [`state`](#state) | wizard, step `school` | text | Empty. |
| [`website`](#website) | wizard, step `school` | text | Empty. |
| [`phone`](#phone) | wizard, step `school` | text | Empty. |
| [`email`](#email) | wizard, step `school` | text | Empty. |
| [`registrarname`](#registrarname) | wizard, step `school` | text | Empty. |
| [`registrartitle`](#registrartitle) | wizard, step `school` | text | Prints the string `letter_registrar`. |
| [`officehours`](#officehours) | wizard, step `school` | text | Empty. |
| [`timezone`](#timezone) | wizard, step `school` | timezone | 99, the server's time zone. The form starts at 99. |
| [`opeid`](#opeid) | wizard, step `school` | text | Empty. |
| [`stateagency`](#stateagency) | wizard, step `approvals` | textarea | Empty. |
| [`statelicense`](#statelicense) | wizard, step `approvals` | text | Empty. |
| [`accreditor`](#accreditor) | wizard, step `approvals` | text | Empty. |
| [`fedaid`](#fedaid) | wizard, step `approvals` | yesno | Off. |
| [`f1`](#f1) | wizard, step `approvals` | yesno | Off. |
| [`ferpanotice`](#ferpanotice) | wizard, step `approvals` | textarea | Empty: no prompt and no acknowledge button. |
| [`calendartype`](#calendartype) | wizard, step `calendar` | calendar | The form starts at "Choose...". |
| [`yearstart`](#yearstart) | wizard, step `calendar` | text | Empty or unreadable means July 1. |
| [`creditunit`](#creditunit) | wizard, step `calendar` | text | Empty. |
| [`withdrawpct`](#withdrawpct) | wizard, step `calendar` | percent | 60, from `dates::WITHDRAW_PERCENT`. |
| [`minattendance`](#minattendance) | wizard, step `calendar` | percent | 80, written at each read. |
| [`atriskgrade`](#atriskgrade) | wizard, step `calendar` | percent0 | 70, from `catalog::ATRISKGRADE`. |
| [`ldamoodle`](#ldamoodle) | wizard, step `calendar` | yesno | Off. |
| [`refundlda`](#refundlda) | wizard, step `calendar` | yesno | Off. |
| [`fulltimehours`](#fulltimehours) | wizard, step `calendar` | hours | 12, written at each read. |
| [`selfreg`](#selfreg) | wizard, step `calendar` | yesno | Off. |
| [`idauto`](#idauto) | wizard, step `admissions` | yesno | Off. |
| [`idprefix`](#idprefix) | wizard, step `admissions` | text | Empty. |
| [`idyear`](#idyear) | wizard, step `admissions` | yesno | On. Only `0` leaves the year out. The form starts at yes. |
| [`idpadding`](#idpadding) | wizard, step `admissions` | number | 4. The form starts at 4. |
| [`idseparator`](#idseparator) | wizard, step `admissions` | text | A hyphen. The word `none` means no separator. |
| [`transferingpa`](#transferingpa) | wizard, step `admissions` | yesno | Off. |
| [`honors`](#honors) | wizard, step `admissions` | textarea | Empty: no honors offered. |
| [`billing`](#billing) | billing setup | yes or no | Off. |
| [`billaccess`](#billaccess) | billing setup | text | `downpayment`. |
| [`paymentaccount`](#paymentaccount) | billing setup | text | None: no online payment. |
| [`reminderdays`](#reminderdays) | billing setup | text | 7. 0 means no reminders. |
| [`refunddays`](#refunddays) | billing setup | text | 30. |
| [`halftime`](#halftime) | billing setup | text | 6, written at each read. |
| [`gradlevels`](#gradlevels) | billing setup | text | None. |
| [`payinfo`](#payinfo) | billing setup | text | Empty. |
| [`statementnote`](#statementnote) | billing setup | text | Empty. |
| [`msgon_<key>`](#message-settings) | messages page | 0 or 1 | On |
| [`msgsubject_<key>`](#message-settings) | messages page | text | The default string |
| [`msgbody_<key>`](#message-settings) | messages page | text | The default string |
| [`msgtestmode`](#message-settings) | messages page | 0 or 1 | Off |
| [`msgtestinbox`](#message-settings) | messages page | email address | Empty |
| [`recoverfrom`](#the-start-of-payment-recovery) | install and upgrade | timestamp | `payments::recover()` sets it to now |

## School settings

`catalog::SCHOOL` maps each key to a form element type and a wizard step: `'schoolname' => ['text', 'school']`. The form `school` builds its fields from that list, and `local/studentrecords/setup.php` saves what the step shows. The steps with a form are `school`, `approvals`, `calendar` and `admissions`.

| Type | Element | Checked by `school::validation()` |
|---|---|---|
| `text` | Text box | |
| `textarea` | Three rows | |
| `yesno` | Yes or no | |
| `timezone` | Moodle's time zones | |
| `calendar` | `importer::CALENDARS` | |
| `percent` | Short text box | Above 0, up to 100 (`err_percent`) |
| `percent0` | Short text box | 0 to 100 (`err_percent0`) |
| `number` | Short text box | Whole number, 1 to 10 (`err_padding`) |
| `hours` | Short text box | Above 0, up to 100 (`err_hoursvalue`) |

A key not answered yet keeps the form's default, because `local/studentrecords/setup.php` passes only keys with a value to `set_data()`. `test_the_at_risk_percent_goes_from_0_to_100` and the other tests in `local/studentrecords/tests/form/school_test.php` protect the percent checks, and `test_the_calendar_step_saves_the_at_risk_percent` protects the save.

### `schoolname`

Step `school`, type `text`. When not set: empty. The form requires it.

The name on every printed page. `letters::pdf()`, `billing::statement_pdf()` and `payments::receipt_pdf()` print it, `local/studentrecords/pay.php` puts it in the payment description, and `local/studentrecords/aidletter.php` prints it, or the site name when it is empty. It fills `{school}` in messages and is the From name of `messages::send_now()`. The wizard's summary shows it.

### `othernames`

Step `school`, type `text`. When not set: empty.

Stored only. No code reads it back, apart from the form and the import.

### `legalname`

Step `school`, type `text`. When not set: empty.

Stored only.

### `address`

Step `school`, type `textarea`. When not set: empty.

Printed by `letters::pdf()`, `billing::statement_pdf()` and `payments::receipt_pdf()`.

### `campusaddress`

Step `school`, type `textarea`. When not set: empty.

Stored only.

### `state`

Step `school`, type `text`. When not set: empty.

Stored only. The import can fill it from the address, as a two-letter code with a warning.

### `website`

Step `school`, type `text`. When not set: empty.

Printed by `letters::pdf()`.

### `phone`

Step `school`, type `text`. When not set: empty.

Printed by `letters::pdf()` and `billing::statement_pdf()`, and fills `{office_phone}` in messages.

### `email`

Step `school`, type `text`. When not set: empty.

Printed by `letters::pdf()` and `billing::statement_pdf()`. Messages come from the no-reply address, not this one.

### `registrarname`

Step `school`, type `text`. When not set: empty.

The signer on `letters::pdf()`.

### `registrartitle`

Step `school`, type `text`. When not set: prints the string `letter_registrar`.

The signer's title on `letters::pdf()`.

### `officehours`

Step `school`, type `text`. When not set: empty.

Stored only.

### `timezone`

Step `school`, type `timezone`. When not set: 99, the server's time zone. The form starts at 99.

`catalog::timezone()`. Every date rule reads it: term dates, "today", display of dates, the year in receipt, letter and student numbers. Changing it moves what a stored noon or midnight means, so change it before terms exist.

### `opeid`

Step `school`, type `text`. When not set: empty.

Stored only. `school::definition()` shows the field only once `fedaid` is saved as yes.

### `stateagency`

Step `approvals`, type `textarea`. When not set: empty.

Shown on the wizard's summary. Nothing else reads it.

### `statelicense`

Step `approvals`, type `text`. When not set: empty.

Stored only.

### `accreditor`

Step `approvals`, type `text`. When not set: empty.

Shown on the wizard's summary. Nothing else reads it. The import blanks a value such as "none", with a warning.

### `fedaid`

Step `approvals`, type `yesno`. When not set: off.

The federal aid module. `aid::enabled()` reads it through `catalog::module_on()`. It opens `local/studentrecords/aid.php`, the `aid` menu group, the aid section of an account and the award letter. Only for schools approved for Title IV aid. The help text says so, and this page gives no aid advice.

### `f1`

Step `approvals`, type `yesno`. When not set: off.

The F-1 module. `catalog::module_on()` reads it in `local/studentrecords/classes/form/student.php`, `local/studentrecords/student.php` and `local/studentrecords/students.php`.

### `ferpanotice`

Step `approvals`, type `textarea`. When not set: empty: no prompt and no acknowledge button.

The text of the annual notice. `local/studentrecords/ferpa.php` shows it, and `student_sections::ferpa_prompt()` prompts while this year's acknowledgment is missing.

### `calendartype`

Step `calendar`, type `calendar`. When not set: the form starts at "Choose...".

One of `importer::CALENDARS`: `semester`, `quarter`, `trimester`, `term`, `continuous`, `rolling`. Shown on the wizard's summary. Nothing else reads it.

### `yearstart`

Step `calendar`, type `text`. When not set: empty or unreadable means July 1.

Start of the academic year for FERPA, read by `ferpa::current_year()`. `ferpa::year_start()` reads `MM-DD`, `8/1` or `August 1`.

### `creditunit`

Step `calendar`, type `text`. When not set: empty.

Stored only.

### `withdrawpct`

Step `calendar`, type `percent`. When not set: 60, from `dates::WITHDRAW_PERCENT`.

Share of the term for the calculated last day to withdraw, read by `catalog::withdraw_percent()`. A date staff picked on a term stays. The import can fill it from an academic rule in words. The form takes above 0 up to 100.

### `minattendance`

Step `calendar`, type `percent`. When not set: 80, written at each read.

Minimum attendance percent. Read by `attendance::save_marks()` for the `lowattendance` message, `attendance::report()`, `record_view::render()`, and the cards and homes. The form takes above 0 up to 100.

### `atriskgrade`

Step `calendar`, type `percent0`. When not set: 70, from `catalog::ATRISKGRADE`.

Course grade percent under which a student is at risk, read by `catalog::atrisk_percent()` for `homes::atrisk()`. 0 leaves grades out of that list, so the form takes 0 to 100.

### `ldamoodle`

Step `calendar`, type `yesno`. When not set: off.

1 = the last Moodle course access counts as attendance in `attendance::for_reg()`.

### `refundlda`

Step `calendar`, type `yesno`. When not set: off.

1 = `registration::end_seat()` figures a refund on the last date of attendance in the course, when there is one.

### `fulltimehours`

Step `calendar`, type `hours`. When not set: 12, written at each read.

Full-time credit hours per term. Read by `outside::nsc_rows()`, `aid::enrollment()`, `aidhelp::enrolled_percent()` and `local/studentrecords/myregistration.php`.

### `selfreg`

Step `calendar`, type `yesno`. When not set: off.

1 = students register themselves. `selfreg::enabled()` reads it. Seat offers need it too.

### `idauto`

Step `admissions`, type `yesno`. When not set: off.

1 = a new file left without an ID gets one. `admissions::auto_id()` reads it, and the student form no longer requires an ID.

### `idprefix`

Step `admissions`, type `text`. When not set: empty.

Start of an automatic ID. Read by `admissions::next_student_id()`.

### `idyear`

Step `admissions`, type `yesno`. When not set: on. Only `0` leaves the year out. The form starts at yes.

Puts this year, in the school's time zone, in an automatic ID.

### `idpadding`

Step `admissions`, type `number`. When not set: 4. The form starts at 4.

Digits of the number in an automatic ID, 1 to 10.

### `idseparator`

Step `admissions`, type `text`. When not set: a hyphen. The word `none` means no separator.

Put between prefix, year and number.

### `transferingpa`

Step `admissions`, type `yesno`. When not set: off.

The default of `ingpa` on new transfer credit, in `local/studentrecords/classes/form/xfer.php`.

### `honors`

Step `admissions`, type `textarea`. When not set: empty: no honors offered.

Honors rules such as `3.9 Summa cum laude; 3.7 Magna cum laude`. `local/studentrecords/graduation.php` reads them with `graduation::parse_honors()` to offer a value at conferral.

## The logo

The logo isn't a config key. It is the file area `logo`, item 0, one .png, .jpg or .jpeg file, saved by the step `school` of `local/studentrecords/setup.php`. `letters::logo_path()` puts it on the letter, statement and receipt PDFs, and any logged-in person may read it (`access::can_read_file()`).

## Billing settings

`local/studentrecords/billingsetup.php` keeps these through the form `billingsettings`. `billingsetup.php` also holds the price list, the school's plans and the refund rules, which are tables, not settings: see [Data model](data-model.md#billing).

### `billing`

When not set: off.

`billing::enabled()`. On adds charges on registering, shows accounts to students, and runs `plans::daily()`. The wizard's summary reads it with `catalog::module_on()`.

> Known problem in 1.4.6: turning billing off doesn't open the courses that wait for payment. `payments::release()` runs after a payment, a line that lowers a balance or a new plan, and not when the setting changes, so those registrations keep waiting.

### `billaccess`

When not set: `downpayment`.

When a registered student gets into the Moodle course: one of `payments::ACCESS`, `downpayment`, `full` or `immediate`. `payments::access_rule()` reads it, and any other value counts as `downpayment`. Registrations made earlier aren't changed.

### `paymentaccount`

When not set: none: no online payment.

The id of a Moodle payment account. `payments::account()` needs it set, available and not archived, or online payment is off.

### `reminderdays`

When not set: 7. 0 means no reminders.

Days before a due date that `plans::daily()` sends the reminder.

### `refunddays`

When not set: 30.

Days to pay a refund back, read by `refunds::refund_days()`. It sets `refund.duedate` of a refund for a drop or withdrawal. A return of aid gets its due date from the worksheet.

### `halftime`

When not set: 6, written at each read.

Half-time credit hours per term. Read by `moneyreports::tax1098()` for box 8, `outside::nsc_rows()`, `aid::enrollment()` and `aidhelp::run()`.

### `gradlevels`

When not set: none.

Comma list of level ids whose students are graduate students. Read by `moneyreports::tax1098()` for box 9 and by `aidhelp::is_graduate()`.

### `payinfo`

When not set: empty.

How to pay. Shown by `local/studentrecords/account.php` while something is owed, and printed by `billing::statement_pdf()`.

### `statementnote`

When not set: empty.

Note printed by `billing::statement_pdf()`.

## Message settings

`local/studentrecords/messages.php` keeps these. [Tasks and messages](tasks-and-messages.md#the-switch-and-the-text) says how the sending uses them.

| Key | Meaning | Read by |
|---|---|---|
| `msgon_<key>` | The message's switch. Anything but `0` is on, so a new message is on with no setting. | `messages::is_on()` |
| `msgsubject_<key>` | The school's own subject. Empty means the default string `msgdef_<key>_subject`. Text equal to the default is stored as empty, so a better default in a later release still applies. | `messages::text()` |
| `msgbody_<key>` | The school's own text, with the default string `msgdef_<key>_body`. It is meant to be stored as empty when it equals the default, as the subject is, but it never equals it: see the known problem below. | `messages::text()` |
| `msgtestmode` | `1` = test mode. | `messages::test_mode()` |
| `msgtestinbox` | The school's test inbox. `messages::test_inbox()` ignores a value that isn't an email address. | `messages::test_inbox()` |

`<key>` is one of the 22 keys of `messages::keys()`.

> Known problem in 1.4.6: the browser sends the text's line breaks as CR LF, and every default text has line breaks, so a saved text never equals its default. `msgbody_<key>` keeps whatever was saved, even when only `msgon_<key>` changed. See [Bugs in this release](../pitfalls.md#bugs-in-this-release).

## The start of payment recovery

`recoverfrom` is the time from which `payments::recover()` looks for online payments that never reached an account. No screen shows it. `xmldb_local_studentrecords_install()` sets it on a new site and upgrade step 2026111607 sets it on an old one, keeping a value that is there. `payments::recover()` sets it to now when it finds none, and records nothing on that run. Those three use `set_config()` directly.

Don't move it back: an older `{payments}` row with no account line is no sign of a lost delivery, because staff may have entered that money by hand. `test_install_sets_the_start_of_the_recovery`, `test_last_step_adds_the_indexes`, `test_recover_leaves_payments_from_before_it_started` and `test_recover_without_a_start_time_starts_now` protect this.

## Keys built at run time

A search for one of these keys finds nothing, because the code builds the name.

| Key | Built in | Notes |
|---|---|---|
| `msgon_<key>` | `messages::is_on()`, `local/studentrecords/messages.php` | |
| `msgsubject_<key>`, `msgbody_<key>` | `messages::text()` as `'msg' . $part . '_' . $key`, and `local/studentrecords/messages.php` | |
| Any module name | `catalog::module_on()`, with `fedaid` and `f1` from the code and also `billing` from the summary step of `local/studentrecords/setup.php` | `'1'` is on |
| Each key of `catalog::SCHOOL` | `local/studentrecords/setup.php` | Only the keys of the step shown |
| `$d['field']` | `drafts::existing()`, `drafts::apply()` | A key of `importer::SCHOOLFIELDS`, or `withdrawpct` |
| `payinfo`, `statementnote` | `billing::statement_pdf()`, in a short loop | |

## Settings of other plugins

The plugin reads two settings of Academic Transcript, and only when that plugin's code is on disk (`\core_component::get_component_directory()`). With it there they apply whatever its "Source of the transcript" setting says.

| Key | Read by | Without the plugin | With it, when not set |
|---|---|---|---|
| `local_academictranscript`, `roundpercent` | `catalog::round_percent()` | No rounding | On, unless the value is `0` |
| `local_academictranscript`, `repeatrule` | `catalog::repeat_rule()` | `all`: every attempt counts | `latest` |

`grading::letter_for_pct()` defaults `$round` to true, so always pass `catalog::round_percent()`.

Uninstall changes two settings of Moodle's: `defaulthomepage` when it points at `home::URL`, and any `user_home_page_preference` with that value.

## Settings from an import

`importer::SCHOOLFIELDS` maps a field label in a sheet to a key. `importer::read_school()` turns `fedaid` and `f1` into `1` or `0`, turns `calendartype` into one of `importer::CALENDARS`, blanks an `accreditor` that says there is none, and can add `state` from the address. `importer::finish_withdraw()` adds `withdrawpct` when an academic rule states one. An approved draft goes through `records::set_setting()` with source `import`. `drafts::fix()` lets staff fix the value and never the key: the key comes from the importer's own list.

## Adding a setting

For a school setting:

1. Add `'name' => ['type', 'step']` to `catalog::SCHOOL`.
2. Add the string `set_name`, in A-Z order, and `set_name_help` if it needs help. `school::definition()` adds the help button when that string exists.
3. Read it with `catalog::setting('name')`, and put the default at the read.
4. If a school's file can hold it, add its wordings to `importer::SCHOOLFIELDS`.
5. Add it to this page. `bin/check-docs` fails until a key of `catalog::SCHOOL`, or any key the code reads with `catalog::setting()`, is named in the docs.

The form and the save need no change. A new type needs a branch in `school::definition()` and a check in `school::validation()`.

For a billing setting, add the element to `billingsettings`, its current value to the `set_data()` call in `local/studentrecords/billingsetup.php`, and a `records::set_setting()` line to the save there. The form has no list to read from.

Don't rename a key, because sites keep values under it. And a key read through `catalog::module_on()` must store `1`, not a word.

## Related pages

- [Code map](README.md)
- [Access](access.md)
- [Data model](data-model.md)
- [Tasks and messages](tasks-and-messages.md)
- [Flows: money](flows-money.md)
- [Extending](extending.md)
