# Calculations

This page says exactly how each figure of Student Records is worked out, where it lives, and which test protects it. Read it before you change anything that a transcript, a statement or a federal report prints.

Open an issue before you change how grades, GPA, quality points or repeat rules are worked out. Transcripts are official records.

## Contents

- [What must never change](#what-must-never-change)
- [Rounding](#rounding)
- [GPA, quality points and hours](#gpa-quality-points-and-hours)
- [Passing a course](#passing-a-course)
- [A letter from a percent](#a-letter-from-a-percent)
- [Repeat rules](#repeat-rules)
- [Degree audit](#degree-audit)
- [Honors](#honors)
- [Academic standing and SAP](#academic-standing-and-sap)
- [Full-time status](#full-time-status)
- [Attendance totals and clock hours](#attendance-totals-and-clock-hours)
- [Dates, windows and the last day to withdraw](#dates-windows-and-the-last-day-to-withdraw)
- [Tuition and fees](#tuition-and-fees)
- [Refund rules](#refund-rules)
- [Payment plan arithmetic](#payment-plan-arithmetic)
- [Aid figures](#aid-figures)
- [Report figures](#report-figures)
- [Related pages](#related-pages)

## What must never change

| Rule | Why | Test |
|---|---|---|
| Official figures are worked out in whole numbers | PHP 8.4 dropped the pre-rounding in `round()`. With plain `round()`, 41.7 quality points summed in floats over 12 hours printed 3.47, and a course at 17.9 of 20 got a B+. The same record must give the same figure on every PHP version | `test_totals_are_exact`, `test_times`, `test_amounts_are_exact`, `test_measures_are_exact`, `test_rate_is_exact`, `test_r2t4_is_exact`, `test_letter_matches_the_transcript` |
| The cumulative GPA comes from exact quality points | An average of term GPAs gives a different number. `registration::totals()` sums every row in one go | `test_history_counts_grades_transfers_and_drops` |
| W and IP are states, never letters | `registration::history()` shows `W` for a withdrawn row whatever the scale says. `IP` exists only in `srsource::attempts()`. Neither earns or counts in a GPA | `test_a_withdrawn_retake_replaces_nothing`, and `test_a_withdrawal_or_a_course_in_progress_counts_in_nothing` in `local/academictranscript/tests/local/srsource_test.php` |
| The record and the transcript leave out the same attempts | `registration::mark_repeats()` asks Academic Transcript's calculator, so there is one rule | `test_student_records_and_the_transcript_agree_on_repeats` in `local/academictranscript/tests/local/srsource_test.php` |
| The record and the transcript earn the same hours | `srsource::attempts()` reads `passed` and `points` from `registration::history()`, so `registration::result_passes()` and the minimum passing grade rules decide both | `test_a_grade_below_the_program_minimum_earns_nothing` in the same file |
| What was issued stays as issued | `gradapp.auditsnapshot`, `letter.content`, a confirmed `stand` row and `refund.details` each keep the figures of their moment. A later change to the record never changes them | `test_run_and_confirm`, `test_issue` |
| Account lines are never changed or removed | A statement printed last year must still add up. A mistake gets a reversing line | `test_reverse` |

Repeat, letter and earned rules run on both plugins, so a change here needs [Integration](integration.md) too.

## Rounding

| Helper | What it does | Used for |
|---|---|---|
| `grading::divide_rounded()` | Whole numbers, a half going away from zero: `intdiv(2 * abs($n) + $by, 2 * $by)`, with the sign back | GPA, standing percents, attendance rate |
| `billing::divide()` | The same arithmetic | Money, aid percents |
| `billing::cents()` | `(int)round($amount * 100)`, for amounts with at most 2 decimals | Every money comparison |
| `billing::times()` | `divide(cents($amount) * cents($factor), 100 * $per) / 100`: a rate times hours, or with `$per` 100 a percent of an amount | Hourly charges, refunds, down payments |
| `plans::amounts()` | Shares in whole cents, the leftover on the last payment | Plan payments |
| `registration::totals()` | Hours in hundredths, quality points in ten-thousandths | GPA |
| `standing::measures()` | Percents from whole hundredths | Completion, time frame |
| `attendance::summary()` | The rate from whole numbers | Attendance rate |
| `aid::share_percent()` | `divide(intdiv(done * 10000, total), 10) / 10` | Percent of a term |
| `aid::past_sixty()` | `done * 10 > total * 6`, whole numbers | The 60% point |
| `aid::ninety_ten()` | `divide(cents(federal) * 10000, cents(all)) / 100` | 90/10 percent |

Never replace any of these with `round()` on a product or a quotient.

`calculator::divide_rounded()` in Academic Transcript is the same arithmetic again, because each plugin must work without the other.

### Where `round()` stays

`round()` is safe where the value can't sit near a half, or where the figure is only shown. These are the kinds of place, and each is left as it is on purpose.

| Place | Why it is safe |
|---|---|
| `billing::cents()`, and `(int)round(... * 100)` in `registration::totals()` and `standing::measures()` | The value has at most 2 decimals, so the product is next to a whole number |
| `(int)round($earnedpct * 10)` in `aidhelp::r2t4()` | The value has 1 decimal |
| `round($amount, 2)` in `billing::insert_line()`, `billing::balance()`, `billing::with_running()` | Sums and copies of amounts that already have 2 decimals |
| `round($pct, 4)` in `registration::write_grade()` | Only for the stored `reg.gradepct`. The letter was chosen before it |
| `grading::letter_for_pct()` | `round(round($pct, 10))` drops a float error first, so 89.499999999999986 reads as 89.5 |
| `round(... , 2)` for `years` in `standing::measures()` | A number of years compared with a maximum, not printed on a transcript |
| `round($days * $percent / 100)` in `dates::withdraw_ymd()` | A whole number of days for a date staff can change |
| `attendance::report()`, `record_view::render()`, `cards::student_numbers()` | Means of rates, shown only |

`reports::rate()` is `floor($part * 1000 / $whole + 0.5) / 10`: half up in floats, for reports only.

## GPA, quality points and hours

`registration::totals()` takes rows from `registration::history()`. For each row with status `completed`, `withdrawn` or `registered`:

```php
// local/studentrecords/classes/local/registration.php, totals()
$hours = (int)round((float)$r['hours'] * 100);
$attempted += $hours;
if ($r['status'] !== 'completed' || ($r['letter'] ?? '') === '' || !empty($r['repeatexcluded'])) {
    continue;
}
if (($r['passed'] ?? null) !== false) {
    $earned += $hours;
}
if (($r['points'] ?? null) !== null) {
    $gpahours += $hours;
    $qualitypoints += (int)round((float)$r['points'] * 100) * $hours;
}
```

The results are `attempted`, `earned` and `gpahours` over 100, `qualitypoints` over 10000, and `gpa`: `grading::divide_rounded($qualitypoints, $gpahours) / 100`, or null with no GPA hours. Quality points in ten-thousandths over hours in hundredths is the GPA in hundredths, so a GPA halfway between two hundredths rounds up on every PHP version.

For example, 1 hour at 4.0 and 3 hours at 3.3 are 40000 and 99000 quality points, over 400 hours, which is 347.5, so 348 and a GPA of 3.48. Through a float and `round()`, PHP 8.4 gives 3.47. `test_totals_are_exact` holds this.

| Row | Attempted | Earned | In GPA |
|---|---|---|---|
| `completed`, passed, a letter in the GPA | Yes | Yes | Yes |
| `completed`, `passed` false | Yes | No | Yes, with its points |
| `completed`, a mark that earns (no points) | Yes | Yes | No |
| `completed`, a mark that doesn't earn | Yes | No | No |
| `completed`, a grade not on the scale (`passed` null) | Yes | Yes | No |
| `completed`, `repeatexcluded` | Yes | No | No |
| `withdrawn` | Yes | No | No |
| `registered` | Yes | No | No |
| `dropped` | No | No | No |
| Active transfer credit | Yes | Yes | When `ingpa` is set and its grade is a letter in the GPA |

A grade not on the scale earns here, because `passed` is null and the test is `!== false`. Only the transcript stops such a grade.

`registration::history()` fills the rows:

- `letter`: `W` for a withdrawn row, whatever is on the scale, else the grade.
- `points`: for a completed row with a grade, the scale's points when `ingpa` is set and the points aren't null, else null.
- `passed`: for a completed row with a grade, `registration::result_passes()`, else null.
- `repeatexcluded`: from `registration::mark_repeats()`, over every row that isn't dropped.

It returns:

| Key | Holds |
|---|---|
| `terms` | Per term: `termid`, `term`, `rows` (every row, dropped ones too) and `totals` from that term's rows |
| `transfers` | Active `local_studentrecords_xfer` rows: `hours` from `credits`, status `completed`, `letter` the grade or `TR`, `passed` true, and `points` as above |
| `cumulative` | `registration::totals()` over all rows that aren't dropped, and transfers |
| `transfertotals` | `registration::totals()` over transfers alone |

A transfer's `countsprogram` plays no part here. It matters only in the degree audit.

`registration::totals()` counts `registered` rows as attempted. `standing::compute()` takes them out first, so `attempted` in the history and in a standing can differ.

## Passing a course

`registration::result_passes()`, in order:

1. A mark, a scale row with `points` null: its `earns`. `earns` is read only for marks. On a letter it does nothing.
2. The rule for this student and course, from `grading::effective_rule()`. It looks at every `local_studentrecords_minpass` row with the program's level, the program, the groups of the course in the program's outline, and the course. The most specific rule wins: course, then group, then program, then level.
3. `grading::passes()`, for that rule. With `minletter`, the grade must not be below that letter in `grading::ordered_letters()`. With `minpct`, the letter's own `minpct` must reach the rule.
4. No answer: a letter on the scale with points passes when its points are above 0.
5. Otherwise null.

`grading::passes()` with `minpct` compares the letter's band, not the student's percent. A rule at 75 fails everyone with a letter whose band starts at 70, even a student at 79.

A mark is a row with `points` null, not a row with `ingpa` 0. A row with points and `ingpa` 0 isn't a mark here, though `srsource::scale_rows()` treats it as one on the transcript side.

`registration::has_passed()`, for prerequisites: any completed attempt whose result isn't `false`, or active transfer credit with this course as `equivcourseid`.

`test_effective_rule_and_passes` in `local/studentrecords/tests/local/grading_test.php` and `test_pass_rules_are_read_fresh` hold this.

## A letter from a percent

`grading::letter_for_pct()` takes the rows with a `minpct`, highest first.

1. With rounding, the percent becomes `round(round($pct, 10))`. A course total can arrive as 89.499999999999986 for 89.5, so the float error goes first.
2. The first row with `$pct >= minpct - 0.00001` wins. So without rounding, a grade in a gap between two ranges gets the lower letter.
3. No row gives null.

`catalog::round_percent()` decides rounding: Academic Transcript's setting `roundpercent` unless it is `0`, and off without that plugin. `$round` defaults to true, so always pass `catalog::round_percent()`.

This is the rule of `calculator::letter()`, so the two give the same letter on the same scale. `test_letter_matches_the_transcript` holds that, with a data provider of boundary values:

```php
// local/studentrecords/tests/local/registration_test.php, boundary_provider()
'a half rounds up' => [89.5, 'A', 'B'],
'a half with a float error' => [89.499999999999986, 'A', 'B'],
'just under a half' => [89.4999, 'B', 'B'],
'on the minimum' => [90.0, 'A', 'A'],
'a float error under the minimum' => [89.999999999999986, 'A', 'A'],
```

The second value is the letter with rounding and the third is the letter without.

`registration::moodle_percent()` is `(finalgrade - grademin) / (grademax - grademin) * 100` of the course total, not rounded, or null with no item, no grade or a zero range. `grademin` and `grademax` are the course total item's, not the student's own `rawgrademax`, so with Moodle's Natural aggregation and an item not yet graded the percent comes out too low, such as half the real one. [Bugs in this release](../pitfalls.md#bugs-in-this-release) lists this. The letter is chosen from that exact number, and `reg.gradepct` keeps it to 4 decimals. Don't work a letter out again from the stored value. `test_letter_from_the_gradebook_uses_the_exact_percentage` holds this.

## Repeat rules

`catalog::repeat_rule()` is `all` without Academic Transcript. With it, the rule is its setting `repeatrule` when that is `highest` or `all`, else `latest`. The rule applies as soon as the sibling is on disk, whatever its "Source of the transcript" setting says, and an unset rule means `latest`.

`registration::mark_repeats()`:

1. Sets `repeatexcluded` false on every row. Stops when the rule is `all`.
2. Orders the terms by start date, then id.
3. Gives the calculator the rows with status `completed` or `withdrawn` as attempts, with `code`, `grade` (the letter), `hours`, `group` (term), `order` and `time`. `registered` rows aren't given, so a retake in progress replaces nothing.
4. Uses `srsource::calculator()` when it exists, else `scale::calculator()` from an older sibling.
5. Copies `excluded` from each result.

In `calculator::compute()`, attempts of the same code, ignoring case, are repeats when the grade is a letter or a mark that earns. So a `W` is never in a repeat group.

| Rule | Keeps |
|---|---|
| `latest` | The attempt with the highest order, then time |
| `highest` | The most grade points, and on a tie the later attempt. A mark that earns ranks below every letter |
| `all` | Every attempt |

An attempt left out counts as attempted, not earned, not in the GPA, and meets no requirement in the audit. A retake under the rule `all` isn't pending for conditional approval, and the maintainers left that as it is.

`registration::mark_repeats()` changes the row objects it is given, and its result depends on the rows given. `standing::compute()` calls it again on a subset for that reason. Don't pass it transfer rows: it needs `code`, `startdate` and `termid`.

`test_repeat_rule`, `test_history_counts_the_latest_attempt`, `test_history_counts_the_highest_attempt`, `test_history_counts_every_attempt`, `test_a_retake_in_progress_replaces_nothing` and `test_a_withdrawn_retake_replaces_nothing` hold this.

## Degree audit

`degreeaudit::run()` gives null with no program. `degreeaudit::evaluate()` needs no database, so it tests without a site.

### What counts

Requirements come from `local_studentrecords_progcrs`, in `sortorder`. The state of each course comes from the history:

- A `repeatexcluded` row is skipped.
- `completed` with a grade: `notpassed` when `passed` is false, else `met`.
- `registered`: `inprogress`.
- A withdrawn or dropped row sets no state.
- For a course with several rows, the best state wins: `met`, then `inprogress`, then `notpassed`.

`degreeaudit::substitutions()` handles substitutions: when the substitute course has a state and the required course is in the outline, the required course takes that state with `source` `subst`. The state is copied as it is, so an in-progress substitute is in progress. Substitutions run before transfer credit is added, so only a registration can be a substitute. A substitution counts the required course's hours, not the substitute's.

Transfer credit counts only from rows with `countsprogram`:

| Case | Effect |
|---|---|
| An equivalent course in the outline | That course becomes `met` with source `transfer` |
| An outline, and no equivalent in it | The credits go to `extra` |
| No outline, and no equivalent, or an equivalent not counted yet | The credits go to `extra` and the course is counted |
| No outline, and the equivalent counted already | Nothing: a course and its transfer equivalent count once |

`atschool` with an outline is the cumulative earned hours less transfer earned hours, in any unit, inside the outline or not. With no outline, it is the sum of the courses of the program's unit whose state is `met`, each once.

With no outline, `extra` also adds `atschool`, and `pendinghours` is the hours of in-progress courses of the unit not counted yet.

`program.trackhours` and `program.maxtime` play no part in the audit.

### `degreeaudit::evaluate()`

- Each group: `listed`, `met` and `inprogress` are sums of course hours from the outline.
- Elective group: needs `degreeaudit::group_hours()`, a number before "credit", "credits", "credit hours", "hours" or "clock hours" in the group's name. With none stated, it is complete with nothing in it.
- Required and track groups: every course `met`.
- Track: of the `track` groups, the one with the best `[complete, met + inprogress]`. The other tracks are left out of `earned`.
- `earned`: `extra` plus `met` of the counted groups. `inprogress` likewise. A course listed in two groups counts in both.
- `hoursok`: no total, or `earned + 0.001 >= total`.
- `gpaok`: no minimum, or `gpa + 0.00001 >= mingpa`, on the cumulative GPA.
- `missing`: holds `audit_groupnotmet`, `audit_hours` and `audit_gpa`.
- `complete`: nothing missing.

### `degreeaudit::run()` after that

1. Adds `pendinghours` to `inprogress`.
2. Evaluates again with in-progress courses as `met`, for `completeifpassed`.
3. `degreeaudit::resident_check()` against `program.residenthours`. A shortfall adds `audit_resident` and clears `complete`.
4. `auditable` is an outline, total hours above 0, or a minimum GPA. Not auditable adds `audit_nothingtocheck`, and such a program is never complete.

`local/studentrecords/tests/local/degreeaudit_test.php` holds all of this. The tests named `test_no_course_list_*` hold the case with no outline, and `test_an_attempt_left_out_meets_no_requirement` holds repeats.

## Honors

`graduation::parse_honors()` reads the setting `honors`: parts split by `;` or new lines, each `<gpa> <name>`, best first. `graduation::honors_for()` gives the first rule with `gpa + 0.00001 >= min`. It only fills a box on the conferral form, and staff can change it. `test_honors` holds this.

## Academic standing and SAP

`standing::rule()` finds the active rule of the level, else the active school-wide rule, else null.

`standing::compute()` for a student and term:

1. Rows from terms whose `startdate` isn't after this term's. Only `completed` and `withdrawn`, so courses in progress aren't measured.
2. `registration::mark_repeats()` on those rows, so a later retake doesn't change an earlier term. `test_a_later_retake_leaves_an_earlier_term_as_it_was` holds this.
3. Adds all transfers, with no date filter.
4. `registration::totals()`, then `standing::measures()`.
5. The previous standing: the last `confirmed` one of an earlier term.

`standing::measures()`:

| Measure | How |
|---|---|
| `gpa` | From the totals |
| `completion` | Earned over attempted as a percent to 2 decimals, or null with nothing attempted |
| `timeframe` | Attempted over the program's total hours as a percent to 2 decimals, or null with no total |
| `years` | Term end less first start, over 365.25 days, to 2 decimals |

`standing::evaluate()`:

| Reason | When |
|---|---|
| `gpa` | The rule has `mingpa`, the GPA isn't null, and `gpa + 0.00001 < mingpa` |
| `completion` | The rule has `mincompletion`, completion isn't null, and `completion + 0.00001 < mincompletion` |
| `maxtime` | `years` over `maxyears`, or `timeframe` over `maxtimeframe` |

- No reason gives `good`, whatever came before.
- `maxtime` among the reasons gives `dismissal`.
- Otherwise the standing goes one step down `standing::LADDER` from the previous one (`good` when none), stopping at `dismissal`.

`maxtransfer` plays no part here. Only `student_sections::transfers()` reads it, for a warning. `standing::rule_from_policies()` reads suggested values out of rules written in words.

`test_evaluate`, `test_measures` and `test_measures_are_exact` in `local/studentrecords/tests/local/standing_test.php` hold this.

## Full-time status

The settings are `fulltimehours` (default 12) and `halftime` (default 6), read at each use as `catalog::setting(...) !== '' ? ... : default`. `student.loadtype` is entered by staff and isn't worked out.

`outside::term_load()`: `hours` sums the course hours of `registered` and `completed` rows of the term. `allended` means the term has rows and none of those two. `any` means rows exist. Clock hours aren't converted. Its docblock says withdrawn rows are included, but the code doesn't count them.

`outside::nsc_status()`, in order:

| Status | When |
|---|---|
| `G` | Awarded by the term's end, or status `graduated` |
| `A` | Status `onleave` |
| `W` | Status `withdrawn`, or all ended |
| `F` | `loadtype` `fulltime`, or hours at least full time |
| `H` | Hours at least half time |
| `L` | Otherwise |

The 1098-T box 8 uses `moneyreports::half_time()`: `loadtype` `fulltime`, or at least the half-time hours in one term of the year, counting credit-hour courses with status `registered`, `completed` or `withdrawn`. `local/studentrecords/myregistration.php` prints a hint under full time, counting credit-hour classes only.

`test_nsc_status` and `test_term_load_counts_every_course` hold this.

## Attendance totals and clock hours

`attendance::summary()`, over a registration's marks:

- Counts per status. `marked` is all marks.
- `hours` is the sum of hours attended, rounded to 2.
- `last` is the latest date marked present or late.
- `rate` is `(present + late) * 1000` over `marked - excused`, through `grading::divide_rounded()`, then over 10. One decimal, null when nothing counts. So excused meetings are left out, and a late mark counts as attended.

`attendance::for_reg()` adds:

| Key | What |
|---|---|
| `moodle` | `{user_lastaccess}` for the linked course, by day in the school's time zone |
| `lda` | `attendance::last_date()`: the later of `last` and `moodle`, and `moodle` only with the setting `ldamoodle` on |
| `meetings` | The count of meetings |
| `scheduled` | The sum of `meeting.hours` |

`attendance::save_marks()` stores 0 hours for absent and excused. For present and late it stores `min(meeting hours, max(0, round(hours, 2)))`.

`attendance::student_last()` and `attendance::term_last()` take the latest `lda` over the student's or the term's registrations. The minimum rate is the setting `minattendance`, default 80.

`test_summary`, `test_rate_is_exact`, `test_last_date` and `test_save_marks` hold this.

## Dates, windows and the last day to withdraw

A date setting is stored as a timestamp, and rules compare days as `Y-m-d` text in the school's time zone.

- `catalog::date_to_time()` is noon in the school's time zone, so the date stays the same when shown in a nearby zone.
- `catalog::day_start()` is the start of a day.
- `catalog::time_to_date()` gives `Y-m-d`.

Term dates can sit at different times of day: the date form gives the start of a day, and import gives noon. So compare days through `catalog::time_to_date()`, as `registration::term_days()` does. A hold's `enddate` isn't the date given: `academic::place_hold()` moves it to the last second of that day. `test_a_hold_runs_through_its_last_day` holds this.

`registration::window_problem()` compares `Y-m-d` text, and the last day counts.

| Action | Window | With no add/drop end |
|---|---|---|
| Add | Until `adddropend` | Until the term's end |
| Drop | Until `adddropend` | Until the term's start |
| Withdraw | After `adddropend`, until `withdrawby` | After the term's start, until `withdrawby` |

The add window has no start, because `registration::window_problem()` never reads `term.adddropstart`. [Bugs in this release](../pitfalls.md#bugs-in-this-release) lists this.

`dates::withdraw_ymd()` works out the last day to withdraw. `days` is the calendar days of the term, both ends counted. `offset` is `max(0, round(days * percent / 100) - 1)`, and the date is the start plus `offset`. `catalog::withdraw_percent()` gives the percent: the setting `withdrawpct`, else 60. `local/studentrecords/terms.php` uses it when `withdrawby` is left empty, and `term.withdrawcalc` marks a date worked out this way, so it follows the term's dates until staff pick their own.

`test_window` and `test_withdraw_ymd` hold this.

## Tuition and fees

[The money page](flows-money.md#charges-at-registration) follows the flow. The sums are these.

`billing::line_amount()`: for `credithour` and `clockhour`, `billing::times()` of the item's amount and the course hours. For any other basis, the item's amount rounded to 2 decimals. A price per credit hour applies only to credit-hour courses, and a price per clock hour only to clock-hour courses (`billing::fits_unit()`).

`billing::pick_tuition()` gives one item: the most specific scope (program, then level, then all), and on a tie the oldest id. `billing::auto_fees()` gives every fee that fits.

`billing::net_charged()` above zero skips an item, over these lines: per registration for `credithour`, `clockhour` and `course`, per term for `term`, and over the whole account for `program` and `once`.

`billing::estimate()` uses the same prices for the cart. Per-term and one-time items count once, and hourly items of one price add up into one line.

`test_pick_tuition`, `test_fits_unit`, `test_auto_fees`, `test_line_amount` and `test_times` hold this.

## Refund rules

`refunds::figure()`:

1. Dates as `Y-m-d`: `event`, `registered`, `start`, `adddropend`, `end`.
2. `refunds::pick_rule()`: the first active rule in `sortorder` where `refunds::rule_fits()` says yes.
3. For each of `refunds::refundable_charges()`: `refunds::percent()`, then `billing::times($net, $percent, 100)`.
4. `amount` is the sum, rounded.

`refunds::rule_fits()`, by `refunds::RULETYPES`:

| Type | Fits when |
|---|---|
| `cancel` | The event is on or after registering, and `refunds::business_days()` from registering to the event is at most `value` |
| `beforestart` | The event is before the term's start |
| `adddrop` | There is an add/drop end, and the event is on or before it |
| `pctterm` | `done * 10000 <= cents(value) * length`, with `length` the days from start to end (at least 1), and `done` the signed days from start to event |
| `days` | The event is on or before the start plus `value` days |
| `after` | Always |

`pctterm` is in whole numbers so that a drop exactly on the rule's percent still fits: 7 of 100 days fits under 7%. `test_rule_fits_on_the_exact_percent` holds this.

`refunds::percent()`: no rule gives 0. A non-refundable charge gives 0 unless the rule has `allfees`. Otherwise `tuitionpct` for kind `tuition`, else `feespct`. A charge with no `feeid` has kind `fee`.

`refunds::business_days()` counts Monday to Friday after the first day up to and including the last. It knows no holidays. `refunds::refund_days()` is the setting `refunddays`, default 30.

`test_pick_rule`, `test_rule_fits_term`, `test_percent`, `test_business_days` and `test_figure_is_exact` hold this.

## Payment plan arithmetic

- `plans::amounts()`: the down payment is the smaller of the total and the percent of the total (`billing::times()`) or the amount. The rest splits with `intdiv()` into `numpay` payments, and the leftover cents go on the last.
- `plans::due_days()`, `fixed`: the given dates, sorted, and the count must equal `numpay`.
- `plans::due_days()`, `relative`: the first date is the term's start plus `firstdue` days, which may be below zero. Then every 7 or 14 days, or monthly on the same day, using the last day of a shorter month.
- `plans::allocate()`: fills payments in due order from the money paid.
- `plans::is_late()`: today is after the due date plus the grace days.

```php
// local/studentrecords/tests/local/plans_test.php, test_amounts()
$this->assertSame([412.5, 412.5, 412.5, 412.5], plans::amounts(1650, 'percent', 25, 3));
$this->assertSame([250.0, 266.66, 266.66, 266.68], plans::amounts(1050, 'amount', 250, 3));
```

`test_amounts_are_exact` holds the case that floats got wrong: 1.14 in two parts gave 0.56 and 0.58.

## Aid figures

The Return of aid steps and the loan checks are on [the federal aid page](flows-aid.md). They are helpers, and a school checks them against the official worksheet and the current handbook.

## Report figures

| Figure | How |
|---|---|
| `reports::completion()` rate | Passed over attempted less in progress |
| `reports::graduation()` rate | Graduated over the cohort, leaving out students in `admissions::PIPELINE` |
| `moneyreports::aging()` | Every line below zero pays the oldest lines above zero first. What is left of each charge goes to `d30`, `d60`, `d90` or `older` by whole days since `txndate` |
| `moneyreports::tax_amounts()` | See [the 1098-T data](flows-money.md#the-1098-t-data) |
| `aid::ninety_ten_worksheet()` | See [the 90/10 worksheet](flows-aid.md#the-9010-worksheet) |
| `cards::term_week()` | The week of the term, 1-based, and the number of weeks, or null outside the term |

`test_rate`, `test_enrollment`, `test_graduation` and `test_aging` hold these.

## Related pages

- [Registration and grades flows](flows-registration-and-grades.md)
- [Money flows](flows-money.md)
- [Federal aid flows](flows-aid.md)
- [Integration](integration.md)
- [Academic Transcript calculations](../academic-transcript/calculations.md)
- [Extending](extending.md)
- [Decisions](../decisions.md)
- [Pitfalls](../pitfalls.md)
