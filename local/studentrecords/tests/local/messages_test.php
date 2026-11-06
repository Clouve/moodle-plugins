<?php

namespace local_studentrecords\local;

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
