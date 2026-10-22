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

#[\PHPUnit\Framework\Attributes\CoversClass(calculator::class)]
/**
 * Tests for the grade calculator.
 *
 * The calculator is pure arithmetic, so these tests build the standard 4.0 scale by hand and use no database.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_academictranscript\local\calculator
 */
final class calculator_test extends \basic_testcase {
    /**
     * With rounding on, the percentage is rounded to a whole number and then matched to a letter.
     *
     * @dataProvider letter_provider
     * @param float $percent The course total
     * @param string $expected The letter
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('letter_provider')]
    public function test_letter_rounds_then_matches(float $percent, string $expected): void {
        $this->assertSame($expected, $this->make_calculator()->letter($percent));
    }

    /**
     * Data for test_letter_rounds_then_matches.
     *
     * @return array
     */
    public static function letter_provider(): array {
        return [
            'full marks' => [100, 'A'],
            'exactly the A minimum' => [93, 'A'],
            'half a point below A rounds up to A' => [92.5, 'A'],
            'under half a point below A rounds down to A-' => [92.4, 'A-'],
            'half a point below A- rounds up to A-' => [89.5, 'A-'],
            'half a point below B+ rounds up to B+' => [86.5, 'B+'],
            'half a point below D- rounds up to D-' => [59.5, 'D-'],
            'under half a point below D- rounds down to F' => [59.4, 'F'],
            'zero' => [0, 'F'],
        ];
    }

    /**
     * A course total that is exactly half a point below a minimum rounds up, even when it arrives a few units in the last
     * place short. The gradebook scales 17.9 out of 20 to 89.499999999999986 and not to 89.5.
     *
     * @dataProvider course_total_provider
     * @param float $grade The grade in the course
     * @param float $max The highest grade possible in the course
     * @param string $expected The letter
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('course_total_provider')]
    public function test_letter_rounds_course_totals_that_end_in_a_half(float $grade, float $max, string $expected): void {
        // The same arithmetic as the gradebook uses to scale a grade to a percentage.
        $percent = $grade / $max * 100;

        $this->assertSame($expected, $this->make_calculator()->letter($percent));
    }

    /**
     * Data for test_letter_rounds_course_totals_that_end_in_a_half.
     *
     * @return array
     */
    public static function course_total_provider(): array {
        return [
            '17.9 out of 20 is 89.5%' => [17.9, 20, 'A-'],
            '35.8 out of 40 is 89.5%' => [35.8, 40, 'A-'],
            '8.95 out of 10 is 89.5%' => [8.95, 10, 'A-'],
            '71.6 out of 80 is 89.5%' => [71.6, 80, 'A-'],
            '89.49 out of 100 is under half a point below A-' => [89.49, 100, 'B+'],
        ];
    }

    /**
     * With rounding off, the percentage must reach a row's minimum as it is.
     */
    public function test_letter_without_rounding(): void {
        $calculator = $this->make_calculator('latest', false);

        $this->assertSame('A-', $calculator->letter(92.99));
        $this->assertSame('B+', $calculator->letter(89.5));
        $this->assertSame('F', $calculator->letter(59.99));
        $this->assertSame('A', $calculator->letter(93));
    }

    /**
     * The rows may come in any order: the row with the highest minimum that the percentage reaches gives the letter.
     */
    public function test_letter_does_not_depend_on_row_order(): void {
        $calculator = new calculator([
            $this->grade('F', 0, 59, 0.0, false),
            $this->grade('B', 83, 86, 3.0),
            $this->grade('A', 93, 100, 4.0),
        ], []);

        $this->assertSame('A', $calculator->letter(95));
        $this->assertSame('B', $calculator->letter(90));
        $this->assertSame('B', $calculator->letter(83));
        $this->assertSame('F', $calculator->letter(40));
    }

    /**
     * A percentage below every minimum has no letter.
     */
    public function test_letter_returns_null_when_no_row_matches(): void {
        $calculator = new calculator([$this->grade('C', 50, 100, 2.0)], []);

        $this->assertNull($calculator->letter(10));
        $this->assertSame('C', $calculator->letter(50));
    }

