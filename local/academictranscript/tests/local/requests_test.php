<?php

namespace local_academictranscript\local;

use local_academictranscript\tests\recording_lock_factory;

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
        $this->a_minute_passes($id);
        $second = requests::issue($id, true);

        $this->assertSame('Admissions Office <office@example.com>', $first->recipient);
        $this->assertNotSame($first->verifycode, $second->verifycode);
        $row = $DB->get_record(requests::TABLE, ['id' => $id]);
        $this->assertSame('issued', $row->status);
        $this->assertEquals($second->id, $row->issueid);
        $this->assertEquals(get_admin()->id, $row->issuedby);
    }

    /**
     * A second click on Issue is refused, so it can't make a second transcript, and the message says what happened. Reissue
     * is a separate action, and it still makes a new copy.
     */
    public function test_a_double_click_on_issue_issues_once(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $this->setAdminUser();

        requests::issue($id);

        $this->assert_refused('req_err_issued', fn() => requests::issue($id));
        $this->assertSame(1, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));

        $this->a_minute_passes($id);
        requests::issue($id, true);
        $this->assertSame(2, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));
    }

    /**
     * Two clicks on Issue at the same moment, or two registrars: the second waits for the lock, finds the request issued and
     * makes nothing.
     */
    public function test_an_issue_that_waited_for_the_lock_finds_it_issued(): void {
        global $CFG, $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $this->setAdminUser();
        $this->use_recording_locks();
        recording_lock_factory::$first = fn() => requests::issue($id);

        $this->assert_refused('req_err_issued', fn() => requests::issue($id));

        unset($CFG->lock_factory);
        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame(1, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));
        $this->assertSame('issued', $DB->get_field(requests::TABLE, 'status', ['id' => $id]));
    }

    /**
     * The lock on the request is taken before the transaction starts and released after it commits, also when issuing fails.
     */
    public function test_issue_holds_the_lock_past_the_commit(): void {
        global $CFG;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $this->setAdminUser();
        $this->use_recording_locks();

        requests::issue($id);
        $this->assert_refused('req_err_issued', fn() => requests::issue($id));

        unset($CFG->lock_factory);
        $once = [['get', 'issue' . $id, false], ['release', 'issue' . $id, false]];
        $this->assertSame(array_merge($once, $once), $this->locks_on('issue'));
    }

    /**
     * Without the lock nothing is issued, and the request stays as it was.
     */
    public function test_issue_makes_nothing_without_the_lock(): void {
        global $CFG, $DB;
        require_once(__DIR__ . '/../fixtures/refusing_lock_factory.php');
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $this->setAdminUser();

        $CFG->lock_factory = '\\local_academictranscript\\tests\\refusing_lock_factory';
        $this->assert_refused('req_err_busy', fn() => requests::issue($id));
        unset($CFG->lock_factory);

        $this->assertSame(0, $DB->count_records('local_academictranscript_issue'));
        $this->assertSame('pending', $DB->get_field(requests::TABLE, 'status', ['id' => $id]));
    }

    /**
     * A double click on Reissue makes one copy, and the second click is told why. Reissue a minute later makes a new copy.
     */
    public function test_a_double_click_on_reissue_makes_one_copy(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $this->setAdminUser();
        requests::issue($id);
        $this->a_minute_passes($id);

        $copy = requests::issue($id, true);
        $this->assert_refused('req_err_justissued', fn() => requests::issue($id, true));

        $this->assertSame(2, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));
        $this->assertEquals($copy->id, $DB->get_field(requests::TABLE, 'issueid', ['id' => $id]));

        $this->a_minute_passes($id);
        $later = requests::issue($id, true);
        $this->assertSame(3, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));
        $this->assertEquals($later->id, $DB->get_field(requests::TABLE, 'issueid', ['id' => $id]));
    }

    /**
     * Two clicks on Reissue at the same moment, or two registrars: the second waits for the lock, finds the new copy and makes
     * nothing. Looking before the wait would find the old copy, which is how two copies get made.
     */
    public function test_a_reissue_that_waited_for_the_lock_finds_the_new_copy(): void {
        global $CFG, $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $this->setAdminUser();
        requests::issue($id);
        $this->a_minute_passes($id);
        $this->use_recording_locks();
        recording_lock_factory::$first = fn() => requests::issue($id, true);

        $this->assert_refused('req_err_justissued', fn() => requests::issue($id, true));

        unset($CFG->lock_factory);
        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame(2, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));
    }

    /**
     * The same recipient twice in a moment on the student's page is one Official transcript, and the second click gets that
     * copy back, so the page can offer it.
     */
    public function test_the_same_direct_issue_twice_makes_one_copy(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setAdminUser();

        $first = requests::issue_direct($student->id, ' Valley College ', $repeated);
        $this->assertFalse($repeated);
        $second = requests::issue_direct($student->id, 'Valley College', $repeated);

        $this->assertTrue($repeated);
        $this->assertEquals($first->id, $second->id);
        $this->assertSame($first->verifycode, $second->verifycode);
        $this->assertSame($first->transcriptno, $second->transcriptno);
        $this->assertSame(1, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));
    }

    /**
     * Both clicks on the student's page run at the same moment: the second waits for the lock, then finds the first copy.
     */
    public function test_a_direct_issue_that_waited_for_the_lock_finds_the_first_copy(): void {
        global $CFG, $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        $this->use_recording_locks();
        $first = null;
        recording_lock_factory::$first = function () use ($student, &$first) {
            $first = requests::issue_direct($student->id, 'Valley College');
        };

        $second = requests::issue_direct($student->id, 'Valley College', $repeated);

        unset($CFG->lock_factory);
        $this->assertTrue($repeated);
        $this->assertEquals($first->id, $second->id);
        $this->assertSame(1, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));
    }

    /**
     * The lock on the student is taken before the transaction starts and released after it commits.
     */
    public function test_direct_issue_holds_the_lock_past_the_commit(): void {
        global $CFG;
        $student = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        $this->use_recording_locks();

        requests::issue_direct($student->id, 'Valley College');
        requests::issue_direct($student->id, 'Valley College');

        unset($CFG->lock_factory);
        $once = [['get', 'direct' . $student->id, false], ['release', 'direct' . $student->id, false]];
        $this->assertSame(array_merge($once, $once), $this->locks_on('direct'));
    }

    /**
     * Without the lock nothing is issued.
     */
    public function test_direct_issue_makes_nothing_without_the_lock(): void {
        global $CFG, $DB;
        require_once(__DIR__ . '/../fixtures/refusing_lock_factory.php');
        $student = $this->getDataGenerator()->create_user();
        $this->setAdminUser();

        $CFG->lock_factory = '\\local_academictranscript\\tests\\refusing_lock_factory';
        $this->assert_refused('req_err_busy', fn() => requests::issue_direct($student->id, 'Valley College'));
        unset($CFG->lock_factory);

        $this->assertSame(0, $DB->count_records('local_academictranscript_issue'));
    }

    /**
     * Another recipient is a transcript of its own, however quick, and so is the same recipient for another student.
     */
    public function test_a_direct_issue_for_someone_else_makes_a_copy(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setAdminUser();

        requests::issue_direct($student->id, 'Valley College');
        requests::issue_direct($student->id, 'Hill College', $repeated);
        $this->assertFalse($repeated);
        requests::issue_direct($other->id, 'Valley College', $repeated);
        $this->assertFalse($repeated);

        $this->assertSame(2, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));
        $this->assertSame(1, $DB->count_records('local_academictranscript_issue', ['userid' => $other->id]));
    }

    /**
     * The same recipient again later, on purpose, is a new Official transcript with a new code.
     */
    public function test_the_same_direct_issue_again_later_makes_a_copy(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        $first = requests::issue_direct($student->id, 'Valley College');
        $DB->set_field('local_academictranscript_issue', 'timecreated', time() - requests::REPEATWINDOW - 1, ['id' => $first->id]);

        $second = requests::issue_direct($student->id, 'Valley College', $repeated);

        $this->assertFalse($repeated);
        $this->assertNotSame($first->verifycode, $second->verifycode);
        $this->assertSame(2, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));
    }

    /**
     * Cancel takes the lock Issue takes, so the two run one after the other.
     */
    public function test_cancel_takes_the_lock_of_the_request(): void {
        global $CFG;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $this->setAdminUser();
        $this->use_recording_locks();

        requests::cancel($id, '');

        unset($CFG->lock_factory);
        $this->assertSame([['get', 'issue' . $id, false], ['release', 'issue' . $id, false]], $this->locks_on('issue'));
    }

    /**
     * Issue gets there first: the Cancel that waited says the request is issued, and changes nothing.
     */
    public function test_a_cancel_that_waited_for_the_lock_finds_it_issued(): void {
        global $CFG, $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $this->setAdminUser();
        $this->use_recording_locks();
        recording_lock_factory::$first = fn() => requests::issue($id);

        $this->assert_refused('req_err_notcancelled', fn() => requests::cancel($id, 'Asked by phone'));

        unset($CFG->lock_factory);
        $row = $DB->get_record(requests::TABLE, ['id' => $id]);
        $this->assertSame('issued', $row->status);
        $this->assertNull($row->note);
        $this->assertFalse($DB->record_exists('local_academictranscript_log', ['action' => 'request_cancelled']));
    }

    /**
     * Cancel gets there first: the Issue that waited says the request is cancelled, and makes nothing.
     */
    public function test_an_issue_that_waited_for_the_lock_finds_it_cancelled(): void {
        global $CFG, $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $this->setAdminUser();
        $this->use_recording_locks();
        recording_lock_factory::$first = fn() => requests::cancel($id, 'Asked by phone');

        $this->assert_refused('req_err_cancelled', fn() => requests::issue($id));

        unset($CFG->lock_factory);
        $this->assertSame('cancelled', $DB->get_field(requests::TABLE, 'status', ['id' => $id]));
        $this->assertSame(0, $DB->count_records('local_academictranscript_issue'));
    }

    /**
     * Without the lock nothing is cancelled.
     */
    public function test_cancel_changes_nothing_without_the_lock(): void {
        global $CFG, $DB;
        require_once(__DIR__ . '/../fixtures/refusing_lock_factory.php');
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $id = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $this->setAdminUser();

        $CFG->lock_factory = '\\local_academictranscript\\tests\\refusing_lock_factory';
        $this->assert_refused('req_err_busy', fn() => requests::cancel($id, ''));
        unset($CFG->lock_factory);

        $this->assertSame('pending', $DB->get_field(requests::TABLE, 'status', ['id' => $id]));
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

        $this->assert_refused('req_err_cancelled', fn() => requests::issue($id));
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
     * A double click sends the form twice. That is one request and one fee, and the second click is told so.
     */
    public function test_the_same_request_twice_in_a_moment_is_one_request(): void {
        global $DB;
        [$user, $student, $sr] = $this->student_records_student();
        $sr->set_settings(['billing' => 1]);
        $this->setUser($user);
        $data = ['delivery' => 'mail', 'recipient' => ' Office ', 'address' => "1 Way\nSpringfield", 'timing' => 'now'];

        $first = requests::create($user->id, $data, $repeated);
        $this->assertFalse($repeated);
        $second = requests::create($user->id, $data, $repeated);

        $this->assertTrue($repeated);
        $this->assertSame($first, $second);
        $this->assertSame(1, $DB->count_records(requests::TABLE));
        $this->assertSame(1, $DB->count_records('local_studentrecords_txn', ['studentid' => $student->id]));
        $this->assertSame(10.0, \local_studentrecords\local\billing::balance((int)$student->id));
        $this->assertSame(1, $DB->count_records('local_academictranscript_log', ['action' => 'request']));
    }

    /**
     * Academic Transcript works on its own too: without Student Records a double click still makes one request.
     */
    public function test_the_same_request_twice_without_student_records(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $data = ['delivery' => 'email', 'recipient' => 'Office', 'email' => 'office@example.com', 'timing' => 'now'];

        $first = requests::create($student->id, $data);
        $second = requests::create($student->id, $data);

        $this->assertSame($first, $second);
        $this->assertSame(1, $DB->count_records(requests::TABLE));
    }

    /**
     * Both clicks run at the same moment: the second waits for the lock, then finds the first one's request. Looking before
     * the wait would find nothing, which is how two fees get on the account.
     */
    public function test_a_request_that_waited_for_the_lock_finds_the_first_one(): void {
        global $CFG, $DB;
        [$user, $student, $sr] = $this->student_records_student();
        $sr->set_settings(['billing' => 1]);
        $this->setUser($user);
        $data = ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now'];
        $this->use_recording_locks();
        $first = 0;
        recording_lock_factory::$first = function () use ($user, $data, &$first) {
            $first = requests::create($user->id, $data);
        };

        $second = requests::create($user->id, $data, $repeated);

        unset($CFG->lock_factory);
        $this->assertTrue($repeated);
        $this->assertSame($first, $second);
        $this->assertSame(1, $DB->count_records(requests::TABLE));
        $this->assertSame(10.0, \local_studentrecords\local\billing::balance((int)$student->id));
    }

    /**
     * The lock on the student is taken before the transaction starts and released after it commits, so the second click
     * always finds what the first one saved. It also ends when the request is the same one again.
     */
    public function test_create_holds_the_lock_past_the_commit(): void {
        global $CFG;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $this->use_recording_locks();
        $data = ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now'];

        requests::create($student->id, $data);
        requests::create($student->id, $data);

        unset($CFG->lock_factory);
        $once = [['get', 'request' . $student->id, false], ['release', 'request' . $student->id, false]];
        $this->assertSame(array_merge($once, $once), $this->locks_on('request'));
    }

    /**
     * Without the lock nothing is saved and no fee goes on the account. The student is told to look again.
     */
    public function test_create_saves_nothing_without_the_lock(): void {
        global $CFG, $DB;
        require_once(__DIR__ . '/../fixtures/refusing_lock_factory.php');
        [$user, $student, $sr] = $this->student_records_student();
        $sr->set_settings(['billing' => 1]);
        $this->setUser($user);

        $CFG->lock_factory = '\\local_academictranscript\\tests\\refusing_lock_factory';
        $this->assert_refused('req_err_busy', fn() => requests::create($user->id, ['delivery' => 'pickup',
            'recipient' => 'Me', 'timing' => 'now']));
        unset($CFG->lock_factory);

        $this->assertSame(0, $DB->count_records(requests::TABLE));
        $this->assertSame(0.0, \local_studentrecords\local\billing::balance((int)$student->id));
    }

    /**
     * A second request that differs in anything the student typed or chose is a request of its own, however quick, and it
     * has its own fee.
     *
     * @dataProvider different_request_provider
     * @param array $first The first request
     * @param array $second The second request
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('different_request_provider')]
    public function test_a_different_request_is_a_new_request(array $first, array $second): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $one = requests::create($student->id, $first);
        $two = requests::create($student->id, $second, $repeated);

        $this->assertFalse($repeated);
        $this->assertNotSame($one, $two);
        $this->assertSame(2, $DB->count_records(requests::TABLE));
    }

    /**
     * Data for test_a_different_request_is_a_new_request.
     *
     * @return array
     */
    public static function different_request_provider(): array {
        $mail = ['delivery' => 'mail', 'recipient' => 'Office', 'address' => '1 Way', 'timing' => 'now'];
        $email = ['delivery' => 'email', 'recipient' => 'Office', 'email' => 'office@example.com', 'timing' => 'now'];
        return [
            'another recipient' => [$mail, ['recipient' => 'Dean'] + $mail],
            'another address' => [$mail, ['address' => '2 Way'] + $mail],
            'another email' => [$email, ['email' => 'dean@example.com'] + $email],
            'another delivery' => [$mail, $email],
            'another time' => [$mail, ['timing' => 'degree'] + $mail],
        ];
    }

    /**
     * The same request again later, on purpose, is a new request with a new fee.
     */
    public function test_the_same_request_again_later_is_a_new_request(): void {
        global $DB;
        [$user, $student, $sr] = $this->student_records_student();
        $sr->set_settings(['billing' => 1]);
        $this->setUser($user);
        $data = ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now'];
        $first = requests::create($user->id, $data);
        $DB->set_field(requests::TABLE, 'timecreated', time() - requests::REPEATWINDOW - 1, ['id' => $first]);

        $second = requests::create($user->id, $data, $repeated);

        $this->assertFalse($repeated);
        $this->assertNotSame($first, $second);
        $this->assertSame(20.0, \local_studentrecords\local\billing::balance((int)$student->id));
    }

    /**
     * A request the registrar has already acted on isn't a click still on its way. Asking again right after it is new.
     *
     * @dataProvider acted_on_provider
     * @param string $action Issue or cancel
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('acted_on_provider')]
    public function test_the_same_request_after_it_was_acted_on_is_a_new_request(string $action): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $data = ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now'];
        $first = requests::create($student->id, $data);
        $this->setAdminUser();
        $action === 'issue' ? requests::issue($first) : requests::cancel($first, '');
        $this->setUser($student);

        $second = requests::create($student->id, $data, $repeated);

        $this->assertFalse($repeated);
        $this->assertNotSame($first, $second);
        $this->assertSame('pending', $DB->get_field(requests::TABLE, 'status', ['id' => $second]));
    }

    /**
     * Data for test_the_same_request_after_it_was_acted_on_is_a_new_request.
     *
     * @return array
     */
    public static function acted_on_provider(): array {
        return ['issued' => ['issue'], 'cancelled' => ['cancel']];
    }

    /**
     * Two students who send the same thing at the same moment each have a request.
     */
    public function test_two_students_can_make_the_same_request(): void {
        global $DB;
        $data = ['delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'now'];
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();

        $this->setUser($one);
        requests::create($one->id, $data);
        $this->setUser($two);
        requests::create($two->id, $data, $repeated);

        $this->assertFalse($repeated);
        $this->assertSame(2, $DB->count_records(requests::TABLE));
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
        $free = requests::create($user->id, ['delivery' => 'pickup', 'recipient' => 'My employer', 'timing' => 'now']);

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
     * Use the lock factory that writes down when a lock is taken and released, with real transactions.
     */
    private function use_recording_locks(): void {
        global $CFG;
        require_once(__DIR__ . '/../fixtures/recording_lock_factory.php');
        $this->preventResetByRollback();
        recording_lock_factory::reset();
        $CFG->lock_factory = '\\local_academictranscript\\tests\\recording_lock_factory';
    }

    /**
     * Make the last copy of a request a minute old, so that Reissue is a deliberate action again.
     *
     * @param int $id request id
     */
    private function a_minute_passes(int $id): void {
        global $DB;
        $DB->set_field(requests::TABLE, 'timeissued', time() - requests::REPEATWINDOW - 1, ['id' => $id]);
    }

    /**
     * What was written down about the locks on one kind of thing.
     *
     * @param string $kind The start of the resource name
     * @return array
     */
    private function locks_on(string $kind): array {
        return array_values(array_filter(recording_lock_factory::$events, fn($e) => str_starts_with($e[1], $kind)));
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
