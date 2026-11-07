<?php

namespace local_studentrecords\local;

/**
 * Holds (block registration until released) and grade changes (requested with a reason, approved or rejected by a
 * second person, applied when approved). A grade change is the only way for staff to change a final grade, and the only
 * way for anyone after the term's grade freeze.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class academic {
    /** @var string Holds table. */
    const HOLDS = 'local_studentrecords_hold';

    /** @var string Grade changes table. */
    const CHANGES = 'local_studentrecords_grchg';

    /** @var string[] Hold types. */
    const HOLDTYPES = ['academic', 'financial', 'admissions', 'records', 'conduct', 'other'];

    // Holds.

    /**
     * Whether a hold is in force on a day: placed, started, not ended, not released.
     *
     * @param \stdClass|array $hold
     * @param int $now
     * @return bool
     */
    public static function hold_active($hold, int $now): bool {
        $h = (array)$hold;
        return empty($h['timereleased']) && (int)$h['startdate'] <= $now && (empty($h['enddate']) || (int)$h['enddate'] >= $now);
    }

    /**
     * Holds of a student, newest first.
     *
     * @param int $studentid
     * @return array
     */
    public static function holds(int $studentid): array {
        global $DB;
        return array_values($DB->get_records(self::HOLDS, ['studentid' => $studentid], 'timeplaced DESC, id DESC'));
    }

    /**
     * Holds in force that block registration.
     *
     * @param int $studentid
     * @return array
     */
    public static function blocking_holds(int $studentid): array {
        return array_values(array_filter(self::holds($studentid), fn($h) => $h->blocksreg && self::hold_active($h, time())));
    }

    /**
     * Place a hold.
     *
     * @param \stdClass $student
     * @param array $data holdtype, reason, blocksreg, startdate, enddate
     * @return int hold id
     */
    public static function place_hold(\stdClass $student, array $data): int {
        global $USER;
        // The hold runs through its last day: the date form gives the start of that day.
        $enddate = empty($data['enddate']) ? null :
            catalog::date_to_time(catalog::time_to_date((int)$data['enddate'])) + (int)(DAYSECS / 2) - 1;
        $id = records::save(self::HOLDS, ['studentid' => $student->id, 'holdtype' => $data['holdtype'],
            'reason' => trim((string)$data['reason']), 'blocksreg' => empty($data['blocksreg']) ? 0 : 1,
            'startdate' => (int)$data['startdate'] ?: time(), 'enddate' => $enddate,
            'placedby' => $USER->id, 'timeplaced' => time()], 'form', (int)$student->userid);
        audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'hold ' . $data['holdtype']);
        messages::student('holdplaced', $student);
        return $id;
    }

    /**
     * Release a hold (kept, with who and when).
     *
     * @param int $holdid
     * @param string $note
     */
    public static function release_hold(int $holdid, string $note): void {
        global $DB, $USER;
        $hold = $DB->get_record(self::HOLDS, ['id' => $holdid], '*', MUST_EXIST);
        if ($hold->timereleased) {
            return;
        }
        $student = students::get((int)$hold->studentid);
        records::save(self::HOLDS, ['id' => $holdid, 'releasedby' => $USER->id, 'timereleased' => time(),
            'releasenote' => \core_text::substr(trim($note), 0, 255)], 'form', (int)$student->userid);
        audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'hold released ' . $hold->holdtype);
        messages::student('holdreleased', $student);
    }

    // Grade changes.

    /**
     * Request a grade change for a completed registration.
     *
     * @param int $regid
     * @param string $newgrade a letter or mark of the scale
     * @param float|null $newpct
     * @param string $reason
     * @return int request id
     */
    public static function request_change(int $regid, string $newgrade, ?float $newpct, string $reason): int {
        return registration::with_lock('reg' . $regid, fn() => self::add_request($regid, $newgrade, $newpct, $reason));
    }

    /**
     * Check and save a grade change request. The caller holds the lock on the registration.
     *
     * @param int $regid
     * @param string $newgrade
     * @param float|null $newpct
     * @param string $reason
     * @return int request id
     */
    protected static function add_request(int $regid, string $newgrade, ?float $newpct, string $reason): int {
        global $DB, $USER;
        $reg = $DB->get_record(registration::REG, ['id' => $regid], '*', MUST_EXIST);
        if ($reg->status !== 'completed') {
            throw new \moodle_exception('err_changenotcompleted', 'local_studentrecords');
        }
        if (trim($reason) === '') {
            throw new \moodle_exception('err_changereason', 'local_studentrecords');
        }
        if (!isset(catalog::grade_menu()[$newgrade])) {
            throw new \moodle_exception('err_teacherpost_notonscale', 'local_studentrecords');
        }
        if ($newgrade === (string)$reg->grade) {
            throw new \moodle_exception('err_samegrade', 'local_studentrecords');
        }
        if ($newpct !== null && ($newpct < 0 || $newpct > 100)) {
            throw new \moodle_exception('err_pct', 'local_studentrecords');
        }
        if ($DB->record_exists(self::CHANGES, ['regid' => $regid, 'state' => 'pending'])) {
            throw new \moodle_exception('err_changepending', 'local_studentrecords');
        }
        $id = (int)$DB->insert_record(self::CHANGES, (object)['regid' => $regid, 'oldgrade' => $reg->grade, 'newgrade' => $newgrade,
            'newpct' => $newpct, 'reason' => trim($reason), 'requestedby' => $USER->id, 'timerequested' => time(),
            'state' => 'pending']);
        $student = students::get((int)$reg->studentid);
        audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'grade change requested ' . $reg->grade . ' -> ' .
            $newgrade);
        return $id;
    }

    /**
     * Approve (and apply) or reject a grade change. The person deciding must hold the approve capability, must not be the
     * one who asked, and must not be the student.
     *
     * @param int $changeid
     * @param bool $approve
     * @param string $note
     */
    public static function decide_change(int $changeid, bool $approve, string $note): void {
        global $DB;
        require_capability('local/studentrecords:approvegradechange', \context_system::instance());
        $regid = (int)$DB->get_field(self::CHANGES, 'regid', ['id' => $changeid], MUST_EXIST);
        registration::with_lock('reg' . $regid, fn() => self::apply_decision($changeid, $approve, $note));
    }

    /**
     * Whether the current user may decide a request: it waits for a decision, they hold the approve capability, they didn't
     * ask for it and it isn't their own grade. The page uses this to offer the buttons. A decision itself goes through
     * decide_change(), which checks all of it again.
     *
     * @param \stdClass $change a row of changes()
     * @return bool
     */
    public static function can_decide(\stdClass $change): bool {
        global $USER;
        return $change->state === 'pending' && (int)$change->requestedby !== (int)$USER->id &&
            (int)$change->userid !== (int)$USER->id &&
            has_capability('local/studentrecords:approvegradechange', \context_system::instance());
    }

    /**
     * Check and save a decision. The caller holds the lock on the registration, so a double click decides only once.
     *
     * @param int $changeid
     * @param bool $approve
     * @param string $note
     */
    protected static function apply_decision(int $changeid, bool $approve, string $note): void {
        global $DB, $USER;
        $change = $DB->get_record(self::CHANGES, ['id' => $changeid], '*', MUST_EXIST);
        if ($change->state !== 'pending') {
            throw new \moodle_exception('err_changedecided', 'local_studentrecords');
        }
        if ((int)$change->requestedby === (int)$USER->id) {
            throw new \moodle_exception('err_changeself', 'local_studentrecords');
        }
        $reg = $DB->get_record(registration::REG, ['id' => $change->regid], '*', MUST_EXIST);
        $student = students::get((int)$reg->studentid);
        if ((int)$student->userid === (int)$USER->id) {
            throw new \moodle_exception('err_changeownrecord', 'local_studentrecords');
        }
        // The approver saw this old grade. If it changed since the request, the request no longer says what it would do.
        if ($approve && (string)$reg->grade !== (string)$change->oldgrade) {
            throw new \moodle_exception('err_changestale', 'local_studentrecords');
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::CHANGES, (object)['id' => $changeid, 'state' => $approve ? 'approved' : 'rejected',
                'decidedby' => $USER->id, 'timedecided' => time(), 'decisionnote' => \core_text::substr(trim($note), 0, 255)]);
            if ($approve) {
                registration::set_grade(
                    (int)$reg->id,
                    $change->newgrade,
                    $change->newpct === null ? null : (float)$change->newpct,
                    'change',
                    true
                );
            }
            audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'grade change ' .
                ($approve ? 'approved' : 'rejected') . ' ' . $change->oldgrade . ' -> ' . $change->newgrade);
        } catch (\Throwable $e) {
            // The page shows the error and carries on, so the transaction must end here.
            $transaction->rollback($e);
        }
        records::commit($transaction, $student);
    }

    /**
     * Grade change requests with course, term and student, newest first.
     *
     * @param string $state pending, approved, rejected or '' for all
     * @param int $studentid 0 = all students
     * @return array
     */
    public static function changes(string $state = 'pending', int $studentid = 0): array {
        global $DB;
        $where = [];
        $params = [];
        if ($state !== '') {
            $where[] = 'g.state = ?';
            $params[] = $state;
        }
        if ($studentid) {
            $where[] = 'r.studentid = ?';
            $params[] = $studentid;
        }
        return $DB->get_records_sql("SELECT g.*, r.studentid, c.code, c.title, o.section, t.name AS termname, s.studentid AS sid,
                                            s.userid, s.ferpaoptout, s.legalfirstname, s.legalmiddlename, s.legallastname,
                                            s.preferredname
                                       FROM {" . self::CHANGES . "} g
                                       JOIN {" . registration::REG . "} r ON r.id = g.regid
                                       JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
                                       JOIN {local_studentrecords_course} c ON c.id = o.courseid
                                       JOIN {local_studentrecords_term} t ON t.id = o.termid
                                       JOIN {" . students::TABLE . "} s ON s.id = r.studentid " .
            ($where ? 'WHERE ' . implode(' AND ', $where) : '') . " ORDER BY g.timerequested DESC, g.id DESC", $params);
    }
}
