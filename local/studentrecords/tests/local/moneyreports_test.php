<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(moneyreports::class)]
/**
 * Tests for money reports: ages of unpaid charges, 1098-T amounts, and the reports read from the database.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\moneyreports
 */
final class moneyreports_test extends \advanced_testcase {
    /** @var \local_studentrecords_generator */
    private $generator;

    /**
     * Billing on, with the admin working.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $this->generator->set_settings(['billing' => 1, 'timezone' => 'America/New_York']);
    }

    /**
     * Payments pay the oldest charges first; what is left is aged.
     */
    public function test_aging(): void {
        $now = 200 * DAYSECS;
        $lines = [
            ['amount' => 1500, 'txndate' => $now - 100 * DAYSECS],
            ['amount' => 150, 'txndate' => $now - 45 * DAYSECS],
            ['amount' => 25, 'txndate' => $now - 5 * DAYSECS],
            ['amount' => -1400, 'txndate' => $now - 2 * DAYSECS],
        ];
        $a = moneyreports::aging($lines, $now);
        $this->assertSame(['total' => 275.0, 'd30' => 25.0, 'd60' => 150.0, 'd90' => 0.0, 'older' => 100.0], $a);
        $paid = [['amount' => 10, 'txndate' => 0], ['amount' => -10, 'txndate' => 1]];
        $this->assertSame(0.0, moneyreports::aging($paid, $now)['total']);
    }

    /**
     * Line for the 1098-T tests.
     *
     * @param string $type
     * @param float $amount
     * @param int $date
     * @param int $qualified
     * @param string|null $method
     * @param int|null $origdate
     * @param int|null $termstart
     * @return array
     */
    protected function l(
        string $type,
        float $amount,
        int $date,
        int $qualified = 0,
        ?string $method = null,
        ?int $origdate = null,
        ?int $termstart = null
    ): array {
        return ['txntype' => $type, 'amount' => $amount, 'txndate' => $date, 'qualified' => $qualified, 'method' => $method,
            'origdate' => $origdate, 'termstart' => $termstart];
    }

    /**
     * Year 1 (1000-2000): charges for a term starting early in year 2, part paid, a scholarship. Year 2: the rest paid.
     */
    public function test_tax_two_years(): void {
        $lines = [
            $this->l('charge', 1500, 1100, 1, null, null, 2100),
            $this->l('charge', 150, 1100, 1, null, null, 2100),
            $this->l('charge', 25, 1100),
            $this->l('payment', -418.75, 1200, 0, 'check'),
            $this->l('credit', -250, 1300, 0, 'scholarship'),
            $this->l('credit', -100, 1300, 0, 'waiver'),
            $this->l('payment', -1000, 2100, 0, 'online'),
        ];
        $y1 = moneyreports::tax_amounts($lines, 1000, 2000, 2500);
        $this->assertSame(668.75, $y1['box1'], 'payments and scholarship toward qualified charges');
        $this->assertSame(250.0, $y1['box5']);
        $this->assertTrue($y1['box7'], 'term starts January to March of next year');
        $this->assertSame(1650.0, $y1['billed']);
        $this->assertSame(418.75, $y1['paid']);
        $y2 = moneyreports::tax_amounts($lines, 2000, 3000, 3500);
        $this->assertSame(981.25, $y2['box1'], 'only up to what was billed');
        $this->assertSame(0.0, $y2['box5']);
        $this->assertFalse($y2['box7']);
    }

    /**
     * A refund paid back in year 2 for money counted in year 1 goes to box 4; a scholarship of year 1 taken back in
     * year 2 goes to box 6.
     */
    public function test_tax_adjustments(): void {
        $lines = [
            $this->l('charge', 1000, 1100, 1),
            $this->l('payment', -800, 1200, 0, 'check'),
            $this->l('credit', -200, 1300, 0, 'grant'),
            $this->l('refund', -1000, 2100, 1),
            $this->l('credit', 200, 2150, 0, 'grant', 1300),
            $this->l('payout', 800, 2200, 0, 'check'),
        ];
        $y1 = moneyreports::tax_amounts($lines, 1000, 2000, 2500);
        $this->assertSame(1000.0, $y1['box1']);
        $this->assertSame(200.0, $y1['box5']);
        $y2 = moneyreports::tax_amounts($lines, 2000, 3000, 3500);
        $this->assertSame(0.0, $y2['box1']);
        $this->assertSame(1000.0, $y2['box4']);
        $this->assertSame(200.0, $y2['box6']);
        $this->assertSame(0.0, $y2['box5']);
        $this->assertSame(800.0, $y2['paidback']);
    }

    /**
     * The last day of a period ends at midnight on its own day, also on the 25 hour day when the clocks go back.
     */
    public function test_payments_on_the_day_the_clocks_go_back(): void {
        $student = $this->generator->create_user_student();
        $late = make_timestamp(2030, 11, 3, 23, 30, 0, 'America/New_York');
        payments::record($student, 40, 'check', ['txndate' => $late]);
        payments::record($student, 60, 'check', ['txndate' => make_timestamp(2030, 11, 4, 0, 30, 0, 'America/New_York')]);

        $rows = moneyreports::payments('2030-11-03', '2030-11-03');

        $this->assertCount(1, $rows);
        $this->assertSame(40.0, $rows[0]['total']);
        $this->assertSame('2030-11-03', $rows[0]['day']);
    }

    /**
     * Payments by day and way of paying: a returned payment takes its amount back out.
     */
    public function test_payments_report(): void {
        $student = $this->generator->create_user_student();
        $day = make_timestamp(2030, 3, 5, 12, 0, 0, 'America/New_York');
        $lineid = payments::record($student, 100, 'check', ['txndate' => $day]);
        payments::record($student, 50.25, 'check', ['txndate' => $day]);
        payments::record($student, 10, 'cash', ['txndate' => $day]);
        billing::reverse($lineid, 'Returned');

        $today = catalog::time_to_date(time());
        $rows = moneyreports::payments('2030-03-05', '2030-03-05');
        $reversal = moneyreports::payments($today, $today);

        $this->assertSame([['day' => '2030-03-05', 'method' => 'check', 'count' => 2, 'total' => 150.25],
            ['day' => '2030-03-05', 'method' => 'cash', 'count' => 1, 'total' => 10.0]], $rows);
        $this->assertSame(-100.0, $reversal[0]['total']);
    }

    /**
     * Who owes, by age, and the 1098-T rows of a year, read from the database.
     */
    public function test_owed_and_tax_rows(): void {
        $student = $this->generator->create_user_student();
        $this->generator->create_user_student();
        $day = make_timestamp(2025, 3, 5, 12, 0, 0, 'America/New_York');
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 1000, 'qualified' => 1, 'txndate' => $day]);
        payments::record($student, 400, 'check', ['txndate' => $day]);

        $owed = moneyreports::owed();
        $tax = moneyreports::tax1098(2025);

        $this->assertCount(1, $owed);
        $this->assertSame(600.0, $owed[0]['total']);
        $this->assertSame(600.0, $owed[0]['older']);
        $this->assertCount(1, $tax);
        $this->assertSame(400.0, $tax[0]['box1']);
        $this->assertSame((int)$student->id, $tax[0]['studentid']);
    }
}
