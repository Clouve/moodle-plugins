<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(student_file::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(degreeaudit::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(attendance::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(messages::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(outside::class)]
/**
 * Tests for the page rules: student file initials and tabs, credits at the school, low attendance warning, message
 * fill-ins and Clearinghouse checks.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\student_file
 * @covers     \local_studentrecords\local\degreeaudit
 * @covers     \local_studentrecords\local\attendance
 * @covers     \local_studentrecords\local\messages
 * @covers     \local_studentrecords\local\outside
 */
final class page_rules_test extends \basic_testcase {
    /**
     * Tabs: unknown tabs and History without the right show the profile.
     */
    public function test_pick_tab(): void {
        $this->assertSame('holds', student_file::pick_tab('holds', false));
        $this->assertSame('profile', student_file::pick_tab('nonsense', true));
        $this->assertSame('profile', student_file::pick_tab('history', false));
        $this->assertSame('history', student_file::pick_tab('history', true));
    }

    /**
     * Credits at the school: no minimum is always fine; classes in progress count only "if passed".
     */
    public function test_resident_check(): void {
        $this->assertSame(['ok' => true, 'okifpassed' => true], degreeaudit::resident_check(0, 0, null));
        $this->assertSame(['ok' => false, 'okifpassed' => true], degreeaudit::resident_check(12, 3, 15));
        $this->assertSame(['ok' => false, 'okifpassed' => false], degreeaudit::resident_check(9, 3, 15));
        $this->assertSame(['ok' => true, 'okifpassed' => true], degreeaudit::resident_check(48, 0, 15));
    }

    /**
     * The low attendance warning goes once, when the rate goes under the minimum.
     */
    public function test_fell_under(): void {
        $this->assertTrue(attendance::fell_under(82.0, 78.0, 80));
        $this->assertTrue(attendance::fell_under(null, 50.0, 80));
        $this->assertFalse(attendance::fell_under(75.0, 70.0, 80));
        $this->assertFalse(attendance::fell_under(90.0, 85.0, 80));
        $this->assertFalse(attendance::fell_under(90.0, null, 80));
    }

    /**
     * New fill-ins are filled; there is no amount fill-in.
     */
    public function test_fill(): void {
        $text = messages::fill('Due {due_date}, pay {pay_link}, call {office_phone}, {amount}', ['due_date' => 'Jan 5',
            'pay_link' => 'https://x', 'office_phone' => '555']);
        $this->assertSame('Due Jan 5, pay https://x, call 555, {amount}', $text);
        $this->assertNotContains('amount', messages::PLACEHOLDERS);
    }

    /**
     * Clearinghouse rows with missing data are listed; the empty SSN column is not a problem.
     */
    public function test_nsc_problems(): void {
        $good = ['', 'Ana', '', 'Lee', 'F', '2027-01-11', '1 Main St', '2001-02-03', '2027-01-11', '2027-04-30', 'S1', 'a@x',
            '51.3801', '1', '12'];
        $bad = $good;
        $bad[7] = '';
        $bad[12] = '';
        $this->assertSame([1 => ['dob', 'cip']], outside::nsc_problems([$good, $bad]));
    }
}
