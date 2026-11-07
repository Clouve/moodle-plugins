# Calculations

This page covers every sum on a transcript: the letter from a percentage, quality points, GPA, repeated courses, hours, transfer credit, and what changes with Student Records as the source. Each rule names the test that protects it, and `CONTRIBUTING.md` asks for an issue before you change any of them.

## Contents

- [Where the arithmetic lives](#where-the-arithmetic-lives)
- [Rules to keep](#rules-to-keep)
- [Attempts: what goes into the sums](#attempts-what-goes-into-the-sums)
- [Letter from a percentage](#letter-from-a-percentage)
- [Repeated courses](#repeated-courses)
- [Hours](#hours)
- [Quality points](#quality-points)
- [GPA](#gpa)
- [Totals of a term and of the record](#totals-of-a-term-and-of-the-record)
- [Transfer credit](#transfer-credit)
- [With Student Records as the source](#with-student-records-as-the-source)
- [Notes under a row](#notes-under-a-row)
- [How numbers print](#how-numbers-print)
- [Problems that stop an Official transcript](#problems-that-stop-an-official-transcript)
- [Related pages](#related-pages)

## Where the arithmetic lives

| Code | Job |
|---|---|
| `calculator::compute()` in `local/academictranscript/classes/local/calculator.php` | All sums: repeats, hours, quality points, GPA, totals |
| `calculator::letter()` | Letter for a percentage |
| `transcript::build()` in `local/academictranscript/classes/local/transcript.php` | Collects the attempts, feeds the calculator, adds transfer credit, writes the summary |
| `scale::calculator()` in `local/academictranscript/classes/local/scale.php` | A calculator on this plugin's scale |
| `srsource::calculator()` in `local/academictranscript/classes/local/srsource.php` | A calculator on the scale of Student Records |
| `scale::calculator_for()` | Adds the repeat rule and the rounding setting to either one |
| `posting::course_percent()` in `local/academictranscript/classes/local/posting.php` | The course total as a percentage |

`calculator` makes no database or Moodle call, so `tests/local/calculator_test.php` extends `\basic_testcase` and tests it with plain arrays. Keep it that way.

## Rules to keep

- The cumulative GPA comes from exact quality points. It is never an average of term GPAs.
- Sums are whole numbers: hours in hundredths, grade points in hundredths, quality points in ten-thousandths. Convert to a float only to return a result.
- A value exactly halfway rounds up, on every PHP version. PHP 8.4 dropped the pre-rounding in `round()`, so plain `round()` of a float such as 3.4749999999999996 gives 3.47 where the right answer is 3.48. That is why `calculator::divide_rounded()` exists. Never replace it with `round()`.
- W and IP are states of a course, never letters. With Student Records as the source a withdrawal and a course in progress never earn or count in a GPA, whatever that scale says about W and IP: `srsource::attempts()` and `srsource::is_state()` see to that. A final grade of W counts as Student Records says, like any other grade. On own data nothing enforces this: W and IP earn nothing only because the standard marks say so.
- An attempt that a repeat rule leaves out keeps its row and its printed quality points, and adds to no total.
- Issued transcripts stay as issued. All of this runs again on each view and each Unofficial PDF, so a change to the scale or to a setting changes every transcript that isn't frozen, old terms included.

## Attempts: what goes into the sums

An attempt is one row of the record: a course in a term with a grade, or IP. `transcript::build()` makes the list, and each attempt reaches `calculator::compute()` as an array:

| Key | Meaning |
|---|---|
| `code` | Course code. Repeats match on it |
| `group` | The term id as a string. Totals group on it |
| `order` | Position of the term, from the term list. An attempt with no term gets -1 |
| `time` | When it happened. It decides between attempts in one term |
| `hours` | Hours of the course |
| `grade` | A letter, a mark or `IP` |
| `earns` | Only from Student Records: whether the attempt earns its hours |
| `points` | Only from Student Records: grade points, or null when it is out of the GPA |

For own data the attempts are:

1. Each row of `local_academictranscript_posted`, in `timeposted`, `id` order, with its stored `hours`, `grade` and `note`. `time` is `timeposted`.
2. Each course from `access::student_courseids()` that has no posted row for its effective term, as an IP attempt. `time` is `PHP_INT_MAX`. Hours come from the course, and missing hours count as 0.

"Being taken now" means only that: the student holds a role of the `student` archetype in the course. Course dates and the end of a term don't matter, so a finished course that nobody posted stays IP.

The effective term is the override in `local_academictranscript_code.termid`, else the course's Term field. See `terms::effective()`.

Attempts with no term go last, under the string `termnotset`. Term blocks follow the term list, by start date, then id.

Tests: `transcript_test::test_build_groups_attempts_by_term_and_sums_totals()`, `terms_test::test_effective_prefers_override()`.

## Letter from a percentage

`posting::course_percent()` gives the course total as a percentage with `grade_grade::standardise_score()`. It returns null when the course has no total item, when the item's maximum isn't above its minimum, or when the student has no final grade. The core method doesn't clamp, so a total above the maximum gives more than 100. It divides by the course total item's `grademin` and `grademax`, not by the student's own `rawgrademax`, so with Moodle's Natural aggregation and an item not yet graded the percent comes out too low, such as half the real one. [Bugs in this release](../pitfalls.md#bugs-in-this-release) lists this.

`calculator::letter()` then does this:

1. With rounding on, the percentage becomes `round(round($percent, 10))`. The inner `round()` drops a float error before the half is judged: the gradebook scales 17.9 out of 20 to 89.499999999999986, and that must count as 89.5. A half rounds up.
2. With rounding off, the percentage stays as it is.
3. The rows are sorted by highest `min`. The first row where `percent >= min - 0.00001` gives the letter.
4. No such row gives null, and the page shows `noscalematch`.

`maxpct` plays no part. So a gap between two rows falls to the lower row, and a total above the top maximum takes the top letter.

The setting `roundpercent` is on unless its value is exactly `'0'`, so an unset value means on. See `scale::calculator_for()`.

The inner `round()` holds for any course whose total has a range below 99,900 points, as the comment in `calculator::letter()` says.

A posting for a student with a file in Student Records takes the letter from `grading::letter_for_pct()` on that plugin's scale. It repeats these steps, and it reads the same setting through `catalog::round_percent()`.

Tests: `calculator_test::test_letter_rounds_then_matches()`, `calculator_test::test_letter_rounds_course_totals_that_end_in_a_half()`, `calculator_test::test_letter_without_rounding()`, `calculator_test::test_letter_does_not_depend_on_row_order()`, `calculator_test::test_letter_returns_null_when_no_row_matches()`, `scale_test::test_calculator_follows_rounding_setting()`.

What gets posted, a hand-entered mark or this letter, is in [posting final grades](execution-flows.md#posting-final-grades).

## Repeated courses

The setting `repeatrule` is `latest`, `highest` or `all`. An empty value means `latest`, and so does an unknown one. Student Records follows the same rule: see [what Student Records uses from this plugin](integration.md#what-student-records-uses-from-this-plugin).

With `all`, nothing is left out. Otherwise `calculator::compute()` works like this:

1. It groups attempts by course code, trimmed and upper-cased.
2. Only attempts with a code, whose grade is a letter or earns hours, join a group. So a mark that earns nothing, as IP, W, NP, I and NC do in the standard marks, is never a repeat and never causes one. A retake in progress leaves the earlier grade counting until the retake has a grade.
3. In a group of two or more, `calculator::better()` keeps one attempt, and the others are excluded.

| Rule | Which attempt stays |
|---|---|
| `latest` | The highest pair of term order and `time`. So a later term beats a later posting |
| `highest` | The highest grade points. A mark that earns, such as P, ranks below every letter, at -1. On a tie, the latest stays |

Tests: `calculator_test::test_repeat_rules()`, `calculator_test::test_repeat_highest_keeps_best_grade()`, `calculator_test::test_repeat_highest_ranks_marks_below_letters()`, `calculator_test::test_repeat_codes_compare_case_insensitively()`, `calculator_test::test_marks_that_earn_nothing_are_not_repeats()`, `calculator_test::test_unknown_repeat_rule_falls_back_to_latest()`, `transcript_test::test_build_marks_repeated_attempts()`.

## Hours

Hours are in whole hundredths inside the calculator: `(int)round((float)$a['hours'] * 100)`.

- Earned: adds an attempt's hours when it earns and isn't excluded. It earns by the `earns` key from the source when there is one, else by the `earns` flag of the letter or mark.
- GPA hours: adds an attempt's hours when it counts in the GPA and isn't excluded.

So F, with `earns` 0 and `ingpa` 1, adds to GPA hours and not to Earned. P adds to Earned and not to GPA hours.

For own data, a grade that is neither a letter nor an active mark earns nothing and counts in nothing, and nothing says so: a posted letter that later leaves the active scale still prints with its hours, but it has no quality points, adds to no total, and raises no problem. The same goes for a mark set to inactive. For a student with a file in Student Records the same case is a problem. See [problems](#problems-that-stop-an-official-transcript).

The unit doesn't change the arithmetic. Credit and clock hours add up the same way, and the student's program only decides the label. `local_academictranscript_posted.unittype` isn't read.

Tests: `calculator_test::test_points_and_flags()`, `calculator_test::test_compute_sums_groups_and_total()`.

## Quality points

For an attempt with grade points:

```php
$qp = $points === null ? null : (int)round($points * 100) * $hours;
```

That line is from `calculator::compute()`. `$hours` is in hundredths here, so `$qp` is in ten-thousandths, and it is exact. The value in `qp` on the attempt is that number rounded once to two decimals.

An attempt counts in the GPA when its letter has `ingpa` 1 and it isn't excluded. A letter with `ingpa` 0 still has points, so it still prints its quality points and adds nothing to a GPA. For an attempt from Student Records, it counts when `points` isn't null.

`calculator::divide_rounded()` rounds every quality point and GPA that the calculator returns:

```php
$quotient = intdiv(2 * abs($number) + $divisor, 2 * $divisor);
return $number < 0 ? -$quotient : $quotient;
```

It rounds a half away from zero, in whole numbers.

Tests: `calculator_test::test_half_cent_quality_points_round_up()`.

## GPA

`calculator::gpa_from_whole()` divides quality points in ten-thousandths by GPA hours in hundredths, which gives the GPA in hundredths, rounded once. It returns text with two decimals, or `''` when there are no GPA hours. Pages print `''` as `-`.

An example from `calculator_test::test_half_cent_gpa_rounds_up()`: an A and three B+ at 3 hours each.

| Step | Value |
|---|---|
| Hours in hundredths | 300 each, 1200 in all |
| Grade points in hundredths | 400, 330, 330, 330 |
| Quality points in ten-thousandths | 120000 + 3 x 99000 = 417000 |
| Quality points printed | 41.70 |
| GPA in hundredths | (2 x 417000 + 1200) / 2400, whole part, = 348 |
| GPA printed | 3.48 |

As floats, the same sum is 41.699999999999996, the division gives 3.4749999999999996, and PHP 8.4 rounds that down to 3.47.

`calculator::gpa()` is a public static helper for the same division from floats. Only the tests call it.

Tests: `calculator_test::test_half_cent_gpa_rounds_up()`, `calculator_test::test_gpa_has_two_decimals()`, `calculator_test::test_gpa_rounds_a_half_up()`, `calculator_test::test_gpa_is_blank_when_the_hours_are_below_half_a_hundredth()`, `calculator_test::test_gpa_of_negative_quality_points_mirrors_the_positive_one()`, `calculator_test::test_gpa_of_one_course_with_fractional_hours_is_its_grade_points()`, `calculator_test::test_total_gpa_with_fractional_hours_uses_the_exact_quality_points()`.

## Totals of a term and of the record

`calculator::compute()` returns `attempts`, `groups` and `total`. A group is one term. Each of those holds `earned`, `gpahours`, `qp` and `gpa`, made by `calculator::totals()` from the whole-number sums. The printed quality points of a term are rounded once from the exact sum, and the GPA divides the exact sum, so rounding never adds up.

`transcript::build()` writes `$data['summary']`:

| Row | Earned | GPA hours | Quality points | GPA |
|---|---|---|---|---|
| `institutional` | attempts | attempts | attempts | attempts |
| `transfer` | all transfer hours | transfer in the GPA only | transfer in the GPA only | always `''` |
| `overall` | the two added | attempts and transfer in the GPA | the same | the same |

The overall figures come from a second `calculator::compute()` over all attempts and the transfer attempts together, so they are exact too. Without transfer in the GPA, `overall` takes the institutional figures.

Tests: `calculator_test::test_compute_sums_groups_and_total()`, `transcript_test::test_build_groups_attempts_by_term_and_sums_totals()`.

## Transfer credit

For own data, `transcript::build()` reads the active rows of `local_academictranscript_transfer`. Each one prints `TR` with no quality points, counts as earned, and adds its hours to the institution's and the record's sums, in whole hundredths. It never counts in a GPA.

For a student with a file in Student Records, `srsource::transfer()` builds the groups from the `transfers` of `registration::history()`:

- A row with grade points, which means Student Records marked it in the GPA and its grade is on the scale, prints its letter and quality points.
- That row also comes back as an attempt with `earns` false, `code` empty and the group `transfer`. The hours are already in Earned, and the attempt adds them to the overall GPA hours and quality points. It never reaches a term, or the institutional row.
- Every other row prints `TR` and only adds hours.
- The Code cell is always empty, because the transfer row in Student Records has no separate code. The external course goes in the title.

Tests: `transcript_test::test_build_adds_transfer_hours_exactly()`, `transcript_test::test_build_keeps_the_transfer_hours_of_each_institution_apart()`, `srsource_test::test_transfer()`, `srsource_test::test_transfer_hours_add_up_exactly()`, `srsource_test::test_transfer_credit_marked_in_gpa_counts_in_the_overall_gpa()`, `srsource_test::test_transfer_credit_not_in_gpa_stays_out_of_the_gpa()`.

## With Student Records as the source

This applies to a student with a file there, when `srsource::enabled()` is true. A student without a file keeps everything above. The transcript and the student's record must show the same figures, so this plugin counts on that plugin's scale and takes that plugin's word on each attempt.

| Question | Own data | Student Records |
|---|---|---|
| Where the attempts come from | Posted rows and courses in progress | `registration::history()`, through `srsource::collect()` and `srsource::attempts()` |
| Which grade each status gives | Not applicable | `completed` with a grade gives the grade, `withdrawn` gives `W`, `registered` gives `IP`. Anything else, such as a drop or a completed row with no grade, is left out |
| Calculator | `scale::calculator()` | `srsource::calculator()` |
| What is a letter | A row of `local_academictranscript_grade` | A row of that scale with points, `ingpa` on, and not `W` or `IP` |
| What is a mark | A row of `local_academictranscript_mark` | Every other row of that scale |
| Whether an attempt counts in the GPA | The letter's `ingpa` | `points` from `registration::history()` isn't null |
| Whether an attempt earns | The `earns` flag | `earns` from `srsource::attempts()`: the row is `completed` and `passed` isn't false. `registration::result_passes()` decides `passed`: a mark by its Earns box, a letter by the minimum passing grade rules of Student Records, or, when no rule decides, by points above 0 |
| Repeat rule and rounding | This plugin's settings | The same settings |
| A grade that isn't on the scale | Silent | A problem that stops an Official transcript |
| Term blocks | Terms of this plugin | Terms of Student Records |
| Transfer credit | Always TR | TR, or a letter that counts in the overall GPA |

In `srsource::calculator()` a letter earns when its points are above 0. A mark with points earns the same way, and a mark without points uses its Earns box. `W` and `IP` never earn. Those flags decide who joins a repeat group and which note a row gets. Whether an attempt really earned comes from the attempt. A grade that isn't on that scale has `passed` null, so its hours count as earned on screen and on an Unofficial copy, while it stops an Official one. [Bugs in this release](../pitfalls.md#bugs-in-this-release) lists this.

The scale of Student Records decides which attempts are repeats, because `is_letter` and `earns` come from it. `registration::mark_repeats()` calls the same `srsource::calculator()`, so the two plugins leave out the same attempts.

Tests: `srsource_test::test_attempts()`, `srsource_test::test_the_transcript_counts_on_the_scale_of_student_records()`, `srsource_test::test_student_records_and_the_transcript_agree_on_repeats()`, `srsource_test::test_repeats_are_decided_on_the_scale_of_student_records()`, `srsource_test::test_a_grade_below_the_program_minimum_earns_nothing()`, `srsource_test::test_a_failing_grade_has_no_note_about_the_minimum()`, `srsource_test::test_a_withdrawal_or_a_course_in_progress_counts_in_nothing()`, `srsource_test::test_a_letter_out_of_the_gpa_is_repeated_like_any_other()`, `srsource_test::test_a_letter_out_of_the_gpa_below_the_minimum_says_so()`, `srsource_test::test_fractional_hours_agree()`.

## Notes under a row

`transcript::build()` writes `notes`, a list of plain strings, in this order:

1. The saved note, when there is one. From Student Records the note is always empty.
2. For a grade that isn't a letter and isn't IP, with no saved note: the mark's label, from `scale::code_label()` or `srsource::mark_label()`. An unknown grade prints its own code here.
3. On an excluded attempt, the string `footnote_repeated`. Otherwise, for a student with a file, the string `footnote_belowminimum` when the attempt earned nothing though its grade would earn.

Tests: `transcript_test::test_build_marks_repeated_attempts()`, `srsource_test::test_a_mark_is_explained_in_the_words_of_student_records()`.

## How numbers print

| Value | Code | Result |
|---|---|---|
| Hours and grade points on a transcript or in the key | `scale::number()` | Two decimals with one trailing zero cut: `3.0`, `3.5`, `5.25`, `1,200.0` |
| Quality points | `number_format($value, 2)` | `9.90` |
| GPA | `calculator::gpa_from_whole()` | `3.48`, or `''`, which prints as `-` |
| Percentages in the key | `scale::percent_text()` | Up to two decimals, no trailing zeros |
| Hours on admin lists | `coursefields::format_hours()`, `format_float($value, 1)` | One decimal, so 5.25 prints as 5.3 on `local/academictranscript/coursecheck.php`, `local/academictranscript/catalog.php`, `local/academictranscript/programs.php` and `local/academictranscript/transfer.php` |

Tests: `scale_test::test_a_number_keeps_the_decimals_it_has()`, `coursefields_test::test_format_hours()`, `builder_test::test_hours_keep_their_decimals()`.

## Problems that stop an Official transcript

`$data['problems']` is a list of plain strings. `transcript::issue()` refuses while it isn't empty, with `cannotissue`. An Unofficial copy prints whatever the sums give.

For own data, each course in progress can add these:

| String | When |
|---|---|
| `problem_nocode` | The course has no code |
| `problem_nohours` | The course has no hours, after the catalog |
| `problem_noterm` | The course has no effective term |
| `problem_unittype` | The course's hour type is set and differs from the student's program |

Posted rows raise none of these: they carry their own copies.

For a student with a file, those four checks don't run. Two others do:

| String | When |
|---|---|
| `problem_postednotinsr` | A row of `local_academictranscript_posted` whose `srregid` and course code both match no attempt there other than IP. `transcript::posted_outside_student_records()` finds it |
| `problem_unknowngrade` | A grade that isn't on the scale there, other than IP and a withdrawal |

Tests: `transcript_test::test_build_lists_problems_for_courses_missing_fields()`, `transcript_test::test_build_flags_unit_type_mismatch()`, `transcript_test::test_issue_refuses_when_problems_exist()`, `srsource_test::test_grade_missing_from_the_scale_stops_an_official_transcript()`, `srsource_test::test_a_posted_grade_missing_from_student_records_is_a_problem()`, `srsource_test::test_a_posted_grade_found_in_student_records_is_no_problem()`, `srsource_test::test_a_final_grade_of_w_off_the_scale_is_a_problem()`.

## Related pages

- [Code map](README.md)
- [Execution flows](execution-flows.md)
- [Data model](data-model.md)
- [Integration](integration.md)
- [Extending](extending.md)
- [Coding conventions](../conventions.md)
