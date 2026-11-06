<?php

namespace local_studentrecords\local;

/**
 * Reports: enrollment by term (headcount, full-time / part-time, credit hours), course completion rate by term and
 * program, graduation rate by admit-term cohort and program.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class reports {
    /**
     * A rate in percent, rounded half up to one decimal; null when there is nothing to count.
     *
     * @param float $part
     * @param float $whole
     * @return float|null
     */
    public static function rate(float $part, float $whole): ?float {
        return $whole > 0 ? floor($part * 1000 / $whole + 0.5) / 10 : null;
    }

    /**
     * A stored name as plain text, for a download or for code that escapes it itself. format_string() on its own gives
     * HTML, which a download would show as "&amp;" and s() would escape a second time.
     *
     * @param string $text
     * @return string
     */
    public static function plain(string $text): string {
        return format_string($text, true, ['context' => \context_system::instance(), 'escape' => false]);
    }

    /**
     * All programs as plain text, "CODE - Name".
     *
     * @return array id => text
     */
    public static function program_names(): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('local_studentrecords_program', null, 'code', 'id, code, name') as $p) {
            $out[$p->id] = $p->code . ' - ' . self::plain($p->name);
        }
        return $out;
    }

    /**
     * A cell that a spreadsheet program opens as text. A cell that starts with = + - @ or a tab runs as a formula when
     * a CSV file is opened, and these cells hold names and notes that people typed or imported. The quote mark makes
     * it text. Numbers stay as they are.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function safe_cell($value) {
        if (!is_string($value) || is_numeric($value) || !preg_match('/^[=+\-@\t\r\n]/', $value)) {
            return $value;
        }
        return "'" . $value;
    }

    /**
     * Send rows to the browser in a Moodle data format, with every cell safe to open in a spreadsheet program.
     *
     * @param string $filename
     * @param string $format a data format, for example csv
     * @param array $head column names
     * @param array $rows lists of cells
     */
    public static function download(string $filename, string $format, array $head, array $rows): void {
        $cells = fn(array $row) => array_map([self::class, 'safe_cell'], $row);
        \core\dataformat::download_data($filename, $format, $cells($head), new \ArrayIterator(array_map($cells, $rows)));
    }

    /**
     * Registrations that count as enrolled (not dropped), with student, program, term and course hours.
     *
     * @param int $termid 0 = all terms
     * @return array
     */
    protected static function registrations(int $termid): array {
        global $DB;
        $params = [];
        $where = "r.status <> 'dropped'";
        if ($termid) {
            $where .= ' AND o.termid = ?';
            $params[] = $termid;
        }
        return $DB->get_records_sql("SELECT r.id, r.studentid, r.status, r.grade, o.termid, o.courseid, c.hours, t.name AS termname,
                                            t.startdate, s.programid, s.loadtype
                                       FROM {" . registration::REG . "} r
                                       JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
                                       JOIN {local_studentrecords_course} c ON c.id = o.courseid
                                       JOIN {local_studentrecords_term} t ON t.id = o.termid
                                       JOIN {" . students::TABLE . "} s ON s.id = r.studentid
                                      WHERE $where
                                   ORDER BY t.startdate", $params);
    }

    /**
     * Enrollment by term and program: headcount, full-time, part-time, credit hours attempted.
     *
     * @param int $termid 0 = all terms
     * @return array rows: term, programid, headcount, fulltime, parttime, unknown, hours
     */
    public static function enrollment(int $termid = 0): array {
        $out = [];
        $seen = [];
        foreach (self::registrations($termid) as $r) {
            $key = $r->termid . '|' . (int)$r->programid;
            if (!isset($out[$key])) {
                $out[$key] = ['term' => $r->termname, 'termid' => (int)$r->termid, 'programid' => (int)$r->programid,
                    'headcount' => 0, 'fulltime' => 0, 'parttime' => 0, 'unknown' => 0, 'hours' => 0.0];
            }
            $out[$key]['hours'] += (float)$r->hours;
            if (!isset($seen[$key][$r->studentid])) {
                $seen[$key][$r->studentid] = true;
                $out[$key]['headcount']++;
                $load = $r->loadtype === 'fulltime' ? 'fulltime' : ($r->loadtype === 'parttime' ? 'parttime' : 'unknown');
                $out[$key][$load]++;
            }
        }
        return array_values($out);
    }

    /**
     * Course completion by term and program: courses attempted, passed, not passed, withdrawn, in progress, rate.
     *
     * @param int $termid 0 = all terms
     * @return array rows: term, programid, attempted, passed, notpassed, withdrawn, inprogress, rate
     */
    public static function completion(int $termid = 0): array {
        $out = [];
        $students = [];
        foreach (self::registrations($termid) as $r) {
            $key = $r->termid . '|' . (int)$r->programid;
            if (!isset($out[$key])) {
                $out[$key] = ['term' => $r->termname, 'termid' => (int)$r->termid, 'programid' => (int)$r->programid,
                    'attempted' => 0, 'passed' => 0, 'notpassed' => 0, 'withdrawn' => 0, 'inprogress' => 0, 'rate' => null];
            }
            $out[$key]['attempted']++;
            if ($r->status === 'withdrawn') {
                $out[$key]['withdrawn']++;
            } else if ($r->status === 'registered' || $r->grade === null) {
                $out[$key]['inprogress']++;
            } else {
                $students[$r->studentid] = $students[$r->studentid] ?? students::get((int)$r->studentid);
                $passed = registration::result_passes($students[$r->studentid], (int)$r->courseid, (string)$r->grade) !== false;
                $out[$key][$passed ? 'passed' : 'notpassed']++;
            }
        }
        foreach ($out as $k => $row) {
            $out[$k]['rate'] = self::rate($row['passed'], $row['attempted'] - $row['inprogress']);
        }
        return array_values($out);
    }

    /**
     * Graduation by cohort (admit term) and program: students, graduated, still enrolled, withdrawn, rate.
     *
     * @return array rows: cohort, termid, programid, students, graduated, continuing, withdrawn, other, rate
     */
    public static function graduation(): array {
        global $DB;
        $out = [];
        $sql = "SELECT s.id, s.status, s.programid, s.admittermid, t.name AS termname, t.startdate
                  FROM {" . students::TABLE . "} s
             LEFT JOIN {local_studentrecords_term} t ON t.id = s.admittermid
              ORDER BY t.startdate";
        foreach ($DB->get_records_sql($sql) as $s) {
            if (in_array($s->status, admissions::PIPELINE, true)) {
                continue;
            }
            $key = (int)$s->admittermid . '|' . (int)$s->programid;
            if (!isset($out[$key])) {
                $out[$key] = ['cohort' => (string)$s->termname, 'termid' => (int)$s->admittermid, 'programid' => (int)$s->programid,
                    'students' => 0, 'graduated' => 0, 'continuing' => 0, 'withdrawn' => 0, 'other' => 0, 'rate' => null];
            }
            $out[$key]['students']++;
            $group = $s->status === 'graduated' ? 'graduated' : (in_array($s->status, ['active', 'enrolled', 'onleave'], true) ?
                'continuing' : ($s->status === 'withdrawn' ? 'withdrawn' : 'other'));
            $out[$key][$group]++;
        }
        foreach ($out as $k => $row) {
            $out[$k]['rate'] = self::rate($row['graduated'], $row['students']);
        }
        return array_values($out);
    }
}
