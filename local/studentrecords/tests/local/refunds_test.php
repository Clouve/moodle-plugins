<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(refunds::class)]
/**
 * Tests for refunds: the rules, with example refund rules and Spring 2027 dates (starts Monday 2027-01-11, add/drop ends
 * 2027-01-19, ends 2027-04-30), and the flow from drop to money paid back, with its database.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\refunds
 */
final class refunds_test extends \advanced_testcase {
    /** @var \local_studentrecords_generator */
    private $generator;

    /**
     * Billing on, with the admin working. These tests go on after a refused step, so each needs its transactions to be
     * real ones: a refused step rolls back only its own work.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $this->generator->set_settings(['billing' => 1]);
    }

    /**
     * A refund rule that fits at any time.
     *
     * @param float $tuitionpct
     * @return int rule id
     */
    private function rule(float $tuitionpct = 100): int {
        return refunds::save_rule(['sortorder' => 1, 'name' => 'Always', 'ruletype' => 'after', 'value' => 0,
            'tuitionpct' => $tuitionpct, 'feespct' => 100, 'allfees' => 0]);
    }

    /**
     * A registered student with tuition charged per course.
     *
     * @param float $tuition
     * @return array [student, registration id]
     */
    private function registered(float $tuition): array {
        $student = $this->generator->create_user_student();
        $this->generator->create_fee(['name' => 'Tuition', 'kind' => 'tuition', 'basis' => 'course', 'amount' => $tuition]);
        $offer = $this->generator->create_offering(['courseid' => $this->generator->create_course()->id,
            'termid' => $this->generator->create_term()->id]);
        $regid = $this->generator->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        return [$student, $regid];
    }

    /**
     * A registration.
     *
     * @param int $regid
     * @return \stdClass
     */
    private function reg(int $regid): \stdClass {
        global $DB;
        return $DB->get_record(registration::REG, ['id' => $regid], '*', MUST_EXIST);
    }

    /**
     * The error code a piece of code stops with.
     *
     * @param callable $work
     * @return string
     */
    private function error_of(callable $work): string {
        try {
            $work();
        } catch (\moodle_exception $e) {
            return $e->errorcode;
        }
        return '';
    }

