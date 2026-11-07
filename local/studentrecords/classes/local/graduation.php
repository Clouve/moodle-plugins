<?php

namespace local_studentrecords\local;

/**
 * Graduation: application, review against the degree audit (approve or deny), conferral (date, credential, honors;
 * the student's status becomes Graduated and the award is written on the record, where the transcript reads it).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class graduation {
    /** @var string Table. */
    const TABLE = 'local_studentrecords_gradapp';

    /** @var string[] States of an application. */
    const STATES = ['applied', 'approved', 'denied', 'conferred', 'cancelled'];

    /**
     * Honors rules from the school setting, best first: "3.9 Summa cum laude; 3.7 Magna cum laude; 3.5 Cum laude".
     *
     * @param string $text
     * @return array list of [min GPA, honors]
     */
    public static function parse_honors(string $text): array {
        $out = [];
        foreach (preg_split('/[;\n]+/', $text) as $part) {
            if (preg_match('/^\s*(\d(?:\.\d+)?)\s*[:=-]?\s*(.+?)\s*$/', $part, $m)) {
                $out[] = [(float)$m[1], $m[2]];
            }
        }
        usort($out, fn($a, $b) => $b[0] <=> $a[0]);
        return $out;
    }

    /**
     * Honors for a GPA ('' when none).
     *
     * @param array $rules from parse_honors()
     * @param float|null $gpa
     * @return string
     */
    public static function honors_for(array $rules, ?float $gpa): string {
        if ($gpa === null) {
            return '';
        }
        foreach ($rules as [$min, $honors]) {
            if ($gpa + 0.00001 >= $min) {
                return $honors;
            }
        }
        return '';
    }

    /**
     * Applications of a student, newest first.
     *
     * @param int $studentid
     * @return array
     */
    public static function applications(int $studentid): array {
        global $DB;
        return array_values($DB->get_records(self::TABLE, ['studentid' => $studentid], 'timeapplied DESC, id DESC'));
    }

    /**
     * Apply for graduation (one open application at a time).
     *
     * @param \stdClass $student
     * @param int $termid expected last term (0 = not given)
     * @param string $note
     * @return int application id
     */
    public static function apply(\stdClass $student, int $termid, string $note = ''): int {
        global $DB, $USER;
        [$in, $params] = $DB->get_in_or_equal(['applied', 'approved']);
        if ($DB->record_exists_select(self::TABLE, "studentid = ? AND state $in", array_merge([$student->id], $params))) {
            throw new \moodle_exception('err_gradappopen', 'local_studentrecords');
        }
        if (!$student->programid) {
            throw new \moodle_exception('err_noprogram', 'local_studentrecords');
        }
        $id = records::save(self::TABLE, ['studentid' => $student->id, 'programid' => $student->programid,
            'termid' => $termid ?: null, 'state' => 'applied', 'timeapplied' => time(), 'appliedby' => $USER->id,
            'note' => \core_text::substr(trim($note), 0, 255)], 'form', (int)$student->userid);
        audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'graduation application');
        return $id;
    }

    /**
     * Stop a person deciding their own application, whatever their role.
     *
     * @param \stdClass $student
     */
    public static function require_not_own(\stdClass $student): void {
        if (access::is_own($student)) {
            throw new \moodle_exception('err_gradownapp', 'local_studentrecords');
        }
    }

    /**
     * Review: the degree audit is run and kept with the application; approve or deny.
     *
     * @param int $appid
     * @param bool $approve
     * @param string $note
     * @param string $diplomaname name on the diploma (approval)
     * @return array the audit
     */
    public static function review(int $appid, bool $approve, string $note, string $diplomaname = ''): array {
        global $DB, $USER;
        $app = $DB->get_record(self::TABLE, ['id' => $appid], '*', MUST_EXIST);
        if ($app->state !== 'applied') {
            throw new \moodle_exception('err_gradappstate', 'local_studentrecords');
        }
        $student = students::get((int)$app->studentid);
        self::require_not_own($student);
        $audit = degreeaudit::run($student);
        // Conditional approval: complete once the classes in progress are passed.
        $conditional = $approve && $audit && !$audit['complete'] && !empty($audit['completeifpassed']);
        if ($approve && (!$audit || (!$audit['complete'] && !$conditional)) && trim($note) === '') {
            throw new \moodle_exception('err_auditincomplete', 'local_studentrecords');
        }
        records::save(
            self::TABLE,
            ['id' => $appid, 'state' => $approve ? 'approved' : 'denied',
            'auditresult' => $audit && $audit['complete'] ? 'complete' : 'incomplete', 'auditsnapshot' => json_encode($audit),
            'reviewedby' => $USER->id, 'timereviewed' => time(), 'reviewnote' => \core_text::substr(trim($note), 0, 255),
            'conditional' => $conditional ? 1 : 0, 'diplomaname' => trim($diplomaname) !== '' ? trim($diplomaname) : null],
            'form',
            (int)$student->userid
        );
        $info = 'graduation ' . ($approve ? 'approved' : 'denied');
        audit::log('update', 'student', (int)$student->id, (int)$student->userid, $info);
        messages::student('gradreviewed', $student);
        return (array)$audit;
    }

    /**
     * Confer the credential: date, credential and honors on the application and the record; status Graduated.
     *
     * @param int $appid
     * @param int $date conferral date
     * @param string $credential as printed
     * @param string $honors
     */
    public static function confer(int $appid, int $date, string $credential, string $honors): void {
        global $DB, $USER;
        $app = $DB->get_record(self::TABLE, ['id' => $appid], '*', MUST_EXIST);
        if ($app->state !== 'approved') {
            throw new \moodle_exception('err_gradappstate', 'local_studentrecords');
        }
        $student = students::get((int)$app->studentid);
        self::require_not_own($student);
        if (!empty($app->conditional)) {
            // A conditional approval becomes final only when the audit is complete (grades posted and passed).
            $audit = degreeaudit::run($student);
            if (!$audit || !$audit['complete']) {
                throw new \moodle_exception('err_conditional', 'local_studentrecords');
            }
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            records::save(
                self::TABLE,
                ['id' => $appid, 'state' => 'conferred', 'conferred' => $date, 'credential' => trim($credential),
                'honors' => trim($honors), 'conferredby' => $USER->id, 'timeconferred' => time()],
                'form',
                (int)$student->userid
            );
            $award = trim($credential) . (trim($honors) !== '' ? ', ' . trim($honors) : '');
            records::save(
                students::TABLE,
                ['id' => $student->id, 'awardname' => $award, 'awarddate' => $date],
                'form',
                (int)$student->userid
            );
            admissions::set_status((int)$student->id, 'graduated', $date, get_string('conferralnote', 'local_studentrecords'));
            audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'conferred ' . $award);
            messages::student('conferred', $student);
        } catch (\Throwable $e) {
            // The page shows the error and carries on, so the transaction must end here.
            $transaction->rollback($e);
        }
        records::commit($transaction, $student);
    }

    /**
     * Cancel an open application.
     *
     * @param int $appid
     * @param string $note
     */
    public static function cancel(int $appid, string $note): void {
        global $DB;
        $app = $DB->get_record(self::TABLE, ['id' => $appid], '*', MUST_EXIST);
        if (!in_array($app->state, ['applied', 'approved'], true)) {
            throw new \moodle_exception('err_gradappstate', 'local_studentrecords');
        }
        $student = students::get((int)$app->studentid);
        records::save(
            self::TABLE,
            ['id' => $appid, 'state' => 'cancelled', 'note' => \core_text::substr(trim($note), 0, 255)],
            'form',
            (int)$student->userid
        );
        audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'graduation application cancelled');
    }
}
