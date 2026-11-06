<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(aidhelp::class)]
/**
 * Tests for the Financial Aid helper math: need, loan limit warning, Return of aid worksheet, disbursement holds.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\aidhelp
 */
final class aidhelp_test extends \advanced_testcase {
    /** @var \local_studentrecords_generator */
    private $generator;

    /**
     * Billing and the federal aid module on, with the admin working.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $this->generator->set_settings(['billing' => 1, 'fedaid' => 1]);
    }

    /**
     * Need is cost minus SAI, never below zero (a negative SAI counts as zero).
     */
    public function test_need(): void {
        $this->assertSame(16250.0, aidhelp::need(18400, 2150));
        $this->assertSame(0.0, aidhelp::need(5000, 9000));
        $this->assertSame(5000.0, aidhelp::need(5000, -1500));
    }

    /**
     * First-year dependent: 5,500 in Direct Loans, 3,500 subsidized.
     */
    public function test_loan_check(): void {
        $this->assertNull(aidhelp::loan_check(['dlsub' => 3500, 'dlunsub' => 2000], 'dependent', 1));
        $over = aidhelp::loan_check(['dlsub' => 3500, 'dlunsub' => 2500], 'dependent', 1);
        $this->assertSame(6000.0, $over['total']);
        $this->assertSame(5500.0, $over['limit']);
        $this->assertSame(2000.0, $over['lowerunsubto']);
        $this->assertNotNull(aidhelp::loan_check(['dlsub' => 4000], 'independent', 1));
        $this->assertNull(aidhelp::loan_check(['dlsub' => 5500, 'dlunsub' => 7000], 'independent', 3));
        $this->assertNull(aidhelp::loan_check(['dlsub' => 100], 'unknown', 1));
    }

    /**
     * Return of aid: 38 of 112 days, the mockup example (Pell 1,850, sub 1,732, unsub 990, charges 3,940).
     */
    public function test_r2t4(): void {
        $w = aidhelp::r2t4(['pell' => 1850, 'dlsub' => 1732, 'dlunsub' => 990], 38, 112, 3940);
        $this->assertSame(4572.0, $w['paid']);
        $this->assertSame(33.9, $w['pct']);
        $this->assertEqualsWithDelta(1550, $w['earned'], 1);
        $this->assertEqualsWithDelta(3022, $w['unearned'], 1);
        $this->assertEqualsWithDelta(2604, $w['schoolshare'], 1);
        $this->assertSame(990.0, $w['returns']['dlunsub']);
        $this->assertEqualsWithDelta(1614, $w['returns']['dlsub'], 1);
        $this->assertArrayNotHasKey('pell', $w['returns']);
        $all = aidhelp::r2t4(['pell' => 1000], 70, 112, 2000);
        $this->assertSame(100.0, $all['earnedpct']);
        $this->assertSame(0.0, $all['schoolshare']);
    }

    /**
     * The worksheet is exact and a half goes up on every PHP version: 2.05 at 50% is 1.03, 101 of 2000 days is 5.1%.
     */
    public function test_r2t4_is_exact(): void {
        $w = aidhelp::r2t4(['pell' => 2.05], 50, 100, 2.05);
        $this->assertSame(50.0, $w['pct']);
        $this->assertSame(1.03, $w['earned']);
        $this->assertSame(1.02, $w['unearned']);
        $this->assertSame(1.02, $w['schoolshare']);
        $this->assertSame(0.0, $w['studentshare']);
        $this->assertSame(5.1, aidhelp::r2t4(['pell' => 100], 101, 2000, 0)['pct']);
        $this->assertSame(100.0, aidhelp::r2t4(['pell' => 100], 500, 100, 0)['pct'], 'never more than the term');
        $this->assertSame(0.0, aidhelp::r2t4(['pell' => 100], -3, 100, 0)['pct']);
        $this->assertSame(100.0, aidhelp::r2t4(['pell' => 100], 3, 0, 0)['pct']);
    }