    /**
     * Grade points, GPA membership and earned hours of letters, marks and unknown grades.
     */
    public function test_points_and_flags(): void {
        $calculator = $this->make_calculator();

        $this->assertSame(3.0, $calculator->points('B'));
        $this->assertNull($calculator->points('P'));
        $this->assertTrue($calculator->in_gpa('A'));
        $this->assertFalse($calculator->in_gpa('P'));
        $this->assertFalse($calculator->earns('F'));
        $this->assertTrue($calculator->earns('P'));
        $this->assertFalse($calculator->earns('W'));
        $this->assertFalse($calculator->earns('XYZ'));
        $this->assertTrue($calculator->is_letter('A-'));
        $this->assertFalse($calculator->is_letter('P'));
    }

    /**
     * The GPA is quality points over GPA hours with two decimals, and blank without GPA hours.
     */
    public function test_gpa_has_two_decimals(): void {
        $this->assertSame('3.33', calculator::gpa(10.0, 3.0));
        $this->assertSame('', calculator::gpa(0.0, 0.0));
    }

    /**
     * A GPA exactly halfway between two hundredths rounds up. An A and three B+ at 3 hours each have 41.7 quality points
     * over 12 hours, a GPA of 3.475. The sum of the products is 41.699999999999996, a few units in the last place short,
     * and still gives 3.48.
     */
    public function test_gpa_rounds_a_half_up(): void {
        $this->assertSame('3.48', calculator::gpa(4.0 * 3.0 + 3.3 * 3.0 + 3.3 * 3.0 + 3.3 * 3.0, 12.0));
        $this->assertSame('3.48', calculator::gpa(41.7, 12.0));
    }

    /**
     * Hours count to the hundredth, so hours below half a hundredth count as none and there is no GPA.
     */
    public function test_gpa_is_blank_when_the_hours_are_below_half_a_hundredth(): void {
        $this->assertSame('', calculator::gpa(0.0, 0.004));
        $this->assertSame('', calculator::gpa(1.0, 0.004));
    }

    /**
     * A negative value rounds away from zero, as round() does, so a negative GPA mirrors the positive one. A value that
     * rounds to zero has no sign.
     */
    public function test_gpa_of_negative_quality_points_mirrors_the_positive_one(): void {
        $this->assertSame('-3.48', calculator::gpa(-41.7, 12.0));
        $this->assertSame('0.00', calculator::gpa(-0.004, 1.0));
    }

    /**
     * Hours earned, GPA hours and quality points are summed for each group (term) and for the whole record.
     */
    public function test_compute_sums_groups_and_total(): void {
        $result = $this->make_calculator()->compute([
            $this->attempt('BUS 101', 'A', 3.0, '1'),
            $this->attempt('BUS 102', 'B', 3.0, '1'),
            $this->attempt('ART 100', 'P', 2.0, '2'),
            $this->attempt('ENG 101', 'F', 3.0, '2'),
            $this->attempt('HIS 101', 'W', 3.0, '2'),
        ]);

        $this->assertSame(['earned' => 6.0, 'gpahours' => 6.0, 'qp' => 21.0, 'gpa' => '3.50'], $result['groups']['1']);
        $this->assertSame(['earned' => 2.0, 'gpahours' => 3.0, 'qp' => 0.0, 'gpa' => '0.00'], $result['groups']['2']);
        $this->assertSame(['earned' => 8.0, 'gpahours' => 9.0, 'qp' => 21.0, 'gpa' => '2.33'], $result['total']);

        $attempts = $result['attempts'];
        $this->assertSame(12.0, $attempts[0]['qp']);
        $this->assertNull($attempts[2]['points']);
        $this->assertNull($attempts[2]['qp']);
        $this->assertFalse($attempts[4]['ingpa']);
        $this->assertFalse($attempts[4]['earned']);
        $this->assertFalse($attempts[4]['excluded']);
    }

