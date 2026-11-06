<?php

namespace local_studentrecords\local;

use html_writer;

/**
 * "My academic record": the program with progress, GPA and attendance, then each term's classes with credits,
 * grade and attendance (newest term first).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class record_view {
    /** @var string */
    const P = 'local_studentrecords';

    /** @var float Attendance within this many points above the minimum is shown as "near minimum". */
    const NEAR = 5.0;

    /**
     * Tone of an attendance rate: bad under the minimum, warn near it, '' otherwise.
     *
     * @param float|null $rate
     * @param float $minimum
     * @return string
     */
    public static function attendance_tone(?float $rate, float $minimum): string {
        if ($rate === null) {
            return '';
        }
        if ($rate < $minimum) {
            return 'bad';
        }
        return $rate < $minimum + self::NEAR ? 'warn' : '';
    }

    /**
     * Percent done, 0-100.
     *
     * @param float $done
     * @param float $total
     * @return int
     */
    public static function percent(float $done, float $total): int {
        return $total > 0 ? (int)max(0, min(100, round($done / $total * 100))) : 0;
    }

    /**
     * The whole view.
     *
     * @param \stdClass $student
     * @return string HTML
     */
    public static function render(\stdClass $student): string {
        $p = self::P;
        $minimum = (float)(catalog::setting('minattendance') !== '' ? catalog::setting('minattendance') : 80);
        $history = registration::history($student);
        $rates = [];
        $termhtml = '';
        foreach (array_reverse($history['terms']) as $i => $t) {
            $inprogress = false;
            $table = new \html_table();
            $table->attributes['class'] = 'sr-table';
            $table->head = [get_string('rec_course', $p), get_string('rec_title', $p), get_string('home_credits', $p),
                get_string('rec_grade', $p), get_string('attendance', $p)];
            foreach ($t['rows'] as $r) {
                if ($r->status === 'dropped') {
                    continue;
                }
                $att = attendance::for_reg($r, $student->userid ? (int)$student->userid : null);
                if ($att['rate'] !== null) {
                    $rates[] = $att['rate'];
                }
                $tone = self::attendance_tone($att['rate'], $minimum);
                $rate = $att['rate'] === null ? '-' : format_float($att['rate'], 0) . '%';
                if ($tone === 'warn') {
                    $rate = shell::tag(get_string('rec_nearmin', $p, $rate), 'warn');
                } else if ($tone === 'bad') {
                    $rate = shell::tag(get_string('rec_undermin', $p, $rate), 'bad');
                }
                if ($r->status === 'registered') {
                    $inprogress = true;
                    $grade = html_writer::span(get_string('rec_inprogress', $p), 'sr-muted');
                } else {
                    $grade = html_writer::tag('strong', s($r->letter));
                    if (!empty($r->repeatexcluded)) {
                        $grade .= ' ' . shell::tag(get_string('repeatnotcounted', $p));
                    }
                }
                $table->data[] = [s($r->code), format_string($r->title), homes::hours_text((float)$r->hours, (string)$r->unittype),
                    $grade, $rate];
            }
            if (!$table->data) {
                continue;
            }
            $meta = $inprogress ? shell::tag(get_string('rec_inprogress', $p), 'info') :
                html_writer::span(get_string('rec_termsummary', $p, (object)[
                    'gpa' => $t['totals']['gpa'] === null ? '-' : format_float($t['totals']['gpa'], 2),
                    'earned' => format_float($t['totals']['earned'], -1)]), 'sr-muted');
            $termhtml .= html_writer::div(
                html_writer::tag('h2', format_string($t['term']), ['class' => 'sr-h2 m-0']) . $meta,
                'sr-termhead mt-4 mb-2'
            ) . html_writer::div(html_writer::table($table), 'table-responsive');
        }
        $html = self::program_card($student, $history, $rates ? round(array_sum($rates) / count($rates)) : null);
        return $html . ($termhtml !== '' ? $termhtml : shell::empty_state(get_string('home_noclasses', $p)));
    }

    /**
     * The program card.
     *
     * @param \stdClass $student
     * @param array $history from registration::history()
     * @param float|null $attendance
     * @return string HTML
     */
    protected static function program_card(\stdClass $student, array $history, ?float $attendance): string {
        global $DB;
        $p = self::P;
        if (!$student->programid || !($program = $DB->get_record('local_studentrecords_program', ['id' => $student->programid]))) {
            return '';
        }
        $audit = degreeaudit::run($student);
        $earned = $audit ? (float)$audit['earned'] : (float)$history['cumulative']['earned'];
        $total = (float)$program->totalhours;
        $unit = $program->unittype === 'clock' ? get_string('rec_clockhours', $p) : get_string('rec_credits', $p);
        $kicker = ($program->isdegree ? get_string('rec_degree', $p) : get_string('rec_certificate', $p)) . ' · ' .
            get_string('unit_' . ($program->unittype === 'clock' ? 'clock' : 'credit'), $p);
        $html = html_writer::div(html_writer::span(s($kicker), 'sr-card-kicker') .
            shell::tag(get_string('status_' . $student->status, $p), in_array($student->status, ['active', 'enrolled'], true) ?
            'good' : 'neutral'), 'sr-card-row');
        $html .= html_writer::tag('h2', format_string($program->name), ['class' => 'sr-card-title h3']);
        $started = $student->admittermid ?
            $DB->get_field('local_studentrecords_term', 'name', ['id' => $student->admittermid]) : '';
        if ($started) {
            $html .= html_writer::tag('p', get_string('rec_started', $p, format_string($started)), ['class' => 'sr-muted mb-2']);
        }
        if ($total > 0) {
            $pct = self::percent($earned, $total);
            $html .= html_writer::div(html_writer::span(get_string('rec_progress', $p, (object)['done' => format_float($earned, -1),
                'total' => format_float($total, -1), 'unit' => $unit])) . html_writer::tag('strong', $pct . '%'), 'sr-card-row');
            $html .= html_writer::div(html_writer::span('', 'sr-progress-bar', ['style' => 'width:' . $pct . '%']), 'sr-progress', [
                'role' => 'progressbar', 'aria-valuenow' => $pct, 'aria-valuemin' => 0, 'aria-valuemax' => 100,
                'aria-label' => get_string('rec_progresslabel', $p)]);
        }
        $facts = [];
        $gpa = $history['cumulative']['gpa'];
        $facts[] = get_string('rec_gpa', $p) . ' ' . html_writer::tag('strong', $gpa === null ? '-' : format_float($gpa, 2));
        $facts[] = get_string('attendance', $p) . ' ' . html_writer::tag('strong', $attendance === null ? '-' : $attendance . '%');
        $facts[] = html_writer::link(
            new \moodle_url('/local/studentrecords/degreeaudit.php', ['id' => $student->id]),
            get_string('rec_seeaudit', $p)
        );
        $html .= html_writer::tag('p', implode(' &nbsp; ', $facts), ['class' => 'mt-2 mb-0']);
        return html_writer::div(html_writer::div($html, 'sr-card'), 'sr-programs');
    }
}
