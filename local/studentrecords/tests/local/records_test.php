<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(records::class)]
/**
 * Tests for saving records with their change history.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\records
 */
final class records_test extends \advanced_testcase {
    /**
     * Start each test with a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A failed save leaves no open transaction, so a caller that goes on (the import does) can still save.
     */
    public function test_failed_save_leaves_no_open_transaction(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->setAdminUser();
        try {
            records::save('local_studentrecords_level', ['name' => str_repeat('x', 300)], 'import');
            $this->fail('No exception');
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(\dml_exception::class, $e);
        }
        $this->assertFalse($DB->is_transaction_started());

        records::save('local_studentrecords_level', ['name' => 'Undergraduate'], 'import');
        $this->assertTrue($DB->record_exists('local_studentrecords_level', ['name' => 'Undergraduate']));
    }

    /**
     * A student with a program and a section to register in. The transaction of the test is out of the way, so the commit of
     * the code under test is the real one, and its messages go as they do on a site.
     *
     * @param array $record more fields of the student
     * @return array generator, student, offer
     */
    private function student_and_section(array $record = []): array {
        $this->preventResetByRollback();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $gen->create_scale();
        $offer = $gen->create_offering(['courseid' => $gen->create_course()->id, 'termid' => $gen->create_term()->id]);
        $student = $gen->create_user_student($record + ['programid' => $gen->create_program()->id]);
        return ['gen' => $gen, 'student' => $student, 'offer' => $offer];
    }

    /**
     * From now on Moodle can't send this student a message: it refuses an account with no email address. A failed send is
     * what a mail server that is down gives too.
     *
     * @param \stdClass $student
     */
    private function block_messages_to(\stdClass $student): void {
        global $DB;
        $DB->set_field('user', 'email', '', ['id' => $student->userid]);
    }

    /**
     * The work is done with no open transaction, and nothing failed yet. When cron sends the message it fails, and the message
     * log says so against the student.
     *
     * @param \stdClass $student
     */
    private function assert_only_the_message_failed(\stdClass $student): void {
        global $DB;
        $this->assertFalse($DB->is_transaction_started());
        $this->assertFalse($DB->record_exists(messages::LOG, ['studentid' => $student->id, 'status' => 'failed']));

        $this->runAdhocTasks(\local_studentrecords\task\send_message::class);

        $this->assertTrue($DB->record_exists(messages::LOG, ['studentid' => $student->id, 'status' => 'failed']));
        $this->assertFalse($DB->record_exists(messages::LOG, ['studentid' => $student->id, 'status' => 'queued']));
        // Moodle reports the failed send as debugging. This site sends nothing out.
        $this->resetDebugging();
    }

    /**
     * A message waits for cron, so a message that fails can't undo the decision, and the person deciding isn't told the
     * decision failed.
     */
    public function test_a_grade_change_stands_when_its_message_fails(): void {
        global $DB;
        $s = $this->student_and_section();
        $regid = $s['gen']->create_registration(['studentid' => $s['student']->id, 'offerid' => $s['offer']->id]);
        registration::set_grade($regid, 'B', null, 'staff');
        $this->setUser($this->getDataGenerator()->create_user());
        $id = academic::request_change($regid, 'A', null, 'Marked again');
        $this->setAdminUser();
        $this->block_messages_to($s['student']);

        academic::decide_change($id, true, 'Agreed');

        $this->assertSame('A', $DB->get_field(registration::REG, 'grade', ['id' => $regid]));
        $this->assertSame('approved', $DB->get_field(academic::CHANGES, 'state', ['id' => $id]));
        $this->assert_only_the_message_failed($s['student']);
    }

    /**
     * A conferral stands when the message about it fails.
     */
    public function test_a_conferral_stands_when_its_message_fails(): void {
        global $DB;
        $s = $this->student_and_section();
        $id = graduation::apply($s['student'], 0);
        graduation::review($id, true, 'Waived');
        $this->block_messages_to($s['student']);

        graduation::confer($id, time(), 'Bachelor of Arts', '');

        $this->assertSame('conferred', $DB->get_field(graduation::TABLE, 'state', ['id' => $id]));
        $this->assertSame('graduated', students::get((int)$s['student']->id)->status);
        $this->assert_only_the_message_failed($s['student']);
    }