    /**
     * An A and three B+ at 3 hours each have 41.7 quality points over 12 hours, a GPA of 3.475, which rounds up to 3.48.
     * As floats, the sum is 41.699999999999996 and the division gives 3.4749999999999996, which PHP 8.4 rounds down to
     * 3.47. The arithmetic is in whole numbers, so the GPA is the same on every PHP version.
     */
    public function test_half_cent_gpa_rounds_up(): void {
        $result = $this->make_calculator()->compute([
            $this->attempt('BUS 101', 'A', 3.0, '1'),
            $this->attempt('BUS 102', 'B+', 3.0, '1'),
            $this->attempt('BUS 103', 'B+', 3.0, '1'),
            $this->attempt('BUS 104', 'B+', 3.0, '1'),
        ]);

        $expected = ['earned' => 12.0, 'gpahours' => 12.0, 'qp' => 41.7, 'gpa' => '3.48'];
        $this->assertSame([12.0, 9.9, 9.9, 9.9], array_column($result['attempts'], 'qp'));
        $this->assertSame($expected, $result['groups']['1']);
        $this->assertSame($expected, $result['total']);
    }

    /**
     * B+ for 0.15 hours is 0.495 quality points, which rounds up to 0.5. As floats, 3.3 times 0.15 is 0.49499999999999994,
     * which PHP 8.4 rounds down to 0.49.
     */
    public function test_half_cent_quality_points_round_up(): void {
        $result = $this->make_calculator()->compute([$this->attempt('BUS 101', 'B+', 0.15, '1')]);

        $this->assertSame(0.5, $result['attempts'][0]['qp']);
        $this->assertSame(0.5, $result['groups']['1']['qp']);
        $this->assertSame(0.5, $result['total']['qp']);
        $this->assertSame('3.30', $result['groups']['1']['gpa']);
        $this->assertSame('3.30', $result['total']['gpa']);
        $this->assertSame(0.15, $result['total']['earned']);
        $this->assertSame(0.15, $result['total']['gpahours']);
    }

    /**
     * With fractional hours, the quality points of a course are not whole cents: 3.7 grade points over a quarter hour is
     * 0.925. The GPA of the whole record is worked out from the exact quality points, as the GPA of each term is, so a
     * record with one course has the grade points of that course as its GPA, in the term and in the total.
     *
     * @dataProvider fractional_hours_provider
     * @param string $grade The letter of the one course
     * @param float $hours The hours of the course
     * @param string $gpa The GPA expected, in the term and in the total
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fractional_hours_provider')]
    public function test_gpa_of_one_course_with_fractional_hours_is_its_grade_points(
        string $grade,
        float $hours,
        string $gpa
    ): void {
        $result = $this->make_calculator()->compute([$this->attempt('BUS 101', $grade, $hours, '1')]);

        $this->assertSame($gpa, $result['groups']['1']['gpa']);
        $this->assertSame($gpa, $result['total']['gpa']);
    }

    /**
     * Data for test_gpa_of_one_course_with_fractional_hours_is_its_grade_points.
     *
     * @return array
     */
    public static function fractional_hours_provider(): array {
        return [
            'a quarter hour of A-' => ['A-', 0.25, '3.70'],
            'fifteen hundredths of an hour of B+' => ['B+', 0.15, '3.30'],
            'five hundredths of an hour of A-' => ['A-', 0.05, '3.70'],
        ];
    }

