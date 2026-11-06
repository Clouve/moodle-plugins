<?php

namespace local_studentrecords\local;

/**
 * Admissions: status history, application checklist, admission decisions, automatic student IDs and IPEDS
 * demographics. The rules that need no database are plain functions (tested without Moodle).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class admissions {
    /** @var string[] Statuses before enrolment (admissions pipeline). */
    const PIPELINE = ['inquiry', 'applicant', 'waitlisted', 'admitted', 'denied', 'deferred'];

    /** @var string[] Admission decisions. */
    const DECISIONS = ['admitted', 'waitlisted', 'denied', 'deferred'];

    /** @var string[] States of a checklist document. */
    const DOCSTATES = ['missing', 'received', 'waived'];

    /** @var string[] Sex as IPEDS asks it (all optional). */
    const SEX = ['female', 'male', 'another', 'notreported'];

    /** @var string[] Hispanic or Latino. */
    const HISPANIC = ['yes', 'no', 'notreported'];

    /** @var string[] IPEDS race categories (several may be chosen). */
    const RACES = ['aian', 'asian', 'black', 'nhpi', 'white'];

    /** @var array|null Active checklist documents loaded in this request. */
    protected static $docs = null;

    // Student IDs.

    /**
     * A student ID in the school's format: prefix, year and a zero-padded sequence number, joined by a separator.
     *
     * @param string $prefix for example RVC (may be empty)
     * @param string $year for example 2026, or '' for no year
     * @param int $seq
     * @param int $padding digits of the sequence number
     * @param string $sep separator, for example '-'
     * @return string for example RVC-2026-0001
     */
    public static function format_id(string $prefix, string $year, int $seq, int $padding, string $sep): string {
        $parts = array_filter([$prefix, $year], fn($p) => $p !== '');
        $parts[] = str_pad((string)$seq, max(1, $padding), '0', STR_PAD_LEFT);
        return implode($sep, $parts);
    }

    /**
     * The next sequence number after the IDs already given in the same format (prefix and year).
     *
     * @param string[] $existing student IDs in use
     * @param string $prefix
     * @param string $year
     * @param string $sep
     * @return int
     */
    public static function next_seq(array $existing, string $prefix, string $year, string $sep): int {
        $head = implode($sep, array_filter([$prefix, $year], fn($p) => $p !== ''));
        $pattern = '/^' . preg_quote($head === '' ? '' : $head . $sep, '/') . '(\d+)$/i';
        $max = 0;
        foreach ($existing as $id) {
            if (preg_match($pattern, trim((string)$id), $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return $max + 1;
    }

    /**
     * Whether new students get an automatic ID (setup wizard > Admissions).
     *
     * @return bool
     */
    public static function auto_id(): bool {
        return catalog::setting('idauto') === '1';
    }

    /**
     * The next automatic student ID (the year is this year in the school's time zone).
     *
     * @return string
     */
    public static function next_student_id(): string {
        global $DB;
        $prefix = trim(catalog::setting('idprefix'));
        $sep = catalog::setting('idseparator');
        $sep = $sep === '' ? '-' : ($sep === 'none' ? '' : $sep);
        $year = catalog::setting('idyear') === '0' ? '' : userdate(time(), '%Y', catalog::timezone(), false);
        $padding = (int)catalog::setting('idpadding') ?: 4;
        $head = implode($sep, array_filter([$prefix, $year], fn($p) => $p !== ''));
        $like = $DB->sql_like('studentid', '?', false);
        $existing = $DB->get_fieldset_select(students::TABLE, 'studentid', $like, [$DB->sql_like_escape($head) . '%']);
        $seq = self::next_seq($existing, $prefix, $year, $sep);
        do {
            $id = self::format_id($prefix, $year, $seq++, $padding, $sep);
        } while ($DB->record_exists(students::TABLE, ['studentid' => $id]));
        return $id;
    }

    // Status.

    /**
     * Problems with a status change: a withdrawal needs the withdrawal date and the last date of attendance,
     * and attendance cannot end after the withdrawal.
     *
     * @param string $status
     * @param int|null $withdrawdate
     * @param int|null $lastattended
     * @return array field => language string key
     */
    public static function status_problems(string $status, ?int $withdrawdate, ?int $lastattended): array {
        $out = [];
        if ($status === 'withdrawn') {
            if (!$withdrawdate) {
                $out['withdrawdate'] = 'err_withdrawdate';
            }
            if (!$lastattended) {
                $out['lastattended'] = 'err_lastattended';
            }
        }
        if ($withdrawdate && $lastattended && $lastattended > $withdrawdate) {
            $out['lastattended'] = 'err_attendedafter';
        }
        return $out;
    }

    /**
     * Write one row of status history.
     *
     * @param int $studentid
     * @param string|null $old
     * @param string $new
     * @param int $effective
     * @param string $note
     */
    public static function add_status_history(int $studentid, ?string $old, string $new, int $effective, string $note = ''): void {
        global $DB, $USER;
        $DB->insert_record('local_studentrecords_stathis', (object)['studentid' => $studentid, 'oldstatus' => $old,
            'newstatus' => $new, 'effective' => $effective, 'note' => \core_text::substr($note, 0, 255),
            'userid' => $USER->id ?? 0, 'timecreated' => time()]);
    }

    /**
     * Status history of a student, newest first.
     *
     * @param int $studentid
     * @return array
     */
    public static function status_history(int $studentid): array {
        global $DB;
        return array_values($DB->get_records(
            'local_studentrecords_stathis',
            ['studentid' => $studentid],
            'effective DESC, id DESC'
        ));
    }

    /**
     * Change a student's status (record, status history, change history, audit log).
     *
     * @param int $studentid
     * @param string $status
     * @param int $effective
     * @param string $note
     */
    public static function set_status(int $studentid, string $status, int $effective, string $note = ''): void {
        $s = students::get($studentid);
        if ($s->status === $status) {
            return;
        }
        records::save(students::TABLE, ['id' => $studentid, 'status' => $status], 'form', (int)$s->userid);
        self::add_status_history($studentid, $s->status, $status, $effective, $note);
        audit::log('update', 'student', $studentid, (int)$s->userid, 'status ' . $status);
    }

    // Checklist.

    /**
     * Whether a checklist document applies to a program: every scope it names (program, level, credential type)
     * must match; a document with no scope applies to all.
     *
     * @param \stdClass|array $doc
     * @param \stdClass|array|null $program the student's program (null = none)
     * @return bool
     */
    public static function applies($doc, $program): bool {
        $doc = (array)$doc;
        $program = $program ? (array)$program : [];
        if (!empty($doc['programid']) && (int)$doc['programid'] !== (int)($program['id'] ?? 0)) {
            return false;
        }
        if (!empty($doc['levelid']) && (int)$doc['levelid'] !== (int)($program['levelid'] ?? 0)) {
            return false;
        }
        if (!empty($doc['credentialtype']) && $doc['credentialtype'] !== ($program['credentialtype'] ?? '')) {
            return false;
        }
        return true;
    }

    /**
     * Required documents that are neither received nor waived.
     *
     * @param array $docs applicable documents (with id and required)
     * @param array $states reqdocid => state
     * @return array the missing documents
     */
    public static function missing(array $docs, array $states): array {
        return array_values(array_filter($docs, fn($d) => !empty($d->required) &&
            !in_array($states[$d->id] ?? 'missing', ['received', 'waived'], true)));
    }

    /**
     * Active checklist documents, id => record.
     *
     * @return array
     */
    public static function all_docs(): array {
        global $DB;
        if (self::$docs === null) {
            self::$docs = $DB->get_records('local_studentrecords_reqdoc', ['active' => 1], 'sortorder, name');
        }
        return self::$docs;
    }

    /**
     * Forget the documents loaded in this request (after the checklist setup changes).
     */
    public static function reset_cache(): void {
        self::$docs = null;
    }

    /**
     * The checklist of a student: applicable documents, each with its state, date and note.
     *
     * @param \stdClass $student
     * @return array list of objects: doc fields + state, statedate, note, appdocid
     */
    public static function checklist(\stdClass $student): array {
        global $DB;
        $program = $student->programid ? $DB->get_record('local_studentrecords_program', ['id' => $student->programid]) : null;
        $rows = $DB->get_records('local_studentrecords_appdoc', ['studentid' => $student->id], '', '*');
        $bydoc = [];
        foreach ($rows as $r) {
            $bydoc[$r->reqdocid] = $r;
        }
        $out = [];
        foreach (self::all_docs() as $doc) {
            if (!self::applies($doc, $program)) {
                continue;
            }
            $r = $bydoc[$doc->id] ?? null;
            $item = clone $doc;
            $item->state = $r->state ?? 'missing';
            $item->statedate = $r->statedate ?? null;
            $item->note = $r->note ?? '';
            $item->appdocid = $r->id ?? 0;
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Number of missing required documents per student.
     *
     * @param array $students records with id and programid
     * @return array student id => count
     */
    public static function missing_counts(array $students): array {
        global $DB;
        $out = [];
        if (!$students || !self::all_docs()) {
            return $out;
        }
        $programs = $DB->get_records('local_studentrecords_program', null, '', 'id, levelid, credentialtype');
        [$in, $params] = $DB->get_in_or_equal(array_map(fn($s) => (int)$s->id, $students));
        $states = [];
        foreach ($DB->get_records_select('local_studentrecords_appdoc', "studentid $in", $params) as $r) {
            $states[$r->studentid][$r->reqdocid] = $r->state;
        }
        foreach ($students as $s) {
            $program = $s->programid ? ($programs[$s->programid] ?? null) : null;
            $docs = array_filter(self::all_docs(), fn($d) => self::applies($d, $program));
            $out[$s->id] = count(self::missing($docs, $states[$s->id] ?? []));
        }
        return $out;
    }

    /**
     * Save the state of checklist documents for a student.
     *
     * @param \stdClass $student
     * @param array $items reqdocid => ['state' => , 'statedate' => , 'note' => ]
     * @return int number changed
     */
    public static function save_checklist(\stdClass $student, array $items): int {
        global $DB;
        $n = 0;
        foreach ($items as $docid => $item) {
            if (!in_array($item['state'], self::DOCSTATES, true)) {
                continue;
            }
            $row = $DB->get_record('local_studentrecords_appdoc', ['studentid' => $student->id, 'reqdocid' => $docid]);
            if (!$row && $item['state'] === 'missing' && trim((string)$item['note']) === '') {
                continue;
            }
            $record = ['studentid' => $student->id, 'reqdocid' => (int)$docid, 'state' => $item['state'],
                'statedate' => $item['state'] === 'missing' ? null : ($item['statedate'] ?: time()),
                'note' => trim((string)$item['note'])];
            if ($row) {
                $record['id'] = $row->id;
            }
            records::save('local_studentrecords_appdoc', $record, 'form', (int)$student->userid);
            $n += records::$lastchanged ? 1 : 0;
        }
        if ($n) {
            audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'checklist ' . $n);
        }
        return $n;
    }

    // Decisions.

    /**
     * Record an admission decision. A student still in the admissions pipeline moves to the matching status;
     * a deferral can move the admit term.
     *
     * @param \stdClass $student
     * @param array $data decision, decisiondate, termid, reason, conditions
     * @return int decision id
     */
    public static function record_decision(\stdClass $student, array $data): int {
        global $DB, $USER;
        $transaction = $DB->start_delegated_transaction();
        $id = (int)$DB->insert_record('local_studentrecords_admdec', (object)['studentid' => $student->id,
            'decision' => $data['decision'], 'decisiondate' => (int)$data['decisiondate'],
            'termid' => !empty($data['termid']) ? (int)$data['termid'] : null, 'reason' => trim((string)$data['reason']),
            'conditions' => trim((string)$data['conditions']), 'decidedby' => (int)$USER->id, 'timecreated' => time()]);
        if (in_array($student->status, self::PIPELINE, true)) {
            self::set_status(
                (int)$student->id,
                $data['decision'],
                (int)$data['decisiondate'],
                get_string('decisionnote', 'local_studentrecords')
            );
        }
        if (!empty($data['termid']) && (int)$data['termid'] !== (int)$student->admittermid) {
            records::save(
                students::TABLE,
                ['id' => $student->id, 'admittermid' => (int)$data['termid']],
                'form',
                (int)$student->userid
            );
        }
        // Program waitlist: a waitlisted applicant waits for a place (in order); admitting or denying ends the wait.
        $wait = $DB->get_record_select('local_studentrecords_wait', "kind = 'program' AND studentid = ? AND status IN
            ('waiting', 'offered')", [$student->id], '*', IGNORE_MULTIPLE);
        if ($data['decision'] === 'waitlisted' && !$wait) {
            records::save('local_studentrecords_wait', ['kind' => 'program', 'programid' => $student->programid ?: null,
                'termid' => !empty($data['termid']) ? (int)$data['termid'] : ($student->admittermid ?: null),
                'studentid' => $student->id, 'status' => 'waiting', 'timecreated' => time()], 'form', (int)$student->userid);
        } else if ($data['decision'] !== 'waitlisted' && $wait) {
            records::save('local_studentrecords_wait', ['id' => $wait->id, 'status' => $data['decision'] === 'admitted' ? 'done' :
                'left', 'timeended' => time()], 'form', (int)$student->userid);
        }
        audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'decision ' . $data['decision']);
        messages::student('admdecision', $student);
        if ($data['decision'] === 'admitted') {
            messages::student('welcome', $student);
        }
        $transaction->allow_commit();
        return $id;
    }

    /**
     * Decisions of a student, newest first.
     *
     * @param int $studentid
     * @return array
     */
    public static function decisions(int $studentid): array {
        global $DB;
        return array_values($DB->get_records(
            'local_studentrecords_admdec',
            ['studentid' => $studentid],
            'decisiondate DESC, id DESC'
        ));
    }

    // Demographics.

    /**
     * Whether the current user may see (and, with manage students, edit) demographics.
     *
     * @return bool
     */
    public static function can_view_demographics(): bool {
        return has_capability('local/studentrecords:viewdemographics', \context_system::instance());
    }

    /**
     * IPEDS race/ethnicity category: nonresident first, then Hispanic or Latino, then one race, two or more races,
     * else unknown.
     *
     * @param string|null $hispanic yes, no, notreported or null
     * @param string|null $races comma list of race codes, notreported or null
     * @param bool $nonresident on a student visa (for example F-1)
     * @return string nonresident, hispanic, aian, asian, black, nhpi, white, twoormore or unknown
     */
    public static function ipeds_category(?string $hispanic, ?string $races, bool $nonresident): string {
        if ($nonresident) {
            return 'nonresident';
        }
        if ($hispanic === 'yes') {
            return 'hispanic';
        }
        $list = array_values(array_intersect(self::RACES, array_map('trim', explode(',', (string)$races))));
        if (count($list) > 1) {
            return 'twoormore';
        }
        return $list ? $list[0] : 'unknown';
    }
}