    /**
     * A registration stands when the message about it fails, and the caller gets its id.
     */
    public function test_a_registration_stands_when_its_message_fails(): void {
        global $DB;
        $s = $this->student_and_section();
        $this->block_messages_to($s['student']);

        $id = registration::add($s['student'], (int)$s['offer']->id);

        $this->assertSame('registered', $DB->get_field(registration::REG, 'status', ['id' => $id]));
        $this->assert_only_the_message_failed($s['student']);
    }

    /**
     * A drop stands when the message about it fails.
     */
    public function test_a_drop_stands_when_its_message_fails(): void {
        global $DB;
        $s = $this->student_and_section();
        $id = registration::add($s['student'], (int)$s['offer']->id);
        $this->block_messages_to($s['student']);

        registration::end($id, 'drop');

        $this->assertSame('dropped', $DB->get_field(registration::REG, 'status', ['id' => $id]));
        $this->assert_only_the_message_failed($s['student']);
    }

    /**
     * Attendance marks stand when the low attendance message fails.
     */
    public function test_attendance_stands_when_its_message_fails(): void {
        global $DB;
        $s = $this->student_and_section();
        $id = registration::add($s['student'], (int)$s['offer']->id);
        $meeting = $s['gen']->create_meeting(['offerid' => $s['offer']->id, 'date' => catalog::time_to_date(time())]);
        $this->block_messages_to($s['student']);

        attendance::save_marks($meeting, [$id => ['status' => 'absent']]);

        $this->assertSame('absent', $DB->get_field(attendance::MARKS, 'status', ['regid' => $id]));
        $this->assert_only_the_message_failed($s['student']);
    }

    /**
     * An admission decision stands when the message about it fails.
     */
    public function test_an_admission_decision_stands_when_its_message_fails(): void {
        global $DB;
        $s = $this->student_and_section(['status' => 'applicant']);
        $this->block_messages_to($s['student']);

        $id = admissions::record_decision($s['student'], ['decision' => 'admitted', 'decisiondate' => time(), 'reason' => '',
            'conditions' => '']);

        $this->assertTrue($DB->record_exists('local_studentrecords_admdec', ['id' => $id]));
        $this->assertSame('admitted', students::get((int)$s['student']->id)->status);
        $this->assert_only_the_message_failed($s['student']);
    }

    /**
     * A payment stands when the message about it fails: the money came in, and the caller gets its line.
     */
    public function test_a_payment_stands_when_its_message_fails(): void {
        global $DB;
        $s = $this->student_and_section();
        $s['gen']->set_settings(['billing' => 1]);
        $this->block_messages_to($s['student']);

        $id = payments::record($s['student'], 100, 'check');

        $this->assertEquals(-100, $DB->get_field(billing::TXN, 'amount', ['id' => $id]));
        $this->assert_only_the_message_failed($s['student']);
    }

    /**
     * A message of Moodle's own, such as a course's welcome message, is still sent by the commit. One that fails does so after
     * the data is saved, so the work stands, the caller carries on, and the audit log says what happened.
     */
    public function test_a_moodle_message_that_fails_at_the_commit_is_only_logged(): void {
        global $DB;
        $s = $this->student_and_section();
        $this->block_messages_to($s['student']);
        $message = new \core\message\message();
        $message->component = 'local_studentrecords';
        $message->name = 'records';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = \core_user::get_user($s['student']->userid);
        $message->subject = 'Welcome';
        $message->fullmessage = 'Welcome';
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = 'Welcome';
        $message->notification = 1;

        $transaction = $DB->start_delegated_transaction();
        records::save('local_studentrecords_level', ['name' => 'Saved first'], 'form');
        message_send($message);
        records::commit($transaction, $s['student']);
        $this->resetDebugging();

        $this->assertFalse($DB->is_transaction_started());
        $this->assertTrue($DB->record_exists('local_studentrecords_level', ['name' => 'Saved first']));
        $this->assertSame(1, $DB->count_records_select(
            'local_studentrecords_log',
            'relateduserid = ? AND ' . $DB->sql_like('info', '?'),
            [$s['student']->userid, 'after saving: %']
        ));
    }

