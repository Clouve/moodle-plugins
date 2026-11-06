<?php

namespace local_studentrecords\local;

/**
 * Attendance: class meetings of a course offering, present / absent / late / excused per registered student with the
 * hours attended (clock hour programs), the last date of attendance per course, and attendance rates. The last date of
 * Moodle course access is shown next to it (read only) and counts only when the school says so.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class attendance {
    /** @var string Class meetings table. */
    const MEETINGS = 'local_studentrecords_meeting';

    /** @var string Attendance marks table. */
    const MARKS = 'local_studentrecords_attend';

    /** @var string[] Attendance states. */
    const STATUSES = ['present', 'late', 'absent', 'excused'];

    // Rules that need no database.

    /**
     * The days of a weekly schedule between two days (both included).
     *
     * @param string $fromymd
     * @param string $toymd
     * @param int[] $weekdays 1 = Monday ... 7 = Sunday
     * @return string[] Y-m-d
     */
    public static function weekly_days(string $fromymd, string $toymd, array $weekdays): array {
        $utc = new \DateTimeZone('UTC');
        $day = new \DateTimeImmutable($fromymd, $utc);
        $to = new \DateTimeImmutable($toymd, $utc);
        $out = [];
        while ($day <= $to && count($out) < 1000) {
            if (in_array((int)$day->format('N'), $weekdays, true)) {
                $out[] = $day->format('Y-m-d');
            }
            $day = $day->modify('+1 day');
        }
        return $out;
    }

    /**
     * Summary of a student's marks in one course: counts, rate, hours, last date attended. The rate is present and
     * late out of the meetings marked, excused meetings left out, from whole numbers so every PHP version agrees.
     *
     * @param array $marks status, hours, date (Y-m-d)
     * @return array present, late, absent, excused, marked, rate (null when nothing counts), hours, last (Y-m-d or '')
     */
    public static function summary(array $marks): array {
        $out = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0, 'marked' => 0, 'rate' => null, 'hours' => 0.0,
            'last' => ''];
        foreach ($marks as $m) {
            $m = (array)$m;
            if (!isset($out[$m['status']])) {
                continue;
            }
            $out[$m['status']]++;
            $out['marked']++;
            $out['hours'] = round($out['hours'] + (float)$m['hours'], 2);
            if (in_array($m['status'], ['present', 'late'], true) && $m['date'] > $out['last']) {
                $out['last'] = $m['date'];
            }
        }
        $counted = $out['marked'] - $out['excused'];
        $out['rate'] = $counted > 0 ? grading::divide_rounded(($out['present'] + $out['late']) * 1000, $counted) / 10.0 : null;
        return $out;
    }

    /**
     * The last date of attendance: the latest of the last day marked present or late and, when the school counts it,
     * the last Moodle course access.
     *
     * @param string $lastmarked Y-m-d or ''
     * @param string $lastmoodle Y-m-d or ''
     * @param bool $countmoodle
     * @return string Y-m-d or ''
     */
    public static function last_date(string $lastmarked, string $lastmoodle, bool $countmoodle): string {
        return $countmoodle && $lastmoodle > $lastmarked ? $lastmoodle : $lastmarked;
    }

    // Meetings.

    /**
     * Meetings of an offering with how many students are marked.
     *
     * @param int $offerid
     * @return array
     */
    public static function meetings(int $offerid): array {
        global $DB;
        return $DB->get_records_sql("SELECT m.*, (SELECT COUNT(1) FROM {" . self::MARKS . "} a WHERE a.meetingid = m.id) AS marked
                                       FROM {" . self::MEETINGS . "} m
                                      WHERE m.offerid = ?
                                   ORDER BY m.meetingdate, m.id", [$offerid]);
    }

    /**
     * Add a meeting (one per day).
     *
     * @param int $offerid
     * @param string $ymd
     * @param float $hours
     * @param string $topic
     * @return int|null id, or null when the offering already has a meeting that day
     */
    public static function add_meeting(int $offerid, string $ymd, float $hours, string $topic = ''): ?int {
        global $DB;
        $time = catalog::date_to_time($ymd);
        if ($DB->record_exists(self::MEETINGS, ['offerid' => $offerid, 'meetingdate' => $time])) {
            return null;
        }
        return records::save(self::MEETINGS, ['offerid' => $offerid, 'meetingdate' => $time, 'hours' => round($hours, 2),
            'topic' => \core_text::substr(trim($topic), 0, 255)], 'form');
    }

    /**
     * Add a weekly schedule of meetings over the offering's term (days already having a meeting are skipped).
     *
     * @param int $offerid
     * @param int[] $weekdays 1 = Monday ... 7 = Sunday
     * @param float $hours
     * @return int meetings added
     */
    public static function add_weekly(int $offerid, array $weekdays, float $hours): int {
        $offer = registration::offer($offerid);
        $days = registration::term_days((int)$offer->termid);
        $n = 0;
        foreach (self::weekly_days($days['startdate'], $days['enddate'], array_map('intval', $weekdays)) as $ymd) {
            $n += self::add_meeting($offerid, $ymd, $hours) ? 1 : 0;
        }
        audit::log('create', 'attendance', $offerid, null, "weekly meetings $n");
        return $n;
    }

    // Marks.

    /**
     * Students registered in an offering (registered, completed or withdrawn) with their mark at a meeting.
     *
     * @param \stdClass $meeting
     * @return array regid => row with student and mark fields
     */
    public static function sheet(\stdClass $meeting): array {
        global $DB;
        return $DB->get_records_sql("SELECT r.id AS regid, r.status AS regstatus, s.id AS sid, s.studentid AS studentno,
                                            s.userid, s.ferpaoptout, s.legalfirstname, s.legalmiddlename, s.legallastname,
                                            s.preferredname, a.id AS markid, a.status, a.hours, a.note
                                       FROM {" . registration::REG . "} r
                                       JOIN {local_studentrecords_student} s ON s.id = r.studentid
                                  LEFT JOIN {" . self::MARKS . "} a ON a.regid = r.id AND a.meetingid = ?
                                      WHERE r.offerid = ? AND r.status IN ('registered', 'completed', 'withdrawn')
                                   ORDER BY s.legallastname, s.legalfirstname", [$meeting->id, $meeting->offerid]);
    }

    /**
     * Name of a student on the attendance sheet: the preferred first name, unless the student opted out of directory
     * information and the person reading may not see it.
     *
     * @param \stdClass $row from sheet()
     * @return string
     */
    public static function sheet_name(\stdClass $row): string {
        $first = $row->preferredname && !access::hide_directory($row) ? $row->preferredname : $row->legalfirstname;
        return trim($first . ' ' . $row->legallastname);
    }

    /**
     * Whether a rate went under the minimum with this change (it was at or above it, or not counted yet).
     *
     * @param float|null $old
     * @param float|null $new
     * @param float $minimum
     * @return bool
     */
    public static function fell_under(?float $old, ?float $new, float $minimum): bool {
        return $new !== null && $new < $minimum && ($old === null || $old >= $minimum);
    }

    /**
     * Save the marks of a meeting (change history kept). Not for meetings after today.
     *
     * @param int $meetingid
     * @param array $marks regid => ['status' => ..., 'hours' => ..., 'note' => ...]; status '' = not marked
     * @return int marks saved
     */
    public static function save_marks(int $meetingid, array $marks): int {
        global $DB;
        $meeting = $DB->get_record(self::MEETINGS, ['id' => $meetingid], '*', MUST_EXIST);
        if (catalog::time_to_date((int)$meeting->meetingdate) > catalog::time_to_date(time())) {
            throw new \moodle_exception('err_futuremeeting', 'local_studentrecords');
        }
        $sheet = self::sheet($meeting);
        $n = 0;
        $minimum = (float)(catalog::setting('minattendance') !== '' ? catalog::setting('minattendance') : 80);
        $before = [];
        foreach (array_keys($marks) as $regid) {
            if (isset($sheet[$regid]) && ($reg = $DB->get_record(registration::REG, ['id' => $regid]))) {
                $before[$regid] = self::for_reg($reg, null)['rate'];
            }
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($marks as $regid => $m) {
                if (!isset($sheet[$regid]) || !in_array($m['status'], self::STATUSES, true)) {
                    continue;
                }
                $row = $sheet[$regid];
                $hours = in_array($m['status'], ['absent', 'excused'], true) ? 0.0 :
                    min((float)$meeting->hours, max(0.0, round((float)$m['hours'], 2)));
                $record = ['meetingid' => $meetingid, 'regid' => $regid, 'studentid' => $row->sid, 'status' => $m['status'],
                    'hours' => $hours, 'note' => \core_text::substr(trim((string)($m['note'] ?? '')), 0, 255) ?: null];
                if ($row->markid) {
                    $record['id'] = $row->markid;
                }
                records::save(self::MARKS, $record, 'form', (int)$row->userid);
                $n++;
            }
            audit::log('update', 'attendance', $meetingid, null, "marks $n");
            // Low attendance warning: once, when a student's rate in the class goes under the minimum.
            $offer = registration::offer((int)$meeting->offerid);
            foreach ($before as $regid => $old) {
                $reg = $DB->get_record(registration::REG, ['id' => $regid]);
                $new = self::for_reg($reg, null)['rate'];
                if (self::fell_under($old, $new, $minimum)) {
                    messages::student('lowattendance', students::get((int)$reg->studentid), ['course' => $offer->code,
                        'term' => $offer->termname]);
                }
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The page shows the error and carries on, so the transaction must end here.
            $transaction->rollback($e);
        }
        return $n;
    }

    /**
     * Whether the current user may take attendance for an offering: records staff, or with take attendance in the
     * linked Moodle course.
     *
     * @param \stdClass $offer
     * @return bool
     */
    public static function can_take(\stdClass $offer): bool {
        if (access::can_manage_students()) {
            return true;
        }
        $context = $offer->moodlecourseid ? \context_course::instance((int)$offer->moodlecourseid, IGNORE_MISSING) : false;
        return $context && has_capability('local/studentrecords:takeattendance', $context);
    }

    // Summaries.

    /**
     * Last Moodle access of a user to a course, as Y-m-d (school time zone), or ''.
     *
     * @param int|null $courseid
     * @param int|null $userid
     * @return string
     */
    public static function moodle_last(?int $courseid, ?int $userid): string {
        global $DB;
        if (!$courseid || !$userid) {
            return '';
        }
        $t = $DB->get_field('user_lastaccess', 'timeaccess', ['courseid' => $courseid, 'userid' => $userid]);
        return $t ? catalog::time_to_date((int)$t) : '';
    }

    /**
     * Attendance of one registration: summary, last Moodle course access, last date of attendance, scheduled hours.
     *
     * @param \stdClass $reg with id, offerid
     * @param int|null $userid the student's Moodle account
     * @return array summary() + moodle, lda, scheduled, meetings
     */
    public static function for_reg(\stdClass $reg, ?int $userid): array {
        global $DB;
        $marks = $DB->get_records_sql("SELECT a.id, a.status, a.hours, m.meetingdate FROM {" . self::MARKS . "} a
                                         JOIN {" . self::MEETINGS . "} m ON m.id = a.meetingid WHERE a.regid = ?", [$reg->id]);
        $s = self::summary(array_map(fn($m) => ['status' => $m->status, 'hours' => $m->hours,
            'date' => catalog::time_to_date((int)$m->meetingdate)], $marks));
        $offer = registration::offer((int)$reg->offerid);
        $s['moodle'] = self::moodle_last($offer->moodlecourseid ? (int)$offer->moodlecourseid : null, $userid);
        $s['lda'] = self::last_date($s['last'], $s['moodle'], catalog::setting('ldamoodle') === '1');
        $s['meetings'] = $DB->count_records(self::MEETINGS, ['offerid' => $reg->offerid]);
        $s['scheduled'] = (float)$DB->get_field_sql("SELECT COALESCE(SUM(hours), 0) FROM {" . self::MEETINGS . "}
                                                       WHERE offerid = ?", [$reg->offerid]);
        return $s;
    }

    /**
     * Last date of attendance of a student over all courses (Y-m-d or '').
     *
     * @param \stdClass $student
     * @return string
     */
    public static function student_last(\stdClass $student): string {
        global $DB;
        $last = '';
        foreach ($DB->get_records(registration::REG, ['studentid' => $student->id]) as $reg) {
            $lda = self::for_reg($reg, $student->userid ? (int)$student->userid : null)['lda'];
            $last = $lda > $last ? $lda : $last;
        }
        return $last;
    }

    /**
     * Last date of attendance of a student in the courses of one term (Y-m-d or '').
     *
     * @param \stdClass $student
     * @param int $termid
     * @return string
     */
    public static function term_last(\stdClass $student, int $termid): string {
        global $DB;
        $last = '';
        $sql = "SELECT r.* FROM {" . registration::REG . "} r JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
                 WHERE r.studentid = ? AND o.termid = ?";
        foreach ($DB->get_records_sql($sql, [$student->id, $termid]) as $reg) {
            $lda = self::for_reg($reg, $student->userid ? (int)$student->userid : null)['lda'];
            $last = $lda > $last ? $lda : $last;
        }
        return $last;
    }

    /**
     * On a withdrawal: fill the student's "last date of attendance" when it is empty (staff can change it).
     *
     * @param \stdClass $student
     */
    public static function fill_last_attended(\stdClass $student): void {
        $fresh = students::get((int)$student->id);
        if ($fresh->lastattended) {
            return;
        }
        $lda = self::student_last($fresh);
        if ($lda !== '') {
            records::save(
                'local_studentrecords_student',
                ['id' => $fresh->id, 'lastattended' => catalog::date_to_time($lda)],
                'attendance',
                (int)$fresh->userid
            );
        }
    }

    /**
     * Attendance by course offering of a term: meetings, marked, rate; students under the minimum rate.
     *
     * @param int $termid 0 = all
     * @return array ['offers' => rows, 'under' => rows, 'minimum' => float]
     */
    public static function report(int $termid = 0): array {
        global $DB;
        $minimum = (float)(catalog::setting('minattendance') !== '' ? catalog::setting('minattendance') : 80);
        $offers = [];
        $under = [];
        foreach (registration::offers($termid) as $o) {
            $rates = [];
            $regs = $DB->get_records_select(
                registration::REG,
                "offerid = ? AND status IN ('registered', 'completed', 'withdrawn')",
                [$o->id]
            );
            foreach ($regs as $reg) {
                $student = students::get((int)$reg->studentid);
                $s = self::for_reg($reg, $student->userid ? (int)$student->userid : null);
                if ($s['rate'] !== null) {
                    $rates[] = $s['rate'];
                    if ($s['rate'] < $minimum) {
                        $under[] = ['student' => students::display_name($student), 'studentno' => $student->studentid,
                            'course' => $o->code . ' ' . $o->section, 'term' => $o->termname, 'rate' => $s['rate'],
                            'lda' => $s['lda']];
                    }
                }
            }
            $offers[] = ['term' => $o->termname, 'course' => $o->code . ' ' . $o->section, 'students' => count($regs),
                'meetings' => $DB->count_records(self::MEETINGS, ['offerid' => $o->id]),
                'rate' => $rates ? round(array_sum($rates) / count($rates), 1) : null];
        }
        return ['offers' => $offers, 'under' => $under, 'minimum' => $minimum];
    }
}
