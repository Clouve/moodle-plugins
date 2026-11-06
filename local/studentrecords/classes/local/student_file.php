<?php

namespace local_studentrecords\local;

use html_writer;

/**
 * The staff view of a student file: a header (initials, name, ID, program, status and privacy tags, View as student,
 * Edit), tabs (Profile, Admissions, Registration, Attendance, Holds, Graduation, Privacy, Documents, History) and
 * an "At a glance" card.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class student_file {
    /** @var string */
    const P = 'local_studentrecords';

    /** @var string[] Tabs in order. */
    const TABS = ['profile', 'admissions', 'registration', 'attendance', 'holds', 'graduation', 'privacy', 'documents',
        'history'];

    /**
     * Initials of a name (first letters of the first and last word).
     *
     * @param string $name
     * @return string
     */
    public static function initials(string $name): string {
        $words = array_values(array_filter(preg_split('/\s+/u', trim($name))));
        if (!$words) {
            return '';
        }
        $first = \core_text::substr($words[0], 0, 1);
        $last = count($words) > 1 ? \core_text::substr(end($words), 0, 1) : '';
        return \core_text::strtoupper($first . $last);
    }

    /**
     * The tab to show: the one asked for if known, else the profile.
     *
     * @param string $tab
     * @param bool $withhistory
     * @return string
     */
    public static function pick_tab(string $tab, bool $withhistory): string {
        if (!in_array($tab, self::TABS, true) || ($tab === 'history' && !$withhistory)) {
            return 'profile';
        }
        return $tab;
    }

    /**
     * Header with name, ID, program, tags and buttons.
     *
     * @param \stdClass $student
     * @param bool $manage
     * @return string HTML
     */
    public static function header(\stdClass $student, bool $manage): string {
        global $DB;
        $p = self::P;
        $hide = access::hide_directory($student);
        $name = students::display_name($student, $hide);
        $program = $student->programid ? $DB->get_field('local_studentrecords_program', 'name', ['id' => $student->programid]) : '';
        $tags = shell::tag(get_string('status_' . $student->status, $p) . ($student->loadtype ? ' · ' .
            get_string('load_' . $student->loadtype, $p) : ''), in_array(
                $student->status,
                ['active', 'enrolled', 'admitted'],
                true
            ) ? 'good' : 'neutral');
        if ($student->ferpaoptout) {
            $tags .= ' ' . shell::tag(get_string('file_ferpablock', $p), 'warn');
        }
        $meta = get_string('studentid', $p) . ' ' . s($student->studentid) . ($program ? ' · ' . format_string($program) : '');
        $first = $hide ? $student->legalfirstname : ($student->preferredname ?: $student->legalfirstname);
        $legal = trim($first . ' ' . $student->legallastname);
        $left = html_writer::span(s(self::initials($legal)), 'sr-avatar', ['aria-hidden' => 'true']) .
            html_writer::div(html_writer::tag('h2', s($name), ['class' => 'sr-file-name']) .
            html_writer::div($meta . ' ' . $tags, 'sr-file-meta'), 'sr-file-id');
        $buttons = html_writer::link(
            new \moodle_url('/local/studentrecords/viewas.php', ['id' => $student->id]),
            get_string('file_viewas', $p),
            ['class' => 'btn btn-outline-secondary']
        );
        if ($manage) {
            $buttons .= ' ' . html_writer::link(new \moodle_url('/local/studentrecords/student.php', ['id' => $student->id,
                'edit' => 1]), get_string('file_edit', $p), ['class' => 'btn btn-primary']);
        }
        return html_writer::div(
            html_writer::div($left, 'sr-file-left') . html_writer::div($buttons, 'sr-file-buttons'),
            'sr-file-header'
        );
    }

    /**
     * The tab row (links; the current one is marked). Scrolls on phones with a fade.
     *
     * @param \stdClass $student
     * @param string $current
     * @param bool $withhistory
     * @return string HTML
     */
    public static function tabs(\stdClass $student, string $current, bool $withhistory): string {
        $p = self::P;
        $items = '';
        $holds = 0;
        foreach (academic::holds((int)$student->id) as $h) {
            $holds += academic::hold_active($h, time()) ? 1 : 0;
        }
        foreach (self::TABS as $tab) {
            if ($tab === 'history' && !$withhistory) {
                continue;
            }
            $label = get_string('file_tab_' . $tab, $p) . ($tab === 'holds' ? ' (' . $holds . ')' : '');
            $attrs = ['class' => 'nav-link' . ($tab === $current ? ' active' : '')];
            if ($tab === $current) {
                $attrs['aria-current'] = 'page';
            }
            $items .= html_writer::tag('li', html_writer::link(new \moodle_url('/local/studentrecords/student.php', [
                'id' => $student->id, 'tab' => $tab]), $label, $attrs), ['class' => 'nav-item']);
        }
        return html_writer::tag('nav', html_writer::tag('ul', $items, ['class' => 'nav nav-tabs sr-tabs']), [
            'aria-label' => get_string('file_tabs', $p), 'class' => 'sr-tabs-wrap']);
    }

    /**
     * A definition list from label => HTML value pairs (empty values shown as a dash).
     *
     * @param array $rows
     * @return string HTML
     */
    public static function facts(array $rows): string {
        $out = '';
        foreach ($rows as $label => $value) {
            $out .= html_writer::tag('dt', s($label)) . html_writer::tag('dd', $value === '' ? '-' : $value);
        }
        return html_writer::tag('dl', $out, ['class' => 'sr-facts']);
    }

    /**
     * Profile tab: contact, personal and state reporting, emergency contacts, and the At a glance card.
     *
     * @param \stdClass $student
     * @param bool $hide FERPA directory block for this viewer
     * @param bool $manage
     * @return string HTML
     */
    public static function profile(\stdClass $student, bool $hide, bool $manage): string {
        global $DB;
        $p = self::P;
        $w = fn(string $v) => $hide ? html_writer::span(get_string('withheld', $p), 'sr-muted') : $v;
        $contact = self::facts([
            get_string('email') => $w(s((string)$student->email)),
            get_string('phone', $p) => $w(s((string)$student->phone)),
            get_string('usaddress', $p) => $w(nl2br(s((string)$student->usaddress))),
            get_string('homeaddress', $p) => $w(nl2br(s((string)$student->homeaddress))),
            get_string('preferredname', $p) => $w(s((string)$student->preferredname)),
            get_string('legalname', $p) => s(trim($student->legalfirstname . ' ' . $student->legalmiddlename . ' ' .
                $student->legallastname)),
        ]);
        $opt = fn(string $f) => $student->$f ? get_string($f . '_' . $student->$f, $p) : '';
        $reporting = self::facts([
            get_string('dob', $p) => $w(catalog::show_date($student->dob ? (int)$student->dob : null)),
            get_string('residency', $p) => $opt('residency'),
            get_string('citizenship', $p) => $opt('citizenship'),
            get_string('firstgen', $p) => $opt('firstgen'),
            get_string('highschool', $p) => s(trim((string)$student->highschool . ($student->hsyear ? ' (' .
                (int)$student->hsyear . ')' : ''))),
            get_string('file_ssn', $p) => html_writer::span(get_string('file_ssnnotstored', $p), 'sr-muted'),
        ]);
        $terms = catalog::terms();
        $advisors = catalog::faculty('');
        $academic = self::facts([
            get_string('admitterm', $p) => $student->admittermid ? format_string($terms[$student->admittermid] ?? '') : '',
            get_string('catalogyear', $p) => s((string)$student->catalogyear),
            get_string('advisor', $p) => $student->advisorid ? s($advisors[$student->advisorid] ?? '') : '',
            get_string('lastattended', $p) => catalog::show_date($student->lastattended ? (int)$student->lastattended : null),
        ]);
        $main = html_writer::div(
            html_writer::div(html_writer::tag('h3', get_string('sec_contact', $p), ['class' => 'sr-card-title']) . $contact .
            html_writer::tag('h3', get_string('sec_academic', $p), ['class' => 'sr-card-title mt-3']) . $academic, 'sr-col') .
            html_writer::div(
                html_writer::tag('h3', get_string('sec_reporting', $p), ['class' => 'sr-card-title']) . $reporting,
                'sr-col'
            ),
            'sr-profile-cols'
        );
        $main .= student_sections::emergency($student, $manage);
        if (admissions::can_view_demographics()) {
            $main .= student_sections::demographics($student, $manage);
        }
        return html_writer::div(
            html_writer::div($main, 'sr-col-main') . html_writer::div(self::glance($student), 'sr-col-side'),
            'sr-columns'
        );
    }

    /**
     * At a glance: credits, GPA, attendance, balance (view only), holds.
     *
     * @param \stdClass $student
     * @return string HTML
     */
    public static function glance(\stdClass $student): string {
        global $DB;
        $p = self::P;
        $history = registration::history($student);
        $audit = degreeaudit::run($student);
        $total = $student->programid ? (float)$DB->get_field(
            'local_studentrecords_program',
            'totalhours',
            ['id' => $student->programid]
        ) :
            0.0;
        $earned = $audit ? (float)$audit['earned'] : (float)$history['cumulative']['earned'];
        $n = cards::student_numbers($student);
        $rows = [
            get_string('file_credits', $p) => format_float($earned, -1) . ($total > 0 ? ' / ' . format_float($total, -1) : ''),
            get_string(
                'rec_gpa',
                $p
            ) => $history['cumulative']['gpa'] === null ? '-' : format_float($history['cumulative']['gpa'], 2),
            get_string('attendance', $p) => $n['attendance'] === null ? '-' : $n['attendance'] . '%',
        ];
        if ($n['balance'] !== null) {
            $rows[get_string('file_balance', $p)] = billing::money($n['balance']);
        }
        $rows[get_string('home_holds', $p)] = $n['holds'] ? html_writer::span((string)$n['holds'], 'sr-tone-bad') :
            html_writer::span(get_string('home_none', $p), 'sr-tone-good');
        $html = html_writer::tag('h3', get_string('file_glance', $p), ['class' => 'sr-card-title']);
        $list = '';
        foreach ($rows as $k => $v) {
            $list .= html_writer::tag('li', html_writer::span(s($k)) . html_writer::tag('strong', $v));
        }
        $html .= html_writer::tag('ul', $list, ['class' => 'sr-costlines border-0 pt-0']);
        if ($n['balance'] !== null && !has_capability('local/studentrecords:viewbilling', \context_system::instance())) {
            $html .= html_writer::tag('p', get_string('file_balanceviewonly', $p), ['class' => 'sr-muted small mb-0']);
        } else if ($n['balance'] !== null) {
            $html .= html_writer::link(
                new \moodle_url('/local/studentrecords/account.php', ['id' => $student->id]),
                get_string('file_openaccount', $p)
            );
        }
        return html_writer::div($html, 'sr-card');
    }
}
