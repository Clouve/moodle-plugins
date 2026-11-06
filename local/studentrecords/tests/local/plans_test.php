<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(plans::class)]
/**
 * Tests for payment plans: due dates, amounts, sharing payments, late, and plans in the database with the daily task.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\plans
 */
final class plans_test extends \advanced_testcase {
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
        $this->generator->set_settings(['billing' => 1]);
    }

    /**
     * A student who owes an amount, on a plan of 25% down and three payments, with a late fee of $25.
     *
     * @param float $owes
     * @return array [student, plan id]
     */
    private function student_on_plan(float $owes): array {
        $student = $this->generator->create_user_student();
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => $owes]);
        $term = $this->generator->create_term();
        $id = plans::create($student, (int)$term->id, null, ['name' => 'Three payments', 'downtype' => 'percent',
            'downvalue' => 25, 'numpay' => 3, 'frequency' => 'monthly', 'duemode' => 'relative', 'firstdue' => 30,
            'planfee' => 0, 'latefee' => 25, 'gracedays' => 0], 'Custom');
        return [$student, $id];
    }

    /**
     * Make a payment of a plan due some days from now.
     *
     * @param int $planid
     * @param int $seq
     * @param int $days below zero: in the past
     */
    private function due_in(int $planid, int $seq, int $days): void {
        global $DB;
        $DB->set_field(plans::INSTS, 'duedate', time() + $days * DAYSECS, ['stuplanid' => $planid, 'seq' => $seq]);
    }

    /**
     * The state of the payments of a plan, in order.
     *
     * @param int $planid
     * @return string[]
     */
    private function states(int $planid): array {
        global $DB;
        return array_values(array_map(fn($i) => $i->status, $DB->get_records(plans::INSTS, ['stuplanid' => $planid], 'seq')));
    }

    /**
     * Due dates from the term start (Spring 2027 starts 2027-01-11), and fixed dates.
     */
    public function test_due_days(): void {
        $this->assertSame(['2027-02-10', '2027-03-10', '2027-04-10'], plans::due_days('relative', '2027-01-11', 30, 'monthly', 3));
        $this->assertSame(['2027-01-18', '2027-01-25'], plans::due_days('relative', '2027-01-11', 7, 'weekly', 2));
        $this->assertSame(['2027-01-04', '2027-01-18'], plans::due_days('relative', '2027-01-11', -7, 'biweekly', 2));
        $this->assertSame(
            ['2027-01-31', '2027-02-28', '2027-03-31'],
            plans::due_days('relative', '2027-01-01', 30, 'monthly', 3),
            'month end: Jan 31, Feb 28, Mar 31'
        );
        $this->assertSame(['2027-02-01', '2027-03-01'], plans::due_days('fixed', '', 0, '', 2, '2027-03-01, 2027-02-01'));
        $this->assertSame([], plans::due_days('fixed', '', 0, '', 3, '2027-02-01, 2027-03-01'), 'one date missing');
        $this->assertSame([], plans::due_days('fixed', '', 0, '', 1, '2027-02-30'), 'not a real date');
        $this->assertTrue(plans::valid_ymd('2028-02-29'));
        $this->assertFalse(plans::valid_ymd('2027-2-1'));
    }

    /**
     * Amounts: 25% down, the rest in equal payments, cents on the last payment.
     */
    public function test_amounts(): void {
        $this->assertSame([412.5, 412.5, 412.5, 412.5], plans::amounts(1650, 'percent', 25, 3));
        $this->assertSame([250.0, 266.66, 266.66, 266.68], plans::amounts(1050, 'amount', 250, 3));
        $this->assertSame([0.0, 550.0, 550.0, 550.0], plans::amounts(1650, 'percent', 0, 3));
        $this->assertSame([100.0, 0.0], plans::amounts(100, 'amount', 500, 1), 'down payment not more than the total');
        $amounts = plans::amounts(1000.01, 'percent', 33.3, 7);
        $this->assertEqualsWithDelta(1000.01, array_sum($amounts), 0.001, 'the payments add up to the total');
    }

    /**
     * Shares are worked out in whole cents, so an amount that divides evenly does divide evenly. In floats 1.14 / 2 * 100 is
     * just under 57, which made the payments 0.56 and 0.58.
     */
    public function test_amounts_are_exact(): void {
        $this->assertSame([0.0, 0.57, 0.57], plans::amounts(1.14, 'percent', 0, 2));
        $this->assertSame([0.58, 0.57], plans::amounts(1.15, 'percent', 50, 1), 'half a cent goes up on the down payment');
        $this->assertSame([3.08, 1.03, 1.03, 1.03], plans::amounts(6.17, 'percent', 49.92, 3));
    }

    /**
     * A down payment is never more than the total, as a percent or as an amount, and no payment goes below zero.
     */
    public function test_amounts_down_payment_above_the_total(): void {
        $this->assertSame([100.0, 0.0, 0.0], plans::amounts(100, 'percent', 1000, 2));
        $this->assertSame([100.0], plans::amounts(100, 'percent', 25, 0), 'no payments: all down');
    }

    /**
     * Money paid toward a plan goes to the payments in due order.
     */
    public function test_allocate(): void {
        $this->assertSame([412.5, 87.5, 0.0, 0.0], plans::allocate([412.5, 412.5, 412.5, 412.5], 500));
        $this->assertSame([412.5, 412.5, 412.5, 412.5], plans::allocate([412.5, 412.5, 412.5, 412.5], 2000));
        $this->assertSame([0.0, 0.0], plans::allocate([10, 10], -50), 'more charges than payments: nothing paid');
    }

    /**
     * Late: after the due date plus the grace days.
     */
    public function test_is_late(): void {
        $this->assertFalse(plans::is_late('2027-02-10', 5, '2027-02-15'));
        $this->assertTrue(plans::is_late('2027-02-10', 5, '2027-02-16'));
        $this->assertTrue(plans::is_late('2027-02-10', 0, '2027-02-11'));
        $this->assertFalse(plans::is_late('2027-02-10', 0, '2027-02-10'));
    }

    /**
     * A plan's payments add up to what is owed, and a term has one active plan.
     */
    public function test_create(): void {
        global $DB;
        $this->preventResetByRollback();
        [$student, $planid] = $this->student_on_plan(1000.01);

        $insts = $DB->get_records(plans::INSTS, ['stuplanid' => $planid], 'seq');
        $this->assertCount(4, $insts);
        $this->assertEqualsWithDelta(1000.01, array_sum(array_map(fn($i) => (float)$i->amount, $insts)), 0.001);
        $plan = $DB->get_record(plans::STUPLANS, ['id' => $planid]);

        try {
            plans::create($student, (int)$plan->termid, null, ['name' => 'Second', 'downtype' => 'percent', 'downvalue' => 25,
                'numpay' => 3, 'frequency' => 'monthly', 'duemode' => 'relative', 'firstdue' => 30, 'planfee' => 0,
                'latefee' => 0, 'gracedays' => 0], 'Custom');
            $this->fail('A term has one active plan.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_planexists', $e->errorcode);
        }
    }

    /**
     * Payments count toward the plan in due order, and a returned payment counts back at once, without waiting for the
     * daily task.
     */
    public function test_payment_and_its_reversal(): void {
        [$student, $planid] = $this->student_on_plan(1000);

        $lineid = payments::record($student, 300, 'check');
        $this->assertSame(['paid', 'due', 'due', 'due'], $this->states($planid));

        billing::reverse($lineid, 'Check returned');
        $this->assertSame(['due', 'due', 'due', 'due'], $this->states($planid));
    }

    /**
     * The plan is done when every payment is paid, and a returned payment opens it again.
     */
    public function test_plan_completes_and_opens_again(): void {
        global $DB;
        [$student, $planid] = $this->student_on_plan(1000);

        $lineid = payments::record($student, 1000, 'check');
        $this->assertSame('completed', $DB->get_field(plans::STUPLANS, 'status', ['id' => $planid]));

        billing::reverse($lineid, 'Check returned');
        $this->assertSame('active', $DB->get_field(plans::STUPLANS, 'status', ['id' => $planid]));
        $this->assertSame(['due', 'due', 'due', 'due'], $this->states($planid));
    }

    /**
     * Running the daily task twice sends one reminder, charges one late fee and places one hold.
     */
    public function test_daily_twice_does_each_thing_once(): void {
        global $DB;
        [$student, $planid] = $this->student_on_plan(1000);
        $this->generator->set_settings(['reminderdays' => 7]);
        $this->due_in($planid, 0, -10);
        $this->due_in($planid, 1, 3);

        $first = plans::daily();
        $second = plans::daily();

        $this->assertSame(['reminders' => 1, 'latefees' => 1, 'holds' => 1], $first);
        $this->assertSame(['reminders' => 0, 'latefees' => 0, 'holds' => 0], $second);
        $this->assertSame(1025.0, billing::balance((int)$student->id));
        $this->assertSame(1, $DB->count_records(academic::HOLDS, ['studentid' => $student->id, 'holdtype' => 'financial']));
    }

    /**
     * The scheduled task runs the same work, and does nothing while billing is off.
     */
    public function test_task(): void {
        [$student, $planid] = $this->student_on_plan(1000);
        $this->due_in($planid, 0, -10);
        $task = new \local_studentrecords\task\billing_daily();

        $this->generator->set_settings(['billing' => 0]);
        ob_start();
        $task->execute();
        $this->generator->set_settings(['billing' => 1]);
        $off = billing::balance((int)$student->id);
        $task->execute();
        $task->execute();
        ob_end_clean();

        $this->assertSame(1000.0, $off);
        $this->assertSame(1025.0, billing::balance((int)$student->id));
    }

    /**
     * A student whose account was cleared by a refund or a reversal owes nothing, so no late fee, hold or reminder.
     */
    public function test_daily_leaves_a_cleared_account_alone(): void {
        global $DB;
        [$student, $planid] = $this->student_on_plan(1000);
        $chargeid = (int)$DB->get_field(billing::TXN, 'id', ['studentid' => $student->id, 'txntype' => 'charge']);
        billing::reverse($chargeid, 'Dropped before the term');
        $this->due_in($planid, 0, -10);
        $this->due_in($planid, 1, 3);

        $this->assertSame(['reminders' => 0, 'latefees' => 0, 'holds' => 0], plans::daily());
        $this->assertSame(0.0, billing::balance((int)$student->id));
    }

    /**
     * A hold staff released is not placed again for the same late payment.
     */
    public function test_released_hold_stays_released(): void {
        global $DB;
        [$student, $planid] = $this->student_on_plan(1000);
        $this->due_in($planid, 0, -10);
        plans::daily();
        $holdid = (int)$DB->get_field(plans::INSTS, 'holdid', ['stuplanid' => $planid, 'seq' => 0]);

        academic::release_hold($holdid, 'Agreed with the student');
        plans::daily();

        $this->assertSame(1, $DB->count_records(academic::HOLDS, ['studentid' => $student->id]));
    }
}