    /**
     * Several courses with fractional hours in two terms. Each GPA divides the exact quality points by the hours, and
     * the quality points that come back are rounded to two decimals.
     *
     * Term 1: A- for 0.75 hours (2.775 quality points) and C+ for 0.33 hours (0.759) make 3.534 over 1.08 hours, a GPA of
     * 3.27. Term 2: A- and C+ for 0.25 hours each (0.925 and 0.575) make 1.5 over 0.5 hours, a GPA of 3.00. The record
     * has 5.034 quality points over 1.58 hours, a GPA of 3.19. Dividing the rounded 5.03 would give 3.18.
     */
    public function test_total_gpa_with_fractional_hours_uses_the_exact_quality_points(): void {
        $result = $this->make_calculator()->compute([
            $this->attempt('MOD 101', 'A-', 0.75, '1', 0),
            $this->attempt('MOD 102', 'C+', 0.33, '1', 0),
            $this->attempt('MOD 201', 'A-', 0.25, '2', 1),
            $this->attempt('MOD 202', 'C+', 0.25, '2', 1),
        ]);

        $this->assertSame('3.27', $result['groups']['1']['gpa']);
        $this->assertSame(3.53, $result['groups']['1']['qp']);
        $this->assertSame('3.00', $result['groups']['2']['gpa']);
        $this->assertSame(1.5, $result['groups']['2']['qp']);
        $this->assertSame('3.19', $result['total']['gpa']);
        $this->assertSame(5.03, $result['total']['qp']);
        $this->assertEqualsWithDelta(1.58, $result['total']['gpahours'], 0.000001);
        $this->assertEqualsWithDelta(1.58, $result['total']['earned'], 0.000001);
    }

    /**
     * Two attempts at one course, an F and then a B, under each repeat rule.
     *
     * @dataProvider repeat_rules_provider
     * @param string $rule The repeat rule
     * @param bool[] $excluded Whether each attempt is left out, in order
     * @param float $gpahours The GPA hours of the record
     * @param string $gpa The GPA of the record
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('repeat_rules_provider')]
    public function test_repeat_rules(string $rule, array $excluded, float $gpahours, string $gpa): void {
        $result = $this->make_calculator($rule)->compute([
            $this->attempt('BUS 101', 'F', 3.0, '1', 0, 100),
            $this->attempt('BUS 101', 'B', 3.0, '1', 1, 200),
        ]);

        $this->assertSame($excluded, array_column($result['attempts'], 'excluded'));
        $this->assertSame($gpahours, $result['total']['gpahours']);
        $this->assertSame($gpa, $result['total']['gpa']);
        $this->assertSame(3.0, $result['total']['earned']);
    }

    /**
     * Data for test_repeat_rules.
     *
     * @return array
     */
    public static function repeat_rules_provider(): array {
        return [
            'latest counts only the later attempt' => ['latest', [true, false], 3.0, '3.00'],
            'highest counts only the better grade' => ['highest', [true, false], 3.0, '3.00'],
            'all counts both attempts' => ['all', [false, false], 6.0, '1.50'],
        ];
    }

    /**
     * Under "highest", the better grade stays even when it came first. Under "latest", the later attempt stays.
     */
    public function test_repeat_highest_keeps_best_grade(): void {
        $attempts = [
            $this->attempt('BUS 101', 'A', 3.0, '1', 0, 100),
            $this->attempt('BUS 101', 'C', 3.0, '1', 1, 200),
        ];

        $this->assertSame([1], $this->excluded_indexes($this->make_calculator('highest')->compute($attempts)));
        $this->assertSame([0], $this->excluded_indexes($this->make_calculator('latest')->compute($attempts)));
    }

    /**
     * Under "highest", a mark that earns hours (P) ranks below every letter, even a D.
     */
    public function test_repeat_highest_ranks_marks_below_letters(): void {
        $attempts = [
            $this->attempt('BUS 101', 'D', 3.0, '1', 0, 100),
            $this->attempt('BUS 101', 'P', 3.0, '1', 1, 200),
        ];

        $this->assertSame([1], $this->excluded_indexes($this->make_calculator('highest')->compute($attempts)));
        $this->assertSame([0], $this->excluded_indexes($this->make_calculator('latest')->compute($attempts)));
    }

    /**
     * Course codes are compared without regard to case or surrounding spaces.
     */
    public function test_repeat_codes_compare_case_insensitively(): void {
        $result = $this->make_calculator()->compute([
            $this->attempt('bus 101', 'A', 3.0, '1', 0, 100),
            $this->attempt('BUS 101 ', 'B', 3.0, '1', 1, 200),
        ]);

        $this->assertSame([0], $this->excluded_indexes($result));
    }

