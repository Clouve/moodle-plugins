<?php

namespace local_studentrecords\local;

use html_writer;

/**
 * Role home pages: student (my home), records staff, finance, financial aid and teacher (my classes).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class homes {
    /** @var string */
    const P = 'local_studentrecords';

    /** @var string[] Views in the order they are picked as the default. */
    const VIEWS = ['student', 'staff', 'finance', 'aid', 'teacher'];

    /** @var array Menu group key => view. */
    const GROUPVIEW = ['student' => 'student', 'records' => 'staff', 'finance' => 'finance', 'aid' => 'aid',
        'teacher' => 'teacher'];

    /** @var array Extra views of a menu group (shown from its menu, never picked as the default). */
    const EXTRAVIEWS = ['teacher' => ['atrisk']];

    /**
     * Views the current user may open, in order.
     *
     * @return string[]
     */
    public static function views(): array {
        $out = [];
        $extra = [];
        foreach (shell::groups() as $g) {
            if (isset(self::GROUPVIEW[$g['key']])) {
                $out[] = self::GROUPVIEW[$g['key']];
                $extra = array_merge($extra, self::EXTRAVIEWS[$g['key']] ?? []);
            }
        }
        return array_merge($out, $extra);
    }

    /**
     * Pick the view: the one asked for if allowed, else the first allowed one, else ''.
     *
     * @param string $asked
     * @param string[] $allowed
     * @return string
     */
    public static function pick(string $asked, array $allowed): string {
        if ($asked !== '' && in_array($asked, $allowed, true)) {
            return $asked;
        }
        return $allowed[0] ?? '';
    }

    /**
     * Page title for a view.
     *
     * @param string $view
     * @return string
     */
    public static function title(string $view): string {
        global $USER;
        if ($view === 'student') {
            return get_string('home_welcomeback', self::P, trim((string)$USER->firstname));
        }
        if ($view === 'finance') {
            return get_string('nav_financehome', self::P);
        }
        if ($view === 'teacher') {
            return get_string('nav_myclasses', self::P);
        }
        if ($view === 'atrisk') {
            return get_string('nav_atrisk', self::P);
        }
        return shell::greeting(time());
    }

    /**
     * Search box (GET form) for a student list page.
     *
     * @param \moodle_url $action
     * @param string $param name of the search parameter
     * @param string $label
     * @return string HTML
     */
    public static function search(\moodle_url $action, string $param, string $label): string {
        $id = html_writer::random_id('srsearch');
        $html = html_writer::label($label, $id, false, ['class' => 'sr-only visually-hidden']);
        $html .= html_writer::empty_tag('input', ['type' => 'search', 'name' => $param, 'id' => $id, 'class' => 'form-control',
            'placeholder' => $label]);
        $html .= html_writer::tag('button', get_string('search'), ['type' => 'submit', 'class' => 'btn btn-primary']);
        return html_writer::tag('form', $html, ['method' => 'get', 'action' => $action->out_omit_querystring(),
            'class' => 'sr-search', 'role' => 'search']);
    }

    /**
     * Student home (my home), or what a student sees for "View as student".
     *
     * @param \stdClass|null $student default: the current user's record
     * @return string HTML
     */
    public static function student(?\stdClass $student = null): string {
        global $DB, $USER;
        $p = self::P;
        $student = $student ?? students::get_by_user((int)$USER->id);
        $uid = (int)$student->userid;
        $now = time();
        $term = cards::current_term($now);
        $html = '';
        $bits = [];
        if ($term) {
            $bits[] = format_string($term->name);
            $week = cards::term_week((int)$term->startdate, (int)$term->enddate, $now);
            if ($week) {
                $bits[] = get_string('home_week', $p, (object)['week' => $week[0], 'weeks' => $week[1]]);
            }
        }
        if ($student->programid && ($prog = $DB->get_record('local_studentrecords_program', ['id' => $student->programid]))) {
            $bits[] = format_string($prog->name);
        }
        if ($bits) {
            $html .= html_writer::tag('p', implode(' · ', $bits), ['class' => 'sr-lead']);
        }
        // Buttons.
        $buttons = [];
        if ($uid && home::shows_courses($uid)) {
            $buttons[] = html_writer::link(new \moodle_url('/my/courses.php'), get_string('gotomycourses', $p), [
                'class' => 'btn btn-primary']);
        }
        $buttons[] = html_writer::link(
            new \moodle_url('/local/studentrecords/student.php', ['id' => $student->id]),
            get_string('mystudentrecord', $p),
            ['class' => 'btn btn-outline-secondary']
        );
        if (
            \core_component::get_component_directory('local_academictranscript') !== null &&
                has_capability('local/academictranscript:viewown', \context_system::instance())
        ) {
            $buttons[] = html_writer::link(
                new \moodle_url('/local/academictranscript/index.php', ['view' => 'mine']),
                get_string('nav_mytranscript', $p),
                ['class' => 'btn btn-outline-secondary']
            );
        }
        $n = cards::student_numbers($student);
        if ($n['balance'] !== null && $n['balance'] > 0.004) {
            $buttons[] = html_writer::link(
                new \moodle_url('/local/studentrecords/pay.php', ['id' => $student->id]),
                get_string('home_payonline', $p),
                ['class' => 'btn btn-outline-secondary']
            );
        }
        $html .= html_writer::div(implode('', $buttons), 'sr-actions');
        // Tiles.
        $tiles = [];
        if ($n['balance'] !== null) {
            $tiles[] = shell::tile(
                get_string('home_balancedue', $p),
                billing::money(max(0, $n['balance'])),
                '',
                new \moodle_url('/local/studentrecords/account.php', ['id' => $student->id]),
                get_string('home_viewaccount', $p)
            );
            if ($n['next']) {
                $tiles[] = shell::tile(get_string('home_nextpayment', $p), userdate(
                    (int)$n['next']->duedate,
                    get_string('strftimedateshortmonthabbr', 'langconfig'),
                    catalog::timezone()
                ), billing::money((float)$n['next']->amount -
                    (float)$n['next']->paid));
            } else {
                $tiles[] = shell::tile(
                    get_string('home_nextpayment', $p),
                    get_string('home_none', $p),
                    get_string('home_noplan', $p)
                );
            }
        }
        $tiles[] = $n['holds'] ? shell::tile(
            get_string('home_holds', $p),
            (string)$n['holds'],
            get_string('home_holdsfix', $p),
            null,
            '',
            'bad'
        ) : shell::tile(
            get_string('home_holds', $p),
            get_string('home_noholds', $p),
            get_string('home_allset', $p),
            null,
            '',
            'good'
        );
        $minimum = catalog::setting('minattendance') !== '' ? catalog::setting('minattendance') : '80';
        $tiles[] = shell::tile(
            get_string('home_myattendance', $p),
            $n['attendance'] === null ? '-' : $n['attendance'] . '%',
            get_string('home_minimum', $p, $minimum),
            null,
            '',
            $n['attendance'] !== null && $n['attendance'] < (float)$minimum ?
            'bad' : ''
        );
        $html .= shell::tiles($tiles);
        // Classes and messages.
        $classes = self::student_classes($student, $term);
        $messages = self::messages($uid);
        $html .= html_writer::div(
            html_writer::div($classes, 'sr-col-main') . html_writer::div($messages, 'sr-col-side'),
            'sr-columns'
        );
        return $html;
    }

    /**
     * "My classes this term" table.
     *
     * @param \stdClass $student
     * @param \stdClass|null $term
     * @return string HTML
     */
    protected static function student_classes(\stdClass $student, ?\stdClass $term): string {
        global $DB, $USER;
        $p = self::P;
        $out = html_writer::tag('h2', get_string('home_myclasses', $p), ['class' => 'sr-h2']);
        $regs = cards::term_regs($student, $term);
        if (!$regs) {
            return $out . shell::empty_state(get_string('home_noclasses', $p));
        }
        $table = new \html_table();
        $table->attributes['class'] = 'sr-table';
        $table->head = [get_string('home_class', $p), get_string('home_teacher', $p), get_string('home_meets', $p),
            get_string('home_credits', $p)];
        foreach ($regs as $r) {
            $code = s($r->code);
            if (
                $r->moodlecourseid && $DB->record_exists('course', ['id' => $r->moodlecourseid]) &&
                    $student->userid && is_enrolled(
                        \context_course::instance((int)$r->moodlecourseid),
                        (int)$student->userid,
                        '',
                        true
                    )
            ) {
                $code = html_writer::link(new \moodle_url('/course/view.php', ['id' => $r->moodlecourseid]), $code);
            }
            $teacher = '';
            if ($r->facultyid && ($f = $DB->get_record('local_studentrecords_faculty', ['id' => $r->facultyid]))) {
                $teacher = s(catalog::faculty_name($f));
            }
            $table->data[] = [$code . ' ' . format_string($r->title), $teacher, s(self::meets((int)$r->offerid)),
                self::hours_text((float)$r->hours, (string)$r->unittype)];
        }
        return $out . html_writer::div(html_writer::table($table), 'table-responsive');
    }

    /**
     * Hours of a class with a short unit: "4" for credit hours, "40 clock hrs" for clock hours.
     *
     * @param float $hours
     * @param string $unittype credit or clock
     * @return string
     */
    public static function hours_text(float $hours, string $unittype): string {
        $n = format_float($hours, -1);
        return $unittype === 'clock' ? get_string('hours_clockshort', self::P, $n) : $n;
    }

    /**
     * When a class meets: the class days and time of the section, else the weekdays of its meeting dates
     * (for example "Tue, Thu").
     *
     * @param int $offerid
     * @return string
     */
    public static function meets(int $offerid): string {
        global $DB;
        $offer = $DB->get_record(registration::OFFER, ['id' => $offerid]);
        if ($offer && ($text = selfreg::meets_text($offer)) !== '') {
            return $text;
        }
        $days = [];
        foreach (attendance::meetings($offerid) as $m) {
            $days[(int)userdate((int)$m->meetingdate, '%u', catalog::timezone(), false, false)] = true;
        }
        if (!$days) {
            return '-';
        }
        ksort($days);
        $names = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
        return implode(', ', array_map(fn($d) => get_string($names[$d], 'calendar'), array_keys($days)));
    }

    /**
     * Latest Student Records messages to a user (Moodle notifications from this plugin).
     *
     * @param int $userid
     * @return string HTML
     */
    protected static function messages(int $userid): string {
        global $DB;
        $p = self::P;
        $out = html_writer::tag('h2', get_string('nav_messages', $p), ['class' => 'sr-h2']);
        $rows = $DB->get_records(
            'notifications',
            ['useridto' => $userid, 'component' => 'local_studentrecords'],
            'timecreated DESC',
            'id, subject, timecreated',
            0,
            3
        );
        if (!$rows) {
            return $out . shell::empty_state(get_string('home_nomessages', $p));
        }
        $items = '';
        foreach ($rows as $m) {
            $items .= html_writer::tag('li', html_writer::div(s($m->subject), 'sr-msg-subject') .
                html_writer::div(userdate((int)$m->timecreated, get_string('strftimedateshort', 'langconfig')), 'sr-msg-meta'));
        }
        return $out . html_writer::tag('ul', $items, ['class' => 'sr-msgs']) .
            html_writer::link(new \moodle_url('/message/output/popup/notifications.php'), get_string('home_allmessages', $p));
    }

    /**
     * Records staff home.
     *
     * @return string HTML
     */
    public static function staff(): string {
        global $DB;
        $p = self::P;
        $html = self::search(new \moodle_url('/local/studentrecords/students.php'), 'search', get_string('home_findstudent', $p));
        $applicants = $DB->get_records('local_studentrecords_student', ['status' => 'applicant']);
        $missing = admissions::missing_counts($applicants);
        $ready = count(array_filter($applicants, fn($s) => empty($missing[$s->id])));
        $withmissing = count($applicants) - $ready;
        $now = time();
        $term = cards::current_term($now);
        $tiles = [shell::tile(
            get_string('home_newapplicants', $p),
            (string)count($applicants),
            get_string('home_readydecision', $p, $ready),
            new \moodle_url('/local/studentrecords/admissions.php'),
            get_string('home_open', $p)
        )];
        if ($term) {
            $registered = (int)$DB->count_records_sql(
                "SELECT COUNT(DISTINCT r.studentid) FROM {" . registration::REG . "} r
                JOIN {" . registration::OFFER . "} o ON o.id = r.offerid WHERE o.termid = ? AND r.status = 'registered'",
                [$term->id]
            );
            $active = $DB->count_records('local_studentrecords_student', ['status' => 'active']);
            $tiles[] = shell::tile(
                get_string('home_registeredfor', $p, reports::plain($term->name)),
                (string)$registered,
                get_string('home_ofactive', $p, $active)
            );
        }
        $holds = ['all' => [], 'financial' => [], 'other' => []];
        foreach ($DB->get_records_select('local_studentrecords_hold', 'timereleased IS NULL OR timereleased = 0') as $h) {
            if (academic::hold_active($h, $now)) {
                $holds['all'][$h->studentid] = true;
                $holds[$h->holdtype === 'financial' ? 'financial' : 'other'][$h->studentid] = true;
            }
        }
        $tiles[] = shell::tile(
            get_string('home_withholds', $p),
            (string)count($holds['all']),
            get_string(
                'home_holdsplit',
                $p,
                (object)['financial' => count($holds['financial']), 'other' => count($holds['other'])]
            ),
            new \moodle_url('/local/studentrecords/standing.php'),
            get_string('home_open', $p)
        );
        $under = $term ? count(attendance::report((int)$term->id)['under']) : 0;
        $tiles[] = shell::tile(
            get_string('home_belowattendance', $p),
            (string)$under,
            '',
            new \moodle_url('/local/studentrecords/reports.php', ['report' => 'attendunder']),
            get_string('home_open', $p),
            $under ? 'bad' : ''
        );
        $html .= shell::tiles($tiles);
        // Needs your attention.
        $rows = [];
        if ($withmissing) {
            $rows[] = [get_string('home_attn_missingdocs', $p, $withmissing),
                new \moodle_url('/local/studentrecords/admissions.php'), get_string('home_review', $p), null];
        }
        $gradapps = $DB->count_records('local_studentrecords_gradapp', ['state' => 'applied']);
        if ($gradapps && staffarea::can_open('graduation')) {
            $rows[] = [get_string('home_attn_gradapps', $p, $gradapps), new \moodle_url('/local/studentrecords/graduation.php'),
                get_string('home_review', $p), null];
        }
        $changes = count(academic::changes('pending'));
        if ($changes && staffarea::can_open('gradechanges')) {
            $rows[] = [get_string('home_attn_gradechanges', $p, $changes), new \moodle_url('/local/studentrecords/gradechange.php'),
                get_string('home_review', $p), null];
        }
        $attention = html_writer::tag('h2', get_string('home_attention', $p), ['class' => 'sr-h2']) .
            shell::attention($rows, get_string('home_attn_none', $p));
        $links = [];
        foreach (
            ['admissions' => 'home_ql_admissions', 'offerings' => 'nav_offerings', 'standing' => 'nav_holds',
            'graduation' => 'graduation', 'reports' => 'reports', 'outsidereports' => 'outsidereports'] as $name => $label
        ) {
            if (staffarea::can_open($name)) {
                $links[] = [new \moodle_url('/local/studentrecords/' . staffarea::PAGES[$name][0]), get_string($label, $p)];
            }
        }
        if (access::can_manage_students()) {
            array_unshift($links, [new \moodle_url('/local/studentrecords/student.php', ['edit' => 1]),
                get_string('home_ql_addstudent', $p)]);
        }
        $quick = html_writer::tag('h2', get_string('home_quicklinks', $p), ['class' => 'sr-h2']) . shell::quick_links($links);
        return $html . html_writer::div(
            html_writer::div($attention, 'sr-col-main') . html_writer::div($quick, 'sr-col-side'),
            'sr-columns'
        );
    }

    /**
     * Finance home.
     *
     * @return string HTML
     */
    public static function finance(): string {
        global $DB;
        $p = self::P;
        $html = self::search(new \moodle_url('/local/studentrecords/billing.php'), 'q', get_string('home_findaccount', $p));
        $today = catalog::time_to_date(time());
        $paid = 0.0;
        $count = 0;
        foreach (moneyreports::payments($today, $today) as $row) {
            $paid += $row['total'];
            $count += $row['count'];
        }
        $proposed = refunds::listing('proposed');
        $refundsum = array_sum(array_map(fn($r) => (float)$r->amount, $proposed));
        $oldest = $proposed ? (int)floor((time() - min(array_map(fn($r) => (int)$r->timecreated, $proposed))) / DAYSECS) : 0;
        $late = moneyreports::late();
        $latesum = array_sum(array_map(fn($i) => (float)$i->amount - (float)$i->paid, $late));
        $tiles = [
            shell::tile(get_string('home_paymentstoday', $p), billing::money($paid), get_string('home_npayments', $p, $count)),
            shell::tile(
                get_string('home_refundswaiting', $p),
                (string)count($proposed),
                $proposed ?
                get_string('home_refundsub', $p, (object)['amount' => billing::money($refundsum), 'days' => $oldest]) : '',
                new \moodle_url('/local/studentrecords/refunds.php'),
                get_string('home_review', $p),
                $proposed ? 'warn' : ''
            ),
            shell::tile(get_string('home_lateplan', $p), (string)count($late), $late ?
                get_string('home_pastdue', $p, billing::money($latesum)) : '', null, '', $late ? 'bad' : ''),
        ];
        $html .= shell::tiles($tiles);
        // Who owes money, by age of the charges.
        $sum = ['d30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'older' => 0.0, 'total' => 0.0];
        $owed = moneyreports::owed();
        foreach ($owed as $row) {
            foreach ($sum as $k => $v) {
                $sum[$k] = round($v + $row[$k], 2);
            }
        }
        $owes = html_writer::tag('h2', get_string('home_whoowes', $p), ['class' => 'sr-h2']);
        $owes .= html_writer::tag('p', get_string('home_owedtotal', $p, (object)['amount' => billing::money($sum['total']),
            'count' => count($owed)]), ['class' => 'sr-muted']);
        $max = max(0.01, $sum['d30'], $sum['d60'], $sum['d90'], $sum['older']);
        $bars = '';
        foreach (['d30', 'd60', 'd90', 'older'] as $k) {
            $pct = (int)round($sum[$k] / $max * 100);
            $bars .= html_writer::tag('li', html_writer::span(get_string('home_age_' . $k, $p), 'sr-bar-label') .
                html_writer::span(
                    html_writer::span('', 'sr-bar-fill sr-bar-' . $k, ['style' => 'width:' . $pct . '%']),
                    'sr-bar-track',
                    ['aria-hidden' => 'true']
                ) . html_writer::span(billing::money($sum[$k]), 'sr-bar-value'));
        }
        $owes .= html_writer::tag('ul', $bars, ['class' => 'sr-bars']);
        $owes .= html_writer::link(
            new \moodle_url('/local/studentrecords/billingreports.php', ['report' => 'owed']),
            get_string('home_agingreport', $p)
        );
        // Late plan payments.
        $latehtml = html_writer::tag('h2', get_string('home_lateplan', $p), ['class' => 'sr-h2']);
        if ($late) {
            $table = new \html_table();
            $table->attributes['class'] = 'sr-table';
            $table->head = [get_string('home_student', $p), get_string('home_due', $p), get_string('home_amount', $p)];
            foreach (array_slice($late, 0, 5) as $i) {
                $days = (int)floor((time() - (int)$i->duedate) / DAYSECS);
                $table->data[] = [
                    html_writer::link(
                        new \moodle_url('/local/studentrecords/account.php', ['id' => $i->sid]),
                        s(students::display_name($i))
                    ),
                    catalog::show_date((int)$i->duedate) . ' · ' . get_string('home_days', $p, $days),
                    billing::money((float)$i->amount - (float)$i->paid),
                ];
            }
            $latehtml .= html_writer::table($table) . html_writer::link(new \moodle_url(
                '/local/studentrecords/billingreports.php',
                ['report' => 'late']
            ), get_string('home_seeall', $p, count($late)));
        } else {
            $latehtml .= shell::empty_state(get_string('home_nolate', $p));
        }
        return $html . html_writer::div(
            html_writer::div($owes, 'sr-col-main') . html_writer::div($latehtml, 'sr-col-side'),
            'sr-columns'
        );
    }

    /**
     * Financial aid home (federal aid module on).
     *
     * @return string HTML
     */
    public static function aid(): string {
        global $DB;
        $p = self::P;
        $html = self::search(
            new \moodle_url('/local/studentrecords/aid.php', ['report' => 'students']),
            'q',
            get_string('home_findstudent', $p)
        );
        $awardyear = aidhelp::award_year(time());
        $awards = $DB->get_records('local_studentrecords_aid', ['awardyear' => $awardyear]);
        $students = [];
        $accepted = [];
        foreach ($awards as $a) {
            $students[$a->studentid] = true;
            if ($a->status === 'accepted') {
                $accepted[$a->studentid] = true;
            }
        }
        $verif = $DB->count_records(aidhelp::YEARS, ['awardyear' => $awardyear, 'verification' => 'selected']);
        $term = cards::current_term(time());
        $run = $term ? aidhelp::run((int)$term->id) : [];
        $ready = array_filter($run, fn($r) => !$r['reasons'] && !$r['payroll']);
        $count = fn(string $k) => count(array_filter($run, fn($r) => in_array($k, $r['reasons'], true)));
        $returns = 0;
        if ($term) {
            foreach (aid::withdrawals((int)$term->id) as $w) {
                $returns += (float)$w['disbursed'] > 0 ? 1 : 0;
            }
        }
        $tiles = [
            shell::tile(get_string('home_aidoffers', $p), (string)count($students), get_string(
                'home_accepted',
                $p,
                count($accepted)
            )),
            shell::tile(
                get_string('aid_waitingdocs', $p),
                (string)$verif,
                get_string('aid_verifnotdone', $p),
                new \moodle_url('/local/studentrecords/aid.php', ['report' => 'verification']),
                get_string('home_open', $p),
                $verif ? 'warn' : ''
            ),
            shell::tile(
                get_string('aid_readytosend', $p),
                billing::money(array_sum(array_column($ready, 'left'))),
                get_string('aid_readycount', $p, count($ready)),
                $term ? new \moodle_url(
                    '/local/studentrecords/aid.php',
                    ['report' => 'run', 'termid' => $term->id]
                ) : null,
                get_string('home_open', $p)
            ),
            shell::tile(
                get_string('nav_returnofaid', $p),
                (string)$returns,
                get_string('home_withdrawals', $p),
                $term ? new \moodle_url('/local/studentrecords/aid.php', ['report' => 'returns', 'termid' => $term->id]) : null,
                get_string('home_open', $p),
                $returns ? 'bad' : ''
            ),
        ];
        $html .= shell::tiles($tiles);
        $rows = [];
        $runurl = $term ? new \moodle_url('/local/studentrecords/aid.php', ['report' => 'run', 'termid' => $term->id]) : null;
        if ($n = $count('held_counseling')) {
            $rows[] = [get_string('aid_attn_counseling', $p, $n), $runurl, get_string('home_review', $p), null];
        }
        if ($n = $count('held_halftime')) {
            $rows[] = [get_string('aid_attn_halftime', $p, $n), $runurl, get_string('home_review', $p), null];
        }
        if ($returns) {
            $rows[] = [get_string('aid_attn_returns', $p, $returns), new \moodle_url(
                '/local/studentrecords/aid.php',
                ['report' => 'returns', 'termid' => $term->id]
            ), get_string('aid_openhelper', $p), null];
        }
        $sap = $DB->count_records_select(
            'local_studentrecords_stand',
            "standing IN ('warning', 'probation', 'dismissal')" . ($term ? ' AND termid = ?' : ''),
            $term ? [$term->id] : null
        );
        if ($sap) {
            $rows[] = [get_string('aid_attn_sap', $p, $sap), new \moodle_url('/local/studentrecords/standing.php'),
                get_string('home_review', $p), null];
        }
        $attention = html_writer::tag('h2', get_string('home_attention', $p), ['class' => 'sr-h2']) .
            shell::attention($rows, get_string('home_attn_none', $p));
        $links = [
            [new \moodle_url('/local/studentrecords/aid.php', ['report' => 'students']), get_string('nav_studentawards', $p)],
            [new \moodle_url('/local/studentrecords/aid.php', ['report' => 'run']), get_string('nav_disbursements', $p)],
            [new \moodle_url('/local/studentrecords/aid.php', ['report' => 'returns']), get_string('nav_returnofaid', $p)],
            [new \moodle_url('/local/studentrecords/aid.php', ['report' => 'verification']), get_string('nav_verification', $p)],
            [new \moodle_url('/local/studentrecords/aid.php', ['report' => 'recipients']), get_string('reports', $p)],
        ];
        $quick = html_writer::tag('h2', get_string('home_quicklinks', $p), ['class' => 'sr-h2']) . shell::quick_links($links);
        return $html . html_writer::div(
            html_writer::div($attention, 'sr-col-main') . html_writer::div($quick, 'sr-col-side'),
            'sr-columns'
        );
    }

    /**
     * Teacher home: my classes with attendance links.
     *
     * @return string HTML
     */
    public static function teacher(): string {
        $p = self::P;
        $offers = shell::my_offerings();
        if (!$offers) {
            return shell::empty_state(get_string('home_noteaching', $p));
        }
        $table = new \html_table();
        $table->attributes['class'] = 'sr-table';
        $table->head = [get_string('home_class', $p), get_string('term', $p), get_string('home_students', $p),
            get_string('home_meets', $p), get_string('attendance', $p)];
        foreach (array_keys($offers) as $id) {
            $o = registration::offer((int)$id);
            $table->data[] = [
                s($o->code . ' ' . $o->section) . ' ' . format_string($o->title),
                format_string($o->termname),
                registration::taken((int)$id),
                s(self::meets((int)$id)),
                html_writer::link(
                    new \moodle_url('/local/studentrecords/attendance.php', ['offer' => $id]),
                    get_string('takeattendance', $p)
                ),
            ];
        }
        return html_writer::div(html_writer::table($table), 'table-responsive');
    }

    /**
     * A percent as short as it can be while it still shows under a limit: 65.4 shows as 65, and 99.6 under 100 as 99.6, not as
     * the 100 it is under.
     *
     * @param float $percent under the limit
     * @param float $limit
     * @return string
     */
    protected static function percent_under(float $percent, float $limit): string {
        $decimals = 0;
        while ($decimals < 5 && (float)format_float($percent, $decimals, false) >= $limit) {
            $decimals++;
        }
        return format_float($percent, $decimals);
    }

    /**
     * Students at risk in the teacher's classes: attendance under or near the minimum, or a Moodle course grade under
     * the school's at-risk percent (when the course has one).
     *
     * @return string HTML
     */
    public static function atrisk(): string {
        global $DB;
        $p = self::P;
        $minimum = (float)(catalog::setting('minattendance') !== '' ? catalog::setting('minattendance') : 80);
        $lowest = catalog::atrisk_percent();
        $rows = [];
        foreach (array_keys(shell::my_offerings()) as $id) {
            $o = registration::offer((int)$id);
            foreach ($DB->get_records(registration::REG, ['offerid' => $id, 'status' => 'registered']) as $reg) {
                $student = students::get((int)$reg->studentid);
                $att = attendance::for_reg($reg, $student->userid ? (int)$student->userid : null);
                $tone = record_view::attendance_tone($att['rate'], $minimum);
                $pct = $o->moodlecourseid && $student->userid ? registration::moodle_percent(
                    (int)$o->moodlecourseid,
                    (int)$student->userid
                ) : null;
                $lowgrade = $pct !== null && $pct < $lowest;
                if ($tone === '' && !$lowgrade) {
                    continue;
                }
                $why = [];
                if ($tone !== '') {
                    $why[] = shell::tag(get_string(
                        $tone === 'bad' ? 'rec_undermin' : 'rec_nearmin',
                        $p,
                        format_float($att['rate'], 0) . '%'
                    ), $tone);
                }
                if ($lowgrade) {
                    $why[] = shell::tag(get_string('risk_grade', $p, self::percent_under($pct, $lowest) . '%'), 'warn');
                }
                $name = students::display_name($student, access::hide_directory($student));
                $rows[] = [s($name), s($o->code . ' ' . $o->section), implode(' ', $why),
                    $att['lda'] !== '' ? catalog::show_date(catalog::date_to_time($att['lda'])) : '-'];
            }
        }
        if (!$rows) {
            return shell::empty_state(get_string('risk_none', $p));
        }
        $table = new \html_table();
        $table->attributes['class'] = 'sr-table';
        $table->head = [get_string('home_student', $p), get_string('home_class', $p), get_string('risk_why', $p),
            get_string('lastattended', $p)];
        $table->data = $rows;
        $intro = $lowest > 0 ? get_string('risk_intro', $p, (object)['attendance' => format_float($minimum, 0),
            'grade' => format_float($lowest, -1)]) : get_string('risk_intro_attendance', $p, format_float($minimum, 0));
        return html_writer::tag('p', $intro, ['class' => 'sr-muted']) .
            html_writer::div(html_writer::table($table), 'table-responsive');
    }
}
