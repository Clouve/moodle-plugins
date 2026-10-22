<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_academictranscript\local;

#[\PHPUnit\Framework\Attributes\CoversClass(scale::class)]
/**
 * Tests for the grading scale and marks.
 *
 * The PHPUnit site starts with the standard scale (12 letters) and the standard marks (P, NP, I, NC, W, TR, IP).
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_academictranscript\local\scale
 */
final class scale_test extends \advanced_testcase {
    /**
     * Start each test with an empty request cache.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        scale::reset_cache();
    }

    /**
     * The ready-made scale has no overlaps or impossible values.
     */
    public function test_validate_rows_accepts_standard_preset(): void {
        $this->assertSame([], scale::validate_rows(scale::standard_preset()));
    }

    /**
     * Each kind of problem in a set of rows gives its own message.
     *
     * @dataProvider validate_rows_provider
     * @param array $rows The grade rows
     * @param array $expected The messages expected, in order: language string id => the value passed to that string
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('validate_rows_provider')]
    public function test_validate_rows_reports_problems(array $rows, array $expected): void {
        $messages = [];
        foreach ($expected as $stringid => $a) {
            $messages[] = get_string($stringid, 'local_academictranscript', $a);
        }

        $this->assertSame($messages, scale::validate_rows($rows));
    }

    /**
     * Data for test_validate_rows_reports_problems.
     *
     * @return array
     */
    public static function validate_rows_provider(): array {
        return [
            'overlapping ranges' => [
                [
                    ['letter' => 'A', 'minpct' => 90, 'maxpct' => 100, 'points' => 4.0],
                    ['letter' => 'B', 'minpct' => 85, 'maxpct' => 91, 'points' => 3.0],
                ],
                ['err_overlap' => 'B / A'],
            ],
            'the same letter twice' => [
                [
                    ['letter' => 'A', 'minpct' => 90, 'maxpct' => 100, 'points' => 4.0],
                    ['letter' => 'A', 'minpct' => 80, 'maxpct' => 89, 'points' => 3.0],
                ],
                ['err_letterdup' => 'A'],
            ],
            'a row without a letter' => [
                [['letter' => ' ', 'minpct' => 0, 'maxpct' => 100, 'points' => 4.0]],
                ['err_letterempty' => null],
            ],
            'a minimum above the maximum' => [
                [['letter' => 'A', 'minpct' => 95, 'maxpct' => 90, 'points' => 4.0]],
                ['err_range' => 'A'],
            ],
            'grade points above 100' => [
                [['letter' => 'A', 'minpct' => 90, 'maxpct' => 100, 'points' => 101]],
                ['err_points' => 'A'],
            ],
        ];
    }

    /**
     * A new scale retires the old rows instead of deleting them.
     */
    public function test_replace_grades_retires_old_rows(): void {
        global $DB;

        $this->assertCount(12, scale::grades());

        scale::replace_grades([
            ['letter' => 'S', 'minpct' => 50, 'maxpct' => 100, 'points' => 4.0, 'ingpa' => 1, 'earns' => 1],
            ['letter' => 'U', 'minpct' => 0, 'maxpct' => 49, 'points' => 0.0, 'ingpa' => 1, 'earns' => 0],
        ]);

        $this->assertSame(2, $DB->count_records('local_academictranscript_grade', ['active' => 1]));
        $this->assertSame(12, $DB->count_records('local_academictranscript_grade', ['active' => 0]));
        $this->assertSame(14, $DB->count_records('local_academictranscript_grade'));
        // The cached scale was dropped, so the new rows show at once.
        $this->assertSame(['S', 'U'], array_values(array_map(fn($row) => $row->letter, scale::grades())));
    }

