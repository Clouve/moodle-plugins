<?php

namespace local_studentrecords\local;

use local_studentrecords\task\send_message;

#[\PHPUnit\Framework\Attributes\CoversClass(messages::class)]
/**
 * Tests for automatic messages: placeholders, test mode, and what is really sent.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\messages
 */
final class messages_test extends \advanced_testcase {
    /** @var \local_studentrecords_generator */
    private $generator;

    /**
     * Billing on and a school name, with the admin working.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // On PostgreSQL a test runs inside a transaction, and a message in a transaction waits for cron.
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $this->generator->set_settings(['billing' => 1, 'schoolname' => 'Riverside College']);
    }

    /**
     * The statuses of the message log for a key, oldest first.
     *
     * @param string $key
     * @return string[]
     */
    private function logged(string $key): array {
        global $DB;
        return array_values(array_map(fn($l) => $l->status, $DB->get_records(messages::LOG, ['msgkey' => $key], 'id')));
    }

    /**
     * Placeholders are filled; there is no placeholder for amounts or grades, so those stay as typed.
     */
    public function test_fill(): void {
        $values = ['school' => 'Example University', 'firstname' => 'John', 'course' => 'ACC2301', 'term' => 'Spring 2027',
            'link' => 'https://example.com/x', 'amount' => '$900.00', 'grade' => 'A'];
        $this->assertSame(
            'Example University: ACC2301 (Spring 2027) for John https://example.com/x',
            messages::fill('{school}: {course} ({term}) for {firstname} {link}', $values)
        );
        $this->assertSame('Paid {amount}, grade {grade}', messages::fill('Paid {amount}, grade {grade}', $values));
        $this->assertSame('Hello ', messages::fill('Hello {firstname}', []));
    }

    /**
     * Test mode: only addresses at example.com.
     */
    public function test_allowed(): void {
        $this->assertTrue(messages::allowed('teststudent@example.com', true));
        $this->assertTrue(messages::allowed(' TestF1Student@Example.COM ', true));
        $this->assertFalse(messages::allowed('registrar@example.org', true));
        $this->assertFalse(messages::allowed('someone@example.com.evil.org', true));
        $this->assertTrue(messages::allowed('registrar@example.org', false));
    }

