<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(payments::class)]
/**
 * Tests for payments: the rules (course access, receipt numbers, online amounts) and the online payment flow through
 * Moodle's payment system, with its database.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\payments
 */
final class payments_test extends \advanced_testcase {
    /** @var \local_studentrecords_generator */
    private $generator;

    /** @var int Moodle payment account. */
    private $accountid;

    /**
     * Billing on and a payment account chosen.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $paymentgenerator = $this->getDataGenerator()->get_plugin_generator('core_payment');
        $account = $paymentgenerator->create_payment_account(['gateways' => 'paypal']);
        $this->accountid = (int)$account->get('id');
        $this->generator->set_settings(['billing' => 1, 'paymentaccount' => $this->accountid]);
    }

    /**
     * A student who owes an amount, logged in.
     *
     * @param float $owes
     * @return \stdClass student record
     */
    private function student_who_owes(float $owes): \stdClass {
        $student = $this->generator->create_user_student();
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => $owes]);
        $this->setUser($student->userid);
        return $student;
    }

    /**
     * What Moodle's payment system saves once the provider has taken the money.
     *
     * @param int $reqid
     * @param float $amount
     * @param int $userid
     * @return int Moodle payment id
     */
    private function provider_took(int $reqid, float $amount, int $userid): int {
        return \core_payment\helper::save_payment(
            $this->accountid,
            'local_studentrecords',
            payments::AREA,
            $reqid,
            $userid,
            $amount,
            billing::CURRENCY,
            'paypal'
        );
    }

    /**
     * Payment lines of a student, with their amounts.
     *
     * @param \stdClass $student
     * @return float[]
     */
    private function payment_amounts(\stdClass $student): array {
        global $DB;
        $lines = $DB->get_records(billing::TXN, ['studentid' => $student->id, 'txntype' => 'payment'], 'id');
        return array_values(array_map(fn($l) => (float)$l->amount, $lines));
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
     * What Moodle's payment system is told to charge comes from the stored request.
     */
    public function test_payable_comes_from_the_request(): void {
        $student = $this->student_who_owes(1650);
        $reqid = payments::create_request($student, 400.5);

        $payable = \core_payment\helper::get_payable('local_studentrecords', payments::AREA, $reqid);

        $this->assertSame(400.5, $payable->get_amount());
        $this->assertSame('USD', $payable->get_currency());
        $this->assertSame($this->accountid, $payable->get_account_id());
    }

    /**
     * A request is for the student's own account, and not for more than the balance.
     */
    public function test_create_request_is_for_the_payer_and_the_balance(): void {
        $student = $this->student_who_owes(100);
        $other = $this->generator->create_user_student();

        $this->assertSame('err_payownonly', $this->error_of(fn() => payments::create_request($other, 50)));
        $this->assertSame('err_payamount', $this->error_of(fn() => payments::create_request($student, 100.01)));
        $this->assertSame('err_payamount', $this->error_of(fn() => payments::create_request($student, 0.99)));
        $this->assertGreaterThan(0, payments::create_request($student, 100));
    }

    /**
     * Another logged in person can't see or pay a student's request by its number. A provider calling back has no one
     * logged in, and scheduled tasks run as the admin.
     */
    public function test_open_request_belongs_to_the_payer(): void {
        $student = $this->student_who_owes(100);
        $reqid = payments::create_request($student, 60);
        $other = $this->generator->create_user_student();
        $service = \local_studentrecords\payment\service_provider::class;

        $this->setUser($other->userid);
        $this->assertSame('err_payownonly', $this->error_of(fn() => $service::get_payable(payments::AREA, $reqid)));
        $this->setGuestUser();
        $this->assertSame('err_payownonly', $this->error_of(fn() => $service::get_payable(payments::AREA, $reqid)));

        $this->setUser(0);
        $this->assertSame(60.0, $service::get_payable(payments::AREA, $reqid)->get_amount());
        $this->setAdminUser();
        $this->assertSame(60.0, $service::get_payable(payments::AREA, $reqid)->get_amount());
        $this->setUser($student->userid);
        $this->assertSame(60.0, $service::get_payable(payments::AREA, $reqid)->get_amount());
    }

    /**
     * Payment area other than the plugin's own is refused.
     */
    public function test_other_payment_area_is_refused(): void {
        $student = $this->student_who_owes(100);
        $reqid = payments::create_request($student, 60);
        $service = \local_studentrecords\payment\service_provider::class;

        $this->expectException(\coding_exception::class);
        $service::get_payable('other', $reqid);
    }

    /**
     * A request left open is stopped before the money is taken when it is paid, when the balance has fallen below it, or
     * when billing is off.
     */
    public function test_open_request_stops_a_request_that_is_out_of_date(): void {
        $student = $this->student_who_owes(100);
        $stale = payments::create_request($student, 100);
        $other = payments::create_request($student, 40);
        payments::record($student, 80, 'check');

        $this->assertSame('err_paidalready', $this->error_of(fn() => payments::open_request($stale)));
        $this->assertSame(1, (int)!empty(payments::open_request(payments::create_request($student, 20))));
        $this->assertSame('err_paidalready', $this->error_of(fn() => payments::open_request($other)));

        $this->generator->set_settings(['billing' => 0]);
        $this->assertSame('err_noonline', $this->error_of(fn() => payments::open_request($stale)));
    }

    /**
     * Delivery puts the amount of the request on the account. A gateway surcharge stays out of it, and the amount a
     * caller might report is never used.
     */
    public function test_deliver_records_the_amount_of_the_request(): void {
        global $DB;
        $student = $this->student_who_owes(1000);
        $reqid = payments::create_request($student, 400);
        $paymentid = $this->provider_took($reqid, 412, (int)$student->userid);

        $this->assertTrue(\core_payment\helper::deliver_order(
            'local_studentrecords',
            payments::AREA,
            $reqid,
            $paymentid,
            (int)$student->userid
        ));

        $this->assertSame([-400.0], $this->payment_amounts($student));
        $this->assertSame(600.0, billing::balance((int)$student->id));
        $req = $DB->get_record(payments::REQ, ['id' => $reqid]);
        $line = $DB->get_record(billing::TXN, ['paymentid' => $paymentid]);
        $this->assertSame('paid', $req->status);
        $this->assertSame((int)$paymentid, (int)$req->paymentid);
        $this->assertSame((int)$line->id, (int)$req->txnid);
        $this->assertSame('online', $line->method);
        $this->assertStringStartsWith('R', $line->receipt);
        $this->assertSame('err_paidalready', $this->error_of(fn() => payments::open_request($reqid)));
    }

    /**
     * The same Moodle payment delivered again changes nothing, even after its line was reversed.
     */
    public function test_deliver_is_idempotent(): void {
        global $DB;
        $student = $this->student_who_owes(1000);
        $reqid = payments::create_request($student, 400);
        $paymentid = $this->provider_took($reqid, 400, (int)$student->userid);

        $this->assertTrue(payments::deliver($reqid, $paymentid, (int)$student->userid));
        $this->assertTrue(payments::deliver($reqid, $paymentid, (int)$student->userid));
        $this->assertSame([-400.0], $this->payment_amounts($student));

        $this->setAdminUser();
        billing::reverse((int)$DB->get_field(billing::TXN, 'id', ['paymentid' => $paymentid]), 'Card payment disputed');
        $this->assertTrue(payments::deliver($reqid, $paymentid, (int)$student->userid));
        $this->assertSame(1000.0, billing::balance((int)$student->id));
        $this->assertCount(2, $this->payment_amounts($student));
    }

    /**
     * A second real payment of one request (two tabs at once) is money received: it gets its own line, so Finance can pay
     * it back, and the request keeps its first payment.
     */
    public function test_second_payment_of_a_request_is_not_lost(): void {
        global $DB;
        $student = $this->student_who_owes(100);
        $reqid = payments::create_request($student, 100);
        $first = $this->provider_took($reqid, 100, (int)$student->userid);
        $second = $this->provider_took($reqid, 100, (int)$student->userid);

        $this->assertTrue(payments::deliver($reqid, $first, (int)$student->userid));
        $this->assertTrue(payments::deliver($reqid, $second, (int)$student->userid));

        $this->assertSame([-100.0, -100.0], $this->payment_amounts($student));
        $this->assertSame(-100.0, billing::balance((int)$student->id));
        $this->assertSame((int)$first, (int)$DB->get_field(payments::REQ, 'paymentid', ['id' => $reqid]));
    }

    /**
     * Delivery refuses a payment that is not the payment of the request: nothing goes on the account.
     */
    public function test_deliver_refuses_a_payment_that_does_not_fit(): void {
        global $DB;
        $student = $this->student_who_owes(1000);
        $other = $this->generator->create_user_student();
        $reqid = payments::create_request($student, 400);
        $otherreq = payments::create_request($student, 300);
        $userid = (int)$student->userid;

        $this->assertFalse(payments::deliver($reqid, 999999, $userid), 'no such payment');
        $this->assertFalse(payments::deliver($reqid, $this->provider_took($otherreq, 300, $userid), $userid), 'other request');
        $this->assertFalse(payments::deliver($reqid, $this->provider_took($reqid, 399.99, $userid), $userid), 'too little');
        $this->assertFalse(
            payments::deliver($reqid, $this->provider_took($reqid, 400, (int)$other->userid), (int)$other->userid),
            'someone else paid'
        );
        $this->assertFalse(payments::deliver($reqid, $this->provider_took($reqid, 400, $userid), (int)$other->userid));
        $service = \local_studentrecords\payment\service_provider::class;
        $this->assertFalse($service::deliver_order('other', $reqid, $this->provider_took($reqid, 400, $userid), $userid));

        $this->assertSame([], $this->payment_amounts($student));
        $this->assertSame('open', $DB->get_field(payments::REQ, 'status', ['id' => $reqid]));
    }

    /**
     * A student with a registration whose course access waits for payment.
     *
     * @param \stdClass $student
     * @param int $courseid Moodle course
     * @return int registration id
     */
    private function waiting_registration(\stdClass $student, int $courseid): int {
        $term = $this->generator->create_term();
        $offer = $this->generator->create_offering(['courseid' => $this->generator->create_course()->id,
            'termid' => $term->id, 'moodlecourseid' => $courseid]);
        return $this->generator->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
    }

    /**
     * The payment that clears the balance opens the course.
     */
    public function test_payment_gives_the_waiting_course_access(): void {
        global $DB;
        $student = $this->student_who_owes(100);
        $course = $this->getDataGenerator()->create_course();
        $regid = $this->waiting_registration($student, (int)$course->id);
        $this->assertSame(1, (int)$DB->get_field(registration::REG, 'accesspending', ['id' => $regid]));

        $reqid = payments::create_request($student, 100);
        payments::deliver($reqid, $this->provider_took($reqid, 100, (int)$student->userid), (int)$student->userid);

        $this->assertSame(0, (int)$DB->get_field(registration::REG, 'accesspending', ['id' => $regid]));
        $this->assertTrue(is_enrolled(\context_course::instance($course->id), (int)$student->userid));
    }

    /**
     * Money taken by the provider stays on the account when the course can't be opened: the course keeps waiting, and the
     * failure goes in the log.
     */
    public function test_course_problem_does_not_lose_the_payment(): void {
        global $DB;
        $this->preventResetByRollback();
        $student = $this->student_who_owes(100);
        $course = $this->getDataGenerator()->create_course();
        $regid = $this->waiting_registration($student, (int)$course->id);
        $DB->delete_records('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);

        $reqid = payments::create_request($student, 100);
        $paymentid = $this->provider_took($reqid, 100, (int)$student->userid);

        $this->assertTrue(payments::deliver($reqid, $paymentid, (int)$student->userid));
        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame([-100.0], $this->payment_amounts($student));
        $this->assertSame('paid', $DB->get_field(payments::REQ, 'status', ['id' => $reqid]));
        $this->assertSame(1, (int)$DB->get_field(registration::REG, 'accesspending', ['id' => $regid]));
        $this->assertTrue($DB->record_exists_select(
            'local_studentrecords_log',
            $DB->sql_like('info', '?'),
            ['course access after payment%']
        ));
    }

    /**
     * Receipt numbers follow on within the year.
     */
    public function test_receipts_follow_on(): void {
        global $DB;
        $student = $this->student_who_owes(1000);
        $first = payments::record($student, 100, 'check');
        $second = payments::record($student, 100, 'cash');
        $year = userdate(time(), '%Y', catalog::timezone(), false);

        $this->assertSame('R' . $year . '-00001', $DB->get_field(billing::TXN, 'receipt', ['id' => $first]));
        $this->assertSame('R' . $year . '-00002', $DB->get_field(billing::TXN, 'receipt', ['id' => $second]));
    }

    /**
     * A staff payment is refused at zero, and the transaction is over when it is.
     */
    public function test_record_refuses_zero(): void {
        global $DB;
        $this->preventResetByRollback();
        $student = $this->student_who_owes(1000);

        $this->assertSame('err_amountpositive', $this->error_of(fn() => payments::record($student, 0, 'check')));
        $this->assertFalse($DB->is_transaction_started());
    }

    /**
     * Course access: right away; after full payment; after the down payment of an approved plan or full payment.
     */
    public function test_access_allowed(): void {
        $this->assertTrue(payments::access_allowed('immediate', 1650, false));
        $this->assertFalse(payments::access_allowed('full', 1650, true), 'full: a plan is not enough');
        $this->assertTrue(payments::access_allowed('full', 0, false));
        $this->assertTrue(payments::access_allowed('full', -25, false), 'school owes the student');
        $this->assertTrue(payments::access_allowed('full', 0.004, false), 'less than a cent');
        $this->assertFalse(payments::access_allowed('downpayment', 1650, false));
        $this->assertTrue(payments::access_allowed('downpayment', 1650, true));
        $this->assertTrue(payments::access_allowed('downpayment', 0, false));
    }

    /**
     * Receipt numbers: R, year, five digits.
     */
    public function test_receipt_number(): void {
        $this->assertSame('R2026-00001', payments::receipt_number('2026', 1));
        $this->assertSame('R2027-12345', payments::receipt_number('2027', 12345));
        $this->assertSame('R2027-123456', payments::receipt_number('2027', 123456));
    }

    /**
     * Online amounts: from $1.00 up to the balance.
     */
    public function test_amount_allowed(): void {
        $this->assertTrue(payments::amount_allowed(1650, 1650));
        $this->assertTrue(payments::amount_allowed(1, 1650));
        $this->assertTrue(payments::amount_allowed(1049.99, 1049.99));
        $this->assertFalse(payments::amount_allowed(1650.01, 1650));
        $this->assertFalse(payments::amount_allowed(0.99, 1650));
        $this->assertFalse(payments::amount_allowed(10, 0));
    }
}
