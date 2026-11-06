<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(dates::class)]
/**
 * Tests for date rules: date of birth picked as day / month / year, and the calculated last day to withdraw.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\dates
 */
final class dates_test extends \basic_testcase {
    /**
     * Day, month and year: all blank = no date; some blank or an impossible date = error; never today by default.
     */
    public function test_parts_to_ymd(): void {
        $this->assertNull(dates::parts_to_ymd('', '', ''));
        $this->assertSame('2000-01-15', dates::parts_to_ymd('15', '1', '2000'));
        $this->assertSame('1999-12-03', dates::parts_to_ymd(3, 12, 1999));
        $this->assertFalse(dates::parts_to_ymd('15', '', '2000'));
        $this->assertFalse(dates::parts_to_ymd('31', '2', '2001'));
        $this->assertSame('2000-02-29', dates::parts_to_ymd('29', '2', '2000'));
        $this->assertFalse(dates::parts_to_ymd('x', '2', '2000'));
        $this->assertSame([15, 1, 2000], dates::ymd_to_parts('2000-01-15'));
        $this->assertSame(['', '', ''], dates::ymd_to_parts(''));
    }

    /**
     * 60% of the term in calendar days, first and last day included (example 2027 terms and edge cases).
     */
    public function test_withdraw_ymd(): void {
        $this->assertSame('2027-03-17', dates::withdraw_ymd('2027-01-11', '2027-04-30'));
        $this->assertSame('2027-07-14', dates::withdraw_ymd('2027-05-10', '2027-08-27'));
        $this->assertSame('2027-11-06', dates::withdraw_ymd('2027-09-07', '2027-12-17'));
        $this->assertSame('2030-10-12', dates::withdraw_ymd('2030-09-02', '2030-11-22', 50));
        $this->assertSame('2030-01-01', dates::withdraw_ymd('2030-01-01', '2030-01-01'));
        $this->assertSame('2030-11-22', dates::withdraw_ymd('2030-09-02', '2030-11-22', 100));
        $this->assertNull(dates::withdraw_ymd('2030-11-22', '2030-09-02'));
        $this->assertNull(dates::withdraw_ymd('', '2030-09-02'));
        $this->assertNull(dates::withdraw_ymd('2030-09-02', '2030-11-22', 0));
    }

    /**
     * A percentage in a rule written in words.
     */
    public function test_percent_in_text(): void {
        $this->assertSame(60.0, dates::percent_in_text('Students may withdraw until 60% of the term'));
        $this->assertSame(62.5, dates::percent_in_text('62.5 percent point'));
        $this->assertNull(dates::percent_in_text('End of week 10'));
        $this->assertNull(dates::percent_in_text('150%'));
    }
}
