<?php

namespace local_academictranscript\local;

/**
 * Builds a student's transcript.
 *
 * Sources: posted final grades (one row per attempt), courses being taken now (IP until posted),
 * transfer credit and the student details. Hours are credit or clock hours, following the
 * student's program. The gradebook is only read. With the setting "Source of the transcript" on
 * Student Records, these come from that plugin for students who have a file there (see srsource).
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class transcript {
    /** @var int Version of the transcript data layout (written as 'version', stored in issued snapshots). */
    public const DATA_VERSION = 1;

    /** @var string[] Allowed student status values. */
    const STATUSES = ['active', 'graduated', 'withdrawn', 'onleave'];

    /**
     * Build the transcript.
     *
     * The names in the data (the program, course titles, the problems that name a course, the notes) are plain text, as
     * they were typed. Whatever prints them escapes them, once.
     *
     * @param int $userid
     * @return array
     */
    public static function build(int $userid): array {
        global $DB;
        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        // Source: Student Records when the setting says so and the student has a file there, else this plugin's data.
        $sr = srsource::enabled() ? srsource::collect($userid) : null;
        $record = $DB->get_record('local_academictranscript_student', ['userid' => $userid]);
        $terms = $sr ? $sr['terms'] : terms::all();
        $termorder = array_flip(array_keys($terms));
        $program = ($record && $record->programid) ?
            $DB->get_record('local_academictranscript_program', ['id' => $record->programid]) : null;
        $unittype = $program ? $program->unittype : programs::CREDIT;

        $data = [
            'version' => self::DATA_VERSION,
            'userid' => $userid,
            'fullname' => fullname($user),
            'studentid' => ($record && trim((string)$record->studentid) !== '') ? $record->studentid : (string)$user->idnumber,
            'dob' => $record->dob ?? null,
            'program' => $program ? text::plain($program->name, \context_system::instance()) : '',
            'credential' => $program ? (string)$program->credential : '',
            'admitterm' => ($record && !empty($record->admitterm) && isset($terms[$record->admitterm])) ?
                $terms[$record->admitterm]->name : '',
            'status' => ($record && in_array($record->status, self::STATUSES)) ?
                get_string('status_' . $record->status, 'local_academictranscript') : '',
            'qualification' => $record->qualification ?? '',
            'awarddate' => $record->awarddate ?? null,
            'unittype' => $unittype,
            'unitlabel' => programs::unit_label($unittype),
            'unitshort' => programs::unit_short($unittype),
            'transfer' => [],
            'terms' => [],
            'summary' => [],
            'lastentry' => null,
            'problems' => [],
            'generated' => time(),
        ];

        if ($sr) {
            $data = array_merge($data, $sr['header']);
            $unittype = $data['unittype'];
            $data['unitlabel'] = programs::unit_label($unittype);
            $data['unitshort'] = programs::unit_short($unittype);
            $data['source'] = srsource::STUDENTRECORDS;
        }

        $lastentry = 0;
        if ($sr) {
            $attempts = $sr['attempts'];
            $lastentry = $sr['lastentry'];
            array_push($data['problems'], ...self::posted_outside_student_records($userid, $attempts));
        } else {
            // Attempts: posted grades.
            $attempts = [];
            $postedkeys = [];
            foreach ($DB->get_records('local_academictranscript_posted', ['userid' => $userid], 'timeposted, id') as $p) {
                $attempts[] = ['courseid' => (int)$p->courseid, 'termid' => (int)$p->termid, 'code' => $p->coursecode,
                    'title' => $p->coursename, 'hours' => (float)$p->hours, 'grade' => $p->grade,
                    'note' => trim((string)$p->note), 'posted' => true, 'time' => (int)$p->timeposted];
                $postedkeys[$p->courseid . '-' . $p->termid] = true;
                $lastentry = max($lastentry, (int)$p->timemodified);
            }

            // Courses being taken now (not yet posted for their term): IP.
            $courseids = access::student_courseids($userid);
            $fields = coursefields::get_resolved($courseids);
            $coderows = $DB->get_records(
                'local_academictranscript_code',
                ['userid' => $userid],
                '',
                'courseid, code, note, termid'
            );
            $courses = $courseids ? $DB->get_records_list('course', 'id', $courseids, 'startdate, id') : [];
            foreach ($courses as $course) {
                $f = $fields[$course->id];
                $termid = terms::effective($userid, $course->id, $f['termindex'], $coderows[$course->id] ?? null);
                if ($termid && isset($postedkeys[$course->id . '-' . $termid])) {
                    continue;
                }
                $name = text::plain($course->fullname, \context_course::instance($course->id));
                if ($f['code'] === '') {
                    $data['problems'][] = get_string('problem_nocode', 'local_academictranscript', $name);
                }
                if ($f['hours'] === null) {
                    $data['problems'][] = get_string('problem_nohours', 'local_academictranscript', $name);
                }
                if (!$termid) {
                    $data['problems'][] = get_string('problem_noterm', 'local_academictranscript', $name);
                }
                if ($f['unittype'] !== '' && $f['unittype'] !== $unittype) {
                    $data['problems'][] = get_string(
                        'problem_unittype',
                        'local_academictranscript',
                        ['course' => $name, 'unit' => programs::unit_label($f['unittype'])]
                    );
                }
                $attempts[] = ['courseid' => (int)$course->id, 'termid' => $termid, 'code' => $f['code'],
                    'title' => posting::title($course, $f['code']), 'hours' => (float)$f['hours'],
                    'grade' => scale::INPROGRESS, 'note' => '', 'posted' => false, 'time' => PHP_INT_MAX];
            }
        }

        // Grade arithmetic (GPA, hours earned, repeats) by the calculator.
        $calcinput = [];
        foreach ($attempts as $i => $a) {
            $calcinput[$i] = ['code' => $a['code'], 'group' => (string)$a['termid'], 'order' => $termorder[$a['termid']] ?? -1,
                'time' => $a['time'], 'hours' => $a['hours'], 'grade' => $a['grade']];
            $calcinput[$i] += array_intersect_key($a, ['earns' => 1, 'points' => 1]);
        }
        // A student with a file in Student Records is counted on the scale there, so the two can't give different results.
        $calc = $sr ? srsource::calculator() : scale::calculator();
        $result = $calc->compute($calcinput);
        if ($sr) {
            foreach ($attempts as $a) {
                if ($a['grade'] !== scale::INPROGRESS && empty($a['withdrawn']) && !$calc->knows($a['grade'])) {
                    $data['problems'][] = get_string(
                        'problem_unknowngrade',
                        'local_academictranscript',
                        ['grade' => $a['grade'], 'course' => $a['code']]
                    );
                }
            }
        }

        $groups = [];
        foreach ($attempts as $i => $a) {
            $r = $result['attempts'][$i];
            $notes = $a['note'] !== '' ? [$a['note']] : [];
            if (!$calc->is_letter($a['grade']) && $a['grade'] !== scale::INPROGRESS && $a['note'] === '') {
                $notes[] = $sr ? srsource::mark_label($a['grade']) : scale::code_label($a['grade']);
            }
            if ($r['excluded']) {
                $notes[] = get_string('footnote_repeated', 'local_academictranscript');
            } else if ($sr && !$r['earned'] && $calc->earns($a['grade'])) {
                $notes[] = get_string('footnote_belowminimum', 'local_academictranscript');
            }
            $groups[$a['termid']][] = ['code' => $a['code'], 'title' => $a['title'], 'hours' => $a['hours'],
                'grade' => $a['grade'], 'points' => $r['points'], 'qp' => $r['qp'], 'ingpa' => $r['ingpa'],
                'earned' => $r['earned'], 'notes' => $notes, 'posted' => $a['posted']];
        }
        $ordered = [];
        foreach ($terms as $termid => $term) {
            if (isset($groups[$termid])) {
                $ordered[$termid] = $term;
            }
        }
        if (isset($groups[0])) {
            $ordered[0] = (object)['name' => get_string('termnotset', 'local_academictranscript'), 'startdate' => 0,
                'enddate' => 0];
        }
        foreach ($ordered as $termid => $term) {
            $t = $result['groups'][(string)$termid];
            $data['terms'][] = ['name' => $term->name, 'startdate' => (int)$term->startdate, 'enddate' => (int)$term->enddate,
                'rows' => $groups[$termid], 'earned' => $t['earned'], 'gpahours' => $t['gpahours'], 'qp' => $t['qp'],
                'gpa' => $t['gpa']];
        }

        if ($sr) {
            $data['transfer'] = $sr['transfer'];
            $transferhundredths = (int)round($sr['transferearned'] * 100);
        } else {
            // Transfer credit, grouped by institution. The hours are added in whole hundredths, as the calculator does, so
            // the sums are exact and the same on every PHP version.
            $transferhundredths = 0;
            $institutionhundredths = [];
            foreach (
                $DB->get_records(
                    'local_academictranscript_transfer',
                    ['userid' => $userid, 'active' => 1],
                    'institution, dateaccepted, id'
                ) as $tr
            ) {
                $key = $tr->institution;
                $hundredths = (int)round((float)$tr->hours * 100);
                $data['transfer'][$key]['institution'] = $tr->institution;
                $data['transfer'][$key]['rows'][] = ['code' => (string)$tr->coursecode, 'title' => $tr->title,
                    'hours' => (float)$tr->hours, 'grade' => scale::TRANSFER, 'points' => null, 'qp' => null, 'ingpa' => false,
                    'earned' => true, 'notes' => trim((string)$tr->note) === '' ? [] : [trim($tr->note)], 'posted' => true];
                $institutionhundredths[$key] = ($institutionhundredths[$key] ?? 0) + $hundredths;
                $data['transfer'][$key]['earned'] = $institutionhundredths[$key] / 100.0;
                $transferhundredths += $hundredths;
                $lastentry = max($lastentry, (int)$tr->timemodified);
            }
            $data['transfer'] = array_values($data['transfer']);
        }

        $inst = $result['total'];
        // Transfer credit Student Records counts in the GPA goes into the overall GPA, and never into a term's.
        $transfergpa = $sr ? $sr['transfergpa'] : [];
        $transfer = $transfergpa ? $calc->compute($transfergpa)['total'] : ['gpahours' => 0.0, 'qp' => 0.0];
        $overall = $transfergpa ? $calc->compute(array_merge($calcinput, $transfergpa))['total'] : $inst;
        $data['summary'] = [
            'institutional' => $inst,
            'transfer' => ['earned' => $transferhundredths / 100.0, 'gpahours' => $transfer['gpahours'], 'qp' => $transfer['qp'],
                'gpa' => ''],
            'overall' => ['earned' => ((int)round($inst['earned'] * 100) + $transferhundredths) / 100.0,
                'gpahours' => $overall['gpahours'], 'qp' => $overall['qp'], 'gpa' => $overall['gpa']],
        ];
        $data['lastentry'] = $lastentry ?: null;
        return $data;
    }

    /**
     * Rows of the key for a transcript: of the scale it was counted on.
     *
     * @param array $data from build()
     * @return array see scale::scale_rows()
     */
    public static function key_rows(array $data): array {
        return ($data['source'] ?? '') === srsource::STUDENTRECORDS && srsource::enabled() ? srsource::key_rows() :
            scale::scale_rows();
    }

    /**
     * Grades posted here that are not on a transcript read from Student Records, as problems.
     *
     * That happens when a school turns Student Records on after grades were posted, or a student gets a file there later.
     * The transcript would leave the grade out without a word, so it has to stop an Official transcript.
     *
     * @param int $userid
     * @param array $attempts the attempts from Student Records
     * @return string[]
     */
    protected static function posted_outside_student_records(int $userid, array $attempts): array {
        global $DB;
        $regs = [];
        $codes = [];
        foreach ($attempts as $a) {
            if ($a['grade'] !== scale::INPROGRESS) {
                $regs[$a['regid']] = true;
                $codes[$a['code']] = true;
            }
        }
        $out = [];
        foreach ($DB->get_records('local_academictranscript_posted', ['userid' => $userid], 'timeposted, id') as $p) {
            if (!isset($regs[(int)$p->srregid]) && !isset($codes[$p->coursecode])) {
                $out[] = get_string('problem_postednotinsr', 'local_academictranscript', ['grade' => $p->grade,
                    'course' => $p->coursecode]);
            }
        }
        return $out;
    }

    /**
     * The data of an issued transcript, in the layout the PDF builder reads. Older copies kept the hours of a row under
     * "credits" and had no unit fields, so they are brought up to date. The oldest copies have no terms, and those can't
     * be drawn again.
     *
     * @param string $snapshot JSON, as stored with the issue
     * @return array|null null when the transcript can't be drawn again
     */
    public static function stored(string $snapshot): ?array {
        $data = json_decode($snapshot, true);
        if (!is_array($data) || !is_array($data['terms'] ?? null)) {
            return null;
        }
        if (isset($data['unitshort'])) {
            return $data;
        }
        $hours = function (array $rows): array {
            foreach ($rows as $i => $row) {
                $rows[$i]['hours'] = $row['hours'] ?? ($row['credits'] ?? 0);
            }
            return $rows;
        };
        foreach ($data['terms'] as $i => $term) {
            $data['terms'][$i]['rows'] = $hours($term['rows'] ?? []);
        }
        foreach ($data['transfer'] ?? [] as $i => $group) {
            $data['transfer'][$i]['rows'] = $hours($group['rows'] ?? []);
        }
        $data['unittype'] = programs::CREDIT;
        $data['unitlabel'] = $data['calendar'] ?? programs::unit_label(programs::CREDIT);
        $data['unitshort'] = programs::unit_short(programs::CREDIT);
        $data['credential'] = $data['credential'] ?? '';
        return $data;
    }

    /**
     * Plugin setting with a default when empty.
     *
     * @param string $name
     * @param string $default
     * @return string
     */
    public static function setting(string $name, string $default = ''): string {
        $value = trim((string)get_config('local_academictranscript', $name));
        return $value === '' ? $default : $value;
    }

    /**
     * The name of the school: the School name setting, or the full name of the site when that is empty.
     *
     * The transcript, its Key and the digital signature all use this name, so they agree.
     *
     * @return string plain text
     */
    public static function school_name(): string {
        $school = self::setting('schoolname');
        if ($school !== '') {
            return $school;
        }
        return text::plain(get_site()->fullname, \context_system::instance());
    }

    /**
     * Format a stored date for the transcript.
     *
     * @param int|null $time
     * @return string
     */
    public static function date(?int $time): string {
        if (empty($time)) {
            return '';
        }
        return userdate($time, get_string('strftimedate', 'langconfig'), 99, false);
    }

    /**
     * Short date for term ranges (e.g. 08/26/24).
     *
     * @param int $time
     * @return string
     */
    public static function shortdate(int $time): string {
        return $time ? userdate($time, get_string('strftimetermdate', 'local_academictranscript'), 99, false) : '';
    }

    /**
     * Create a new unique verification code (24 characters).
     *
     * @return string
     */
    public static function new_verifycode(): string {
        global $DB;
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 24; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while ($DB->record_exists('local_academictranscript_issue', ['verifycode' => $code]));
        return $code;
    }

    /**
     * Display form of a verification code: XXXX-XXXX-...
     *
     * @param string $code
     * @return string
     */
    public static function format_code(string $code): string {
        return implode('-', str_split($code, 4));
    }

    /**
     * Normalise user input of a verification code.
     *
     * @param string $input
     * @return string
     */
    public static function clean_code(string $input): string {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($input));
    }

    /**
     * Issue an Official transcript: store the frozen snapshot (including the transcript key) and return the record.
     *
     * @param int $userid
     * @param string $recipient who the transcript is issued to (required)
     * @return \stdClass
     */
    public static function issue(int $userid, string $recipient): \stdClass {
        global $DB, $USER;
        $recipient = trim($recipient);
        if ($recipient === '') {
            throw new \moodle_exception('recipientrequired', 'local_academictranscript');
        }
        // Every way of issuing comes through here, so nobody can issue their own transcript by any of them.
        if ($userid === (int)$USER->id) {
            throw new \moodle_exception('issueown', 'local_academictranscript');
        }
        // A hold stops every way of issuing, which is what the student is told.
        $holds = requests::holds($userid);
        if ($holds) {
            throw new \moodle_exception('issueonhold', 'local_academictranscript', '', s(implode('; ', $holds)));
        }
        $data = self::build($userid);
        if ($data['problems']) {
            throw new \moodle_exception('cannotissue', 'local_academictranscript', '', implode('; ', $data['problems']));
        }
        $issue = (object)['userid' => $userid, 'verifycode' => self::new_verifycode(), 'issuedby' => $USER->id,
            'timecreated' => time(), 'recipient' => \core_text::substr($recipient, 0, 255), 'snapshot' => ''];
        $transaction = $DB->start_delegated_transaction();
        $issue->id = $DB->insert_record('local_academictranscript_issue', $issue);
        $issue->transcriptno = sprintf('T-%s-%06d', date('Y', $issue->timecreated), $issue->id);
        $data['generated'] = $issue->timecreated;
        $data['recipient'] = $issue->recipient;
        $data['transcriptno'] = $issue->transcriptno;
        $data['key'] = self::key_rows($data);
        $issue->snapshot = json_encode($data);
        $DB->update_record('local_academictranscript_issue', $issue);
        $transaction->allow_commit();
        \local_academictranscript\event\official_issued::create(['context' => \context_user::instance($userid),
            'objectid' => $issue->id, 'relateduserid' => $userid])->trigger();
        logger::log('issue', $userid, $issue->id, 'to: ' . $issue->recipient);
        return $issue;
    }
}
