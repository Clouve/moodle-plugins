<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(grading::class)]
/**
 * Tests for grading: scale checks, pass rules by level, program, group and course, rules written in words.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\grading
 */
final class grading_test extends \basic_testcase {
    /**
     * A 12-letter 4.0 scale.
     *
     * @return array
     */
    protected static function scale(): array {
        $rows = [];
        foreach (
            [['A', 94, 100, 4.0], ['A-', 90, 93, 3.7], ['B+', 86, 89, 3.3], ['B', 83, 85, 3.0], ['B-', 80, 82, 2.7],
                ['C+', 76, 79, 2.3], ['C', 73, 75, 2.0], ['C-', 70, 72, 1.7], ['D+', 66, 69, 1.3], ['D', 63, 65, 1.0],
                ['D-', 60, 62, 0.7], ['F', 0, 59, 0.0]] as [$l, $min, $max, $pts]
        ) {
            $rows[] = ['letter' => $l, 'minpct' => $min, 'maxpct' => $max, 'points' => $pts, 'ingpa' => 1];
        }
        return $rows;
    }

    /**
     * Letters ordered best first, whatever the input order.
     */
    public function test_ordered_letters(): void {
        $this->assertSame(
            ['A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'F'],
            grading::ordered_letters(array_reverse(self::scale()))
        );
    }

    /**
     * Scale checks find overlaps, gaps and duplicates; a clean scale has none.
     */
    public function test_check_scale(): void {
        $this->assertSame([], grading::check_scale(self::scale()));
        $bad = self::scale();
        $bad[1]['minpct'] = 92;
        $bad[1]['maxpct'] = 95;
        $bad[] = ['letter' => 'A', 'minpct' => 30, 'maxpct' => 40, 'points' => 4];
        $keys = array_map(fn($p) => $p[0], grading::check_scale($bad));
        $this->assertContains('problem_overlap', $keys);
        $this->assertContains('problem_gap', $keys);
        $this->assertContains('problem_duplicateletter', $keys);
    }

    /**
     * Lowest passing letter from Pass/Fail words, per level.
     */
    public function test_lowest_pass_from_words(): void {
        $letters = grading::ordered_letters(self::scale());
        $under = array_fill_keys($letters, 'Pass');
        $under['F'] = 'Fail';
        $this->assertSame(['D-', null], grading::lowest_pass_from_words($letters, $under));
        $grad = $under;
        foreach (['C-', 'D+', 'D', 'D-'] as $l) {
            $grad[$l] = 'Fail (C- or lower)';
        }
        $this->assertSame(['C', null], grading::lowest_pass_from_words($letters, $grad));
        $odd = $under;
        $odd['B'] = 'Fail';
        $this->assertSame([null, 'problem_passorder'], grading::lowest_pass_from_words($letters, $odd));
        $this->assertSame([null, 'problem_passwords'], grading::lowest_pass_from_words($letters, ['A' => 'maybe']));
    }

    /**
     * Level minimums written in words.
     */
    public function test_level_minimum_from_text(): void {
        $this->assertSame(['minletter' => null, 'minpct' => 80.0], grading::level_minimum_from_text('80% (B)'));
        $this->assertSame(['minletter' => 'C', 'minpct' => null], grading::level_minimum_from_text('Above C- (C or better)'));
        $this->assertNull(grading::level_minimum_from_text('Core: C-; Gen Ed: D-'));
    }

    /**
     * Program rules written in words become rules where the wording is clear; the rest are notes.
     */
    public function test_parse_program_rules(): void {
        $letters = grading::ordered_letters(self::scale());
        $groups = ['General Education Courses (30 credits)', 'Major Core Courses (60 credits)', 'Thesis (6 credits)'];
        $courses = ['ART1100' => 'Drawing I', 'CAP4900' => 'Senior Capstone', 'RES6900' => 'Research Seminar'];
        $r = grading::parse_program_rules('Core min C-; Gen Ed min D-; 2.0 GPA in major courses', $letters, $groups, $courses);
        $this->assertCount(2, $r['rules']);
        $this->assertSame(
            ['group', 'Major Core Courses (60 credits)', 'C-'],
            [$r['rules'][0]['scope'], $r['rules'][0]['group'], $r['rules'][0]['minletter']]
        );
        $this->assertSame('General Education Courses (30 credits)', $r['rules'][1]['group']);
        $this->assertSame(['2.0 GPA in major courses'], $r['notes']);

        $r = grading::parse_program_rules('C- or lower fails; Capstone CAP4900 needs 70%+', $letters, $groups, $courses);
        $this->assertSame(['program', 'C'], [$r['rules'][0]['scope'], $r['rules'][0]['minletter']]);
        $this->assertSame(['course', 'CAP4900', 70.0], [$r['rules'][1]['scope'], $r['rules'][1]['course'],
            $r['rules'][1]['minpct']]);

        $r = grading::parse_program_rules('B or better in each course', $letters, $groups, $courses);
        $this->assertSame(['program', 'B'], [$r['rules'][0]['scope'], $r['rules'][0]['minletter']]);
        $r = grading::parse_program_rules('B- in research seminar; Recommended B', $letters, $groups, $courses);
        $this->assertSame(['course', 'RES6900', 'B-'], [$r['rules'][0]['scope'], $r['rules'][0]['course'],
            $r['rules'][0]['minletter']]);
        $this->assertSame(['Recommended B'], $r['notes']);
        $r = grading::parse_program_rules('B or better in thesis courses; below C does not count', $letters, $groups, $courses);
        $this->assertSame(['group', 'Thesis (6 credits)', 'B'], [$r['rules'][0]['scope'], $r['rules'][0]['group'],
            $r['rules'][0]['minletter']]);
        $this->assertSame(['program', 'C'], [$r['rules'][1]['scope'], $r['rules'][1]['minletter']]);
    }

    /**
     * The most specific rule wins, and passes() applies letters and percentages.
     */
    public function test_effective_rule_and_passes(): void {
        $scale = self::scale();
        $rules = [
            ['levelid' => 1, 'programid' => null, 'groupname' => '', 'courseid' => null, 'minletter' => 'D-', 'minpct' => null],
            ['levelid' => 2, 'programid' => null, 'groupname' => '', 'courseid' => null, 'minletter' => 'C', 'minpct' => null],
            ['levelid' => null, 'programid' => 10, 'groupname' => '', 'courseid' => null, 'minletter' => 'B', 'minpct' => null],
            ['levelid' => null, 'programid' => 11, 'groupname' => 'Core', 'courseid' => null, 'minletter' => 'C-',
                'minpct' => null],
            ['levelid' => null, 'programid' => 12, 'groupname' => '', 'courseid' => 99, 'minletter' => null, 'minpct' => 70],
        ];
        // Undergraduate: D- passes. Graduate: C- fails.
        $this->assertTrue(grading::passes($scale, 'D-', grading::effective_rule($rules, 1, 11, ['General'], 5)));
        $this->assertFalse(grading::passes($scale, 'C-', grading::effective_rule($rules, 2, 13, [], 5)));
        $this->assertTrue(grading::passes($scale, 'C', grading::effective_rule($rules, 2, 13, [], 5)));
        // Program rule beats level rule; group rule beats level rule.
        $this->assertFalse(grading::passes($scale, 'B-', grading::effective_rule($rules, 2, 10, [], 5)));
        $this->assertFalse(grading::passes($scale, 'D', grading::effective_rule($rules, 1, 11, ['Core'], 5)));
        // Course percentage rule: C- starts at 70, so it passes; D+ does not.
        $this->assertTrue(grading::passes($scale, 'C-', grading::effective_rule($rules, 2, 12, [], 99)));
        $this->assertFalse(grading::passes($scale, 'D+', grading::effective_rule($rules, 2, 12, [], 99)));
        // No rule: cannot be decided.
        $this->assertNull(grading::passes($scale, 'A', grading::effective_rule($rules, 7, 13, [], 5)));
        $this->assertNull(grading::passes($scale, 'Z', $rules[0]));
    }
}