    /**
     * Holds: a withdrawal stops; verification, counseling (loans) and half time (loans) hold.
     */
    public function test_hold_reasons(): void {
        $ok = ['verification' => 'complete', 'counseling' => true, 'withdrawn' => false, 'hours' => 12, 'halftime' => 6];
        $this->assertSame([], aidhelp::hold_reasons('dlsub', $ok));
        $this->assertSame(['stopped_withdrawn'], aidhelp::hold_reasons('pell', ['withdrawn' => true] + $ok));
        $this->assertSame(['held_counseling', 'held_halftime'], aidhelp::hold_reasons('dlunsub', ['counseling' => false,
            'hours' => 5] + $ok));
        $this->assertSame([], aidhelp::hold_reasons('pell', ['counseling' => false, 'hours' => 3] + $ok));
        $this->assertSame(['held_verification'], aidhelp::hold_reasons('pell', ['verification' => 'selected'] + $ok));
    }

    /**
     * Award years run July to June.
     */
    public function test_award_year(): void {
        $this->assertSame('2026-27', aidhelp::award_year(mktime(12, 0, 0, 11, 3, 2026)));
        $this->assertSame('2025-26', aidhelp::award_year(mktime(12, 0, 0, 6, 30, 2026)));
    }

    /**
     * The award year turns over in the school's time zone, not the server's.
     */
    public function test_award_year_uses_the_school_time_zone(): void {
        $this->generator->set_settings(['timezone' => 'America/Los_Angeles']);
        $this->assertSame('2025-26', aidhelp::award_year(gmmktime(3, 0, 0, 7, 1, 2026)), 'evening of June 30 at the school');
        $this->assertSame('2026-27', aidhelp::award_year(gmmktime(9, 0, 0, 7, 1, 2026)));
    }

