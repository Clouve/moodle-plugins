<?php

namespace local_studentrecords\local;

use html_table;
use html_writer;
use moodle_url;

/**
 * The admissions sections of a student record: status history, decisions, application checklist, prior
 * education, emergency contacts and demographics.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class student_sections {
    /** @var string Plugin. */
    const P = 'local_studentrecords';

    /**
     * All sections the current user may see.
     *
     * @param \stdClass $student
     * @param bool $manage may edit
     * @return string HTML
     */
    public static function render(\stdClass $student, bool $manage): string {
        $out = $manage ? self::release_check($student) : self::ferpa_prompt($student);
        $out .= self::account($student);
        $out .= self::holds($student, $manage);
        $out .= self::academic($student, $manage);
        $out .= self::attendance($student);
        $out .= self::standing_history($student);
        $out .= self::graduation($student, $manage);
        $out .= self::ferpa_section($student, $manage);
        $out .= self::checklist($student, $manage);
        $out .= self::decisions($student, $manage);
        $out .= self::status_history($student);
        $out .= self::education($student, $manage);
        $out .= self::emergency($student, $manage);
        if (admissions::can_view_demographics()) {
            $out .= self::demographics($student, $manage);
        }
        return $out;
    }

    /**
     * Account summary (balance and a link to the account) for Finance staff and the student, when billing is on.
     *
     * @param \stdClass $student
     * @return string HTML
     */
    public static function account(\stdClass $student): string {
        global $OUTPUT;
        if (!billing::enabled() || !billing::can_view($student)) {
            return '';
        }
        $p = self::P;
        $balance = billing::balance((int)$student->id);
        $state = $balance > 0.004 ? 'balancedue' : ($balance < -0.004 ? 'balancecredit' : 'balancezero');
        return $OUTPUT->heading(get_string('studentaccount', $p), 3) . html_writer::tag('p', get_string(
            $state,
            $p,
            billing::money(abs($balance))
        ) . self::next_payment((int)$student->id) . ' ' . html_writer::link(
            new moodle_url('/local/studentrecords/account.php', ['id' => $student->id]),
            get_string('openaccount', $p)
        ));
    }

    /**
     * Attendance per course: present, late, absent, excused, rate, hours attended (clock hours), last date of
     * attendance, last Moodle course access.
     *
     * @param \stdClass $student
     * @return string HTML
     */
    public static function attendance(\stdClass $student): string {
        global $DB, $OUTPUT;
        $p = self::P;
        $regs = $DB->get_records_sql("SELECT r.*, o.termid FROM {" . registration::REG . "} r
                                        JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
                                       WHERE r.studentid = ? AND r.status <> 'dropped'", [$student->id]);
        $table = new html_table();
        $table->head = [get_string('hist_course', $p), get_string('term', $p), get_string('attend_present', $p),
            get_string('attend_late', $p), get_string('attend_absent', $p), get_string('attend_excused', $p),
            get_string('attendrate', $p), get_string('hoursattended', $p), get_string('lastattended', $p),
            get_string('lastmoodle', $p)];
        foreach ($regs as $reg) {
            $s = attendance::for_reg($reg, $student->userid ? (int)$student->userid : null);
            if (!$s['meetings'] && $s['moodle'] === '') {
                continue;
            }
            $offer = registration::offer((int)$reg->offerid);
            $hours = format_float($s['hours'], -1) . ($offer->unittype === 'clock' ? ' / ' . format_float($s['scheduled'], -1) :
                '');
            $show = fn($ymd) => $ymd !== '' ? catalog::show_date(catalog::date_to_time($ymd)) : '-';
            $table->data[] = [s($offer->code), format_string($offer->termname), $s['present'], $s['late'], $s['absent'],
                $s['excused'], $s['rate'] === null ? '-' : format_float($s['rate'], 1) . '%', $hours, $show($s['lda']),
                $show($s['moodle'])];
        }
        if (!$table->data) {
            return '';
        }
        return $OUTPUT->heading(get_string('attendance', $p), 3) . html_writer::table($table);
    }

    /**
     * The next plan payment as text (with a leading space), or ''.
     *
     * @param int $studentid
     * @return string
     */
    public static function next_payment(int $studentid): string {
        $next = plans::next_due($studentid);
        if (!$next) {
            return '';
        }
        return ' ' . get_string('nextplanpayment', self::P, (object)['amount' => billing::money((float)$next->amount -
            (float)$next->paid), 'date' => catalog::show_date((int)$next->duedate)]);
    }

    /**
     * Link to a part edit page.
     *
     * @param \stdClass $student
     * @param string $part
     * @param string $label language string
     * @param int $item
     * @return string HTML
     */
    protected static function edit_link(\stdClass $student, string $part, string $label, int $item = 0): string {
        return html_writer::link(new moodle_url('/local/studentrecords/studentpart.php', array_filter(['id' => $student->id,
            'part' => $part, 'item' => $item])), get_string($label, self::P), ['class' => 'btn btn-secondary btn-sm mb-3']);
    }

    /**
     * Registration and academic history: courses by term with status and grade, credits attempted and earned,
     * term and cumulative GPA; link to the Academic Transcript when that plugin is installed.
     *
     * @param \stdClass $student
     * @param bool $manage
     * @return string HTML
     */
    public static function academic(\stdClass $student, bool $manage): string {
        global $OUTPUT;
        $p = self::P;
        $out = $OUTPUT->heading(get_string('academichistory', $p), 3);
        $history = registration::history($student);
        $num = fn($v) => $v === null ? '-' : format_float($v, 2);
        $hours = fn($v) => format_float($v, -1);
        $link = fn($action, $reg, $label) => html_writer::link(new moodle_url(
            '/local/studentrecords/register.php',
            ['id' => $student->id, 'action' => $action, 'reg' => $reg]
        ), get_string($label, $p));
        foreach ($history['terms'] as $t) {
            $table = new html_table();
            $table->caption = format_string($t['term']);
            $table->head = [get_string('hist_course', $p), get_string('coursetitle', $p), get_string('section', $p),
                get_string('hours', $p), get_string('regstatus', $p), get_string('finalgrade', $p), ''];
            foreach ($t['rows'] as $r) {
                $actions = [];
                if ($manage && $r->status === 'registered') {
                    $actions[] = $link('drop', $r->id, 'action_drop');
                    $actions[] = $link('withdraw', $r->id, 'action_withdraw');
                }
                if ($manage && in_array($r->status, ['registered', 'completed'], true) && !registration::frozen((int)$r->offerid)) {
                    $actions[] = $link('grade', $r->id, 'action_grade');
                }
                if ($manage && $r->status === 'completed') {
                    $actions[] = html_writer::link(
                        new moodle_url('/local/studentrecords/gradechange.php', ['reg' => $r->id]),
                        get_string('requestchange', $p)
                    );
                }
                $table->data[] = [s($r->code), format_string($r->title), s($r->section), $hours($r->hours),
                    get_string('regstatus_' . $r->status, $p) . ($r->override ? ' *' : '') .
                    (!empty($r->accesspending) && $r->status === 'registered' ? ' ' .
                    html_writer::span(get_string('waitingpayment', $p), 'badge bg-warning text-dark') : ''), s($r->letter) .
                    ($r->passed === false ? ' ' . html_writer::span(get_string('notpassed', $p), 'badge bg-danger') : '') .
                    (!empty($r->repeatexcluded) ? ' ' . shell::tag(get_string('repeatnotcounted', $p)) : ''),
                    implode(' | ', $actions)];
            }
            $tt = $t['totals'];
            $table->data[] = [html_writer::tag('strong', get_string('termtotals', $p)), '', '',
                get_string('attemptedearned', $p, (object)['attempted' => $hours($tt['attempted']),
                'earned' => $hours($tt['earned'])]), '', get_string('gpa', $p) . ' ' . $num($tt['gpa']), ''];
            $out .= html_writer::table($table);
        }
        $out .= self::transfers($student, $manage, $history);
        if (!$history['terms'] && !$history['transfers']) {
            $out .= html_writer::tag('p', get_string('noregistrations', $p));
        } else {
            $c = $history['cumulative'];
            $out .= html_writer::tag('p', html_writer::tag('strong', get_string('cumulative', $p)) . ': ' .
                get_string('attemptedearned', $p, (object)['attempted' => $hours($c['attempted']),
                    'earned' => $hours($c['earned'])]) .
                ', ' . get_string('gpa', $p) . ' ' . $num($c['gpa']));
        }
        $buttons = '';
        if ($manage) {
            $buttons .= html_writer::link(new moodle_url('/local/studentrecords/register.php', ['id' => $student->id,
                'action' => 'add']), get_string('registerforcourse', $p), ['class' => 'btn btn-secondary btn-sm mb-3 me-2']);
        }
        if (\core_component::get_component_directory('local_academictranscript')) {
            $buttons .= html_writer::link(
                new moodle_url('/local/academictranscript/index.php', ['userid' => $student->userid]),
                get_string('academictranscript', $p),
                ['class' => 'btn btn-link btn-sm mb-3']
            );
        }
        return $out . $buttons;
    }

    /**
     * Transfer credit: list, totals and the program's maximum (warning when exceeded).
     *
     * @param \stdClass $student
     * @param bool $manage
     * @param array $history from registration::history()
     * @return string HTML
     */
    public static function transfers(\stdClass $student, bool $manage, array $history): string {
        global $DB, $OUTPUT;
        $p = self::P;
        $out = '';
        if ($history['transfers']) {
            $courses = $DB->get_records_menu('local_studentrecords_course', null, '', 'id, code');
            $table = new html_table();
            $table->caption = get_string('transfercredit', $p);
            $table->head = [get_string('xfer_institution', $p), get_string('xfer_extcourse', $p), get_string('hours', $p),
                get_string('finalgrade', $p), get_string('xfer_equiv', $p), get_string('xfer_countsprogram', $p),
                get_string('xfer_ingpa', $p), ''];
            $inprogram = 0.0;
            foreach ($history['transfers'] as $x) {
                $inprogram += $x->countsprogram ? (float)$x->credits : 0;
                $table->data[] = [s($x->institution), s((string)$x->extcourse), format_float($x->credits, -1), s($x->letter),
                    $x->equivcourseid ? s($courses[$x->equivcourseid] ?? '') : '', $x->countsprogram ? get_string('yes') :
                    get_string('no'), $x->ingpa ? get_string('yes') : get_string('no'), $manage ? html_writer::link(
                        new moodle_url('/local/studentrecords/studentpart.php', ['id' => $student->id, 'part' => 'xfer',
                        'item' => $x->id]),
                        get_string('edit')
                    ) : ''];
            }
            $out .= html_writer::table($table);
            $program = $student->programid ? $DB->get_record('local_studentrecords_program', ['id' => $student->programid]) : null;
            $rule = standing::rule($program ? (int)$program->levelid : null);
            if ($program && $program->totalhours && $rule && $rule['maxtransfer'] !== null) {
                $max = (float)$program->totalhours * (float)$rule['maxtransfer'] / 100;
                if ($inprogram > $max + 0.001) {
                    $out .= $OUTPUT->notification(get_string('xfer_overmax', $p, (object)['credits' => format_float($inprogram, -1),
                        'max' => format_float($max, -1), 'pct' => format_float($rule['maxtransfer'], -1)]), 'warning');
                }
            }
        }
        if ($manage) {
            $out .= html_writer::link(new moodle_url('/local/studentrecords/studentpart.php', ['id' => $student->id,
                'part' => 'xfer']), get_string('addtransfer', $p), ['class' => 'btn btn-secondary btn-sm mb-3 me-2']);
        }
        return $out;
    }

    /**
     * What staff must see before releasing information: directory opt-out, this year's FERPA notice, releases in force.
     *
     * @param \stdClass $student
     * @return string HTML
     */
    public static function release_check(\stdClass $student): string {
        global $OUTPUT;
        $p = self::P;
        $c = ferpa::release_check($student);
        $year = ferpa::current_year();
        $acked = $c['acknowledged'] ? get_string('rc_acked', $p, (object)['year' => $year,
            'date' => catalog::show_date((int)$c['acknowledged']->acknowledged)]) : get_string('rc_notacked', $p, $year);
        $lines = [get_string($c['optout'] ? 'rc_optout' : 'rc_nooptout', $p), $acked];
        if ($c['releases']) {
            foreach ($c['releases'] as $r) {
                $lines[] = get_string('rc_release', $p, (object)['who' => s($r->recipient), 'what' => s(implode(', ', array_map(
                    fn($k) => get_string('relscope_' . $k, $p),
                    explode(',', $r->scope)
                )))]);
            }
        } else {
            $lines[] = get_string('rc_noreleases', $p);
        }
        return $OUTPUT->notification(
            html_writer::tag('strong', get_string('releasecheck', $p)) . html_writer::alist($lines),
            $c['optout'] ? 'warning' : 'info'
        );
    }

    /**
     * For the student: a prompt to read this year's FERPA notice when not acknowledged yet.
     *
     * @param \stdClass $student
     * @return string HTML
     */
    public static function ferpa_prompt(\stdClass $student): string {
        global $OUTPUT, $USER;
        if (
            (int)$student->userid !== (int)$USER->id || catalog::setting('ferpanotice') === '' ||
                ferpa::acknowledgment((int)$student->id)
        ) {
            return '';
        }
        return $OUTPUT->notification(html_writer::link(
            new moodle_url('/local/studentrecords/ferpa.php'),
            get_string('ferpaprompt', self::P, ferpa::current_year())
        ), 'warning');
    }

    /**
     * FERPA notice acknowledgments, release authorizations and letters issued.
     *
     * @param \stdClass $student
     * @param bool $manage
     * @return string HTML
     */
    public static function ferpa_section(\stdClass $student, bool $manage): string {
        global $DB, $OUTPUT;
        $p = self::P;
        $out = $OUTPUT->heading(get_string('ferpareleases', $p), 3);
        $acks = $DB->get_records(ferpa::ACKS, ['studentid' => $student->id], 'acadyear DESC');
        $out .= html_writer::tag('p', $acks ? implode('<br>', array_map(fn($a) => get_string('ferpaackline', $p, (object)[
            'year' => s($a->acadyear), 'date' => catalog::show_date((int)$a->acknowledged),
            'method' => get_string('ferpamethod_' . $a->method, $p)]), $acks)) : get_string('ferpanoacks', $p));
        if ($manage && !ferpa::acknowledgment((int)$student->id)) {
            $out .= self::edit_link($student, 'ferpapaper', 'recordpaperack');
        }
        $releases = ferpa::releases((int)$student->id);
        if ($releases) {
            $table = new html_table();
            $table->head = [get_string('relrecipient', $p), get_string('emerg_relationship', $p), get_string('relscope', $p),
                get_string('holdfrom', $p), get_string('reltodate', $p), get_string('reldetails', $p), ''];
            foreach ($releases as $r) {
                $revoked = (object)['date' => catalog::show_date((int)$r->timerevoked),
                    'who' => s(history_view::user_name((int)$r->revokedby))];
                $revokeurl = new moodle_url('/local/studentrecords/studentpart.php', ['id' => $student->id, 'part' => 'revoke',
                    'item' => $r->id]);
                $state = $r->timerevoked ? get_string('removedonby', $p, $revoked) :
                    ($manage ? html_writer::link($revokeurl, get_string('revoke', $p)) : '');
                $table->data[] = [s($r->recipient), s((string)$r->relationship), s(implode(', ', array_map(
                    fn($k) => get_string('relscope_' . $k, $p),
                    explode(',', $r->scope)
                ))), catalog::show_date((int)$r->fromdate), $r->todate ? catalog::show_date((int)$r->todate) : '',
                    s((string)$r->details), $state];
            }
            $out .= html_writer::table($table);
        }
        if ($manage) {
            $out .= self::edit_link($student, 'relauth', 'addrelease');
            $letters = letters::issued((int)$student->id);
            if ($letters) {
                $items = array_map(fn($l) => html_writer::link(
                    new moodle_url('/local/studentrecords/letter.php', ['letter' => $l->id]),
                    s($l->letterno)
                ) . ' - ' . catalog::show_date((int)$l->timeissued) . ($l->recipient !== '' ? ' - ' .
                    s($l->recipient) : '') . ' - ' . s(history_view::user_name((int)$l->issuedby)), $letters);
                $out .= $OUTPUT->heading(get_string('lettersissued', $p), 4) . html_writer::alist($items);
            }
            $out .= html_writer::link(
                new moodle_url('/local/studentrecords/letter.php', ['id' => $student->id]),
                get_string('enrollmentletter', $p),
                ['class' => 'btn btn-secondary btn-sm mb-3']
            );
        }
        return $out;
    }

    /**
     * Degree audit link and graduation applications.
     *
     * @param \stdClass $student
     * @param bool $manage
     * @return string HTML
     */
    public static function graduation(\stdClass $student, bool $manage): string {
        global $OUTPUT;
        $p = self::P;
        $out = $OUTPUT->heading(get_string('graduation', $p), 3);
        $apps = graduation::applications((int)$student->id);
        if ($apps) {
            $terms = array_map('format_string', catalog::terms());
            $table = new html_table();
            $table->head = [get_string('date'), get_string('gradterm', $p), get_string('gradstate', $p),
                get_string('audit_result', $p), get_string('award', $p), get_string('notes', $p)];
            foreach ($apps as $a) {
                $table->data[] = [catalog::show_date((int)$a->timeapplied), $a->termid ? ($terms[$a->termid] ?? '') : '',
                    get_string('gradstate_' . $a->state, $p), $a->auditresult ? get_string('audit_' . $a->auditresult, $p) : '',
                    $a->conferred ? s(trim($a->credential . ($a->honors ? ', ' . $a->honors : ''))) . ' (' .
                    catalog::show_date((int)$a->conferred) . ')' : '', s(trim($a->reviewnote . ' ' . $a->note))];
            }
            $out .= html_writer::table($table);
        }
        $out .= html_writer::link(
            new moodle_url('/local/studentrecords/degreeaudit.php', ['id' => $student->id]),
            get_string('degreeaudit', $p),
            ['class' => 'btn btn-secondary btn-sm mb-3 me-2']
        );
        if ($manage) {
            $out .= self::edit_link($student, 'gradapp', 'applygraduation');
        }
        return $out;
    }

    /**
     * Holds: those in force first (warning), then released or ended ones.
     *
     * @param \stdClass $student
     * @param bool $manage
     * @return string HTML
     */
    public static function holds(\stdClass $student, bool $manage): string {
        global $OUTPUT;
        $p = self::P;
        $holds = academic::holds((int)$student->id);
        $out = $OUTPUT->heading(get_string('holds', $p), 3);
        $active = array_filter($holds, fn($h) => academic::hold_active($h, time()));
        if ($active) {
            $out .= $OUTPUT->notification(get_string('holdsactive', $p, implode(', ', array_map(
                fn($h) => get_string('holdtype_' . $h->holdtype, $p),
                $active
            ))), 'error');
        }
        if ($holds) {
            $table = new html_table();
            $table->head = [get_string('holdtype', $p), get_string('holdreason', $p), get_string('holdblocks', $p),
                get_string('holdfrom', $p), get_string('holdplacedby', $p), get_string('holdreleased', $p), ''];
            foreach ($holds as $h) {
                $released = $h->timereleased ? get_string('removedonby', $p, (object)['date' => catalog::show_date(
                    (int)$h->timereleased
                ), 'who' => s(history_view::user_name((int)$h->releasedby))]) . ($h->releasenote ? ': ' . s($h->releasenote) : '') :
                    ($h->enddate ? get_string('holduntil', $p, catalog::show_date((int)$h->enddate)) : '');
                $table->data[] = [get_string('holdtype_' . $h->holdtype, $p), nl2br(s((string)$h->reason)),
                    $h->blocksreg ? get_string('yes') : get_string('no'), catalog::show_date((int)$h->startdate),
                    s(history_view::user_name((int)$h->placedby)), $released, $manage && !$h->timereleased ?
                    html_writer::link(new moodle_url('/local/studentrecords/studentpart.php', ['id' => $student->id,
                    'part' => 'release', 'item' => $h->id]), get_string('releasehold', $p)) : ''];
            }
            $out .= html_writer::table($table);
        } else {
            $out .= html_writer::tag('p', get_string('none'));
        }
        return $out . ($manage ? self::edit_link($student, 'hold', 'placehold') : '');
    }

    /**
     * Academic standing per term (confirmed and proposed).
     *
     * @param \stdClass $student
     * @return string HTML
     */
    public static function standing_history(\stdClass $student): string {
        global $OUTPUT;
        $p = self::P;
        $rows = standing::history((int)$student->id);
        $out = $OUTPUT->heading(get_string('academicstanding', $p), 3);
        if (!$rows) {
            return $out . html_writer::tag('p', get_string('none'));
        }
        $num = fn($v, $d = 2) => $v === null ? '-' : format_float($v, $d);
        $table = new html_table();
        $table->head = [get_string('term', $p), get_string('standing', $p), get_string('gpa', $p), get_string('completionrate', $p),
            get_string('yearsenrolled', $p), get_string('standingreasons', $p), get_string('standingstate', $p)];
        foreach ($rows as $r) {
            $reasons = array_map(fn($k) => get_string('reason_' . $k, $p), array_filter(explode(',', (string)$r->reasons)));
            $table->data[] = [format_string($r->termname), get_string('standing_' . $r->standing, $p), $num($r->gpa),
                $r->completion === null ? '-' : $num($r->completion, 1) . '%', $num($r->years, 1), s(implode(', ', $reasons)),
                get_string('standingstate_' . $r->state, $p)];
        }
        return $out . html_writer::table($table);
    }

    /**
     * Application checklist.
     *
     * @param \stdClass $student
     * @param bool $manage
     * @return string HTML
     */
    public static function checklist(\stdClass $student, bool $manage): string {
        global $OUTPUT;
        $p = self::P;
        $docs = admissions::checklist($student);
        $out = $OUTPUT->heading(get_string('appchecklist', $p), 3);
        if (!$docs) {
            return $out . html_writer::tag('p', get_string('nochecklist', $p));
        }
        $states = [];
        foreach ($docs as $d) {
            $states[$d->id] = $d->state;
        }
        $missing = admissions::missing($docs, $states);
        if ($missing) {
            $out .= $OUTPUT->notification(get_string('missingdocs', $p, implode(', ', array_map(
                fn($d) => format_string($d->name),
                $missing
            ))), 'warning');
        }
        $table = new html_table();
        $table->head = [get_string('reqdoc_name', $p), get_string('docstate', $p), get_string('date'), get_string('notes', $p)];
        foreach ($docs as $d) {
            $class = $d->state === 'missing' ? ($d->required ? 'text-danger' : 'text-muted') : 'text-success';
            $table->data[] = [format_string($d->name) . ($d->required ? '' : ' (' . get_string('optional', $p) . ')'),
                html_writer::span(get_string('docstate_' . $d->state, $p), $class), catalog::show_date($d->statedate ?
                (int)$d->statedate : null), s($d->note)];
        }
        $out .= html_writer::table($table);
        return $out . ($manage ? self::edit_link($student, 'checklist', 'updatechecklist') : '');
    }

    /**
     * Admission decisions.
     *
     * @param \stdClass $student
     * @param bool $manage
     * @return string HTML
     */
    public static function decisions(\stdClass $student, bool $manage): string {
        global $OUTPUT;
        $p = self::P;
        $out = $OUTPUT->heading(get_string('decisions', $p), 3);
        $rows = admissions::decisions((int)$student->id);
        if ($rows) {
            $terms = catalog::terms();
            $table = new html_table();
            $table->head = [get_string('decisiondate', $p), get_string('decision', $p), get_string('admitterm', $p),
                get_string('decisionreason', $p), get_string('decisionconditions', $p), get_string('decidedby', $p)];
            foreach ($rows as $r) {
                $table->data[] = [catalog::show_date((int)$r->decisiondate), get_string('decision_' . $r->decision, $p),
                    $r->termid ? format_string($terms[$r->termid] ?? '') : '', nl2br(s((string)$r->reason)),
                    nl2br(s((string)$r->conditions)), s(history_view::user_name((int)$r->decidedby))];
            }
            $out .= html_writer::table($table);
        } else {
            $out .= html_writer::tag('p', get_string('none'));
        }
        return $out . ($manage ? self::edit_link($student, 'decision', 'recorddecision') : '');
    }

    /**
     * Status history.
     *
     * @param \stdClass $student
     * @return string HTML
     */
    public static function status_history(\stdClass $student): string {
        global $OUTPUT;
        $p = self::P;
        $rows = admissions::status_history((int)$student->id);
        $out = $OUTPUT->heading(get_string('statushistory', $p), 3);
        if (!$rows) {
            return $out . html_writer::tag('p', get_string('none'));
        }
        $status = fn($s) => $s ? get_string('status_' . $s, $p) : '';
        $table = new html_table();
        $table->head = [get_string('effective', $p), get_string('oldvalue', $p), get_string('newvalue', $p),
            get_string('notes', $p), get_string('changedby', $p), get_string('date')];
        foreach ($rows as $r) {
            $table->data[] = [catalog::show_date((int)$r->effective), $status($r->oldstatus), $status($r->newstatus), s($r->note),
                s(history_view::user_name((int)$r->userid)), userdate($r->timecreated)];
        }
        return $out . html_writer::table($table);
    }

    /**
     * Prior education.
     *
     * @param \stdClass $student
     * @param bool $manage
     * @return string HTML
     */
    public static function education(\stdClass $student, bool $manage): string {
        global $DB, $OUTPUT;
        $p = self::P;
        $out = $OUTPUT->heading(get_string('prioreducation', $p), 3);
        $rows = $DB->get_records('local_studentrecords_educ', ['studentid' => $student->id, 'active' => 1], 'completed DESC, id');
        $countries = get_string_manager()->get_list_of_countries();
        if ($rows) {
            $table = new html_table();
            $table->head = [get_string('educ_school', $p), get_string('educ_credential', $p), get_string('educ_completed', $p),
                get_string('country'), get_string('notes', $p), ''];
            foreach ($rows as $r) {
                $table->data[] = [s($r->school), s((string)$r->credential), catalog::show_date($r->completed ? (int)$r->completed :
                    null), s($countries[$r->country] ?? ''), s((string)$r->notes), $manage ? html_writer::link(new moodle_url(
                        '/local/studentrecords/studentpart.php',
                        ['id' => $student->id, 'part' => 'educ', 'item' => $r->id]
                    ), get_string('edit')) : ''];
            }
            $out .= html_writer::table($table);
        } else {
            $out .= html_writer::tag('p', get_string('none'));
        }
        return $out . ($manage ? self::edit_link($student, 'educ', 'addeduc') : '');
    }

    /**
     * Emergency contacts.
     *
     * @param \stdClass $student
     * @param bool $manage
     * @return string HTML
     */
    public static function emergency(\stdClass $student, bool $manage): string {
        global $DB, $OUTPUT;
        $p = self::P;
        $out = $OUTPUT->heading(get_string('emergencycontacts', $p), 3);
        $rows = $DB->get_records('local_studentrecords_emerg', ['studentid' => $student->id, 'active' => 1], 'sortorder, id');
        if ($rows) {
            $table = new html_table();
            $table->head = [get_string('name'), get_string('emerg_relationship', $p), get_string('phone', $p),
                get_string('email'), get_string('notes', $p), ''];
            foreach ($rows as $r) {
                $table->data[] = [s((string)$r->name), s((string)$r->relationship), s((string)$r->phone), s((string)$r->email),
                    s((string)$r->notes), $manage ? html_writer::link(new moodle_url(
                        '/local/studentrecords/studentpart.php',
                        ['id' => $student->id, 'part' => 'emerg', 'item' => $r->id]
                    ), get_string('edit')) : ''];
            }
            $out .= html_writer::table($table);
        } else {
            $out .= html_writer::tag('p', get_string('none'));
        }
        return $out . ($manage ? self::edit_link($student, 'emerg', 'addemerg') : '');
    }

    /**
     * Demographics (only with the view demographics capability).
     *
     * @param \stdClass $student
     * @param bool $manage
     * @return string HTML
     */
    public static function demographics(\stdClass $student, bool $manage): string {
        global $DB, $OUTPUT;
        $p = self::P;
        $out = $OUTPUT->heading(get_string('demographics', $p), 3);
        $d = $DB->get_record('local_studentrecords_demog', ['studentid' => $student->id]);
        $notgiven = html_writer::span(get_string('notgiven', $p), 'text-muted');
        $races = [];
        foreach (array_filter(explode(',', (string)($d->races ?? ''))) as $code) {
            $races[] = get_string('race_' . $code, $p);
        }
        $table = new html_table();
        $table->data = [
            [html_writer::tag('strong', get_string('demog_sex', $p)), !empty($d->sex) ? get_string('sex_' . $d->sex, $p) :
                $notgiven],
            [html_writer::tag('strong', get_string('demog_hispanic', $p)), !empty($d->hispanic) ?
                get_string('hispanic_' . $d->hispanic, $p) : $notgiven],
            [html_writer::tag('strong', get_string('demog_race', $p)), $races ? s(implode(', ', $races)) : $notgiven],
            [html_writer::tag('strong', get_string('ipedscategory', $p)), get_string('ipeds_' . admissions::ipeds_category(
                $d->hispanic ?? null,
                $d->races ?? null,
                !empty($student->isf1)
            ), $p)],
        ];
        $out .= html_writer::table($table);
        return $out . ($manage ? self::edit_link($student, 'demog', 'editdemog') : '');
    }
}