    /**
     * Going through the money flows, no message holds a dollar amount or a grade, in its subject or its text.
     */
    public function test_messages_hold_no_amounts_or_grades(): void {
        $sink = $this->redirectMessages();
        $student = $this->generator->create_user_student();
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 1234.56]);
        $term = $this->generator->create_term();
        $planid = plans::create($student, (int)$term->id, null, ['name' => 'Plan', 'downtype' => 'percent', 'downvalue' => 25,
            'numpay' => 2, 'frequency' => 'monthly', 'duemode' => 'relative', 'firstdue' => 30, 'planfee' => 0,
            'latefee' => 77.77, 'gracedays' => 0], 'Custom');
        plans::send_agreement($student, $planid);
        payments::record($student, 111.11, 'check');
        global $DB;
        $DB->set_field(plans::INSTS, 'duedate', time() - 10 * DAYSECS, ['stuplanid' => $planid, 'seq' => 0]);
        plans::daily();
        billing::add_line(['studentid' => $student->id, 'txntype' => 'credit', 'amount' => -5000, 'method' => 'adjustment',
            'description' => 'Adjustment']);
        refunds::payout($student, 3.33, 'check');

        $waiting = $DB->get_fieldset_select('task_adhoc', 'customdata', 'classname = ?', ['\\' . send_message::class]);
        $this->assertNotEmpty($waiting);
        foreach ($waiting as $job) {
            $this->assertStringNotContainsString('$', $job);
            $this->assertDoesNotMatchRegularExpression('/\b\d[\d,]*\.\d\d\b/', $job);
        }
        $this->runAdhocTasks(send_message::class);

        $sent = $sink->get_messages();

        $this->assertGreaterThanOrEqual(5, count($sent));
        foreach ($sent as $message) {
            $text = $message->subject . ' ' . $message->fullmessage . ' ' . $message->smallmessage;
            $this->assertStringNotContainsString('$', $text);
            $this->assertDoesNotMatchRegularExpression('/\b\d[\d,]*\.\d\d\b/', $text);
        }
    }

    /**
     * Test mode with a test inbox: the message goes to the inbox, and never to the person.
     */
    public function test_test_mode_with_a_test_inbox(): void {
        $messagesink = $this->redirectMessages();
        $emailsink = $this->redirectEmails();
        $this->generator->set_settings(['msgtestmode' => 1, 'msgtestinbox' => 'inbox@example.org']);
        $student = $this->generator->create_user_student(['email' => 'real.person@example.net']);

        $this->assertTrue(messages::student('paymentreceived', $student));

        $this->assertSame(0, $messagesink->count());
        $emails = $emailsink->get_messages();
        $this->assertCount(1, $emails);
        $this->assertSame('inbox@example.org', $emails[0]->to);
        $this->assertSame(['testinbox'], $this->logged('paymentreceived'));
    }

    /**
     * Test mode without a test inbox: only addresses at example.com get messages.
     */
    public function test_test_mode_without_a_test_inbox(): void {
        $sink = $this->redirectMessages();
        $this->generator->set_settings(['msgtestmode' => 1]);
        $real = $this->generator->create_user_student(['email' => 'real.person@example.net']);
        $test = $this->generator->create_user_student(['email' => 'test.person@example.com']);

        $this->assertFalse(messages::student('paymentreceived', $real));
        $this->assertTrue(messages::student('paymentreceived', $test));

        $this->assertSame(1, $sink->count());
        $this->assertSame((int)$test->userid, (int)$sink->get_messages()[0]->useridto);
        $this->assertSame(['testmode', 'sent'], $this->logged('paymentreceived'));
    }

    /**
     * A message switched off is logged, not sent.
     */
    public function test_message_switched_off(): void {
        $sink = $this->redirectMessages();
        $this->generator->set_settings(['msgon_paymentreceived' => 0]);
        $student = $this->generator->create_user_student();

        $this->assertFalse(messages::student('paymentreceived', $student));

        $this->assertSame(0, $sink->count());
        $this->assertSame(['off'], $this->logged('paymentreceived'));
    }

    /**
     * Messages come from the school, and that change stays out of Moodle's own no-reply user, which Moodle can keep for
     * the whole request.
     */
    public function test_noreply_user_is_not_changed(): void {
        global $CFG;
        $this->redirectMessages();
        $this->redirectEmails();
        $student = $this->generator->create_user_student();
        $noreply = $this->getDataGenerator()->create_user(['firstname' => 'Noreply', 'lastname' => 'Robot']);
        $CFG->noreplyuserid = $noreply->id;
        \core_user::reset_internal_users();

        messages::student('paymentreceived', $student);
        $this->generator->set_settings(['msgtestmode' => 1, 'msgtestinbox' => 'inbox@example.org']);
        messages::student('paymentreceived', $student);

        $user = \core_user::get_noreply_user();
        $this->assertSame('Noreply', $user->firstname);
        $this->assertSame('Robot', $user->lastname);
        $this->assertSame((int)$noreply->id, (int)$user->id);
        $this->assertSame($noreply->email, $user->email);
        unset($CFG->noreplyuserid);
        \core_user::reset_internal_users();
    }

    /**
     * Staff messages go to people with the capability, and say nothing about the student's money.
     */
    public function test_staff_message(): void {
        $sink = $this->redirectMessages();
        $student = $this->generator->create_user_student();
        $finance = $this->getDataGenerator()->create_user();
        $roleid = \local_studentrecords\local\billing::create_finance_role();
        role_assign($roleid, $finance->id, \context_system::instance()->id);

        $sent = messages::staff('staffrefund', $student, new \moodle_url('/local/studentrecords/refunds.php', ['id' => 1]));

        $this->assertSame(1, $sent);
        $this->assertSame((int)$finance->id, (int)$sink->get_messages()[0]->useridto);
        $this->assertStringNotContainsString('$', $sink->get_messages()[0]->fullmessage);
    }

    /**
     * With no transaction open, a message goes at once.
     */
    public function test_a_message_with_no_transaction_goes_at_once(): void {
        global $DB;
        $sink = $this->redirectMessages();
        $student = $this->generator->create_user_student();

        $this->assertTrue(messages::student('paymentreceived', $student));

        $this->assertSame(1, $sink->count());
        $this->assertSame(['sent'], $this->logged('paymentreceived'));
        $this->assertSame(0, $DB->count_records('task_adhoc', ['classname' => '\\' . send_message::class]));
    }

    /**
     * Nothing is sent inside a transaction, and the commit sends nothing: a mail server that hangs would hold up the commit and
     * any lock around it. The message waits for cron. Until it really goes, the log doesn't say it was sent.
     */
    public function test_a_message_in_a_transaction_waits_for_cron(): void {
        global $DB;
        $sink = $this->redirectMessages();
        $student = $this->generator->create_user_student();

        $transaction = $DB->start_delegated_transaction();
        $this->assertFalse(messages::student('paymentreceived', $student));
        $transaction->allow_commit();

        $this->assertSame(0, $sink->count());
        $this->assertSame(['queued'], $this->logged('paymentreceived'));

        $this->runAdhocTasks(send_message::class);

        $this->assertSame(1, $sink->count());
        $this->assertSame((int)$student->userid, (int)$sink->get_messages()[0]->useridto);
        $this->assertSame(['sent'], $this->logged('paymentreceived'));
        $this->assertSame(0, $DB->count_records('task_adhoc', ['classname' => '\\' . send_message::class]));
    }

    /**
     * Cron can run a task again, for example after it stopped between sending and finishing. The message goes once: the log row
     * says it left the queue.
     */
    public function test_a_queued_message_goes_once(): void {
        global $DB;
        $sink = $this->redirectMessages();
        $student = $this->generator->create_user_student();
        $transaction = $DB->start_delegated_transaction();
        messages::student('paymentreceived', $student);
        $transaction->allow_commit();
        $job = json_decode($DB->get_field('task_adhoc', 'customdata', ['classname' => '\\' . send_message::class]));

        messages::send_queued($job);
        messages::send_queued($job);

        $this->assertSame(1, $sink->count());
        $this->assertSame(['sent'], $this->logged('paymentreceived'));
    }

    /**
     * The status of the newest message of a key for a student, and none when there is no such message.
     */
    public function test_last_status(): void {
        $this->redirectMessages();
        $student = $this->generator->create_user_student();
        $this->assertSame('', messages::last_status('aidletter', (int)$student->id));

        $this->generator->set_settings(['msgon_aidletter' => 0]);
        messages::student('aidletter', $student);
        $this->generator->set_settings(['msgon_aidletter' => 1]);
        messages::student('aidletter', $student);

        $this->assertSame('sent', messages::last_status('aidletter', (int)$student->id));
    }

    /**
     * A transaction that is rolled back takes its messages with it: nobody hears about work that was not saved.
     */
    public function test_a_rollback_drops_the_waiting_message(): void {
        global $DB;
        $sink = $this->redirectMessages();
        $student = $this->generator->create_user_student();

        try {
            billing::transaction(function () use ($student) {
                messages::student('paymentreceived', $student);
                throw new \moodle_exception('err_busy', 'local_studentrecords');
            });
            $this->fail('The work stopped, so the transaction ends with the error.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_busy', $e->errorcode);
        }
        $this->runAdhocTasks(send_message::class);

        $this->assertSame(0, $sink->count());
        $this->assertSame([], $this->logged('paymentreceived'));
        $this->assertSame(0, $DB->count_records('task_adhoc', ['classname' => '\\' . send_message::class]));
    }

    /**
     * Each message of a transaction is sent on its own. One that can't be sent doesn't lose the ones after it, the log says
     * which one failed and who it was for, and the work stays saved.
     */
    public function test_a_failed_message_does_not_lose_the_others(): void {
        global $CFG, $DB;
        $emails = $this->redirectEmails();
        unset($CFG->noemailever);
        $broken = $this->generator->create_user_student();
        $good = $this->generator->create_user_student();
        $DB->set_field('user', 'email', '', ['id' => $broken->userid]);

        $transaction = $DB->start_delegated_transaction();
        messages::student('paymentreceived', $broken);
        messages::student('paymentreceived', $good);
        records::commit($transaction);
        $this->runAdhocTasks(send_message::class);
        $this->resetDebugging();

        $rows = $DB->get_records(messages::LOG, ['msgkey' => 'paymentreceived'], 'id');
        $this->assertSame([[(int)$broken->id, 'failed'], [(int)$good->id, 'sent']], array_values(array_map(
            fn($l) => [(int)$l->studentid, $l->status],
            $rows
        )));
        $this->assertSame([$DB->get_field('user', 'email', ['id' => $good->userid])], array_map(
            fn($mail) => $mail->to,
            $emails->get_messages()
        ));
        $this->assertFalse($DB->record_exists_select(
            'local_studentrecords_log',
            $DB->sql_like('info', '?'),
            ['after saving: %']
        ));
    }

    /**
     * Test mode is read when the message really goes, so a queued message can't reach a real person after test mode came on.
     */
    public function test_test_mode_counts_when_the_message_goes(): void {
        global $DB;
        $messagesink = $this->redirectMessages();
        $emailsink = $this->redirectEmails();
        $student = $this->generator->create_user_student(['email' => 'real.person@example.net']);
        $other = $this->generator->create_user_student(['email' => 'other.person@example.net']);

        $transaction = $DB->start_delegated_transaction();
        messages::student('paymentreceived', $student);
        $transaction->allow_commit();
        $this->generator->set_settings(['msgtestmode' => 1]);
        $this->runAdhocTasks(send_message::class);

        $this->assertSame(['testmode'], $this->logged('paymentreceived'));
        $this->assertSame(0, $messagesink->count());

        $transaction = $DB->start_delegated_transaction();
        messages::student('refundpaid', $other);
        $transaction->allow_commit();
        $this->generator->set_settings(['msgtestinbox' => 'inbox@example.org']);
        $this->runAdhocTasks(send_message::class);

        $this->assertSame(['testinbox'], $this->logged('refundpaid'));
        $this->assertSame(0, $messagesink->count());
        $this->assertSame('inbox@example.org', $emailsink->get_messages()[0]->to);
    }

    /**
     * A queued message goes only if it still may: not once it is switched off or the person's account is gone, and not after a
     * privacy request removed it from the log.
     */
    public function test_a_queued_message_checks_again_when_it_goes(): void {
        global $DB;
        $sink = $this->redirectMessages();
        $off = $this->generator->create_user_student();
        $gone = $this->generator->create_user_student();
        $erased = $this->generator->create_user_student();

        $transaction = $DB->start_delegated_transaction();
        messages::student('paymentreceived', $off);
        messages::student('refundpaid', $gone);
        messages::student('holdplaced', $erased);
        $transaction->allow_commit();
        $this->generator->set_settings(['msgon_paymentreceived' => 0]);
        delete_user(\core_user::get_user($gone->userid));
        $DB->delete_records(messages::LOG, ['studentid' => $erased->id]);
        $this->runAdhocTasks(send_message::class);

        $this->assertSame(0, $sink->count());
        $this->assertSame(['off'], $this->logged('paymentreceived'));
        $this->assertSame(['nouser'], $this->logged('refundpaid'));
        $this->assertSame([], $this->logged('holdplaced'));
    }

    /**
     * Staff messages of a transaction wait too, one for each person.
     */
    public function test_staff_messages_in_a_transaction_wait_for_cron(): void {
        global $DB;
        $sink = $this->redirectMessages();
        $student = $this->generator->create_user_student();
        $roleid = billing::create_finance_role();
        foreach ([$this->getDataGenerator()->create_user(), $this->getDataGenerator()->create_user()] as $finance) {
            role_assign($roleid, $finance->id, \context_system::instance()->id);
        }

        $transaction = $DB->start_delegated_transaction();
        $url = new \moodle_url('/local/studentrecords/refunds.php', ['id' => 1]);
        $this->assertSame(0, messages::staff('staffrefund', $student, $url));
        $transaction->allow_commit();

        $this->assertSame(['queued', 'queued'], $this->logged('staffrefund'));
        $this->runAdhocTasks(send_message::class);
        $this->assertSame(2, $sink->count());
        $this->assertSame(['sent', 'sent'], $this->logged('staffrefund'));
        $this->assertStringEndsWith('refunds.php?id=1', $sink->get_messages()[0]->contexturl);
    }

    /**
     * A message written under any lock waits for cron, whatever kind it is.
     */
    public function test_any_message_under_a_lock_waits_for_cron(): void {
        $this->redirectMessages();
        $student = $this->generator->create_user_student();

        registration::with_lock('offer1', fn() => messages::student('paymentreceived', $student));

        $this->assertSame(['queued'], $this->logged('paymentreceived'));
    }

    /**
     * Grades and seat offers are sent while a lock on a registration or a section is held, with no transaction open. A lock
     * must not wait on a mail server, so they go through cron.
     */
    public function test_messages_sent_under_a_lock_wait_for_cron(): void {
        $sink = $this->redirectMessages();
        $student = $this->generator->create_user_student();

        foreach (['gradeposted', 'waitseat'] as $key) {
            registration::with_lock('reg1', fn() => messages::student($key, $student, ['course' => 'ABC 101']));
            $this->assertSame(['queued'], $this->logged($key));
        }
        $this->assertSame(0, $sink->count());

        $this->runAdhocTasks(send_message::class);

        $this->assertSame(2, $sink->count());
        $this->assertStringContainsString('ABC 101', $sink->get_messages()[0]->fullmessage);
        $this->assertSame(['sent'], $this->logged('waitseat'));
    }

    /**
     * The message log page names the state of a message that waits for cron.
     */
    public function test_every_status_has_a_name(): void {
        foreach (['queued', 'sent', 'failed', 'off', 'nouser', 'testmode', 'testinbox', 'test'] as $status) {
            $this->assertTrue(get_string_manager()->string_exists('msgstatus_' . $status, 'local_studentrecords'), $status);
        }
    }

    /**
     * Send me a test goes to the staff member, or to the test inbox in test mode.
     */
    public function test_send_test(): void {
        $sink = $this->redirectEmails();
        $this->assertTrue(messages::send_test('paymentreceived'));
        $this->generator->set_settings(['msgtestmode' => 1, 'msgtestinbox' => 'inbox@example.org']);
        $this->assertTrue(messages::send_test('paymentreceived'));

        $emails = $sink->get_messages();
        $this->assertCount(2, $emails);
        $this->assertSame('inbox@example.org', $emails[1]->to);
        $this->assertStringContainsString('TEST', $emails[1]->subject);
    }
}
