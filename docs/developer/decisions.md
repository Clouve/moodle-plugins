# Decisions

This page says why things are the way they are. Each decision says what the maintainers decided and why, and names the code or test that holds it. Read it before you "fix" something that looks odd. If you think a decision should change, open an issue first.

## Contents

- [Licence and copyright](#licence-and-copyright)
- [Grades, GPA and hours](#grades-gpa-and-hours)
- [Who may do what](#who-may-do-what)
- [Mail, locks and transactions](#mail-locks-and-transactions)
- [Money](#money)
- [Upgrades and compatibility](#upgrades-and-compatibility)
- [How the plugins fit together](#how-the-plugins-fit-together)
- [Known and left alone](#known-and-left-alone)
- [Related pages](#related-pages)

## Licence and copyright

### Two plugins are under MIT, with no GPL header

Academic Transcript and Student Records are under the MIT License, so anyone can use, change and share them. That is why they have no Moodle GPL header, and why `bin/check` and CI exclude `moodle.Files.BoilerplateComment`. Never run `phpcbf` on them, because it writes the GPL header in.

Academic Transcript moved to MIT in 1.4.3. Earlier releases stay under the GNU GPL.

### The theme stays under the GPL

Seven of the theme's files adapt Boost, Moodle's own theme, and it keeps Boost's authors. A plugin that adapts a substantial part of Moodle's code is under the GPL as a whole. The theme can move to MIT only if those files are rewritten. [Why the theme stays GPL](theme/extending.md#why-the-theme-stays-gpl) lists them.

### Copyright is Clouve Inc. only

The code of the plugins names Clouve Inc. An outside contributor adds their own `@copyright` line next to it, and leaves the `@license` line alone. The files adapted from Boost keep Boost's authors as well.

### The fonts are renamed

The transcript fonts are Clouve Sans and Clouve Serif, renamed from Source Sans 3 and Source Serif 4, because the SIL Open Font License reserves the name "Source". They weren't named "Transcript", because that name clashes with an existing typeface. `local/academictranscript/fonts/readme_moodle.txt` says how they were made. This is the one exception to "keep bundled third-party code as upstream ships it".

## Grades, GPA and hours

### The cumulative GPA comes from exact quality points

It isn't an average of term GPAs. `calculator::compute()` sums whole numbers and divides once. `registration::totals()` does the same on the record. An average would be wrong when terms have different hours, and a sum of rounded rows would drift.

### Everything rounds exactly, half up, on every PHP version

GPAs, quality points, letters and hours round exactly, half up. PHP 8.4 dropped the pre-rounding in `round()`: with plain `round()`, 41.7 quality points summed in floats over 12 hours printed 3.47, and 17.9/20 got a B+. A transcript is an official record, so the same record must give the same figures on any server. That is why the plugins have their own exact rounding helpers. [Pitfalls](pitfalls.md#exact-rounding-on-every-php-version) lists them.

### With Student Records as the source, the transcript follows Student Records

The transcript follows Student Records on earned credits, where the minimum passing grade rules of Student Records decide them, on transfer credit marked "in GPA", and on one grading scale, the one in Student Records. `srsource::calculator()` counts on that scale, with `earns` and `points` per attempt from `registration::history()`.

The reason: a school must not get two answers to "what is this student's GPA". The maintainers ran a fuzz test of record against transcript, which found 0 mismatches. That fuzz test isn't in this repo. `test_student_records_and_the_transcript_agree_on_repeats` and `test_fractional_hours_agree` hold the rule.

### Grade arithmetic stays in Academic Transcript

`srsource` reads from Student Records, and the sums stay in `calculator`. Student Records calls that calculator for repeats. One piece of code decides what a repeat is, so the two can't leave out different attempts.

### W and IP are states, never letters

A withdrawal and a course in progress aren't grades. `registration::history()` shows `W` from the status, and `srsource::attempts()` makes `IP` from the status, whatever the scale holds. A school may add W to its scale with 0 points, and `srsource::is_state()` still keeps it from earning.

### Student Records follows the repeat rule of Academic Transcript

With Academic Transcript on disk, Student Records follows its repeat rule by calling its calculator, in `registration::mark_repeats()`. Without it, every attempt counts. This doesn't depend on the `source` setting, because the rule is about how a school counts a retake, and the plugin that has the setting owns it.

### Issued transcripts stay as issued

A later change to the record never changes a frozen copy. `transcript::issue()` stores the data in `local_academictranscript_issue.snapshot`, with the key of that day. An Official transcript is a record of what was true when it was issued.

### Staff can correct a grade until the term's grade freeze

That is a design, not a gap. An earlier review "fixed" it, and that had to be put back. Until the freeze staff use "Enter final grade". After it, a change needs a second person, through `academic::request_change()` and `academic::decide_change()`. `test_staff_correct_a_grade_until_the_freeze` holds it.

## Who may do what

### Nobody acts on their own record

Nobody acts on their own grade, degree, status, award, program, Student Records transfer rows, substitutions, admission decisions or Official transcript, and refunds can't be self-approved. Staff often have a file of their own, so a capability isn't enough. The checks are in the classes, so every path meets them. `access::is_own()` and `transcript::issue()` are the starting points.

### Staff may keep some of their own items

Staff may keep their own transfer credit in Academic Transcript, their own holds and their own standing overrides. The maintainers looked at these and left them allowed. Don't add a check without an issue.

### Holds stop every way of issuing

A hold stops an Official transcript on every path, because `transcript::issue()` calls `requests::holds()`, and the student is told that the registrar sends it when the hold is released. A student with a hold can still ask.

### The Counts tab needs the view demographics capability

The counts on `local/studentrecords/outsidereports.php` show students by category and sex, so they need `local/studentrecords:viewdemographics`. The grid is empty without it.

### Attendance by section stays as it is

A teacher's attendance across linked sections isn't joined up. Each section keeps its own meetings and marks.

## Mail, locks and transactions

### Nothing sends mail inside a transaction or a lock

`messages` queues the ad-hoc task `send_message`, and the log goes queued, then sent or failed. `$DB->is_transaction_started()` and `registration::holds_lock()` decide. A mail server that hangs must not hold up a commit or anyone waiting for the lock, and one message that fails must not lose the others. The task is saved with the transaction, so work that's rolled back sends nothing.

### Commit after the try block

`records::commit()` in Student Records and `logger::commit()` in Academic Transcript run after the `try` block. Moodle sends held-back messages during the commit, so a failed send comes after the data is saved and must not reach the `catch`. The work stands, and the log says what happened.

### Lock order

Section before registration, and nothing is taken under `accounts`. A fixed order means two requests can't wait on each other. Some locks must outlast their commit, so that a request that waited sees what was saved. [Pitfalls](pitfalls.md#locks-that-must-outlast-their-commit) says which.

## Money

### `recover_payments` runs every 5 minutes

Moodle's payment system delivers a payment once and doesn't try again. So `recover_payments` records `{payments}` rows with no account line. It works only from `recoverfrom` on, so a site's history is never touched: an older payment with no line may be money that Finance entered by hand.

### `erase_removed_accounts` runs hourly

It finishes approved deletion requests for removed accounts. A privacy officer approved each of them, but on a site with data registry defaults Moodle's own task can't reach this plugin's data once the user context is gone.

### One active payment plan at a time

A student has one active payment plan, for any term (`plans::create()`). Two plans would each claim the same payments.

## Upgrades and compatibility

### `$plugin->version` looks like a future date

It must stay above the builds that schools already ran before the first public release (`2026111604`, `2026111606` and `2026110303`), or those sites can't upgrade. So those numbers look like future dates on purpose.

### Uninstalling keeps the course fields

Uninstalling Academic Transcript keeps the Course code, Hours and Hour type course fields, relaxed, and their values. The codes and hours stay on the courses, and teachers can edit them after the plugin is gone (`test_uninstall_lets_teachers_edit_the_kept_fields`).

### Older snapshots stay readable where they can

Sites of older builds have older snapshots of issued transcripts. A snapshot with no `version` and no `terms` can't be drawn again, and `local/academictranscript/issued.php` says so. Version 2 is brought up by `transcript::stored()`. The repo's version 1 equals an old build's version 3. So never read the layout from the version number alone.

### One template for four versions

The theme keeps few copies of Boost's files, because those change between versions. The login form and the user menu are Moodle's own, so each version shows its own. The top bar and the drawers are the theme's own templates, with both forms of what differs. See [Compatibility](compatibility.md#how-the-code-handles-a-difference).

### The tabs stay on core/moremenu on Moodle 5.3

Boost 5.3 renders its tabs with React, through `core/primarymoremenu` and `core/secondarymoremenu`. The theme stays on `core/moremenu`, which all four versions have, so the tabs keep the school's colour.

### One accent colour check, twice

The accent colour goes into SCSS, so `setting_accentcolour::write_setting()` checks it on save and `brand::accent()` checks it again on read. A value set in the database or in `config.php` skips the form. The contrast limit of 4.5 to 1 is WCAG 2.1 AA.

## How the plugins fit together

### Each plugin stays optional

A school can run any one of them. So no plugin lists another in `$plugin->dependencies`, and every call is behind a check. Each plugin has its own version, tag and ZIP.

### Two local plugins, two locks, two logs

Academic Transcript also works without Student Records, so it has its own lock type, its own log and its own commit helper, and it repeats the `sr-` styles it needs. A shared library would make one plugin depend on the other.

### Docs at the repo root

The docs live in `docs/`, not in the plugin folders, so the screenshots stay out of the install ZIPs.

## Known and left alone

The maintainers looked at these and left them. [Pitfalls](pitfalls.md#known-and-left-alone) has the details.

- Pages that end a branch in `die()`, so a removed guard could end a PHPUnit run with exit 0.
- Moodle's welcome mail still goes at the commit under the section lock.
- A closed plan doesn't reopen by itself after the other plan is cancelled.
- A retake under repeat rule `all` isn't pending for conditional approval.
- A hung-mail run was proved only on PostgreSQL.
- Core's `enrol_fee` raises a privacy error, so the officer gets a notice during a deletion.
- The Transcript Key contact line shows placeholders until the school edits it.

## Related pages

- [Pitfalls](pitfalls.md)
- [Architecture](architecture.md)
- [Compatibility](compatibility.md)
- [Release](release.md)