    /**
     * Marks are matched by code. A new mark goes last, and the system flag of TR and IP cannot be changed.
     */
    public function test_save_marks_updates_by_code_and_keeps_system_flags(): void {
        global $DB;

        $this->assertCount(7, scale::marks());

        scale::save_marks([
            ['code' => 'p', 'label' => 'Passed', 'earns' => 1],
            ['code' => 'XC', 'label' => 'Extra credit', 'earns' => 1],
            ['code' => 'TR', 'label' => 'Transfer', 'earns' => 1, 'systemmark' => 0],
        ]);

        // The codes are compared as they are stored, because a case-insensitive database would match "p" with "P".
        $marks = $DB->get_records('local_academictranscript_mark', null, 'sortorder');
        $codes = array_values(array_map(fn($mark) => $mark->code, $marks));
        $this->assertSame(['P', 'NP', 'I', 'NC', 'W', 'TR', 'IP', 'XC'], $codes);
        $this->assertSame('Passed', $DB->get_field('local_academictranscript_mark', 'label', ['code' => 'P']));
        $extra = $DB->get_record('local_academictranscript_mark', ['code' => 'XC'], '*', MUST_EXIST);
        $this->assertSame('Extra credit', $extra->label);
        $this->assertEquals(1, $extra->earns);
        $this->assertEquals(0, $extra->systemmark);
        $this->assertEquals(8, $extra->sortorder);
        $this->assertEquals(1, $DB->get_field('local_academictranscript_mark', 'systemmark', ['code' => 'TR']));
        // The cached marks were dropped, so the new mark shows at once.
        $this->assertCount(8, scale::marks());
    }

    /**
     * Teachers can enter every mark except TR and IP, which the plugin sets itself.
     */
    public function test_teacher_marks_exclude_system_marks(): void {
        $this->assertSame(['P', 'NP', 'I', 'NC', 'W'], array_keys(scale::teacher_marks()));
    }

    /**
     * The mark labels for a menu are escaped, because a select shows its labels as they are.
     */
    public function test_teacher_marks_are_escaped_for_menus(): void {
        scale::save_marks([['code' => 'P', 'label' => 'Pass & credit', 'earns' => 1]]);

        $this->assertSame('Pass &amp; credit', scale::teacher_marks()['P']);
    }

    /**
     * The label of a mark for a footnote is plain text: an ampersand stays "&". A code that is not a mark comes back
     * as it is.
     */
    public function test_code_label_gives_plain_text(): void {
        scale::save_marks([['code' => 'P', 'label' => 'Pass & credit', 'earns' => 1]]);

        $this->assertSame('Pass & credit', scale::code_label('P'));
        $this->assertSame('B+', scale::code_label('B+'));
    }

    /**
     * The menu for changing a posted grade goes into a select, which shows its labels as they are, so the labels are
     * escaped: the percentage text of a letter (a school can type anything there), the code of a mark, and its label.
     */
    public function test_grade_menu_escapes_labels(): void {
        scale::replace_grades([
            ['letter' => 'A', 'minpct' => 90, 'maxpct' => 100, 'points' => 4.0, 'ingpa' => 1, 'earns' => 1,
                'percentlabel' => '<b>Top</b>'],
            ['letter' => 'F', 'minpct' => 0, 'maxpct' => 89, 'points' => 0.0, 'ingpa' => 1, 'earns' => 0],
        ]);
        scale::save_marks([
            ['code' => 'P', 'label' => 'Pass & credit', 'earns' => 1],
            ['code' => 'X&Y', 'label' => 'Extra', 'earns' => 1],
        ]);

        $menu = scale::grade_menu();

        $this->assertSame('A (&lt;b&gt;Top&lt;/b&gt;)', $menu['A']);
        $this->assertSame('F (Below 90%)', $menu['F']);
        $this->assertSame('P - Pass &amp; credit', $menu['P']);
        $this->assertSame('X&amp;Y - Extra', $menu['X&Y']);
    }

