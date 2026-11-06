<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(ferpa::class)]
/**
 * Tests for FERPA rules, the letter's term and report rates (no database).
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\ferpa
 */
final class ferpa_test extends \basic_testcase {
    /**
     * Academic year from the school's year start (default July 1).
     */
    public function test_academic_year(): void {
        $this->assertSame('2026-2027', ferpa::academic_year('2026-10-23', ''));
        $this->assertSame('2025-2026', ferpa::academic_year('2026-06-30', ''));
        $this->assertSame('2026-2027', ferpa::academic_year('2026-07-01', ''));
        $this->assertSame('2025-2026', ferpa::academic_year('2026-07-31', '08-01'));
        $this->assertSame('2026-2027', ferpa::academic_year('2026-08-01', '8/1'));
        $this->assertSame([8, 15], ferpa::year_start('August 15'));
        $this->assertSame([1, 1], ferpa::year_start('Jan'));
        $this->assertSame([7, 1], ferpa::year_start('soon'));
        $this->assertSame([7, 1], ferpa::year_start('13-40'));
    }

    /**
     * A release authorization is in force between its dates, unless revoked, for the kinds it names (or all).
     */
    public function test_release_active(): void {
        $r = ['fromdate' => 1000, 'todate' => 5000, 'timerevoked' => null, 'scope' => 'enrollment,grades'];
        $this->assertTrue(ferpa::release_active($r, 2000));
        $this->assertTrue(ferpa::release_active($r, 2000, 'grades'));
        $this->assertFalse(ferpa::release_active($r, 2000, 'financial'));
        $this->assertFalse(ferpa::release_active($r, 6000));
        $this->assertFalse(ferpa::release_active($r, 500));
        $this->assertFalse(ferpa::release_active(['timerevoked' => 1500] + $r, 2000));
        $this->assertTrue(ferpa::release_active(['scope' => 'all', 'todate' => null] + $r, 999999, 'conduct'));
    }

    /**
     * The letter's term: running now, else the next, else the last; reports' rates.
     */
    public function test_letter_term_and_rates(): void {
        $terms = [['id' => 1, 'startdate' => 100, 'enddate' => 200], ['id' => 2, 'startdate' => 300, 'enddate' => 400],
            ['id' => 3, 'startdate' => 500, 'enddate' => 600]];
        $this->assertSame(2, letters::pick_term($terms, 350));
        $this->assertSame(2, letters::pick_term($terms, 250));
        $this->assertSame(1, letters::pick_term($terms, 50));
        $this->assertSame(3, letters::pick_term($terms, 700));
        $this->assertNull(letters::pick_term([], 700));
        $this->assertSame(66.7, reports::rate(2, 3));
        $this->assertNull(reports::rate(0, 0));
    }
}
