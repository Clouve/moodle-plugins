<?php

namespace local_academictranscript\local;

#[\PHPUnit\Framework\Attributes\CoversClass(requests::class)]
/**
 * Tests for Official transcript requests, on their own and with Student Records.
 *
 * The tests with Student Records are skipped when it is not installed.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_academictranscript\local\requests
 */
final class requests_test extends \advanced_testcase {
    /**
     * Start each test with a clean site and an empty request cache.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        scale::reset_cache();
    }

    /**
     * A request needs a known delivery and timing, a recipient, a good email for email, and an address for mail.
     */
    public function test_problems(): void {
        $this->assertSame([], requests::problems(['delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'now']));
        $this->assertSame(['address' => 'req_err_address'], requests::problems(['delivery' => 'mail',
            'recipient' => 'Admissions Office', 'address' => ' ', 'timing' => 'grades']));
        $this->assertSame(['email' => 'req_err_email'], requests::problems(['delivery' => 'email',
            'recipient' => 'Admissions Office', 'email' => 'not an address', 'timing' => 'now']));
        $this->assertSame(
            ['delivery' => 'req_err_delivery', 'timing' => 'req_err_timing', 'recipient' => 'req_err_recipient'],
            requests::problems(['delivery' => 'fax', 'recipient' => '', 'timing' => 'later'])
        );
    }

    /**
     * Fees come from the settings, fall back to the default, and are never negative.
     */
    public function test_fee(): void {
        $this->assertSame(5.0, requests::fee('email'));
        set_config('fee_mail', '12.50', 'local_academictranscript');
        $this->assertSame(12.5, requests::fee('mail'));
        set_config('fee_pickup', '-3', 'local_academictranscript');
        $this->assertSame(0.0, requests::fee('pickup'));
        set_config('fee_email', 'free', 'local_academictranscript');
        $this->assertSame(5.0, requests::fee('email'));
    }

    /**
     * A saved request keeps only the fields of its delivery. Without Student Records nothing is charged.
     */
    public function test_create_keeps_the_fields_of_the_delivery(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $id = requests::create($student->id, ['delivery' => 'email', 'recipient' => ' Admissions Office ',
            'email' => 'office@example.com', 'address' => 'ignored', 'timing' => 'now']);

        $row = $DB->get_record(requests::TABLE, ['id' => $id]);
        $this->assertSame('Admissions Office', $row->recipient);
        $this->assertSame('office@example.com', $row->email);
        $this->assertNull($row->address);
        $this->assertEquals(5, $row->fee);
        $this->assertEquals(0, $row->charged);
        $this->assertSame('pending', $row->status);
        $this->assertTrue($DB->record_exists('local_academictranscript_log', ['action' => 'request', 'studentid' => $student->id]));
    }

    /**
     * A request with a problem is refused and nothing is saved.
     */
    public function test_create_refuses_a_bad_request(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        try {
            requests::create($student->id, ['delivery' => 'mail', 'recipient' => 'Office', 'address' => '', 'timing' => 'now']);
            $this->fail('The request was saved.');
        } catch (\moodle_exception $e) {
            $this->assertSame('req_err_address', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records(requests::TABLE));
    }

    /**
     * Issuing a request makes the Official transcript for the recipient, and issuing it again gives a new code.
     */
    public function test_issue_and_reissue(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'email', 'recipient' => 'Admissions Office',
            'email' => 'office@example.com', 'timing' => 'now']);
        $this->setAdminUser();

        $first = requests::issue($id);
        $second = requests::issue($id, true);

        $this->assertSame('Admissions Office <office@example.com>', $first->recipient);
        $this->assertNotSame($first->verifycode, $second->verifycode);
        $row = $DB->get_record(requests::TABLE, ['id' => $id]);
        $this->assertSame('issued', $row->status);
        $this->assertEquals($second->id, $row->issueid);
        $this->assertEquals(get_admin()->id, $row->issuedby);
    }

    /**
     * A second click on Issue is refused, so it can't make a second transcript. Reissue is a separate action.
     */
    public function test_a_double_click_on_issue_issues_once(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $this->setAdminUser();

        requests::issue($id);

        $this->assert_refused('req_err_notready', fn() => requests::issue($id));
        $this->assertSame(1, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));
    }

    /**
     * Only an issued request can be issued again.
     */
    public function test_reissue_needs_an_issued_request(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $this->setAdminUser();

        $this->assert_refused('req_err_notready', fn() => requests::issue($id, true));
    }

    /**
     * When issuing fails, nothing stays open and the request stays as it was.
     */
    public function test_a_failed_issue_leaves_no_open_transaction(): void {
        global $DB;
        $this->preventResetByRollback();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $student = $this->getDataGenerator()->create_user();
        $course = $generator->create_course_with_fields(['code' => '', 'hours' => null]);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $this->setAdminUser();

        $this->assert_refused('cannotissue', fn() => requests::issue($id));

        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame('pending', $DB->get_field(requests::TABLE, 'status', ['id' => $id]));
    }

    /**
     * A long recipient and a long email still fit the recipient column of the issue.
     */
    public function test_issue_fits_a_long_recipient(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'email', 'recipient' => str_repeat('r', 255),
            'email' => 'office@example.com', 'timing' => 'now']);
        $this->setAdminUser();