    /**
     * The percentage text of a grade row: its own label, "Below X%" for a row that starts at 0, or "min-max%".
     *
     * @dataProvider percent_label_provider
     * @param array $row Fields of the grade row
     * @param string $expected The text
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('percent_label_provider')]
    public function test_percent_label(array $row, string $expected): void {
        $this->assertSame($expected, scale::percent_label((object)($row + ['percentlabel' => ''])));
    }

    /**
     * Data for test_percent_label.
     *
     * @return array
     */
    public static function percent_label_provider(): array {
        return [
            'a range' => [['minpct' => 93, 'maxpct' => 100], '93-100%'],
            'the lowest row says below' => [['minpct' => 0, 'maxpct' => 59], 'Below 60%'],
            'an own label wins' => [['minpct' => 90, 'maxpct' => 100, 'percentlabel' => 'Honors'], 'Honors'],
            'decimals are kept' => [['minpct' => 89.5, 'maxpct' => 92.25], '89.5-92.25%'],
        ];
    }

    /**
     * The transcript key gives each description once, over the grades that share it, and then one row for each mark.
     */
    public function test_scale_rows_merge_equal_descriptions(): void {
        $rows = scale::scale_rows();

        $this->assertCount(19, $rows);
        $this->assertSame([
            'grade' => 'A', 'points' => '4.0', 'percent' => '93-100%', 'description' => 'Excellent', 'rowspan' => 2,
            'ismark' => false, 'ingpa' => true,
        ], $rows[0]);
        $this->assertSame([
            'grade' => 'A-', 'points' => '3.7', 'percent' => '90-92%', 'description' => null, 'rowspan' => 0,
            'ismark' => false, 'ingpa' => true,
        ], $rows[1]);
        $this->assertSame('B+', $rows[2]['grade']);
        $this->assertSame('Good', $rows[2]['description']);
        $this->assertSame(3, $rows[2]['rowspan']);
        $this->assertSame([
            'grade' => 'F', 'points' => '0.0', 'percent' => 'Below 60%', 'description' => 'Failing', 'rowspan' => 1,
            'ismark' => false, 'ingpa' => true,
        ], $rows[11]);
        $this->assertSame([
            'grade' => 'P', 'points' => get_string('notingpa', 'local_academictranscript'), 'percent' => '',
            'description' => get_string('markdesc_p', 'local_academictranscript'), 'rowspan' => 1, 'ismark' => true,
            'ingpa' => false,
        ], $rows[12]);
    }

    /**
     * The calculator built from the scale rounds the percentage unless the "round percent" setting is "0".
     */
    public function test_calculator_follows_rounding_setting(): void {
        set_config('roundpercent', '0', 'local_academictranscript');
        $this->assertSame('A-', scale::letter(92.6));

        set_config('roundpercent', '1', 'local_academictranscript');
        $this->assertSame('A', scale::letter(92.6));

        // A setting that was never saved rounds too.
        unset_config('roundpercent', 'local_academictranscript');
        $this->assertSame('A', scale::letter(92.6));
    }

    /**
     * Adding the standard marks puts back a missing one, but a mark the school retired stays retired.
     */
    public function test_add_missing_standard_marks_keeps_retired_marks_retired(): void {
        global $DB;

        scale::save_marks([['code' => 'W', 'label' => 'Withdrawn', 'earns' => 0, 'active' => 0]]);
        $DB->delete_records('local_academictranscript_mark', ['code' => 'NC']);
        scale::reset_cache();
        $this->assertSame(['P', 'NP', 'I'], array_keys(scale::teacher_marks()));

        scale::add_missing_standard_marks();

        // NC is back, so the call did run. W is still retired and was not added a second time.
        $this->assertSame(['P', 'NP', 'I', 'NC'], array_keys(scale::teacher_marks()));
        $withdrawn = $DB->get_records('local_academictranscript_mark', ['code' => 'W']);
        $this->assertCount(1, $withdrawn);
        $this->assertEquals(0, reset($withdrawn)->active);
        $this->assertSame(7, $DB->count_records('local_academictranscript_mark'));
    }
}
