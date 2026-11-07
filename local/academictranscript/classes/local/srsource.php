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

    /** @var string Mark of a withdrawal, on every transcript. */
    const WITHDRAWN = 'W';

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
     * Calculator on the grading scale of Student Records, with this plugin's repeat rule.
     *
     * The scale decides which attempts are repeats and whether a grade is known. Whether an attempt counts is for Student
     * Records to say, on each attempt: see attempts().
     *
     * @return calculator
     */
    public static function calculator(): calculator {
        [$letters, $rows] = self::scale_rows();
        $grades = [];
        foreach ($letters as $letter => $row) {
            $grades[] = ['letter' => $letter, 'min' => $row->minpct, 'max' => $row->maxpct, 'points' => $row->points,
                'ingpa' => $row->ingpa, 'earns' => (float)$row->points > 0];
        }
        $marks = [];
        foreach ($rows as $code => $row) {
            // W and IP are states of a course, so they never earn. A row with grade points earns by its points, as a letter
            // does: Student Records hides the Earns box for those rows.
            $earns = $row->points !== null ? (float)$row->points > 0 : (bool)$row->earns;
            $marks[] = ['code' => $code, 'earns' => $earns && !self::is_state($code)];
        }
        return scale::calculator_for($grades, $marks);
    }

    /**
     * Whether a code stands for the state of a course, whatever the scale says about it.
     *
     * @param string $code
     * @return bool
     */
    protected static function is_state(string $code): bool {
        return $code === self::WITHDRAWN || $code === scale::INPROGRESS;
    }

    /**
     * The scale of Student Records, as this plugin reads it: a letter counts in the GPA, and anything else is a mark. That
     * includes W and IP, which a school may set up with 0 grade points, and a row with grade points kept out of the GPA.
     *
     * @return array [letter => row of the letters, letter => row of the marks]
     */
    protected static function scale_rows(): array {
        $letters = [];
        $marks = [];
        foreach (\local_studentrecords\local\catalog::scale() as $row) {
            if ($row->points !== null && $row->ingpa && !self::is_state($row->letter)) {
                $letters[$row->letter] = $row;
            } else {
                $marks[$row->letter] = $row;
            }
        }
        return [$letters, $marks];
    }

    /**
     * Marks a teacher can give a student with a file in Student Records: the scale's marks, without the transcript's own.
     *
     * @return array code => label, escaped for a menu
     */
    public static function teacher_marks(): array {
        $menu = [];
        foreach (self::scale_rows()[1] as $code => $row) {
            if ($code !== scale::INPROGRESS && $code !== scale::TRANSFER) {
                $menu[$code] = format_string((string)$row->description !== '' ? $row->description : $code);
            }
        }
        return $menu;
    }

    /**
     * Rows of the transcript key on the grading scale of Student Records.
     *
     * The transcript prints W, IP and TR whatever the scale holds, so the key explains them too. A mark with no description
     * there takes this plugin's wording.
     *
     * @return array see scale::scale_rows()
     */
    public static function key_rows(): array {
        [$rows, $scalemarks] = self::scale_rows();
        $marks = [];
        foreach ($scalemarks as $letter => $row) {
            $marks[$letter] = (object)['code' => $letter, 'label' => $letter, 'description' => (string)$row->description];
        }
        $wording = [];
        foreach (scale::standard_marks() as $m) {
            $wording[$m['code']] = (object)$m;
        }
        foreach (scale::marks() as $m) {
            $wording[$m->code] = $m;
        }
        foreach ([self::WITHDRAWN, scale::INPROGRESS, scale::TRANSFER] as $code) {
            $marks[$code] = $marks[$code] ?? $wording[$code];
        }
        foreach ($marks as $code => $m) {
            if ((string)$m->description === '' && isset($wording[$code])) {
                $marks[$code] = $wording[$code];
            }
        }
        $letters = array_values(array_filter(
            array_keys(\local_studentrecords\local\catalog::letters()),
            fn($letter) => isset($rows[$letter])
        ));
        $mins = array_filter(array_map(fn($l) => (float)$rows[$l]->minpct, $letters), fn($min) => $min > 0);
        $grades = [];
        foreach ($letters as $letter) {
            $row = $rows[$letter];
            $grades[] = (object)['letter' => $letter, 'points' => $row->points, 'percent' => self::percent($row, $mins),
                'description' => $row->description, 'ingpa' => $row->ingpa];
        }
        return scale::key_rows($grades, array_values($marks));
    }

    /**
     * Percentage text of a row of the scale in Student Records, for the key.
     *
     * @param \stdClass $row
     * @param float[] $mins the minimums above 0 of all the letters
     * @return string '' for a letter with no percentages
     */
    protected static function percent(\stdClass $row, array $mins): string {
        if ($row->minpct === null) {
            return '';
        }
        if ((float)$row->minpct <= 0) {
            return $mins ? get_string('belowpercent', 'local_academictranscript', scale::percent_text(min($mins))) : '';
        }
        return scale::percent_text((float)$row->minpct) . '-' . scale::percent_text((float)$row->maxpct) . '%';
    }

    /**
     * Label of a mark for the footnotes: its description in Student Records, else this plugin's label.
     *
     * @param string $code
     * @return string plain text
     */
    public static function mark_label(string $code): string {
        $marks = self::scale_rows()[1];
        $description = isset($marks[$code]) ? trim((string)$marks[$code]->description) : '';
        return $description !== '' ? text::plain($description, \context_system::instance()) : scale::code_label($code);
    }

    /**
     * Attempts for the calculator from Student Records course rows.
     *
     * Completed with a grade gives the grade, withdrawn gives W, and registered gives IP. Drops are left out, because
     * they are not on a transcript. A finished course earns its hours unless Student Records says it didn't pass, and it
     * counts in the GPA with the grade points Student Records gives it.
     *
     * @param array $rows rows with status, grade, code, title, hours, termid, timeregistered, and from registration::history()
     *     passed and points
     * @return array attempts: courseid, termid, code, title, hours, grade, note, posted, time, regid, earns, withdrawn, and
     *     points when the row has them
     */
    public static function attempts(array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            $r = (array)$r;
            if ($r['status'] === 'completed' && (string)$r['grade'] !== '') {
                $grade = (string)$r['grade'];
            } else if ($r['status'] === 'withdrawn') {
                $grade = self::WITHDRAWN;
            } else if ($r['status'] === 'registered') {
                $grade = scale::INPROGRESS;
            } else {
                continue;
            }
            $attempt = ['courseid' => (int)($r['courseid'] ?? 0), 'termid' => (int)$r['termid'], 'code' => (string)$r['code'],
                'title' => (string)$r['title'], 'hours' => (float)$r['hours'], 'grade' => $grade, 'note' => '',
                'posted' => $grade !== scale::INPROGRESS, 'time' => $grade === scale::INPROGRESS ? PHP_INT_MAX :
                (int)($r['timeregistered'] ?? 0), 'regid' => (int)($r['id'] ?? 0),
                'earns' => $r['status'] === 'completed' && ($r['passed'] ?? null) !== false,
                'withdrawn' => $r['status'] === 'withdrawn'];
            if (array_key_exists('points', $r)) {
                $attempt['points'] = $r['points'] === null ? null : (float)$r['points'];
            }
            $out[] = $attempt;
        }
        return $out;
    }

    /**
     * Transfer credit rows grouped by institution, in this plugin's transcript shape.
     *
     * The hours are added in whole hundredths, as the calculator does, so the sums are exact and the same on every PHP
     * version. A row Student Records counts in the GPA shows its letter and quality points. Those rows also come back as
     * attempts, for the overall GPA. Their hours are earned here, so the attempts don't earn them again.
     *
     * @param array $transfers rows with institution, extcourse, credits, note, and for a row in the GPA grade and points
     * @return array [list of institution groups, total hours, attempts in the GPA]
     */
    public static function transfer(array $transfers): array {
        $groups = [];
        $hundredths = [];
        $ingpa = [];
        $calculator = null;
        foreach ($transfers as $x) {
            $x = (array)$x;
            $key = (string)$x['institution'];
            $groups[$key]['institution'] = $key;
            $row = ['code' => '', 'title' => (string)($x['extcourse'] ?? ''), 'hours' => (float)$x['credits'],
                'grade' => scale::TRANSFER, 'points' => null, 'qp' => null, 'ingpa' => false, 'earned' => true,
                'notes' => trim((string)($x['note'] ?? '')) === '' ? [] : [trim((string)$x['note'])], 'posted' => true];
            if (($x['points'] ?? null) !== null) {
                $attempt = ['code' => '', 'group' => 'transfer', 'order' => -1, 'time' => 0, 'hours' => (float)$x['credits'],
                    'grade' => (string)$x['grade'], 'earns' => false, 'points' => (float)$x['points']];
                $calculator = $calculator ?? self::calculator();
                $counted = $calculator->compute([$attempt])['attempts'][0];
                $row = ['grade' => $attempt['grade'], 'points' => $counted['points'], 'qp' => $counted['qp'],
                    'ingpa' => true] + $row;
                $ingpa[] = $attempt;
            }
            $groups[$key]['rows'][] = $row;
            $hundredths[$key] = ($hundredths[$key] ?? 0) + (int)round((float)$x['credits'] * 100);
            $groups[$key]['earned'] = $hundredths[$key] / 100.0;
        }
        return [array_values($groups), array_sum($hundredths) / 100.0, $ingpa];
    }

    /**
     * Everything the transcript needs from Student Records, or null when the user has no Student Records file.
     *
     * @param int $userid
     * @return array|null header (transcript fields), terms (id => object name, startdate, enddate), attempts, transfer,
     *     transferearned, transfergpa (attempts in the GPA), lastentry
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
        [$transfer, $transferearned, $transfergpa] = self::transfer($history['transfers']);
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
            'transfergpa' => $transfergpa,
            'lastentry' => $lastentry,
        ];
    }
}
