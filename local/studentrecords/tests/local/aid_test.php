<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(aid::class)]
/**
 * Tests for the federal aid module: its rules, and awards and disbursements in the database.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\aid
 */
final class aid_test extends \advanced_testcase {
    /** @var \local_studentrecords_generator */
    private $generator;

    /**
     * Billing and the module on, with the admin working.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $this->generator->set_settings(['billing' => 1, 'fedaid' => 1]);
    }

    /**
     * An accepted award.
     *
     * @param \stdClass $student
     * @param float $amount
     * @return int award id
     */
    private function award(\stdClass $student, float $amount): int {
        return aid::save_award($student, ['awardyear' => '2029-30', 'fund' => 'pell', 'amount' => $amount,
            'status' => 'accepted', 'termid' => (int)$this->generator->create_term()->id]);
    }

    /**
     * Disbursement credit kinds: federal grants, federal loans, other aid; work-study is not put on the account.
     */
    public function test_credit_kind(): void {
        $this->assertSame('fedgrant', aid::credit_kind('pell'));
        $this->assertSame('fedgrant', aid::credit_kind('fseog'));
        $this->assertSame('fedloan', aid::credit_kind('dlunsub'));
        $this->assertSame('fedloan', aid::credit_kind('dlplus'));
        $this->assertNull(aid::credit_kind('fws'));
        $this->assertSame('grant', aid::credit_kind('state'));
        $this->assertSame('scholarship', aid::credit_kind('private'));
    }

    /**
     * Past the 60% point by any amount, even when the percent rounds to 60.0.
     */
    public function test_past_sixty(): void {
        $this->assertFalse(aid::past_sixty(66, 110));
        $this->assertTrue(aid::past_sixty(67, 110));
        $this->assertTrue(aid::past_sixty(6001, 10000));
        $this->assertSame(60.0, aid::share_percent(6001, 10000));
    }

    /**
     * Percent of the term completed in calendar days (Spring 2027: 2027-01-11 to 2027-04-30, 110 days).
     */
    public function test_percent_completed(): void {
        $this->assertSame(0.9, aid::percent_completed('2027-01-11', '2027-04-30', '2027-01-11'));
        $this->assertSame(60.0, aid::percent_completed('2027-01-11', '2027-04-30', '2027-03-17'));
        $this->assertSame(60.9, aid::percent_completed('2027-01-11', '2027-04-30', '2027-03-18'));
        $this->assertSame(100.0, aid::percent_completed('2027-01-11', '2027-04-30', '2027-05-20'));
        $this->assertSame(0.0, aid::percent_completed('2027-01-11', '2027-04-30', '2027-01-01'));
    }

    /**
     * 90/10 percent.
     */
    public function test_ninety_ten(): void {
        $this->assertSame(85.0, aid::ninety_ten(8500, 10000));
        $this->assertNull(aid::ninety_ten(0, 0));
    }

    /**
     * Percents of a term and the 90/10 share are exact and a half goes up. As floats 10.05 / 1000 * 100 is just under 1.005.
     */
    public function test_percents_are_exact(): void {
        $this->assertSame(12.5, aid::percent_completed('2027-01-01', '2027-01-08', '2027-01-01'));
        $this->assertSame(5.1, aid::percent_completed('2020-01-01', '2025-06-22', '2020-04-10'));
        $this->assertSame(44.9, aid::share_percent(4486, 10000), 'to four decimals, then rounded to three');
        $this->assertSame(34.5, aid::share_percent(38, 110), 'the fourth decimal is cut off first: 0.3454');
        $this->assertSame(1.01, aid::ninety_ten(10.05, 1000));
        $this->assertSame(0.13, aid::ninety_ten(1.25, 1000));
        $this->assertSame(100.0, aid::ninety_ten(500, 500));
        $this->assertNull(aid::ninety_ten(0, 0));
    }

    /**
     * A disbursement can't be more than what is left of the award, and a reversed one counts back.
     */
    public function test_disburse(): void {
        global $DB;
        $this->preventResetByRollback();
        $student = $this->generator->create_user_student();
        $award = $this->award($student, 1000);

        $first = aid::disburse($award, 600, time());
        $this->assertSame(-600.0, billing::balance((int)$student->id));

        try {
            aid::disburse($award, 400.01, time());
            $this->fail('A disbursement can\'t be more than what is left.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_aidamount', $e->errorcode);
        }

        aid::disburse($award, 400, time());
        $this->assertSame(1000.0, (float)aid::awards((int)$student->id)[$award]->disbursed);

        billing::reverse($first, 'Student not eligible');
        $this->assertSame(400.0, (float)aid::awards((int)$student->id)[$award]->disbursed);
        $this->assertSame(2, $DB->count_records(billing::TXN, ['aidid' => $award, 'txntype' => 'credit', 'reverses' => null]));
    }

    /**
     * Only accepted awards are disbursed, and only by Financial Aid staff.
     */
    public function test_disburse_needs_an_accepted_award_and_manageaid(): void {
        $this->preventResetByRollback();
        $student = $this->generator->create_user_student();
        $award = aid::save_award($student, ['awardyear' => '2029-30', 'fund' => 'pell', 'amount' => 100, 'status' => 'offered']);

        try {
            aid::disburse($award, 10, time());
            $this->fail('Only accepted awards are disbursed.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_aidnotdisbursable', $e->errorcode);
        }

        $this->setUser($student->userid);
        $this->expectException(\required_capability_exception::class);
        aid::disburse($award, 10, time());
    }

    /**
     * The reports read from the database, and a withdrawal row says which student it is.
     */
    public function test_reports(): void {
        $student = $this->generator->create_user_student(['status' => 'withdrawn']);
        $award = $this->award($student, 1000);
        aid::disburse($award, 250, make_timestamp(2030, 2, 1, 12));
        $termid = (int)aid::awards((int)$student->id)[$award]->termid;

        $this->assertSame(250.0, aid::recipients('2029-30')[0]['disbursed']);
        $this->assertCount(1, aid::disbursements(make_timestamp(2030, 1, 1), make_timestamp(2030, 3, 1)));
        $this->assertSame((int)$student->id, aid::withdrawals($termid)[0]['sid']);
        $this->assertCount(1, aid::enrollment($termid));
        $this->assertSame(100.0, aid::ninety_ten_worksheet(make_timestamp(2030, 1, 1), make_timestamp(2030, 3, 1))['percent']);
    }
}
