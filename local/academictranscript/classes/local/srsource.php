<?php

namespace local_academictranscript\local;

/**
 * Student Records as the source of a transcript (setting "Source of the transcript").
 *
 * The student's details, terms, courses with final grades, withdrawals (W), courses in progress (IP), transfer credit and
 * the conferred award are read from local_studentrecords, read only. Grade arithmetic stays this plugin's. A student
 * without a Student Records file keeps the transcript from this plugin's own data.
 *
 * Names are plain text here too: whatever prints them escapes them, once.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class srsource {
    /** @var string Setting value: this plugin's own data. */
    const OWN = 'own';

    /** @var string Setting value: Student Records. */
    const STUDENTRECORDS = 'studentrecords';

    /**
     * Whether transcripts are read from Student Records (setting on and the plugin installed).
     *
     * @return bool
     */
    public static function enabled(): bool {
        return get_config('local_academictranscript', 'source') === self::STUDENTRECORDS &&
            \core_component::get_component_directory('local_studentrecords') !== null &&
            class_exists('\\local_studentrecords\\local\\registration');
    }

    /**
     * Source menu for the setting.
     *
     * @return array
     */
    public static function menu(): array {
        return [self::OWN => get_string('source_own', 'local_academictranscript'),
            self::STUDENTRECORDS => get_string('source_studentrecords', 'local_academictranscript')];
    }

    /**
     * Attempts for the calculator from Student Records course rows.
     *
     * Completed with a grade gives the grade, withdrawn gives W, and registered gives IP. Drops are left out, because
     * they are not on a transcript.
     *
     * @param array $rows rows with status, grade, code, title, hours, termid, timeregistered
     * @return array attempts: courseid, termid, code, title, hours, grade, note, posted, time, regid
     */
    public static function attempts(array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            $r = (array)$r;
            if ($r['status'] === 'completed' && (string)$r['grade'] !== '') {
                $grade = (string)$r['grade'];
            } else if ($r['status'] === 'withdrawn') {
                $grade = 'W';
            } else if ($r['status'] === 'registered') {
                $grade = scale::INPROGRESS;
            } else {
                continue;
            }
            $out[] = ['courseid' => (int)($r['courseid'] ?? 0), 'termid' => (int)$r['termid'], 'code' => (string)$r['code'],
                'title' => (string)$r['title'], 'hours' => (float)$r['hours'], 'grade' => $grade, 'note' => '',
                'posted' => $grade !== scale::INPROGRESS, 'time' => $grade === scale::INPROGRESS ? PHP_INT_MAX :
                (int)($r['timeregistered'] ?? 0), 'regid' => (int)($r['id'] ?? 0)];
        }
        return $out;
    }

    /**
     * Transfer credit rows grouped by institution, in this plugin's transcript shape.
     *
     * The hours are added in whole hundredths, as the calculator does, so the sums are exact and the same on every PHP
     * version.
     *
     * @param array $transfers rows with institution, extcourse, credits, note
     * @return array [list of institution groups, total hours]
     */
    public static function transfer(array $transfers): array {
        $groups = [];
        $hundredths = [];
        foreach ($transfers as $x) {
            $x = (array)$x;
            $key = (string)$x['institution'];
            $groups[$key]['institution'] = $key;
            $groups[$key]['rows'][] = ['code' => '', 'title' => (string)($x['extcourse'] ?? ''), 'hours' => (float)$x['credits'],
                'grade' => scale::TRANSFER, 'points' => null, 'qp' => null, 'ingpa' => false, 'earned' => true,
                'notes' => trim((string)($x['note'] ?? '')) === '' ? [] : [trim((string)$x['note'])], 'posted' => true];
            $hundredths[$key] = ($hundredths[$key] ?? 0) + (int)round((float)$x['credits'] * 100);
            $groups[$key]['earned'] = $hundredths[$key] / 100.0;
        }
        return [array_values($groups), array_sum($hundredths) / 100.0];
    }

    /**
     * Everything the transcript needs from Student Records, or null when the user has no Student Records file.
     *
     * @param int $userid
     * @return array|null header (transcript fields), terms (id => object name, startdate, enddate), attempts, transfer,
     *     transferearned, lastentry
     */
    public static function collect(int $userid): ?array {
        global $DB;
        $student = \local_studentrecords\local\students::get_by_user($userid);
        if (!$student) {
            return null;
        }
        $history = \local_studentrecords\local\registration::history($student);
        $rows = [];
        $lastentry = 0;
        foreach ($history['terms'] as $t) {
            foreach ($t['rows'] as $r) {
                $rows[] = $r;
                $lastentry = max($lastentry, (int)$r->timemodified);
            }
        }
        foreach ($history['transfers'] as $x) {
            $lastentry = max($lastentry, (int)$x->timemodified);
        }
        $system = \context_system::instance();
        $terms = [];
        foreach ($DB->get_records('local_studentrecords_term', null, 'startdate, id', 'id, name, startdate, enddate') as $t) {
            $terms[(int)$t->id] = (object)['name' => text::plain($t->name, $system), 'startdate' => (int)$t->startdate,
                'enddate' => (int)$t->enddate];
        }
        $program = $student->programid ? $DB->get_record('local_studentrecords_program', ['id' => $student->programid]) : null;
        [$transfer, $transferearned] = self::transfer($history['transfers']);
        $sr = 'local_studentrecords';
        $legalname = trim($student->legalfirstname . ' ' . ($student->legalmiddlename ? $student->legalmiddlename . ' ' : '') .
            $student->legallastname);
        $admit = $student->admittermid ? ($terms[$student->admittermid] ?? null) : null;
        return [
            'header' => [
                'studentid' => (string)$student->studentid,
                'fullname' => $legalname,
                'dob' => $student->dob ? (int)$student->dob : null,
                'program' => $program ? text::plain($program->name, $system) : '',
                'credential' => $program ? (string)$program->credential : '',
                'admitterm' => $admit ? $admit->name : '',
                'status' => get_string_manager()->string_exists('status_' . $student->status, $sr) ?
                    get_string('status_' . $student->status, $sr) : (string)$student->status,
                'qualification' => (string)$student->awardname,
                'awarddate' => $student->awarddate ? (int)$student->awarddate : null,
                'unittype' => $program && $program->unittype === programs::CLOCK ? programs::CLOCK : programs::CREDIT,
            ],
            'terms' => $terms,
            'attempts' => self::attempts($rows),
            'transfer' => $transfer,
            'transferearned' => $transferearned,
            'lastentry' => $lastentry,
        ];
    }
}
