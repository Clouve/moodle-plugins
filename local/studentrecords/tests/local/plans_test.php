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
     * The terms of a custom plan.
     *
     * @param array $changes
     * @return array
     */
    private function custom_terms(array $changes = []): array {
        return $changes + ['name' => 'Second', 'downtype' => 'percent', 'downvalue' => 25, 'numpay' => 3,
            'frequency' => 'monthly', 'duemode' => 'relative', 'firstdue' => 30, 'planfee' => 0, 'latefee' => 0, 'gracedays' => 0];
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
            $this->fail('A student has one active plan.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_planexists', $e->errorcode);
        }
    }

    /**
     * A student has one active plan. Each plan covers the whole balance, so a second one, for any term, would count the same
     * payments again. Nothing of the refused plan is saved, and its message says what to do.
     */
    public function test_a_second_active_plan_is_refused_for_any_term(): void {
        $this->preventResetByRollback();
        [$student, $planid] = $this->student_on_plan(1000);
        $otherterm = $this->generator->create_term();

        try {
            plans::create($student, (int)$otherterm->id, null, $this->custom_terms(['planfee' => 50]), 'Custom');
            $this->fail('A student has one active plan.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_planexists', $e->errorcode);
            $this->assertStringContainsString(get_string('err_planexists', 'local_studentrecords'), $e->getMessage());
        }

        $this->assertSame([$planid], array_map('intval', array_keys(plans::for_student((int)$student->id))));
        $this->assertSame(1000.0, billing::balance((int)$student->id));
    }

    /**
     * The way forward works: a plan that was cancelled or finished leaves room for a new one, for any term.
     */
    public function test_a_new_plan_after_the_old_one_ended(): void {
        global $DB;
        [$student, $planid] = $this->student_on_plan(1000);
        $term = $this->generator->create_term();

        plans::cancel($planid, 'Moved to a shorter plan');
        $second = plans::create($student, (int)$term->id, null, $this->custom_terms(), 'Custom');
        payments::record($student, 1000, 'check');
        $this->assertSame('completed', $DB->get_field(plans::STUPLANS, 'status', ['id' => $second]));
        // That payment is from before the next plan, which counts what is paid from the second it starts.
        $DB->set_field(billing::TXN, 'timecreated', time() - DAYSECS, ['studentid' => $student->id]);
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 300]);
        $third = plans::create($student, (int)$this->generator->create_term()->id, null, $this->custom_terms(), 'Custom');

        $this->assertSame('active', $DB->get_field(plans::STUPLANS, 'status', ['id' => $third]));
    }

    /**
     * Plans that overlapped before this rule stay as they are: both stay active, and the daily task handles both.
     */
    public function test_plans_that_already_overlap_are_left_alone(): void {
        global $DB;
        [$student, $planid] = $this->student_on_plan(1000);
        $old = $DB->get_record(plans::STUPLANS, ['id' => $planid]);
        $second = (int)$DB->insert_record(plans::STUPLANS, (object)(['termid' => $this->generator->create_term()->id] +
            array_diff_key((array)$old, ['id' => 1])));
        foreach ($DB->get_records(plans::INSTS, ['stuplanid' => $planid]) as $inst) {
            $DB->insert_record(plans::INSTS, (object)(['stuplanid' => $second] + array_diff_key((array)$inst, ['id' => 1])));
        }

        $this->due_in($planid, 0, -10);
        $this->due_in($second, 0, -10);

        plans::sync($student);
        $counts = plans::daily();

        $this->assertSame('active', $DB->get_field(plans::STUPLANS, 'status', ['id' => $planid]));
        $this->assertSame('active', $DB->get_field(plans::STUPLANS, 'status', ['id' => $second]));
        $this->assertSame(['reminders' => 0, 'latefees' => 2, 'holds' => 2], $counts);
        foreach ([$planid, $second] as $id) {
            $first = $DB->get_record(plans::INSTS, ['stuplanid' => $id, 'seq' => 0]);
            $this->assertNotEmpty($first->latefeeid, 'late fee of plan ' . $id);
            $this->assertNotEmpty($first->holdid, 'hold of plan ' . $id);
        }
    }

    /**
     * When two completed plans stop being paid in full at once, the newest reopens: it is the one the student is following. The
     * older one stays completed and Finance is told.
     */
    public function test_the_newest_of_two_plans_reopens(): void {
        global $DB;
        [$student, $older] = $this->student_on_plan(1000);
        $plan = $DB->get_record(plans::STUPLANS, ['id' => $older]);
        $newer = (int)$DB->insert_record(plans::STUPLANS, (object)(['termid' => $this->generator->create_term()->id,
            'name' => 'Newer', 'timeapproved' => (int)$plan->timeapproved + 60] + array_diff_key((array)$plan, ['id' => 1])));
        $DB->set_field(plans::STUPLANS, 'status', 'completed', ['studentid' => $student->id]);
        foreach ($DB->get_records(plans::INSTS, ['stuplanid' => $older]) as $inst) {
            $DB->insert_record(plans::INSTS, (object)(['stuplanid' => $newer] + array_diff_key((array)$inst, ['id' => 1])));
        }
        $DB->set_field(plans::INSTS, 'status', 'paid', []);
        \core\notification::fetch();

        plans::sync($student);

        $this->assertSame('active', $DB->get_field(plans::STUPLANS, 'status', ['id' => $newer]));
        $this->assertSame('completed', $DB->get_field(plans::STUPLANS, 'status', ['id' => $older]));
        $this->assertCount(1, \core\notification::fetch());
    }

    /**
     * The fall plan was paid in full and the spring plan was given. Then the fall check comes back.
     *
     * @return array student, fall plan id, spring plan id, spring term id, fall payment line id
     */
    private function fall_check_returned_after_a_spring_plan(): array {
        global $DB;
        $student = $this->generator->create_user_student();
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 1000]);
        $fall = plans::create($student, (int)$this->generator->create_term()->id, null, $this->custom_terms(['name' => 'Fall',
            'latefee' => 25]), 'Custom');
        $DB->set_field(plans::STUPLANS, 'timeapproved', time() - 60 * DAYSECS, ['id' => $fall]);
        $line = payments::record($student, 1000, 'check');
        $DB->set_field(billing::TXN, 'timecreated', time() - 50 * DAYSECS, ['id' => $line]);
        plans::sync($student);
        $this->assertSame('completed', $DB->get_field(plans::STUPLANS, 'status', ['id' => $fall]));
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 800]);
        $term = (int)$this->generator->create_term()->id;
        $spring = plans::create($student, $term, null, $this->custom_terms(['name' => 'Spring', 'latefee' => 25]), 'Custom');

        billing::reverse($line, 'Check returned');

        return [$student, $fall, $spring, $term, $line];
    }

    /**
     * A student has one active plan, so a returned payment doesn't reopen a completed plan while another plan is active. The
     * active plan isn't touched: the returned payment was made before it started.
     */
    public function test_a_returned_payment_does_not_reopen_a_plan_next_to_an_active_one(): void {
        global $DB;
        [$student, $fall, $spring] = $this->fall_check_returned_after_a_spring_plan();

        $this->assertSame('completed', $DB->get_field(plans::STUPLANS, 'status', ['id' => $fall]));
        $this->assertSame('active', $DB->get_field(plans::STUPLANS, 'status', ['id' => $spring]));
        $this->assertSame(0.0, plans::paid_toward($DB->get_record(plans::STUPLANS, ['id' => $spring])));
        $this->assertSame(1800.0, billing::balance((int)$student->id));
    }

    /**
     * After that, a new payment counts on the active plan, the late work happens once, for the active plan only, and the
     * student's term keeps its plan.
     */
    public function test_the_active_plan_works_after_a_returned_payment_of_an_older_plan(): void {
        global $DB;
        [$student, $fall, $spring, $term] = $this->fall_check_returned_after_a_spring_plan();
        $down = (float)$DB->get_field(plans::INSTS, 'amount', ['stuplanid' => $spring, 'seq' => 0]);

        payments::record($student, $down, 'check');

        $this->assertTrue(plans::plan_ok($student, $term));
        $this->assertSame('paid', $DB->get_field(plans::INSTS, 'status', ['stuplanid' => $spring, 'seq' => 0]));

        $this->due_in($spring, 1, -10);
        $this->due_in($fall, 1, -10);
        $counts = plans::daily();

        $this->assertSame(['reminders' => 0, 'latefees' => 1, 'holds' => 1], $counts);
        $this->assertSame(1, $DB->count_records(academic::HOLDS, ['studentid' => $student->id, 'holdtype' => 'financial']));
        $this->assertSame('active', $DB->get_field(plans::STUPLANS, 'status', ['id' => $spring]));
        $this->assertSame('completed', $DB->get_field(plans::STUPLANS, 'status', ['id' => $fall]));
    }

    /**
     * Finance is told plainly what happened: at once, on the page that made the change, and for good in the note of the plan.
     * Bringing the plans up to date again doesn't say it twice.
     */
    public function test_finance_is_told_when_a_plan_cannot_reopen(): void {
        global $DB;
        \core\notification::fetch();
        [$student, $fall] = $this->fall_check_returned_after_a_spring_plan();
        $text = get_string('plan_notreopened', 'local_studentrecords', (object)['plan' => 'Fall', 'active' => 'Spring']);

        $notices = \core\notification::fetch();
        $this->assertCount(1, $notices);
        $this->assertSame(\core\output\notification::NOTIFY_WARNING, $notices[0]->get_message_type());
        $this->assertSame(s($text), $notices[0]->get_message());
        $this->assertStringContainsString($text, $DB->get_field(plans::STUPLANS, 'note', ['id' => $fall]));
        $this->assertTrue($DB->record_exists('local_studentrecords_log', ['objecttype' => 'plan', 'objectid' => $fall,
            'info' => 'not reopened: another plan is active']));

        plans::sync($student);
        plans::daily();

        $this->assertCount(0, \core\notification::fetch());
        $this->assertSame(1, substr_count($DB->get_field(plans::STUPLANS, 'note', ['id' => $fall]), $text));
    }

    /**
     * Once no other plan is active, the plan that could not reopen does: the money is still owed.
     */
    public function test_a_plan_reopens_when_the_other_plan_has_ended(): void {
        global $DB;
        [$student, $fall, $spring] = $this->fall_check_returned_after_a_spring_plan();

        plans::cancel($spring, 'Moved to one plan');
        plans::sync($student);

        $this->assertSame('active', $DB->get_field(plans::STUPLANS, 'status', ['id' => $fall]));
    }

    /**
     * Only a reversal of a line from before a plan stays out of its total. A payment made on the plan and reversed counts
     * back, and so does a reversal of the older line on the older plan.
     */
    public function test_paid_toward_leaves_out_reversals_of_older_lines(): void {
        global $DB;
        $student = $this->generator->create_user_student();
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 1000]);
        $older = payments::record($student, 200, 'check');
        $DB->set_field(billing::TXN, 'timecreated', time() - 50 * DAYSECS, ['id' => $older]);
        $id = plans::create($student, (int)$this->generator->create_term()->id, null, $this->custom_terms(), 'Custom');
        $plan = $DB->get_record(plans::STUPLANS, ['id' => $id]);
        $newer = payments::record($student, 100, 'check');
        $this->assertSame(100.0, plans::paid_toward($plan));

        billing::reverse($older, 'Check returned');
        $this->assertSame(100.0, plans::paid_toward($plan));

        billing::reverse($newer, 'Check returned');
        $this->assertSame(0.0, plans::paid_toward($plan));

        $plan->timeapproved = time() - 60 * DAYSECS;
        $this->assertSame(0.0, plans::paid_toward($plan));
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
     * The reason of a late payment hold says what the hold is for, without an amount: it shows on the record's Holds tab to
     * staff who don't see the account, and the account shows the money.
     */
    public function test_late_hold_says_why_without_an_amount(): void {
        global $DB;
        [$student, $planid] = $this->student_on_plan(1000);
        $this->due_in($planid, 0, -10);
        $due = (int)$DB->get_field(plans::INSTS, 'duedate', ['stuplanid' => $planid, 'seq' => 0]);

        plans::daily();

        $reason = $DB->get_field(academic::HOLDS, 'reason', ['studentid' => $student->id, 'holdtype' => 'financial']);
        $this->assertStringContainsString('Late payment on the plan "Three payments"', $reason);
        $this->assertStringContainsString(catalog::show_date($due), $reason);
        $this->assertStringNotContainsString('$', $reason);
        $this->assertStringNotContainsString('250.00', $reason);
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