        $issue = requests::issue($id);

        $this->assertSame(255, \core_text::strlen($issue->recipient));
    }

    /**
     * A cancelled request can't be issued, and only a pending request can be cancelled.
     */
    public function test_cancel(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'now']);
        $this->setAdminUser();

        requests::cancel($id, 'Asked by phone');

        $this->assert_refused('req_err_notready', fn() => requests::issue($id));
        $this->assert_refused('req_err_notpending', fn() => requests::cancel($id, 'Again'));
    }

    /**
     * Without Student Records nothing waits and there are no holds, so a request is pending whatever timing it asked for.
     */
    public function test_state_without_student_records(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'degree']);

        $this->assertSame('pending', requests::state(requests::for_user($student->id)[$id]));
        $this->assertFalse(requests::can_charge($student->id));
    }

    /**
     * With Student Records billing on, the fee goes on the student's account, and the request says so.
     */
    public function test_fee_goes_on_the_student_records_account(): void {
        global $DB;
        [$user, $student, $sr] = $this->student_records_student();
        $sr->set_settings(['billing' => 1]);
        $this->setUser($user);

        $id = requests::create($user->id, ['delivery' => 'mail', 'recipient' => 'Office', 'address' => '1 Way', 'timing' => 'now']);

        $this->assertEquals(1, $DB->get_field(requests::TABLE, 'charged', ['id' => $id]));
        $this->assertSame(10.0, \local_studentrecords\local\billing::balance((int)$student->id));
    }

    /**
     * With billing off, or a fee of nothing, no line goes on the account and the request doesn't claim one.
     */
    public function test_no_charge_with_billing_off_or_no_fee(): void {
        global $DB;
        [$user, $student, $sr] = $this->student_records_student();
        $this->setUser($user);

        $off = requests::create($user->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $sr->set_settings(['billing' => 1]);
        set_config('fee_pickup', '0', 'local_academictranscript');
        $free = requests::create($user->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);

        $this->assertEquals(0, $DB->get_field(requests::TABLE, 'charged', ['id' => $off]));
        $this->assertEquals(0, $DB->get_field(requests::TABLE, 'charged', ['id' => $free]));
        $this->assertSame(0.0, \local_studentrecords\local\billing::balance((int)$student->id));
    }

    /**
     * "After this term's grades" waits for classes in a term that had started when the student asked. A class in a term to
     * come doesn't hold the request back.
     */
    public function test_waiting_for_grades_ignores_later_terms(): void {
        global $DB;
        [$user, $student, $sr] = $this->student_records_student();
        $next = $sr->create_term(['startdate' => date('Y-m-d', time() + 30 * DAYSECS),
            'enddate' => date('Y-m-d', time() + 90 * DAYSECS)]);
        $offer = $sr->create_offering(['courseid' => $sr->create_course()->id, 'termid' => $next->id]);
        $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        $this->setUser($user);
        $id = requests::create($user->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'grades']);

        $this->assertSame('pending', requests::state($DB->get_record(requests::TABLE, ['id' => $id])));

        $DB->set_field('local_studentrecords_term', 'startdate', time() - DAYSECS, ['id' => $next->id]);
        $this->assertSame('waiting', requests::state($DB->get_record(requests::TABLE, ['id' => $id])));
    }

    /**
     * A hold keeps a request on hold, and it blocks issuing, reissuing included, until it is released.
     */
    public function test_a_hold_blocks_issue_and_reissue(): void {
        [$user, $student] = $this->student_records_student();
        $this->setUser($user);
        $id = requests::create($user->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $this->setAdminUser();
        requests::issue($id);

        $hold = \local_studentrecords\local\academic::place_hold($student, ['holdtype' => 'financial', 'reason' => 'Unpaid fees',
            'blocksreg' => 1, 'startdate' => time() - 60]);

        $this->assertCount(1, requests::holds($user->id));
        $this->assertStringContainsString('Unpaid fees', requests::holds($user->id)[0]);
        $this->assert_refused('req_err_notready', fn() => requests::issue($id, true));
        $this->assert_refused('issueonhold', fn() => transcript::issue($user->id, 'Office'));

        $this->setUser($user);
        $second = requests::create($user->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $this->setAdminUser();
        $this->assertSame('onhold', requests::state(requests::for_user($user->id)[$second]));
        $this->assert_refused('req_err_notready', fn() => requests::issue($second));

        \local_studentrecords\local\academic::release_hold($hold, 'Paid');
        $this->assertSame('pending', requests::state(requests::for_user($user->id)[$second]));
        $this->assertNotEmpty(requests::issue($second)->verifycode);
    }

    /**
     * A request that waits for this term's grades waits while a class is still in progress.
     */
    public function test_waits_for_grades(): void {
        [$user, $student, $sr] = $this->student_records_student();
        $term = $sr->create_term(['startdate' => date('Y-m-d', time() - 30 * DAYSECS),
            'enddate' => date('Y-m-d', time() + 30 * DAYSECS)]);
        $offer = $sr->create_offering(['courseid' => $sr->create_course()->id, 'termid' => $term->id]);
        $regid = $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        $this->setUser($user);
        $id = requests::create($user->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'grades']);
        $this->setAdminUser();

        $this->assertSame('waiting', requests::state(requests::for_user($user->id)[$id]));

        \local_studentrecords\local\registration::set_grade($regid, 'A', null, 'staff');
        $this->assertSame('pending', requests::state(requests::for_user($user->id)[$id]));
    }

    /**
     * A request that waits for the degree waits until the student has graduated.
     */
    public function test_waits_for_the_degree(): void {
        global $DB;
        [$user, $student] = $this->student_records_student();
        $this->setUser($user);
        $id = requests::create($user->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'degree']);
        $this->setAdminUser();

        $this->assertSame('waiting', requests::state(requests::for_user($user->id)[$id]));
        $this->assert_refused('req_err_notready', fn() => requests::issue($id));

        $DB->set_field('local_studentrecords_student', 'status', 'graduated', ['id' => $student->id]);
        $this->assertSame('pending', requests::state(requests::for_user($user->id)[$id]));
    }

    /**
     * Make a student in Student Records and use that plugin as the source, or skip the test without it.
     *
     * @return array The Moodle user, the student record, and the generator of Student Records
     */
    private function student_records_student(): array {
        if (!class_exists('\\local_studentrecords\\local\\registration')) {
            $this->markTestSkipped('Student Records is not installed.');
        }
        set_config('source', srsource::STUDENTRECORDS, 'local_academictranscript');
        $this->setAdminUser();
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $user = $this->getDataGenerator()->create_user();
        $student = $sr->create_student(['userid' => $user->id, 'studentid' => 'S-1001']);
        return [$user, $student, $sr];
    }

    /**
     * Run code that must be refused with an error code.
     *
     * @param string $errorcode
     * @param callable $callback
     */
    private function assert_refused(string $errorcode, callable $callback): void {
        try {
            $callback();
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
            return;
        }
        $this->fail('The code was not refused.');
    }
}