    /**
     * A student who withdrew from a term after aid was paid.
     *
     * @return array [student, term id]
     */
    private function withdrawn_with_aid(): array {
        $term = $this->generator->create_term(['startdate' => '2030-01-01', 'enddate' => '2030-04-10']);
        $student = $this->generator->create_user_student();
        $student = students::get(students::save(['id' => $student->id, 'status' => 'withdrawn',
            'withdrawdate' => catalog::date_to_time('2030-01-20')]));
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 3000, 'termid' => $term->id]);
        $award = aid::save_award($student, ['awardyear' => '2029-30', 'fund' => 'dlunsub', 'amount' => 1000,
            'status' => 'accepted', 'termid' => (int)$term->id]);
        aid::disburse($award, 1000, time());
        return [$student, (int)$term->id];
    }

    /**
     * Sending the worksheet makes one refund item for Finance. Approving it charges the returned aid to the student, once.
     */
    public function test_return_of_aid_flow(): void {
        global $DB;
        $this->preventResetByRollback();
        [$student, $termid] = $this->withdrawn_with_aid();
        $this->assertSame(2000.0, billing::balance((int)$student->id));

        $refundid = aidhelp::send_return($student, $termid);

        $this->assertSame('aidreturn', $DB->get_field(refunds::REFUNDS, 'reason', ['id' => $refundid]));
        try {
            aidhelp::send_return($student, $termid);
            $this->fail('One return per term.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_returnexists', $e->errorcode);
        }

        $amount = (float)$DB->get_field(refunds::REFUNDS, 'amount', ['id' => $refundid]);
        $this->assertGreaterThan(0, $amount);
        refunds::decide($refundid, true, 'Sent back');

        $this->assertSame(round(2000 + $amount, 2), billing::balance((int)$student->id));
    }

    /**
     * A disbursement run sends what is ready, once.
     */
    public function test_send_ready_twice(): void {
        $term = $this->generator->create_term();
        $student = $this->generator->create_user_student();
        $offer = $this->generator->create_offering(['courseid' => $this->generator->create_course()->id, 'termid' => $term->id]);
        $this->generator->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        aid::save_award($student, ['awardyear' => '2029-30', 'fund' => 'pell', 'amount' => 700, 'status' => 'accepted',
            'termid' => (int)$term->id]);

        $this->assertSame(['count' => 1, 'total' => 700.0], aidhelp::send_ready((int)$term->id));
        $this->assertSame(['count' => 0, 'total' => 0.0], aidhelp::send_ready((int)$term->id));
        $this->assertSame(-700.0, billing::balance((int)$student->id));
    }

    /**
     * Handbook example, volume 5: 52 of 110 days, $1,000 of loans paid out and $1,700 more that could have been. The student
     * earned more than was paid out, so a post-withdrawal disbursement is due and nothing goes back.
     */
    public function test_r2t4_handbook_post_withdrawal_disbursement(): void {
        $w = aidhelp::r2t4(['dlsub' => 1000], 52, 110, 0, 0, 1700);

        $this->assertSame(47.3, $w['pct']);
        $this->assertSame(1277.1, $w['earned']);
        $this->assertSame(277.1, $w['pwd']);
        $this->assertSame(0.0, $w['unearned']);
        $this->assertSame(0.0, $w['schoolshare']);
        $this->assertSame([], $w['returns']);
    }

    /**
     * Handbook example: 11 of 110 days, grants only. The school returns $900, and of the student's $1,440 half the grants are
     * protected, which leaves $140 to pay back.
     */
    public function test_r2t4_handbook_grants(): void {
        $w = aidhelp::r2t4(['pell' => 1400, 'fseog' => 1200], 11, 110, 1000);

        $this->assertSame(10.0, $w['pct']);
        $this->assertSame(260.0, $w['earned']);
        $this->assertSame(2340.0, $w['unearned']);
        $this->assertSame(900.0, $w['schoolshare']);
        $this->assertSame(['pell' => 900.0], $w['returns']);
        $this->assertSame(1440.0, $w['studentshare']);
        $this->assertSame(0.0, $w['loansleft']);
        $this->assertSame(1440.0, $w['grantdue']);
        $this->assertSame(1300.0, $w['grantprotected']);
        $this->assertSame(140.0, $w['grantowed']);
        $this->assertSame(['pell' => 140.0], $w['grantreturns']);
    }

    /**
     * Handbook example: 50 of 100 days with a loan and grants. The school returns the whole loan and $1,320 of Pell, and the
     * student's $90 is under the grant protection.
     */
    public function test_r2t4_handbook_loans_first(): void {
        $w = aidhelp::r2t4(['pell' => 2750, 'fseog' => 2000, 'dlsub' => 1930], 50, 100, 6500);

        $this->assertSame(3340.0, $w['earned']);
        $this->assertSame(3340.0, $w['unearned']);
        $this->assertSame(3250.0, $w['schoolshare']);
        $this->assertSame(['dlsub' => 1930.0, 'pell' => 1320.0], $w['returns']);
        $this->assertSame(90.0, $w['studentshare']);
        $this->assertSame(0.0, $w['loansleft']);
        $this->assertSame(2375.0, $w['grantprotected']);
        $this->assertSame(0.0, $w['grantowed']);
    }

    /**
     * Handbook example of a clock-hour program: 45 of 900 scheduled hours, with the rest of the period's aid as aid that could
     * have been disbursed.
     */
    public function test_r2t4_handbook_clock_hours(): void {
        $w = aidhelp::r2t4(['pell' => 2000, 'dlsub' => 1700], 45, 900, 8500, 2000, 1700);

        $this->assertSame(5.0, $w['pct']);
        $this->assertSame(370.0, $w['earned']);
        $this->assertSame(3330.0, $w['unearned']);
        $this->assertSame(3330.0, $w['schoolshare']);
        $this->assertSame(['dlsub' => 1700.0, 'pell' => 1630.0], $w['returns']);
        $this->assertSame(0.0, $w['studentshare']);
    }

    /**
     * A percent is worked out to four decimals and rounded to three: 0.4486 is 44.9%, and 38 of 110 days is 0.3454, so 34.5%.
     * Past 60% by any amount earns everything, even when the percent shows 60.0%. Exactly 60% doesn't.
     */
    public function test_r2t4_rounding_and_the_sixty_percent_point(): void {
        $this->assertSame(44.9, aidhelp::r2t4(['pell' => 100], 4486, 10000, 0)['pct']);
        $this->assertSame(34.5, aidhelp::r2t4(['pell' => 100], 38, 110, 0)['pct']);

        $exact = aidhelp::r2t4(['pell' => 1000], 66, 110, 0);
        $this->assertSame(60.0, $exact['earnedpct']);
        $this->assertSame(600.0, $exact['earned']);

        $past = aidhelp::r2t4(['pell' => 1000], 6001, 10000, 2000);
        $this->assertSame(60.0, $past['pct']);
        $this->assertSame(100.0, $past['earnedpct']);
        $this->assertSame(0.0, $past['unearned']);
        $this->assertSame(0.0, $past['schoolshare']);
    }

    /**
     * A grant overpayment of $50 or less in a program isn't paid back. The amount is per program, not for the whole.
     */
    public function test_r2t4_small_grant_overpayments(): void {
        $w = aidhelp::r2t4(['pell' => 200, 'fseog' => 300], 0, 100, 0);
        $this->assertSame(250.0, $w['grantprotected']);
        $this->assertSame(['pell' => 200.0], $w['grantreturns']);
        $this->assertSame(200.0, $w['grantowed']);

        $this->assertSame(0.0, aidhelp::r2t4(['pell' => 1000], 46, 100, 0)['grantowed'], 'over the protection by $40');
    }

    /**
     * Loans in the student's share stay on their terms: only what is left after them can be a grant overpayment.
     */
    public function test_r2t4_loans_come_before_grants_in_the_student_share(): void {
        $w = aidhelp::r2t4(['dlunsub' => 1000, 'pell' => 1000], 0, 100, 0);

        $this->assertSame(2000.0, $w['studentshare']);
        $this->assertSame(1000.0, $w['loansleft']);
        $this->assertSame(1000.0, $w['grantdue']);
        $this->assertSame(500.0, $w['grantowed']);
    }

    /**
     * The limits shrink with the share of full time: 50% of $5,500 is $2,750. Loans over that are over the limit.
     */
    public function test_loan_limits_for_less_than_full_time(): void {
        $this->assertSame([2750, 1750], aidhelp::limits('dependent', 1, 50));
        $this->assertSame([5500, 3500], aidhelp::limits('dependent', 1));
        $this->assertNull(aidhelp::limits('unknown', 1));

        $this->assertNull(aidhelp::loan_check(['dlsub' => 1750, 'dlunsub' => 1000], 'dependent', 1, 50));
        $over = aidhelp::loan_check(['dlsub' => 1750, 'dlunsub' => 1500], 'dependent', 1, 50);
        $this->assertSame(2750.0, $over['limit']);
        $this->assertSame(1000.0, $over['lowerunsubto']);
    }

    /**
     * The share is the credits over full-time credits across the terms: 6 and 12 of 24 is 75%, and 6 and 18 of 24 is 100%, as
     * more is never above 100. A term with nothing registered yet doesn't count, and neither does a term the student left.
     */
    public function test_enrolled_percent(): void {
        global $DB;
        $student = $this->generator->create_user_student();
        $hours = function (array $courses) use ($student) {
            $term = $this->generator->create_term();
            foreach ($courses as $credits) {
                $course = $this->generator->create_course(['hours' => $credits]);
                $offer = $this->generator->create_offering(['courseid' => $course->id, 'termid' => $term->id]);
                $this->generator->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
            }
            return (int)$term->id;
        };
        $six = $hours([3, 3]);
        $twelve = $hours([6, 6]);
        $eighteen = $hours([6, 6, 6]);
        $empty = (int)$this->generator->create_term()->id;

        $this->assertSame(50, aidhelp::enrolled_percent($student, '2026-27', [$six]));
        $this->assertSame(75, aidhelp::enrolled_percent($student, '2026-27', [$six, $twelve]));
        $this->assertSame(100, aidhelp::enrolled_percent($student, '2026-27', [$six, $eighteen]));
        $this->assertSame(100, aidhelp::enrolled_percent($student, '2026-27', [$eighteen]));
        $this->assertSame(50, aidhelp::enrolled_percent($student, '2026-27', [$six, $empty]));
        $this->assertSame(100, aidhelp::enrolled_percent($student, '2026-27', [$twelve]));
        $this->assertSame(100, aidhelp::enrolled_percent($student, '2026-27', []));

        // The rule starts with the 2026-27 award year.
        $this->assertSame(100, aidhelp::enrolled_percent($student, '2025-26', [$six]));

        // A term the student left says nothing about their load.
        $DB->set_field(registration::REG, 'status', 'withdrawn', ['studentid' => $student->id]);
        $this->assertSame(100, aidhelp::enrolled_percent($student, '2026-27', [$six]));
    }

    /**
     * The rule is for term programs: a clock-hour program stays at 100.
     */
    public function test_enrolled_percent_leaves_clock_hour_programs_alone(): void {
        $program = $this->generator->create_program(['unittype' => 'clock']);
        $student = $this->generator->create_user_student(['programid' => $program->id]);
        $term = $this->generator->create_term();
        $offer = $this->generator->create_offering(['courseid' => $this->generator->create_course()->id, 'termid' => $term->id]);
        $this->generator->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);

        $this->assertSame(100, aidhelp::enrolled_percent($student, '2026-27', [(int)$term->id]));
    }

    /**
     * A program at a level the school marks as graduate is graduate. No setting means nobody is.
     */
    public function test_is_graduate(): void {
        global $DB;
        $graduate = $this->generator->create_program(['level' => 'Graduate']);
        $undergraduate = $this->generator->create_program();
        $student = $this->generator->create_user_student(['programid' => $graduate->id]);
        $other = $this->generator->create_user_student(['programid' => $undergraduate->id]);
        $this->assertFalse(aidhelp::is_graduate($student));

        $level = $DB->get_field('local_studentrecords_level', 'id', ['name' => 'Graduate']);
        $this->generator->set_settings(['gradlevels' => $level]);

        $this->assertTrue(aidhelp::is_graduate($student));
        $this->assertFalse(aidhelp::is_graduate($other));
        $this->assertFalse(aidhelp::is_graduate($this->generator->create_user_student()));
    }

    /**
     * Details from the form: amounts, whole days and a real date get through. Anything else is left out for the default.
     */
    public function test_worksheet_details(): void {
        $details = aidhelp::worksheet_details(['withdrew' => '2030-01-20', 'determined' => '2030-02-03', 'charges' => '$1,250.50',
            'couldgrants' => '', 'couldloans' => 'abc', 'breakdays' => '7', 'breaksbefore' => '-2', 'hoursdone' => '45.5']);

        $this->assertSame('2030-01-20', $details['withdrew']);
        $this->assertSame('2030-02-03', $details['determined']);
        $this->assertSame(1250.5, $details['charges']);
        $this->assertSame(7, $details['breakdays']);
        $this->assertSame(45.5, $details['hoursdone']);
        $this->assertArrayNotHasKey('couldgrants', $details);
        $this->assertArrayNotHasKey('couldloans', $details);
        $this->assertArrayNotHasKey('breaksbefore', $details);

        $this->assertSame([], aidhelp::worksheet_details(['determined' => '2030-02-31', 'breakdays' => '99999']));
    }

    /**
     * The term is 100 days and the student left on day 20. A 10-day break after that comes off the term only, and a break
     * before it comes off both.
     */
    public function test_worksheet_leaves_out_scheduled_breaks(): void {
        [$student, $termid] = $this->withdrawn_with_aid();

        $this->assertSame(20.0, aidhelp::worksheet($student, $termid)['pct']);

        $after = aidhelp::worksheet($student, $termid, ['breakdays' => 10]);
        $this->assertSame(22.2, $after['pct']);
        $this->assertSame(90.0, $after['period']);

        $before = aidhelp::worksheet($student, $termid, ['breakdays' => 10, 'breaksbefore' => 5]);
        $this->assertSame(16.7, $before['pct']);
        $this->assertSame(15.0, $before['done']);
    }

    /**
     * The return is due 45 days after the date the school determined the withdrawal, which defaults to the withdrawal date.
     * The days are counted as dates, so a clock change doesn't move the due date.
     */
    public function test_worksheet_due_date_runs_from_the_determination(): void {
        [$student, $termid] = $this->withdrawn_with_aid();

        $this->assertSame(catalog::date_to_time('2030-03-06'), aidhelp::worksheet($student, $termid)['duedate']);
        $this->assertSame(
            catalog::date_to_time('2030-03-18'),
            aidhelp::worksheet($student, $termid, ['determined' => '2030-02-01'])['duedate']
        );
    }

    /**
     * The charges are those from before the day the student left. A charge reversed on that day or later still counts, and a
     * later charge doesn't. A refund counts from the day of its event, not the day it was approved. The aid a return put back
     * on the account isn't a charge, and staff can type another figure.
     */
    public function test_worksheet_uses_the_charges_when_the_student_left(): void {
        global $DB;
        $this->preventResetByRollback();
        [$student, $termid] = $this->withdrawn_with_aid();
        $this->assertSame(3000.0, aidhelp::worksheet($student, $termid)['charges']);

        // Reversed on or after the day the student left: it still counts. Reversed the day before: it doesn't.
        $late = $this->generator->create_charge(['studentid' => $student->id, 'amount' => 400, 'termid' => $termid,
            'txndate' => catalog::date_to_time('2030-01-05')]);
        $reversal = billing::reverse($late, 'Taken off');
        foreach (['2030-02-01' => 3400.0, '2030-01-20' => 3400.0, '2030-01-19' => 3000.0] as $day => $expected) {
            $DB->set_field(billing::TXN, 'txndate', catalog::date_to_time($day), ['id' => $reversal]);
            $this->assertSame($expected, aidhelp::worksheet($student, $termid)['charges'], $day);
        }
        // Early on the day the student left is that day too.
        $DB->set_field(billing::TXN, 'txndate', catalog::day_start('2030-01-20') + HOURSECS, ['id' => $reversal]);
        $this->assertSame(3400.0, aidhelp::worksheet($student, $termid)['charges']);

        // A charge made after the student left stays out, and the page can say so.
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 100, 'termid' => $termid,
            'txndate' => catalog::date_to_time('2030-01-22')]);
        $w = aidhelp::worksheet($student, $termid);
        $this->assertSame(3400.0, $w['charges']);
        $this->assertSame(100.0, $w['chargeslater']);

        // A class dropped on 01-10 with a refund approved on 01-25.
        $charge = $this->generator->create_charge(['studentid' => $student->id, 'amount' => 600, 'termid' => $termid,
            'txndate' => catalog::date_to_time('2030-01-05')]);
        $refundid = $DB->insert_record(refunds::REFUNDS, (object)['studentid' => $student->id, 'termid' => $termid,
            'reason' => 'drop', 'eventdate' => catalog::date_to_time('2030-01-10'), 'details' => '[]', 'amount' => 600,
            'status' => 'approved', 'userid' => 2, 'timecreated' => time(), 'timemodified' => time()]);
        billing::add_line(['studentid' => $student->id, 'txntype' => 'refund', 'amount' => -600, 'termid' => $termid,
            'chargeid' => $charge, 'refundid' => $refundid, 'description' => 'Dropped a class',
            'txndate' => catalog::date_to_time('2030-01-25')]);
        $this->assertSame(3400.0, aidhelp::worksheet($student, $termid)['charges']);

        $refundid = aidhelp::send_return($student, $termid);
        refunds::decide($refundid, true, 'Sent back');
        $this->assertTrue($DB->record_exists(billing::TXN, ['refundid' => $refundid, 'txntype' => 'charge']));
        $this->assertSame(3400.0, aidhelp::worksheet($student, $termid)['charges']);

        $this->assertSame(500.0, aidhelp::worksheet($student, $termid, ['charges' => 500.0])['charges']);
        $this->assertSame(3400.0, aidhelp::worksheet($student, $termid, ['charges' => 500.0])['chargesfound']);
    }

    /**
     * The day a student left, in order: attendance in the term, the file's last attended date, the file's withdrawal date, the
     * day the last class ended. Dates outside the term don't count, so an old date from another term can't end up here.
     */
    public function test_left_term(): void {
        global $DB;
        [$student, $termid] = $this->withdrawn_with_aid();
        $term = $DB->get_record('local_studentrecords_term', ['id' => $termid]);

        $this->assertSame('2030-01-20', aid::left_term($student, $term));

        $student->lastattended = catalog::date_to_time('2030-01-10');
        $this->assertSame('2030-01-10', aid::left_term($student, $term), 'last attended comes first');

        $student->lastattended = catalog::date_to_time('2029-11-10');
        $this->assertSame('2030-01-20', aid::left_term($student, $term), 'a date from another term says nothing');

        $student->lastattended = null;
        $student->withdrawdate = catalog::date_to_time('2029-11-10');
        $this->assertSame('', aid::left_term($student, $term));

        $offer = $this->generator->create_offering(['courseid' => $this->generator->create_course()->id, 'termid' => $termid]);
        $regid = $this->generator->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        $DB->set_field(registration::REG, 'timeended', catalog::date_to_time('2030-02-02'), ['id' => $regid]);
        $this->assertSame('2030-02-02', aid::left_term($student, $term), 'the day the last class ended');
    }

    /**
     * Staff can give another withdrawal date, in the term. With no date anywhere the worksheet says so and can't be sent. It
     * never uses today.
     */
    public function test_worksheet_needs_a_date(): void {
        $this->preventResetByRollback();
        [$student, $termid] = $this->withdrawn_with_aid();
        $student->withdrawdate = null;

        $w = aidhelp::worksheet($student, $termid);
        $this->assertTrue($w['needsdate']);
        try {
            aidhelp::send_return($student, $termid);
            $this->fail('A worksheet needs a date.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_returndate', $e->errorcode);
        }

        $w = aidhelp::worksheet($student, $termid, ['withdrew' => '2030-01-20']);
        $this->assertFalse($w['needsdate']);
        $this->assertSame(20.0, $w['pct']);

        $w = aidhelp::worksheet($student, $termid, ['withdrew' => '2031-01-01']);
        $this->assertTrue($w['needsdate']);
        $this->assertSame(['withdrew'], $w['ignored']);
    }

    /**
     * Aid counts as paid out as of the date the school determined the withdrawal. What came later stays out, and the page can
     * say so.
     */
    public function test_worksheet_counts_aid_paid_by_the_determination(): void {
        global $DB;
        [$student, $termid] = $this->withdrawn_with_aid();
        $award = aid::save_award($student, ['awardyear' => '2029-30', 'fund' => 'pell', 'amount' => 500, 'status' => 'accepted',
            'termid' => $termid]);
        aid::disburse($award, 500, catalog::date_to_time('2030-02-20'));

        $w = aidhelp::worksheet($student, $termid, ['determined' => '2030-02-01']);
        $this->assertSame(1000.0, $w['paid']);
        $this->assertSame(500.0, $w['paidlater']);
        $this->assertArrayNotHasKey('pell', $w['funds']);

        $w = aidhelp::worksheet($student, $termid, ['determined' => '2030-02-20']);
        $this->assertSame(1500.0, $w['paid']);
        $this->assertSame(0.0, $w['paidlater']);

        // A determination can't come before the withdrawal.
        $w = aidhelp::worksheet($student, $termid, ['determined' => '2030-01-01']);
        $this->assertSame(['determined'], $w['ignored']);
        $this->assertSame('2030-01-20', $w['determinedday']);
    }

    /**
     * Federal aid paid out with no term is on no worksheet, so the page says how much.
     */
    public function test_worksheet_reports_aid_with_no_term(): void {
        [$student, $termid] = $this->withdrawn_with_aid();
        $award = aid::save_award($student, ['awardyear' => '2029-30', 'fund' => 'pell', 'amount' => 800, 'status' => 'accepted']);
        aid::disburse($award, 800, time());

        $w = aidhelp::worksheet($student, $termid);

        $this->assertSame(1000.0, $w['paid']);
        $this->assertSame(800.0, $w['noterm']);
    }

    /**
     * Break days that can't fit, for example more than the days left in the term, are left out and named.
     */
    public function test_worksheet_refuses_break_days_that_do_not_fit(): void {
        [$student, $termid] = $this->withdrawn_with_aid();

        $w = aidhelp::worksheet($student, $termid, ['breakdays' => 95]);
        $this->assertSame(20.0, $w['pct']);
        $this->assertSame(['breakdays', 'breaksbefore'], $w['ignored']);

        $w = aidhelp::worksheet($student, $termid, ['breakdays' => 5, 'breaksbefore' => 6]);
        $this->assertSame(['breakdays', 'breaksbefore'], $w['ignored']);
    }

    /**
     * A clock-hour program counts scheduled hours. Until staff enter them the worksheet shows days and can't be sent.
     */
    public function test_worksheet_needs_scheduled_hours_for_a_clock_hour_program(): void {
        global $DB;
        $this->preventResetByRollback();
        [$student, $termid] = $this->withdrawn_with_aid();
        $program = $this->generator->create_program(['unittype' => 'clock']);
        $DB->set_field('local_studentrecords_student', 'programid', $program->id, ['id' => $student->id]);
        $student = students::get((int)$student->id);

        $this->assertTrue(aidhelp::worksheet($student, $termid)['needshours']);
        $this->assertTrue(aidhelp::worksheet($student, $termid, ['hoursperiod' => 900.0])['needshours'], 'both are needed');
        $tomany = aidhelp::worksheet($student, $termid, ['hoursdone' => 950.0, 'hoursperiod' => 900.0]);
        $this->assertTrue($tomany['needshours']);
        $this->assertSame(['hoursdone'], $tomany['ignored']);
        try {
            aidhelp::send_return($student, $termid);
            $this->fail('A clock-hour worksheet needs hours.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_returnhours', $e->errorcode);
        }

        $w = aidhelp::worksheet($student, $termid, ['hoursdone' => 45.0, 'hoursperiod' => 900.0]);
        $this->assertFalse($w['needshours']);
        $this->assertSame(5.0, $w['pct']);
        $this->assertGreaterThan(0, aidhelp::send_return($student, $termid, ['hoursdone' => 45.0, 'hoursperiod' => 900.0]));
    }

    /**
     * Sending uses the details on the worksheet: what goes to Finance is what staff saw.
     */
    public function test_send_return_uses_the_details(): void {
        global $DB;
        [$student, $termid] = $this->withdrawn_with_aid();
        $details = ['charges' => 500.0, 'determined' => '2030-02-01'];
        $w = aidhelp::worksheet($student, $termid, $details);

        $refund = $DB->get_record(refunds::REFUNDS, ['id' => aidhelp::send_return($student, $termid, $details)]);

        $this->assertSame(400.0, $w['schoolshare']);
        $this->assertEquals(400, $refund->amount);
        $this->assertEquals($w['duedate'], $refund->duedate);
    }

    /**
     * What goes to Finance is what staff saw. If the figures moved after they opened the worksheet, nothing is sent.
     */
    public function test_send_return_refuses_changed_figures(): void {
        global $DB;
        $this->preventResetByRollback();
        [$student, $termid] = $this->withdrawn_with_aid();
        $saw = billing::cents(aidhelp::worksheet($student, $termid)['schoolshare']);

        try {
            aidhelp::send_return($student, $termid, [], $saw + 1);
            $this->fail('The figures changed.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_returnchanged', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records(refunds::REFUNDS));

        $this->assertGreaterThan(0, aidhelp::send_return($student, $termid, [], $saw));
    }

    /**
     * A student with no classes in the term gets no aid, grants included: it would be an overpayment. Without the hours known
     * nothing changes.
     */
    public function test_a_student_with_no_classes_gets_no_aid(): void {
        $this->assertSame(['held_noclasses'], aidhelp::hold_reasons('pell', ['hours' => 0.0]));
        $this->assertSame(['held_noclasses'], aidhelp::hold_reasons('dlsub', ['hours' => 0.0, 'counseling' => true]));
        $this->assertSame([], aidhelp::hold_reasons('pell', ['hours' => 3.0]));
        $this->assertSame([], aidhelp::hold_reasons('pell', []));
    }

    /**
     * Only the subsidized part over its limit is a problem with that loan, not with the total.
     */
    public function test_loan_check_names_the_loan_to_lower(): void {
        $this->assertSame('dlsub', aidhelp::loan_check(['dlsub' => 4000], 'dependent', 1)['fund']);
        $this->assertSame('dlunsub', aidhelp::loan_check(['dlsub' => 3500, 'dlunsub' => 2500], 'dependent', 1)['fund']);
    }

    /**
     * Aid accepted and not paid out shows as a hint for the form, by kind. It doesn't change the figures.
     */
    public function test_worksheet_hints_at_aid_not_paid_out(): void {
        [$student, $termid] = $this->withdrawn_with_aid();
        aid::save_award($student, ['awardyear' => '2029-30', 'fund' => 'pell', 'amount' => 700, 'status' => 'accepted',
            'termid' => $termid]);
        aid::save_award($student, ['awardyear' => '2029-30', 'fund' => 'dlsub', 'amount' => 300, 'status' => 'offered',
            'termid' => $termid]);

        $w = aidhelp::worksheet($student, $termid);

        $this->assertSame(['grants' => 700.0, 'loans' => 0.0], $w['notpaid']);
        $this->assertSame(0.0, $w['couldhave']);
        $this->assertSame(1000.0, $w['paid']);
    }
}