    /**
     * A transaction that can't commit: its commit stops with the given error.
     *
     * @param \Throwable $error
     * @return \moodle_transaction
     */
    private function failing_transaction(\Throwable $error): \moodle_transaction {
        global $DB;
        return new class ($DB, $error) extends \moodle_transaction {
            /** @var \Throwable The error of the commit. */
            private \Throwable $error;

            /**
             * Keep the error.
             *
             * @param \moodle_database $database
             * @param \Throwable $error
             */
            public function __construct($database, \Throwable $error) {
                parent::__construct($database);
                $this->error = $error;
            }

            /**
             * Fail as a database does when it refuses a commit.
             */
            public function allow_commit() {
                throw $this->error;
            }
        };
    }

    /**
     * A database that refuses the commit leaves the transaction open. Whatever the error is, that isn't a message that failed
     * after the data was saved: the caller must hear about it.
     */
    public function test_a_commit_the_database_refuses_still_throws(): void {
        global $DB;
        $this->preventResetByRollback();
        $error = new \dml_write_exception('The database refused the commit');
        $open = $DB->start_delegated_transaction();

        try {
            records::commit($this->failing_transaction($error));
            $this->fail('A commit the database refused went through.');
        } catch (\dml_write_exception $e) {
            $this->assertSame($error, $e);
        } finally {
            $open->allow_commit();
        }

        $this->assertSame(0, $DB->count_records('local_studentrecords_log'));
    }

    /**
     * A transaction that was already closed can't commit. No transaction is open then, and the error says so: it still stops
     * the caller, and nothing says the work was saved.
     */
    public function test_a_commit_of_a_closed_transaction_still_throws(): void {
        global $DB;
        $this->preventResetByRollback();
        $transaction = $DB->start_delegated_transaction();
        $transaction->allow_commit();

        try {
            records::commit($transaction);
            $this->fail('A closed transaction committed.');
        } catch (\dml_transaction_exception $e) {
            $this->assertStringContainsString('already disposed', $e->getMessage());
        }

        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame(0, $DB->count_records('local_studentrecords_log'));
    }

    /**
     * A commit that doesn't go through is a failure of the work: it still stops the caller, and nothing says it was saved.
     */
    public function test_a_commit_that_fails_still_throws(): void {
        global $DB;
        $this->preventResetByRollback();
        $outer = $DB->start_delegated_transaction();
        $inner = $DB->start_delegated_transaction();
        try {
            $inner->rollback(new \moodle_exception('err_busy', 'local_studentrecords'));
        } catch (\moodle_exception $e) {
            $this->assertSame('err_busy', $e->errorcode);
        }

        try {
            records::commit($outer);
            $this->fail('A commit after a rollback went through.');
        } catch (\dml_transaction_exception $e) {
            $this->assertStringContainsString('lower level rollback', $e->getMessage());
        } finally {
            $DB->force_transaction_rollback();
        }

        $this->assertSame(0, $DB->count_records('local_studentrecords_log'));
    }

    /**
     * A new record and a change are in the history, and saving the same values again writes nothing.
     */
    public function test_history(): void {
        $this->setAdminUser();
        $id = records::save('local_studentrecords_level', ['name' => 'Graduate'], 'form');
        $this->assertTrue(records::$lastchanged);
        records::save('local_studentrecords_level', ['id' => $id, 'name' => 'Graduate'], 'form');
        $this->assertFalse(records::$lastchanged);
        records::save('local_studentrecords_level', ['id' => $id, 'name' => 'Masters'], 'form');
        $history = records::get_history('local_studentrecords_level', $id);
        $this->assertSame('Masters', $history[0]->newvalue);
        $this->assertSame('Graduate', $history[0]->oldvalue);
    }

    /**
     * Numbers compare as numbers. Text that only looks like a number stays text, so a change to it is saved.
     *
     * @dataProvider same_provider
     * @param mixed $a
     * @param mixed $b
     * @param bool $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('same_provider')]
    public function test_same($a, $b, bool $expected): void {
        $this->assertSame($expected, records::same($a, $b));
    }

    /**
     * Data for test_same.
     *
     * @return array
     */
    public static function same_provider(): array {
        return [
            'a stored decimal' => ['3.00', 3, true],
            'a stored decimal and float' => ['120.50', 120.5, true],
            'null is empty' => [null, '', true],
            'a real change' => ['3.00', '3.50', false],
            'leading zeros are part of an ID' => ['007', '7', false],
            'a long number is text' => ['12345678901234567890', '12345678901234567891', false],
            'not scientific notation' => ['1e3', '1000', false],
        ];
    }
}
