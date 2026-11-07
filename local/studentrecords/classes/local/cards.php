<?php

namespace local_studentrecords\local;

use html_writer;

/**
 * Student Records cards on Moodle pages (shown by the Student Records theme): a strip on the Dashboard for students,
 * and on course and activity pages an attendance card for students and a "today's class" card for teachers. Also the
 * numbers on the student home page.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class cards {
    /** @var string */
    const P = 'local_studentrecords';

    /**
     * The card HTML for a Moodle page, or ''.
     *
     * @param \moodle_page $page
     * @return string
     */
    public static function for_page(\moodle_page $page): string {
        global $USER;
        if (!isloggedin() || isguestuser()) {
            return '';
        }
        if ($page->pagelayout === 'mydashboard') {
            $student = students::get_by_user((int)$USER->id);
            if ($student && has_capability('local/studentrecords:viewown', \context_system::instance())) {
                return self::dashboard_strip($student);
            }
            return shell::groups() ? self::staff_strip() : '';
        }
        if (!in_array($page->pagelayout, ['course', 'incourse'], true) || empty($page->course->id) || $page->course->id == SITEID) {
            return '';
        }
        $courseid = (int)$page->course->id;
        $teacher = self::teacher_card($courseid);
        if ($teacher !== '') {
            return $teacher;
        }
        return self::student_card($courseid);
    }

    /**
     * The current term: the active term running today, else the next one to start, else the last one.
     *
     * @param int $now
     * @return \stdClass|null
     */
    public static function current_term(int $now): ?\stdClass {
        global $DB;
        $terms = $DB->get_records('local_studentrecords_term', ['active' => 1], 'startdate');
        $next = null;
        $last = null;
        foreach ($terms as $t) {
            if ($t->startdate <= $now && $now <= $t->enddate + DAYSECS) {
                return $t;
            }
            if ($t->startdate > $now && !$next) {
                $next = $t;
            }
            $last = $t;
        }
        return $next ?? $last;
    }

    /**
     * Week of the term (1-based) and number of weeks, or null outside the term.
     *
     * @param int $start
     * @param int $end
     * @param int $now
     * @return array|null [week, weeks]
     */
    public static function term_week(int $start, int $end, int $now): ?array {
        if ($end < $start || $now < $start || $now > $end + DAYSECS) {
            return null;
        }
        $weeks = max(1, (int)ceil(($end - $start + DAYSECS) / (7 * DAYSECS)));
        $week = min($weeks, (int)floor(($now - $start) / (7 * DAYSECS)) + 1);
        return [$week, $weeks];
    }

    /**
     * A student's numbers: balance, next plan payment, holds in force, attendance over this term's classes.
     *
     * @param \stdClass $student
     * @return array balance (float|null when billing is off), next (stdClass|null), holds (int), attendance (float|null)
     */
    public static function student_numbers(\stdClass $student): array {
        $out = ['balance' => null, 'next' => null, 'holds' => 0, 'attendance' => null];
        if (billing::enabled()) {
            $out['balance'] = billing::balance((int)$student->id);
            $out['next'] = plans::next_due((int)$student->id);
        }
        $now = time();
        foreach (academic::holds((int)$student->id) as $h) {
            if (academic::hold_active($h, $now)) {
                $out['holds']++;
            }
        }
        $rates = [];
        foreach (self::term_regs($student, self::current_term($now)) as $reg) {
            $s = attendance::for_reg($reg, $student->userid ? (int)$student->userid : null);
            if ($s['rate'] !== null) {
                $rates[] = $s['rate'];
            }
        }
        $out['attendance'] = $rates ? round(array_sum($rates) / count($rates)) : null;
        return $out;
    }

    /**
     * The student's registrations (registered or completed) in a term, with offering details.
     *
     * @param \stdClass $student
     * @param \stdClass|null $term
     * @return array
     */
    public static function term_regs(\stdClass $student, ?\stdClass $term): array {
        global $DB;
        if (!$term) {
            return [];
        }
        return $DB->get_records_sql("SELECT r.*, o.section, o.facultyid, o.moodlecourseid, c.code, c.title, c.hours, c.unittype
                                        FROM {" . registration::REG . "} r
                                        JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
                                        JOIN {local_studentrecords_course} c ON c.id = o.courseid
                                       WHERE r.studentid = ? AND o.termid = ? AND r.status IN ('registered', 'completed')
                                    ORDER BY c.code, o.section", [$student->id, $term->id]);
    }

    /**
     * Dashboard strip: balance, next payment, holds, and a link to Student Records.
     *
     * @param \stdClass $student
     * @return string HTML
     */
    public static function dashboard_strip(\stdClass $student): string {
        $n = self::student_numbers($student);
        $parts = [];
        if ($n['balance'] !== null) {
            $parts[] = html_writer::span(get_string('card_balance', self::P, billing::money(max(0, $n['balance']))));
        }
        if ($n['next']) {
            $parts[] = html_writer::span(get_string('card_nextpayment', self::P, (object)[
                'amount' => billing::money((float)$n['next']->amount - (float)$n['next']->paid),
                'date' => catalog::show_date((int)$n['next']->duedate)]));
        }
        $parts[] = $n['holds'] ? html_writer::span(get_string('card_holds', self::P, $n['holds']), 'sr-tone-bad') :
            html_writer::span(get_string('card_noholds', self::P), 'sr-tone-good');
        $sep = html_writer::span(' · ', 'sr-strip-sep', ['aria-hidden' => 'true']);
        $body = html_writer::tag('strong', get_string('pluginname', self::P), ['class' => 'sr-strip-title']) .
            html_writer::span(implode($sep, $parts), 'sr-strip-parts');
        $links = self::transcript_link(false) . html_writer::link(new \moodle_url(home::URL), get_string('card_open', self::P), [
            'class' => 'btn btn-outline-secondary']);
        return html_writer::tag(
            'section',
            html_writer::div($body, 'sr-strip-body') . html_writer::div($links, 'sr-strip-links'),
            ['class' => 'sr-strip', 'aria-label' => get_string('pluginname', self::P)]
        );
    }

    /**
     * Dashboard strip for staff: their Student Records home and, with transcript rights, Transcript requests.
     *
     * @return string HTML
     */
    public static function staff_strip(): string {
        $body = html_writer::tag('strong', get_string('pluginname', self::P), ['class' => 'sr-strip-title']) .
            html_writer::span(get_string('card_staffline', self::P), 'sr-strip-parts');
        $links = self::transcript_link(true) . html_writer::link(new \moodle_url(home::URL), get_string('card_open', self::P), [
            'class' => 'btn btn-outline-secondary']);
        return html_writer::tag(
            'section',
            html_writer::div($body, 'sr-strip-body') . html_writer::div($links, 'sr-strip-links'),
            ['class' => 'sr-strip', 'aria-label' => get_string('pluginname', self::P)]
        );
    }

    /**
     * The transcript link of the Dashboard strip: "My transcript" for students, "Transcript requests" for staff who
     * issue official transcripts; '' when Academic Transcript is not installed or not allowed.
     *
     * @param bool $staff
     * @return string HTML
     */
    public static function transcript_link(bool $staff): string {
        $dir = \core_component::get_component_directory('local_academictranscript');
        if ($dir === null) {
            return '';
        }
        $sys = \context_system::instance();
        if ($staff) {
            if (!file_exists($dir . '/requests.php') || !has_capability('local/academictranscript:issueofficial', $sys)) {
                return '';
            }
            return html_writer::link(
                new \moodle_url('/local/academictranscript/requests.php'),
                get_string('nav_transcriptrequests', self::P),
                ['class' => 'btn btn-outline-secondary']
            );
        }
        if (!has_capability('local/academictranscript:viewown', $sys)) {
            return '';
        }
        return html_writer::link(
            new \moodle_url('/local/academictranscript/index.php', ['view' => 'mine']),
            get_string('nav_mytranscript', self::P),
            ['class' => 'btn btn-outline-secondary']
        );
    }

    /**
     * Student's attendance card for a course linked to an offering they are registered in.
     *
     * @param int $courseid
     * @return string HTML
     */
    public static function student_card(int $courseid): string {
        global $DB, $USER;
        $student = students::get_by_user((int)$USER->id);
        if (!$student || !has_capability('local/studentrecords:viewown', \context_system::instance())) {
            return '';
        }
        $regs = $DB->get_records_sql("SELECT r.*, c.code
                                         FROM {" . registration::REG . "} r
                                         JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
                                         JOIN {local_studentrecords_course} c ON c.id = o.courseid
                                         JOIN {local_studentrecords_term} t ON t.id = o.termid
                                        WHERE r.studentid = ? AND o.moodlecourseid = ? AND r.status IN ('registered', 'completed')
                                     ORDER BY t.startdate DESC", [$student->id, $courseid], 0, 1);
        if (!$regs) {
            return '';
        }
        $reg = reset($regs);
        $s = attendance::for_reg($reg, (int)$USER->id);
        $minimum = catalog::setting('minattendance') !== '' ? catalog::setting('minattendance') : '80';
        $rate = $s['rate'] === null ? '-' : format_float($s['rate'], 0) . '%';
        $html = html_writer::div(get_string('srcard_from', self::P), 'sr-card-kicker');
        $html .= html_writer::div(html_writer::span(get_string('srcard_myattendance', self::P, s($reg->code))) .
            html_writer::span($rate, 'sr-card-big'), 'sr-card-row');
        if ($s['marked']) {
            $html .= html_writer::tag('p', get_string('srcard_missed', self::P, (object)['absent' => $s['absent'],
                'marked' => $s['marked'], 'minimum' => $minimum]), ['class' => 'sr-card-sub']);
        } else {
            $html .= html_writer::tag('p', get_string('srcard_nomarks', self::P), ['class' => 'sr-card-sub']);
        }
        $html .= html_writer::div(html_writer::link(new \moodle_url(home::URL), get_string('card_open', self::P)), 'sr-card-links');
        return html_writer::tag('section', $html, ['class' => 'sr-card sr-sidecard',
            'aria-label' => get_string('srcard_from', self::P)]);
    }

    /**
     * Teacher's card: today's (or the next) class meeting with "Take attendance", and students under the minimum.
     *
     * @param int $courseid
     * @return string HTML
     */
    public static function teacher_card(int $courseid): string {
        global $DB;
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context || !has_capability('local/studentrecords:takeattendance', $context)) {
            return '';
        }
        $offers = $DB->get_records_sql("SELECT o.id FROM {" . registration::OFFER . "} o
                                           JOIN {local_studentrecords_term} t ON t.id = o.termid
                                          WHERE o.moodlecourseid = ? AND t.active = 1
                                       ORDER BY t.startdate DESC", [$courseid]);
        if (!$offers) {
            return '';
        }
        $offer = registration::offer((int)reset($offers)->id);
        if (!attendance::can_take($offer)) {
            return '';
        }
        $today = catalog::time_to_date(time());
        $meeting = null;
        foreach (attendance::meetings((int)$offer->id) as $m) {
            if (catalog::time_to_date((int)$m->meetingdate) >= $today) {
                $meeting = $m;
                break;
            }
        }
        $p = self::P;
        $html = html_writer::div(get_string('pluginname', $p), 'sr-card-kicker');
        if ($meeting) {
            $istoday = catalog::time_to_date((int)$meeting->meetingdate) === $today;
            $html .= html_writer::tag('h3', get_string($istoday ? 'srcard_todayclass' : 'srcard_nextclass', $p), [
                'class' => 'sr-card-title']);
            $html .= html_writer::tag('p', s($offer->code . ' ' . $offer->section) . ' · ' .
                catalog::show_date((int)$meeting->meetingdate), ['class' => 'sr-card-sub']);
            $html .= html_writer::link(
                new \moodle_url('/local/studentrecords/attendance.php', $istoday ? ['meeting' => $meeting->id] :
                    ['offer' => $offer->id]),
                get_string('takeattendance', $p),
                ['class' => 'btn btn-primary w-100 mb-3']
            );
        } else {
            $html .= html_writer::tag('h3', s($offer->code . ' ' . $offer->section), ['class' => 'sr-card-title']);
            $html .= html_writer::link(
                new \moodle_url('/local/studentrecords/attendance.php', ['offer' => $offer->id]),
                get_string('attendance', $p),
                ['class' => 'btn btn-primary w-100 mb-3']
            );
        }
        $minimum = (float)(catalog::setting('minattendance') !== '' ? catalog::setting('minattendance') : 80);
        $under = 0;
        foreach ($DB->get_records(registration::REG, ['offerid' => $offer->id, 'status' => 'registered']) as $reg) {
            $s = attendance::for_reg($reg, null);
            if ($s['rate'] !== null && $s['rate'] < $minimum) {
                $under++;
            }
        }
        $html .= html_writer::div(html_writer::span(get_string('srcard_belowmin', $p, format_float($minimum, 0))) .
            shell::tag(get_string('srcard_students', $p, $under), $under ? 'bad' : 'good'), 'sr-card-row sr-card-divider');
        $html .= html_writer::div(html_writer::link(new \moodle_url('/local/studentrecords/attendance.php', [
            'offer' => $offer->id]), get_string('srcard_history', $p)), 'sr-card-links');
        return html_writer::tag('section', $html, ['class' => 'sr-card sr-sidecard', 'aria-label' => get_string('pluginname', $p)]);
    }
}
