<?php

namespace local_academictranscript\local;

/**
 * Official transcript requests.
 *
 * A student asks for an Official transcript (email PDF, mail or pick up) and the registrar issues it from the queue.
 * With Student Records as the transcript source, the fee goes on the student's account there, a request waits while the
 * student has a hold, and it waits for grades or the degree when the student asked for that.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class requests {
    /** @var string */
    const TABLE = 'local_academictranscript_request';

    /** @var array Delivery => [setting name of the fee, default fee]. */
    const DELIVERY = ['email' => ['fee_email', '5'], 'mail' => ['fee_mail', '10'], 'pickup' => ['fee_pickup', '10']];

    /** @var string[] When to send. */
    const TIMING = ['now', 'grades', 'degree'];

    /** @var int Seconds to wait for another click or person working on the same request. */
    const LOCKWAIT = 10;

    /**
     * @var int Seconds in which the same action again counts as the same click: the same request while it is pending, Reissue
     * of a copy just made, or the same recipient on the student's page.
     */
    const REPEATWINDOW = 60;

    /**
     * Fee of a delivery.
     *
     * @param string $delivery
     * @return float
     */
    public static function fee(string $delivery): float {
        [$name, $default] = self::DELIVERY[$delivery];
        $v = transcript::setting($name, $default);
        return is_numeric($v) ? max(0.0, round((float)$v, 2)) : (float)$default;
    }

    /**
     * Problems with a request form: empty list when it can be saved.
     *
     * @param array $data delivery, recipient, email, address, timing
     * @return array field => language string key
     */
    public static function problems(array $data): array {
        $out = [];
        if (!isset(self::DELIVERY[$data['delivery'] ?? ''])) {
            $out['delivery'] = 'req_err_delivery';
        }
        if (!in_array($data['timing'] ?? '', self::TIMING, true)) {
            $out['timing'] = 'req_err_timing';
        }
        if (trim((string)($data['recipient'] ?? '')) === '') {
            $out['recipient'] = 'req_err_recipient';
        }
        if (($data['delivery'] ?? '') === 'email' && !validate_email(trim((string)($data['email'] ?? '')))) {
            $out['email'] = 'req_err_email';
        }
        if (($data['delivery'] ?? '') === 'mail' && trim((string)($data['address'] ?? '')) === '') {
            $out['address'] = 'req_err_address';
        }
        return $out;
    }

    /**
     * Holds in force on the student's Student Records file (reasons), when Student Records is the source.
     *
     * @param int $userid
     * @return string[] plain text
     */
    public static function holds(int $userid): array {
        if (!srsource::enabled()) {
            return [];
        }
        $student = \local_studentrecords\local\students::get_by_user($userid);
        if (!$student) {
            return [];
        }
        $out = [];
        foreach (\local_studentrecords\local\academic::holds((int)$student->id) as $h) {
            if (\local_studentrecords\local\academic::hold_active($h, time())) {
                $out[] = get_string('holdtype_' . $h->holdtype, 'local_studentrecords') .
                    (trim((string)$h->reason) !== '' ? ': ' . trim((string)$h->reason) : '');
            }
        }
        return $out;
    }

    /**
     * Whether a fee can go on the student's account: Student Records is the source, its billing is on, and the student
     * has a file there. Without this the fee is only recorded on the request, and the school collects it another way.
     *
     * @param int $userid
     * @return bool
     */
    public static function can_charge(int $userid): bool {
        return srsource::enabled() && \local_studentrecords\local\billing::enabled() &&
            \local_studentrecords\local\students::get_by_user($userid) !== null;
    }

    /**
     * Whether a request asked to wait (for grades or the degree) and still waits.
     *
     * @param \stdClass $request
     * @return bool
     */
    public static function waiting(\stdClass $request): bool {
        global $DB;
        if ($request->timing === 'now' || !srsource::enabled()) {
            return false;
        }
        $student = \local_studentrecords\local\students::get_by_user((int)$request->userid);
        if (!$student) {
            return false;
        }
        if ($request->timing === 'degree') {
            return $student->status !== 'graduated';
        }
        // Grades: classes still in progress in a term that had started when the student asked.
        return $DB->record_exists_sql("SELECT 1 FROM {local_studentrecords_reg} r
                                         JOIN {local_studentrecords_offer} o ON o.id = r.offerid
                                         JOIN {local_studentrecords_term} t ON t.id = o.termid
                                        WHERE r.studentid = ? AND r.status = 'registered' AND t.startdate <= ?", [$student->id,
            $request->timecreated]);
    }

    /**
     * Queue state of a request: pending, onhold, waiting, issued or cancelled.
     *
     * @param \stdClass $request
     * @return string
     */
    public static function state(\stdClass $request): string {
        if (in_array($request->status, ['issued', 'cancelled'], true)) {
            return $request->status;
        }
        if (self::holds((int)$request->userid)) {
            return 'onhold';
        }
        return self::waiting($request) ? 'waiting' : 'pending';
    }

    /**
     * Do some work holding a lock, so that two requests can't both read a row and then act on it. The work starts and
     * commits its own transaction, so the lock is released after the commit, and whoever waited sees what was saved.
     *
     * This is Moodle's lock, not the one of Student Records, because this plugin also works without that plugin.
     *
     * @param string $resource what is locked, for example request12 or issue34
     * @param callable $work
     * @return mixed what the work returns
     * @throws \moodle_exception when the lock stays taken for too long
     */
    protected static function one_at_a_time(string $resource, callable $work) {
        $lock = \core\lock\lock_config::get_lock_factory('local_academictranscript')->get_lock($resource, self::LOCKWAIT);
        if (!$lock) {
            throw new \moodle_exception('req_err_busy', 'local_academictranscript');
        }
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    /**
     * Save a request. The fee goes on the student's account when it can (see can_charge()).
     *
     * A double click sends the form twice, and both can run at the same moment. Under the lock one saves first, and the other
     * finds that request and saves nothing, so there is one request and one fee. A different request is new at any time, and
     * so is the same request after REPEATWINDOW or after the registrar has acted on it.
     *
     * @param int $userid
     * @param array $data
     * @param bool|null $repeated set to true when this was the same request again and nothing was saved
     * @return int request id
     */
    public static function create(int $userid, array $data, ?bool &$repeated = null): int {
        $repeated = false;
        $problems = self::problems($data);
        if ($problems) {
            throw new \moodle_exception(reset($problems), 'local_academictranscript');
        }
        $row = (object)['userid' => $userid, 'delivery' => $data['delivery'],
            'recipient' => \core_text::substr(trim($data['recipient']), 0, 255),
            'email' => $data['delivery'] === 'email' ? \core_text::substr(trim($data['email']), 0, 255) : null,
            'address' => $data['delivery'] === 'mail' ? trim($data['address']) : null, 'timing' => $data['timing']];
        return self::one_at_a_time('request' . $userid, function () use ($row, &$repeated) {
            $id = self::same_request($row);
            $repeated = $id !== null;
            return $id ?? self::save_request($row);
        });
    }

    /**
     * The student's request that this one repeats: still pending, made a moment ago, and the same in all the student chose.
     * The caller holds the lock on the student.
     *
     * @param \stdClass $row the new request
     * @return int|null request id
     */
    protected static function same_request(\stdClass $row): ?int {
        global $DB;
        $recent = $DB->get_records_select(
            self::TABLE,
            'userid = ? AND status = ? AND timecreated >= ?',
            [$row->userid, 'pending', time() - self::REPEATWINDOW]
        );
        foreach ($recent as $r) {
            if (
                $r->delivery === $row->delivery && $r->recipient === $row->recipient && $r->timing === $row->timing &&
                    (string)$r->email === (string)$row->email && (string)$r->address === (string)$row->address
            ) {
                return (int)$r->id;
            }
        }
        return null;
    }

    /**
     * Save a new request and its fee, all or nothing. The caller holds the lock on the student.
     *
     * @param \stdClass $row
     * @return int request id
     */
    protected static function save_request(\stdClass $row): int {
        global $DB;
        $userid = (int)$row->userid;
        $fee = self::fee($row->delivery);
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $id = (int)$DB->insert_record(self::TABLE, (object)((array)$row + ['fee' => $fee, 'charged' => 0, 'status' => 'pending',
                'timecreated' => $now, 'timemodified' => $now]));
            if ($fee > 0 && self::can_charge($userid)) {
                $student = \local_studentrecords\local\students::get_by_user($userid);
                \local_studentrecords\local\billing::add_line(['studentid' => $student->id, 'txntype' => 'charge',
                    'amount' => $fee, 'description' => get_string(
                        'req_chargedesc',
                        'local_academictranscript',
                        get_string('req_delivery_' . $row->delivery, 'local_academictranscript')
                    ), 'txndate' => $now,
                    'qualified' => 0, 'refundable' => 0, 'note' => 'transcript request ' . $id]);
                $DB->set_field(self::TABLE, 'charged', 1, ['id' => $id]);
            }
            logger::log('request', $userid, 0, $row->delivery . ' ' . $row->timing);
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        logger::commit($transaction, $userid);
        return $id;
    }

    /**
     * Issue the Official transcript of a request, or issue it again with a new code (registrar).
     *
     * A hold blocks both: "the registrar sends it when the hold is released" is what the student is told.
     *
     * Two clicks on Issue or on Reissue, or two registrars, can run at the same moment. Under the lock one makes the copy, and
     * the other finds it and makes nothing. Reissue works again after REPEATWINDOW.
     *
     * @param int $requestid
     * @param bool $again true to issue a request that was issued before. Only Reissue asks for that.
     * @return \stdClass the issue record
     */
    public static function issue(int $requestid, bool $again = false): \stdClass {
        return self::one_at_a_time('issue' . $requestid, fn() => self::issue_request($requestid, $again));
    }

    /**
     * Check a request and make its Official transcript. The caller holds the lock on the request.
     *
     * @param int $requestid
     * @param bool $again
     * @return \stdClass the issue record
     */
    protected static function issue_request(int $requestid, bool $again): \stdClass {
        global $DB, $USER;
        $r = $DB->get_record(self::TABLE, ['id' => $requestid], '*', MUST_EXIST);
        if ($r->status === 'cancelled') {
            throw new \moodle_exception('req_err_cancelled', 'local_academictranscript');
        }
        if (!$again && $r->status === 'issued') {
            throw new \moodle_exception('req_err_issued', 'local_academictranscript');
        }
        $ready = $again ? $r->status === 'issued' && !self::holds((int)$r->userid) : self::state($r) === 'pending';
        if (!$ready) {
            throw new \moodle_exception('req_err_notready', 'local_academictranscript');
        }
        if ($again && (int)$r->timeissued >= time() - self::REPEATWINDOW) {
            throw new \moodle_exception('req_err_justissued', 'local_academictranscript');
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $issue = transcript::issue(
                (int)$r->userid,
                $r->recipient . ($r->delivery === 'email' && $r->email ? ' <' . $r->email . '>' : '')
            );
            $now = time();
            $DB->update_record(self::TABLE, (object)['id' => $r->id, 'status' => 'issued', 'issueid' => $issue->id,
                'issuedby' => $USER->id, 'timeissued' => $now, 'timemodified' => $now]);
            logger::log('request_issued', (int)$r->userid, (int)$issue->id, (string)$r->id);
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        logger::commit($transaction, (int)$r->userid);
        return $issue;
    }

    /**
     * Issue an Official transcript from the student's page, with no request behind it (staff).
     *
     * A double click sends the form twice, and both can run at the same moment. Under the lock one makes the transcript, and
     * the other gets that copy back. Another recipient is a new transcript at any time, and so is the same recipient after
     * REPEATWINDOW.
     *
     * @param int $userid
     * @param string $recipient
     * @param bool|null $repeated set to true when this was the same issue again and nothing was made
     * @return \stdClass the issue record
     */
    public static function issue_direct(int $userid, string $recipient, ?bool &$repeated = null): \stdClass {
        $repeated = false;
        return self::one_at_a_time('direct' . $userid, function () use ($userid, $recipient, &$repeated) {
            $issue = self::same_issue($userid, $recipient);
            $repeated = $issue !== null;
            return $issue ?? transcript::issue($userid, $recipient);
        });
    }

    /**
     * The Official transcript this one repeats: for the same student and recipient, and made a moment ago. The caller holds the
     * lock on the student.
     *
     * @param int $userid
     * @param string $recipient
     * @return \stdClass|null id, recipient, transcriptno and verifycode of the issue
     */
    protected static function same_issue(int $userid, string $recipient): ?\stdClass {
        global $DB;
        $recipient = \core_text::substr(trim($recipient), 0, 255);
        $recent = $DB->get_records_select(
            'local_academictranscript_issue',
            'userid = ? AND timecreated >= ?',
            [$userid, time() - self::REPEATWINDOW],
            'id DESC',
            'id, recipient, transcriptno, verifycode'
        );
        foreach ($recent as $issue) {
            if ((string)$issue->recipient === $recipient) {
                return $issue;
            }
        }
        return null;
    }

    /**
     * Cancel a request that is not issued yet. A fee already on the account stays, and staff can credit it.
     *
     * This takes the lock of issue(), so Cancel and Issue can't both go ahead: whichever comes second changes nothing.
     *
     * @param int $requestid
     * @param string $note
     */
    public static function cancel(int $requestid, string $note): void {
        self::one_at_a_time('issue' . $requestid, fn() => self::cancel_request($requestid, $note));
    }

    /**
     * Check a request and cancel it. The caller holds the lock on the request.
     *
     * @param int $requestid
     * @param string $note
     */
    protected static function cancel_request(int $requestid, string $note): void {
        global $DB;
        $r = $DB->get_record(self::TABLE, ['id' => $requestid], '*', MUST_EXIST);
        if ($r->status === 'issued') {
            throw new \moodle_exception('req_err_notcancelled', 'local_academictranscript');
        }
        if ($r->status !== 'pending') {
            throw new \moodle_exception('req_err_notpending', 'local_academictranscript');
        }
        $note = \core_text::substr(trim($note), 0, 255);
        $DB->update_record(self::TABLE, (object)['id' => $r->id, 'status' => 'cancelled', 'note' => $note,
            'timemodified' => time()]);
        logger::log('request_cancelled', (int)$r->userid, 0, trim($r->id . ' ' . $note));
    }

    /**
     * Requests of one student, newest first.
     *
     * @param int $userid
     * @return array
     */
    public static function for_user(int $userid): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['userid' => $userid], 'timecreated DESC, id DESC');
    }
}
