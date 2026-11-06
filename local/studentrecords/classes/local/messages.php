<?php

namespace local_studentrecords\local;

/**
 * Automatic messages: a Moodle notification (and an email, as the person's message settings allow) when something
 * happens. Each message can be switched off and its text changed by the school (reset to default). The text says only
 * that there is something new and asks to log in: there are no placeholders for grades or amounts. Messages come from
 * the no-reply address with the school name. Every message is logged, sent or skipped. In test mode only addresses at
 * example.com get messages.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class messages {
    /** @var string Message log table. */
    const LOG = 'local_studentrecords_msglog';

    /** @var array Messages to students: key => Moodle message type. */
    const STUDENT = [
        'regadded' => 'registration', 'regdropped' => 'registration', 'regwithdrawn' => 'registration',
        'accessopen' => 'registration', 'paymentreceived' => 'billing', 'paymentdue' => 'billing',
        'paymentlate' => 'billing', 'holdplaced' => 'records', 'holdreleased' => 'records', 'refundapproved' => 'billing',
        'refundpaid' => 'billing', 'gradeposted' => 'records', 'admdecision' => 'records', 'gradreviewed' => 'records',
        'conferred' => 'records', 'waitseat' => 'registration', 'welcome' => 'records', 'lowattendance' => 'records',
        'planagreement' => 'billing', 'aidletter' => 'billing',
    ];

    /** @var array Messages to staff: key => capability of the people who get it. */
    const STAFF = [
        'staffrefund' => 'local/studentrecords:approverefund',
        'stafflate' => 'local/studentrecords:managebilling',
    ];

    /** @var string[] Placeholders the school may use in the text. */
    const PLACEHOLDERS = ['school', 'firstname', 'course', 'term', 'link', 'due_date', 'pay_link', 'office_phone'];

    /** @var string Email ending that gets messages in test mode. */
    const TESTDOMAIN = '@example.com';

    // Rules that need no database.

    /**
     * Fill the placeholders of a text; unknown placeholders are left as they are.
     *
     * @param string $text
     * @param array $values placeholder => value
     * @return string
     */
    public static function fill(string $text, array $values): string {
        foreach (self::PLACEHOLDERS as $name) {
            $text = str_replace('{' . $name . '}', (string)($values[$name] ?? ''), $text);
        }
        return $text;
    }

    /**
     * Whether a person may get a message: in test mode only addresses at example.com.
     *
     * @param string $email
     * @param bool $testmode
     * @return bool
     */
    public static function allowed(string $email, bool $testmode): bool {
        return !$testmode || str_ends_with(strtolower(trim($email)), self::TESTDOMAIN);
    }

    // Settings.

    /**
     * All message keys.
     *
     * @return string[]
     */
    public static function keys(): array {
        return array_merge(array_keys(self::STUDENT), array_keys(self::STAFF));
    }

    /**
     * Whether a message is switched on (default on).
     *
     * @param string $key
     * @return bool
     */
    public static function is_on(string $key): bool {
        return catalog::setting('msgon_' . $key) !== '0';
    }

    /**
     * The school's test inbox (email address), or ''.
     *
     * @return string
     */
    public static function test_inbox(): string {
        $inbox = trim(catalog::setting('msgtestinbox'));
        return validate_email($inbox) ? $inbox : '';
    }

    /**
     * Email a message to an address that is not a Moodle user (the test inbox).
     *
     * @param string $address
     * @param string $subject
     * @param string $body
     * @return bool
     */
    protected static function email_inbox(string $address, string $subject, string $body): bool {
        // A copy: Moodle can keep one no-reply user for the whole request, and these changes are only for this message.
        $to = clone \core_user::get_noreply_user();
        $to->email = $address;
        $to->firstname = get_string('msg_testinbox', 'local_studentrecords');
        $to->lastname = '';
        $to->mailformat = 0;
        $to->id = -99;
        $from = \core_user::get_noreply_user();
        return (bool)email_to_user($to, $from, '[' . get_string('msg_testtag', 'local_studentrecords') . '] ' . $subject, $body);
    }

    /**
     * "Send me a test": a message with sample fill-ins to the staff member (or the test inbox in test mode).
     *
     * @param string $key
     * @return bool sent
     */
    public static function send_test(string $key): bool {
        global $USER;
        $values = ['school' => catalog::setting('schoolname'), 'firstname' => $USER->firstname, 'course' => 'ABC 101',
            'term' => get_string('msg_sampleterm', 'local_studentrecords'),
                'link' => (new \moodle_url('/local/studentrecords/index.php'))
            ->out(false), 'due_date' => catalog::show_date(time() + 7 * DAYSECS), 'pay_link' => (new \moodle_url(
                '/local/studentrecords/index.php'
            ))->out(false), 'office_phone' => catalog::setting('phone')];
        $subject = \core_text::substr(self::fill(self::text($key, 'subject'), $values), 0, 255);
        $body = self::fill(self::text($key, 'body'), $values);
        $inbox = self::test_inbox();
        if (self::test_mode() && $inbox !== '') {
            $sent = self::email_inbox($inbox, $subject, $body);
        } else {
            $sent = (bool)email_to_user($USER, \core_user::get_noreply_user(), '[' . get_string(
                'msg_testtag',
                'local_studentrecords'
            ) . '] ' . $subject, $body);
        }
        self::log($key, $USER, 0, $subject, $sent ? 'test' : 'failed');
        return $sent;
    }

    /**
     * Whether test mode is on.
     *
     * @return bool
     */
    public static function test_mode(): bool {
        return catalog::setting('msgtestmode') === '1';
    }

    /**
     * The subject or text of a message: the school's own, or the default.
     *
     * @param string $key
     * @param string $part subject or body
     * @return string
     */
    public static function text(string $key, string $part): string {
        $own = catalog::setting('msg' . $part . '_' . $key);
        return $own !== '' ? $own : get_string('msgdef_' . $key . '_' . $part, 'local_studentrecords');
    }

    // Sending.

    /**
     * Send a message to a student (when switched on, the student has a Moodle account and, in test mode, a test address).
     *
     * @param string $key one of STUDENT
     * @param \stdClass $student
     * @param array $values course, term (optional)
     * @return bool sent
     */
    public static function student(string $key, \stdClass $student, array $values = []): bool {
        $url = new \moodle_url('/local/studentrecords/' . (in_array(self::STUDENT[$key], ['billing'], true) ? 'account.php' :
            'student.php'), ['id' => $student->id]);
        if ($key === 'waitseat') {
            $url = new \moodle_url('/local/studentrecords/myregistration.php');
        }
        $user = $student->userid ? (\core_user::get_user((int)$student->userid) ?: null) : null;
        return self::deliver($key, $user, (int)$student->id, self::STUDENT[$key], $url, $values);
    }

    /**
     * Send a staff message to everyone with the message's capability (by role).
     *
     * @param string $key one of STAFF
     * @param \stdClass $student the student it is about
     * @param \moodle_url $url
     * @param array $values course, term (optional)
     * @return int sent
     */
    public static function staff(string $key, \stdClass $student, \moodle_url $url, array $values = []): int {
        $n = 0;
        $users = get_users_by_capability(\context_system::instance(), self::STAFF[$key], 'u.*', 'u.id');
        foreach ($users as $user) {
            $n += self::deliver($key, $user, (int)$student->id, 'staffalerts', $url, $values) ? 1 : 0;
        }
        if (!$users) {
            self::log($key, null, (int)$student->id, '', 'nouser');
        }
        return $n;
    }

    /**
     * Build, send and log one message.
     *
     * @param string $key
     * @param \stdClass|null $user recipient
     * @param int $studentid
     * @param string $provider Moodle message type
     * @param \moodle_url $url
     * @param array $values
     * @return bool sent
     */
    protected static function deliver(
        string $key,
        ?\stdClass $user,
        int $studentid,
        string $provider,
        \moodle_url $url,
        array $values
    ): bool {
        $next = $studentid ? plans::next_due($studentid) : null;
        $values += ['school' => catalog::setting('schoolname'), 'firstname' => $user ? $user->firstname : '',
            'link' => $url->out(false), 'course' => '', 'term' => '',
            'due_date' => $next ? catalog::show_date((int)$next->duedate) : '',
            'pay_link' => $studentid ? (new \moodle_url('/local/studentrecords/pay.php', ['id' => $studentid]))->out(false) : '',
            'office_phone' => catalog::setting('phone')];
        $subject = \core_text::substr(self::fill(self::text($key, 'subject'), $values), 0, 255);
        if (!self::is_on($key)) {
            return self::log($key, $user, $studentid, $subject, 'off');
        }
        if (!$user || !empty($user->deleted) || !empty($user->suspended)) {
            return self::log($key, $user, $studentid, $subject, 'nouser');
        }
        $body = self::fill(self::text($key, 'body'), $values);
        $inbox = self::test_inbox();
        if (self::test_mode() && $inbox !== '') {
            // Test mode with a test inbox: the message goes only to the school's test inbox, never to the person.
            $sent = self::email_inbox($inbox, $subject, $body);
            self::log($key, $user, $studentid, $subject, $sent ? 'testinbox' : 'failed');
            return $sent;
        }
        if (!self::allowed((string)$user->email, self::test_mode())) {
            return self::log($key, $user, $studentid, $subject, 'testmode');
        }
        $from = clone \core_user::get_noreply_user();
        $from->firstname = catalog::setting('schoolname') !== '' ? catalog::setting('schoolname') : $from->firstname;
        $from->lastname = '';
        $message = new \core\message\message();
        $message->component = 'local_studentrecords';
        $message->name = $provider;
        $message->userfrom = $from;
        $message->userto = $user;
        $message->subject = $subject;
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = get_string('pluginname', 'local_studentrecords');
        $sent = (bool)message_send($message);
        self::log($key, $user, $studentid, $subject, $sent ? 'sent' : 'failed');
        return $sent;
    }

    /**
     * Log a message, sent or not.
     *
     * @param string $key
     * @param \stdClass|null $user
     * @param int $studentid
     * @param string $subject
     * @param string $status sent, failed, off, nouser or testmode
     * @return bool true when sent
     */
    protected static function log(string $key, ?\stdClass $user, int $studentid, string $subject, string $status): bool {
        global $DB;
        $DB->insert_record(self::LOG, (object)['msgkey' => $key, 'userid' => $user->id ?? null,
            'studentid' => $studentid ?: null, 'subject' => $subject, 'status' => $status, 'timecreated' => time()]);
        return $status === 'sent';
    }

    /**
     * The message log, newest first.
     *
     * @param int $limit
     * @param string $status '' = all
     * @return array
     */
    public static function recent(int $limit = 200, string $status = ''): array {
        global $DB;
        return $DB->get_records_sql("SELECT l.*, s.studentid AS studentno, u.firstname, u.lastname, u.email
                                       FROM {" . self::LOG . "} l
                                  LEFT JOIN {local_studentrecords_student} s ON s.id = l.studentid
                                  LEFT JOIN {user} u ON u.id = l.userid " .
            ($status !== '' ? 'WHERE l.status = ?' : '') . "
                                   ORDER BY l.timecreated DESC, l.id DESC", $status !== '' ? [$status] : [], 0, $limit);
    }
}