    /**
     * A user with a role made of capabilities, at site level.
     *
     * @param string[] $capabilities
     * @return \stdClass
     */
    private function user_with(array $capabilities): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_system::instance();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id);
        }
        role_assign($roleid, $user->id, $context->id);
        return $user;
    }

    /**
     * Staff who approve refunds can't decide a refund on their own student account, and the refund stays as it was.
     */
    public function test_staff_cannot_decide_their_own_refund(): void {
        global $DB;
        $this->rule();
        [$student, $regid] = $this->registered(1000);
        $refundid = refunds::propose($regid, 'drop');
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_system::instance();
        assign_capability('local/studentrecords:approverefund', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $student->userid, $context->id);
        $this->setUser($student->userid);

        foreach ([true, false] as $approve) {
            $this->assertSame('err_refundself', $this->error_of(fn() => refunds::decide($refundid, $approve, 'Mine')));
        }

        $this->assertSame('proposed', $DB->get_field(refunds::REFUNDS, 'status', ['id' => $refundid]));
        $this->assertSame(1000.0, billing::balance((int)$student->id));
    }

    /**
     * Example refund rules in order (cancel period, before the first class, during add/drop, after).
     *
     * @return array
     */
    protected function rules(): array {
        $r = fn($id, $type, $value, $tuition, $fees, $all) => (object)['id' => $id, 'ruletype' => $type, 'value' => $value,
            'tuitionpct' => $tuition, 'feespct' => $fees, 'allfees' => $all, 'active' => 1];
        return [$r(1, 'cancel', 3, 100, 100, 1), $r(2, 'beforestart', 0, 100, 100, 0), $r(3, 'adddrop', 0, 100, 100, 0),
            $r(4, 'after', 0, 0, 0, 0)];
    }

    /**
     * Dates of a drop.
     *
     * @param string $event
     * @param string $registered
     * @return array
     */
    protected function c(string $event, string $registered): array {
        return ['event' => $event, 'registered' => $registered, 'start' => '2027-01-11', 'adddropend' => '2027-01-19',
            'end' => '2027-04-30'];
    }

    /**
     * Business days skip Saturday and Sunday.
     */
    public function test_business_days(): void {
        $this->assertSame(3, refunds::business_days('2027-01-04', '2027-01-07'));
        $this->assertSame(3, refunds::business_days('2026-10-23', '2026-10-28'), 'Friday to Wednesday over a weekend');
        $this->assertSame(0, refunds::business_days('2027-01-09', '2027-01-10'), 'Saturday to Sunday');
        $this->assertSame(0, refunds::business_days('2027-01-07', '2027-01-04'));
    }

    /**
     * The first rule that fits: cancel period, before the first class, add/drop, after.
     */
    public function test_pick_rule(): void {
        $pick = fn($event, $registered) => refunds::pick_rule($this->rules(), $this->c($event, $registered))->id;
        $this->assertSame(1, $pick('2027-01-07', '2027-01-04'), 'within 3 business days');
        $this->assertSame(2, $pick('2027-01-08', '2027-01-04'), '4 business days, before the first class');
        $this->assertSame(1, $pick('2027-01-13', '2027-01-11'), 'registered on the first day, cancels 2 days later');
        $this->assertSame(3, $pick('2027-01-15', '2026-12-01'), 'during add/drop');
        $this->assertSame(3, $pick('2027-01-19', '2026-12-01'), 'last day of add/drop');
        $this->assertSame(4, $pick('2027-01-20', '2026-12-01'), 'after add/drop: nothing back');
        $inactive = $this->rules();
        $inactive[3]->active = 0;
        $this->assertNull(refunds::pick_rule($inactive, $this->c('2027-02-01', '2026-12-01')), 'no rule fits');
    }

    /**
     * Percent of the term and days after the start.
     */
    public function test_rule_fits_term(): void {
        $pct = ['ruletype' => 'pctterm', 'value' => 60];
        $this->assertTrue(refunds::rule_fits($pct, $this->c('2027-03-17', '2026-12-01')), '65 of 109 days');
        $this->assertFalse(refunds::rule_fits($pct, $this->c('2027-03-18', '2026-12-01')), '66 of 109 days');
        $this->assertTrue(refunds::rule_fits($pct, $this->c('2027-01-02', '2026-12-01')), 'before the start');
        $days = ['ruletype' => 'days', 'value' => 14];
        $this->assertTrue(refunds::rule_fits($days, $this->c('2027-01-25', '2026-12-01')));
        $this->assertFalse(refunds::rule_fits($days, $this->c('2027-01-26', '2026-12-01')));
        $noadddrop = $this->c('2027-01-12', '2026-12-01');
        $noadddrop['adddropend'] = '';
        $this->assertFalse(refunds::rule_fits(['ruletype' => 'adddrop', 'value' => 0], $noadddrop), 'term without add/drop');
    }

    /**
     * A drop exactly on the percent of a rule fits it. As floats, 7 / 100 * 100 is a little over 7.
     */
    public function test_rule_fits_on_the_exact_percent(): void {
        $c = ['event' => '2027-01-08', 'registered' => '2026-12-01', 'start' => '2027-01-01', 'adddropend' => '',
            'end' => '2027-04-11'];
        $this->assertTrue(refunds::rule_fits(['ruletype' => 'pctterm', 'value' => 7], $c), '7 of 100 days');
        $this->assertFalse(refunds::rule_fits(['ruletype' => 'pctterm', 'value' => 6.99], $c));
        $this->assertTrue(refunds::rule_fits(['ruletype' => 'pctterm', 'value' => 7.01], $c));
    }

    /**
     * Percent back: tuition and refundable fees by the rule; non-refundable fees only when the rule says all.
     */
    public function test_percent(): void {
        [$cancel, $before, , $after] = $this->rules();
        $this->assertSame(100.0, refunds::percent($cancel, 'fee', false), 'cancel period: registration fee back too');
        $this->assertSame(0.0, refunds::percent($before, 'fee', false), 'before start: registration fee kept');
        $this->assertSame(100.0, refunds::percent($before, 'tuition', true));
        $this->assertSame(0.0, refunds::percent($after, 'tuition', true));
        $this->assertSame(0.0, refunds::percent(null, 'tuition', true));
        $half = (object)['tuitionpct' => 50, 'feespct' => 25, 'allfees' => 0];
        $this->assertSame(50.0, refunds::percent($half, 'tuition', true));
        $this->assertSame(25.0, refunds::percent($half, 'fee', true));
    }

    /**
     * Approval puts one refund line per charge on the account, and the approver and the date are kept.
     */
    public function test_approve(): void {
        global $DB, $USER;
        $this->rule();
        [$student, $regid] = $this->registered(1000);
        $this->assertSame(1000.0, billing::balance((int)$student->id));
        $refundid = refunds::propose($regid, 'drop');
        $this->assertSame('proposed', $DB->get_field(refunds::REFUNDS, 'status', ['id' => $refundid]));
        $this->assertSame(1000.0, billing::balance((int)$student->id), 'nothing changes before approval');

        refunds::decide($refundid, true, 'OK');

        $refund = $DB->get_record(refunds::REFUNDS, ['id' => $refundid]);
        $this->assertSame('approved', $refund->status);
        $this->assertSame((int)$USER->id, (int)$refund->decidedby);
        $this->assertSame(0.0, billing::balance((int)$student->id));
        $this->assertSame(1, $DB->count_records(billing::TXN, ['refundid' => $refundid, 'txntype' => 'refund']));
    }

    /**
     * A refund of a charge is rounded to the cent, a half cent going up, on every PHP version.
     */
    public function test_figure_is_exact(): void {
        $this->rule(50);
        [, $regid] = $this->registered(1.15);

        $figured = refunds::figure($this->reg($regid), time());

        $this->assertSame(0.58, $figured['amount']);
    }

    /**
     * Rejecting leaves the account as it is.
     */
    public function test_reject(): void {
        global $DB;
        $this->rule();
        [$student, $regid] = $this->registered(1000);
        $refundid = refunds::propose($regid, 'drop');

        refunds::decide($refundid, false, 'Attended too long');

        $this->assertSame('rejected', $DB->get_field(refunds::REFUNDS, 'status', ['id' => $refundid]));
        $this->assertSame(1000.0, billing::balance((int)$student->id));
    }

    /**
     * Managing billing is not enough to decide a refund, and neither is seeing it.
     */
    public function test_decide_needs_approverefund(): void {
        global $DB;
        $this->rule();
        [$student, $regid] = $this->registered(1000);
        $refundid = refunds::propose($regid, 'drop');
        $clerk = $this->user_with(['local/studentrecords:viewbilling', 'local/studentrecords:managebilling']);
        $this->setUser($clerk);

        try {
            refunds::decide($refundid, true, 'Mine now');
            $this->fail('Only people with approverefund decide a refund.');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString('nopermissions', $e->errorcode);
        }

        $this->assertSame('proposed', $DB->get_field(refunds::REFUNDS, 'status', ['id' => $refundid]));
        $this->assertSame(1000.0, billing::balance((int)$student->id));

        $this->setUser($this->user_with(['local/studentrecords:approverefund']));
        refunds::decide($refundid, true, 'OK');
        $this->assertSame(0.0, billing::balance((int)$student->id));
    }

    /**
     * A refund is decided once.
     */
    public function test_decide_twice_is_refused(): void {
        $this->rule();
        [$student, $regid] = $this->registered(1000);
        $refundid = refunds::propose($regid, 'drop');
        refunds::decide($refundid, true, 'OK');

        $this->assertSame('err_refunddecided', $this->error_of(fn() => refunds::decide($refundid, true, 'Again')));
        $this->assertSame(0.0, billing::balance((int)$student->id));
    }

    /**
     * A refund figured before its charge was reversed can't be approved: the student would get money for a charge that is
     * gone. It waits to be figured again, which shows there is nothing left to refund.
     */
    public function test_approve_after_the_charge_was_reversed(): void {
        global $DB;
        $this->rule();
        [$student, $regid] = $this->registered(1000);
        $refundid = refunds::propose($regid, 'drop');
        billing::reverse((int)$DB->get_field(billing::TXN, 'id', ['regid' => $regid, 'txntype' => 'charge']), 'Billed by mistake');

        $this->assertSame('err_refundstale', $this->error_of(fn() => refunds::decide($refundid, true, 'OK')));
        $this->assertSame(0.0, billing::balance((int)$student->id));
        $this->assertSame('proposed', $DB->get_field(refunds::REFUNDS, 'status', ['id' => $refundid]));

        refunds::refigure($refundid, time());
        $this->assertSame('none', $DB->get_field(refunds::REFUNDS, 'status', ['id' => $refundid]));
    }

    /**
     * Two refunds figured for the same charge (a drop, a new registration, a second drop) give the money back once.
     */
    public function test_same_charge_is_refunded_once(): void {
        $this->rule();
        [$student, $regid] = $this->registered(1000);
        $first = refunds::propose($regid, 'drop');
        $second = refunds::propose($regid, 'drop');

        refunds::decide($first, true, 'OK');

        $this->assertSame('err_refundstale', $this->error_of(fn() => refunds::decide($second, true, 'OK')));
        $this->assertSame(0.0, billing::balance((int)$student->id));
    }

    /**
     * A partly refunded charge has only the rest left, and a later refund is figured on that.
     */
    public function test_refund_is_figured_on_what_is_left(): void {
        global $DB;
        $this->rule(40);
        [$student, $regid] = $this->registered(1000);
        refunds::decide(refunds::propose($regid, 'drop'), true, 'OK');

        $second = refunds::propose($regid, 'drop');

        $this->assertSame(600.0, billing::balance((int)$student->id));
        $this->assertSame(240.0, (float)$DB->get_field(refunds::REFUNDS, 'amount', ['id' => $second]));
    }

    /**
     * Figuring again uses the new day and changes nothing on the account. A return of aid has no registration to figure
     * from, so it is refused with an error a person can read.
     */
    public function test_refigure(): void {
        global $DB;
        $this->rule();
        [$student, $regid] = $this->registered(1000);
        $refundid = refunds::propose($regid, 'drop');
        $day = time() + 5 * DAYSECS;

        refunds::refigure($refundid, $day);

        $this->assertSame($day, (int)$DB->get_field(refunds::REFUNDS, 'eventdate', ['id' => $refundid]));
        $this->assertSame(1000.0, billing::balance((int)$student->id));

        refunds::decide($refundid, true, 'OK');
        $this->assertSame('err_refunddecided', $this->error_of(fn() => refunds::refigure($refundid, time())));

        $aidreturn = (int)$DB->insert_record(refunds::REFUNDS, (object)['studentid' => $student->id, 'reason' => 'aidreturn',
            'eventdate' => time(), 'details' => '[]', 'amount' => 10, 'status' => 'proposed', 'timecreated' => time(),
            'timemodified' => time()]);
        $this->assertFalse(refunds::can_refigure($DB->get_record(refunds::REFUNDS, ['id' => $aidreturn])));
        $this->assertSame('err_refunddecided', $this->error_of(fn() => refunds::refigure($aidreturn, time())));
    }

    /**
     * Aid sent back to the government is charged to the student, but it is not a school charge to refund.
     */
    public function test_return_of_aid_charge_is_not_refunded(): void {
        $this->rule();
        [$student, $regid] = $this->registered(1000);
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 500, 'refundid' => 1, 'refundable' => 0,
            'termid' => refunds::figure($this->reg($regid), time())['termid']]);

        $this->assertSame(1000.0, refunds::figure($this->reg($regid), time())['amount']);
    }

    /**
     * Money paid back can't be more than the school owes, in one go or in two.
     */
    public function test_payout(): void {
        $student = $this->generator->create_user_student();
        billing::add_line(['studentid' => $student->id, 'txntype' => 'credit', 'amount' => -100.5, 'method' => 'adjustment',
            'description' => 'Overpaid']);

        $this->assertSame('err_payoutamount', $this->error_of(fn() => refunds::payout($student, 100.51, 'check')));
        $this->assertSame('err_payoutamount', $this->error_of(fn() => refunds::payout($student, 0, 'check')));
        refunds::payout($student, 60, 'check');
        $this->assertSame(-40.5, billing::balance((int)$student->id));
        $this->assertSame('err_payoutamount', $this->error_of(fn() => refunds::payout($student, 60, 'check')));
        refunds::payout($student, 40.5, 'check');
        $this->assertSame(0.0, billing::balance((int)$student->id));
        $this->assertSame('err_payoutamount', $this->error_of(fn() => refunds::payout($student, 0.01, 'check')));
    }

    /**
     * Nothing to pay back while the student owes.
     */
    public function test_payout_when_the_student_owes(): void {
        $student = $this->generator->create_user_student();
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 50]);

        $this->assertSame('err_payoutamount', $this->error_of(fn() => refunds::payout($student, 10, 'check')));
    }
}