    /**
     * A withdrawal (a mark that earns nothing) is not a repeat of the later attempt.
     */
    public function test_marks_that_earn_nothing_are_not_repeats(): void {
        $result = $this->make_calculator()->compute([
            $this->attempt('BUS 101', 'W', 3.0, '1', 0, 100),
            $this->attempt('BUS 101', 'B', 3.0, '1', 1, 200),
        ]);

        $this->assertSame([], $this->excluded_indexes($result));
        $this->assertSame(['earned' => 3.0, 'gpahours' => 3.0, 'qp' => 9.0, 'gpa' => '3.00'], $result['total']);
    }

    /**
     * A rule that is not "latest", "highest" or "all" works like "latest".
     */
    public function test_unknown_repeat_rule_falls_back_to_latest(): void {
        // A B and then an F: "latest" leaves out the B, "highest" would leave out the F and "all" neither.
        $result = $this->make_calculator('bogus')->compute([
            $this->attempt('BUS 101', 'B', 3.0, '1', 0, 100),
            $this->attempt('BUS 101', 'F', 3.0, '1', 1, 200),
        ]);

        $this->assertSame([0], $this->excluded_indexes($result));
        $this->assertSame(['earned' => 0.0, 'gpahours' => 3.0, 'qp' => 0.0, 'gpa' => '0.00'], $result['total']);
    }

    /**
     * A calculator with the standard 4.0 scale and the standard marks.
     *
     * The marks are P (earns), NP, I, NC, W, TR (earns) and IP. Every letter counts in the GPA, and every letter
     * except F earns the hours.
     *
     * @param string $rule The repeat rule
     * @param bool $round Whether to round the percentage before choosing the letter
     * @return calculator
     */
    private function make_calculator(string $rule = 'latest', bool $round = true): calculator {
        $scale = [
            ['A', 93, 100, 4.0], ['A-', 90, 92, 3.7], ['B+', 87, 89, 3.3], ['B', 83, 86, 3.0], ['B-', 80, 82, 2.7],
            ['C+', 77, 79, 2.3], ['C', 73, 76, 2.0], ['C-', 70, 72, 1.7], ['D+', 67, 69, 1.3], ['D', 63, 66, 1.0],
            ['D-', 60, 62, 0.7], ['F', 0, 59, 0.0],
        ];
        $grades = [];
        foreach ($scale as [$letter, $min, $max, $points]) {
            $grades[] = $this->grade($letter, $min, $max, $points, $letter !== 'F');
        }
        $marks = [];
        foreach (['P' => 1, 'NP' => 0, 'I' => 0, 'NC' => 0, 'W' => 0, 'TR' => 1, 'IP' => 0] as $code => $earns) {
            $marks[] = ['code' => $code, 'earns' => $earns];
        }
        return new calculator($grades, $marks, $rule, $round);
    }

    /**
     * One grade row in the shape the calculator takes.
     *
     * @param string $letter
     * @param float $min The lowest percentage
     * @param float $max The highest percentage
     * @param float $points The grade points
     * @param bool $earns Whether the grade earns the course hours
     * @return array
     */
    private function grade(string $letter, float $min, float $max, float $points, bool $earns = true): array {
        return ['letter' => $letter, 'min' => $min, 'max' => $max, 'points' => $points, 'ingpa' => 1,
            'earns' => $earns ? 1 : 0];
    }

    /**
     * One attempt at a course in the shape that compute() takes.
     *
     * @param string $code The course code
     * @param string $grade A letter or a mark
     * @param float $hours The credit or clock hours
     * @param string $group The term the attempt belongs to
     * @param int $order The position of the term
     * @param int $time The time of the attempt
     * @return array
     */
    private function attempt(string $code, string $grade, float $hours, string $group, int $order = 0, int $time = 0): array {
        return ['code' => $code, 'group' => $group, 'order' => $order, 'time' => $time, 'hours' => $hours,
            'grade' => $grade];
    }

    /**
     * The positions of the attempts that a repeat rule left out.
     *
     * @param array $result What compute() returned
     * @return int[]
     */
    private function excluded_indexes(array $result): array {
        return array_keys(array_filter(array_column($result['attempts'], 'excluded')));
    }
}
