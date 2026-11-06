<?php

/**
 * Course offerings per term: list, add and edit, and the roster of one offering (final grades from Moodle).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\access;
use local_studentrecords\local\catalog;
use local_studentrecords\local\records;
use local_studentrecords\local\registration;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\students;

$termid = optional_param('termid', 0, PARAM_INT);
$edit = optional_param('edit', -1, PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
require_login(null, false);
staffarea::setup('offerings', new moodle_url('/local/studentrecords/offerings.php', array_filter(['termid' => $termid])));
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/offerings.php');
$terms = array_map('format_string', catalog::terms());

if ($edit >= 0) {
    $PAGE->set_url(new moodle_url($url, ['edit' => $edit]));
    $form = new \local_studentrecords\form\offer(new moodle_url($url, ['edit' => $edit]));
    if ($edit) {
        $rec = $DB->get_record(registration::OFFER, ['id' => $edit], '*', MUST_EXIST);
        foreach (['facultyid', 'moodlecourseid'] as $f) {
            $rec->$f = (int)$rec->$f;
        }
        $rec->capacity = $rec->capacity === null ? '' : (string)$rec->capacity;
        foreach (explode(',', (string)$rec->meetdays) as $d) {
            if ($d !== '') {
                $rec->{'day_' . $d} = 1;
            }
        }
        $form->set_data($rec);
    } else if ($termid) {
        $form->set_data(['termid' => $termid]);
    }
    if ($form->is_cancelled()) {
        redirect(new moodle_url($url, array_filter(['termid' => $termid])));
    }
    if ($data = $form->get_data()) {
        $record = (array)$data;
        $record['section'] = trim($record['section']);
        $record['capacity'] = $record['capacity'] === '' ? null : (int)$record['capacity'];
        foreach (['facultyid', 'moodlecourseid'] as $f) {
            $record[$f] = empty($record[$f]) ? null : (int)$record[$f];
        }
        $days = [];
        foreach (\local_studentrecords\local\selfreg::DAYS as $d) {
            if (!empty($record['day_' . $d])) {
                $days[] = $d;
            }
            unset($record['day_' . $d]);
        }
        $record['meetdays'] = $days ? implode(',', $days) : null;
        foreach (['starttime', 'endtime', 'room'] as $f) {
            $record[$f] = trim((string)$record[$f]) === '' ? null : trim((string)$record[$f]);
        }
        unset($record['notqualifiedok'], $record['submitbutton']);
        if (!$edit) {
            unset($record['id']);
        }
        $newid = records::save(registration::OFFER, $record);
        // More seats (or a reopened section) go to students on the waitlist first.
        \local_studentrecords\local\waitlist::seat_opened($newid);
        \local_studentrecords\local\audit::log($edit ? 'update' : 'create', 'offering', $newid);
        redirect(new moodle_url($url, ['id' => $newid]), get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo staffarea::header();
    echo $OUTPUT->heading(get_string($edit ? 'editoffering' : 'addoffering', $p));
    $form->display();
    echo $OUTPUT->footer();
    die();
}

if ($id) {
    $offer = registration::offer($id);
    $PAGE->set_url(new moodle_url($url, ['id' => $id]));
    // The setup page shows the section to anyone with configure. Who is in it, and their grades, need a student capability.
    $seestudents = access::sees_all_students();
    $managegrades = access::can_manage_students();
    if ($action === 'moodlegrades') {
        require_sesskey();
        require_capability('local/studentrecords:managestudents', context_system::instance());
        [$set, $kept, $none, $frozen] = registration::grades_from_moodle($id);
        if ($frozen) {
            $error = \core\output\notification::NOTIFY_ERROR;
            redirect(new moodle_url($url, ['id' => $id]), get_string('err_frozen', $p), null, $error);
        }
        redirect(new moodle_url($url, ['id' => $id]), get_string('moodlegradesdone', $p, (object)['set' => $set, 'kept' => $kept,
            'none' => $none]), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo staffarea::header();
    echo $OUTPUT->heading(s($offer->code . ' ' . $offer->section) . ' - ' . format_string($offer->title) . ' (' .
        format_string($offer->termname) . ')');
    $instructor = $offer->facultyid ? $DB->get_record('local_studentrecords_faculty', ['id' => $offer->facultyid]) : null;
    $moodle = $offer->moodlecourseid ? $DB->get_record('course', ['id' => $offer->moodlecourseid], 'id, shortname') : null;
    $facts = [
        get_string('instructor', $p) => $instructor ? s(catalog::faculty_name($instructor)) : '',
        get_string('capacity', $p) => ($offer->capacity ?? '-') . ' / ' . get_string('seatstaken', $p, registration::taken($id)),
        get_string('hours', $p) => format_float($offer->hours, -1) . ' ' . get_string('unit_' . $offer->unittype, $p),
        get_string('moodlecourse', $p) => $moodle ? html_writer::link(
            new moodle_url('/course/view.php', ['id' => $moodle->id]),
            format_string($moodle->shortname)
        ) : get_string('none'),
        get_string('status', $p) => get_string('offerstatus_' . $offer->status, $p),
        get_string('home_meets', $p) => s(\local_studentrecords\local\selfreg::meets_text($offer, true) ?: '-'),
        get_string('waitlist', $p) => count(\local_studentrecords\local\waitlist::for_offer($id)),
    ];
    $table = new html_table();
    foreach ($facts as $k => $v) {
        $table->data[] = [html_writer::tag('strong', $k), $v];
    }
    echo html_writer::table($table);
    $waiting = $seestudents ? \local_studentrecords\local\waitlist::for_offer($id) : [];
    if ($waiting) {
        echo $OUTPUT->heading(get_string('waitlist', $p), 3);
        $wt = new html_table();
        $wt->head = ['#', get_string('student', $p), get_string('status', $p), get_string('date')];
        $n = 0;
        foreach ($waiting as $w) {
            $ws = students::get((int)$w->studentid);
            if (access::hide_directory($ws)) {
                $ws->preferredname = null;
            }
            $wt->data[] = [++$n, s(students::display_name($ws)) . ' (' . s($ws->studentid) . ')',
                get_string('waitstatus_' . $w->status, $p), userdate((int)$w->timecreated, get_string(
                    'strftimedatetimeshort',
                    'langconfig'
                ))];
        }
        echo html_writer::table($wt);
    }
    echo html_writer::link(new moodle_url($url, ['edit' => $id]), get_string('editoffering', $p), [
        'class' => 'btn btn-secondary mb-3 me-2']);
    if ($moodle && $managegrades) {
        echo $OUTPUT->single_button(
            new moodle_url($url, ['id' => $id, 'action' => 'moodlegrades', 'sesskey' => sesskey()]),
            get_string('moodlegrades', $p),
            'post',
            ['class' => 'd-inline-block mb-3']
        );
    }
    echo html_writer::link(
        new moodle_url('/local/studentrecords/attendance.php', ['offer' => $id]),
        get_string('attendance', $p),
        ['class' => 'btn btn-secondary mb-3 ms-2']
    );
    if (!$seestudents) {
        echo $OUTPUT->footer();
        die();
    }
    echo $OUTPUT->heading(get_string('classroster', $p), 3);
    $regs = $DB->get_records_sql("SELECT r.*, s.studentid AS sid, s.legalfirstname, s.legalmiddlename, s.legallastname,
                                         s.preferredname, s.userid, s.ferpaoptout
                                    FROM {" . registration::REG . "} r
                                    JOIN {" . students::TABLE . "} s ON s.id = r.studentid
                                   WHERE r.offerid = ?
                                ORDER BY s.legallastname, s.legalfirstname", [$id]);
    $table = new html_table();
    $table->head = [get_string('studentid', $p), get_string('name'), get_string('regstatus', $p), get_string('finalgrade', $p),
        get_string('gradesource', $p)];
    foreach ($regs as $r) {
        if (access::hide_directory($r)) {
            $r->preferredname = null;
        }
        $table->data[] = [s($r->sid), html_writer::link(new moodle_url(
            '/local/studentrecords/student.php',
            ['id' => $r->studentid]
        ), s(students::display_name($r))), get_string('regstatus_' . $r->status, $p),
            s((string)$r->grade) . ($r->gradepct !== null ? ' (' . format_float($r->gradepct, 1) . '%)' : ''),
            $r->gradesource ? get_string('gradesource_' . $r->gradesource, $p) : ''];
    }
    echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('none'));
    echo $OUTPUT->footer();
    die();
}

// Copy from last term: the sections of the term before the chosen one (not already there), without Moodle course.
$copy = optional_param('copy', 0, PARAM_BOOL);
if ($copy && $termid) {
    $from = registration::previous_term($termid);
    if ($from && optional_param('confirm', 0, PARAM_BOOL)) {
        require_sesskey();
        $n = registration::copy_sections((int)$from->id, $termid);
        redirect(
            new moodle_url($url, ['termid' => $termid]),
            get_string('off_copied', $p, $n),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo staffarea::header();
    if (!$from) {
        echo $OUTPUT->notification(get_string('off_nofrom', $p), 'info');
    } else {
        echo $OUTPUT->confirm(
            get_string('off_copyconfirm', $p, (object)['from' => format_string($from->name), 'to' => $terms[$termid] ?? '',
                'n' => count(registration::sections_to_copy((int)$from->id, $termid))]),
            new moodle_url($url, ['termid' => $termid, 'copy' => 1, 'confirm' => 1, 'sesskey' => sesskey()]),
            new moodle_url($url, ['termid' => $termid])
        );
    }
    echo $OUTPUT->footer();
    die();
}

echo staffarea::header();
$q = trim(optional_param('q', '', PARAM_TEXT));
$shell = \local_studentrecords\local\shell::class;
$buttons = '';
if ($termid) {
    $buttons .= html_writer::link(
        new moodle_url($url, ['termid' => $termid, 'copy' => 1]),
        get_string('off_copy', $p),
        ['class' => 'btn btn-outline-secondary']
    );
}
$buttons .= html_writer::link(
    new moodle_url($url, array_filter(['edit' => 0, 'termid' => $termid], fn($v) => $v !== null)),
    get_string('off_addsection', $p),
    ['class' => 'btn btn-primary']
);
echo html_writer::div($buttons, 'sr-actions justify-content-end mt-0');
$termoptions = '';
foreach ([0 => get_string('all')] + $terms as $tid => $tname) {
    $termoptions .= html_writer::tag('option', $tname, ['value' => $tid] + ((int)$tid === $termid ? ['selected' => 'selected'] :
        []));
}
$filter = html_writer::div(html_writer::label(get_string('term', $p), 'offterm') . html_writer::tag(
    'select',
    $termoptions,
    ['name' => 'termid', 'id' => 'offterm', 'class' => 'form-select']
), 'sr-field');
$filter .= html_writer::div(
    html_writer::label(get_string('search'), 'offq') . html_writer::empty_tag('input', ['type' => 'search',
    'name' => 'q', 'id' => 'offq', 'value' => $q, 'class' => 'form-control', 'placeholder' => get_string('off_searchhint', $p)]),
    'sr-field sr-field-grow'
);
$filter .= html_writer::div(html_writer::tag('button', get_string('search'), ['type' => 'submit',
    'class' => 'btn btn-outline-secondary']), 'sr-field');
echo html_writer::tag('form', $filter, ['method' => 'get', 'action' => $url->out_omit_querystring(), 'class' => 'sr-filter']);
$table = new html_table();
$table->attributes['class'] = 'sr-table';
$table->head = [get_string('hist_course', $p), get_string('off_sec', $p), get_string('home_teacher', $p),
    get_string('home_meets', $p), get_string('room', $p), get_string('off_seats', $p), get_string('status', $p),
    get_string('attendance', $p)];
$faculty = catalog::faculty('');
foreach (registration::offers($termid) as $o) {
    $teacher = $o->facultyid ? (string)($faculty[$o->facultyid] ?? '') : '';
    $hay = $o->code . ' ' . $o->title . ' ' . $teacher . ' ' . (string)$o->room;
    if ($q !== '' && stripos($hay, $q) === false) {
        continue;
    }
    $waiting = count(\local_studentrecords\local\waitlist::for_offer((int)$o->id));
    $full = $o->capacity !== null && (int)$o->taken >= (int)$o->capacity;
    if ($o->status !== 'open') {
        $status = $shell::tag(get_string('offerstatus_' . $o->status, $p), 'neutral');
    } else if (!$o->facultyid) {
        $status = $shell::tag(get_string('off_needsteacher', $p), 'bad');
    } else if ($full) {
        $status = $shell::tag(get_string('off_fullwait', $p, $waiting), 'warn');
    } else {
        $status = $shell::tag(get_string('offerstatus_open', $p), 'good');
    }
    $pct = $o->capacity ? (int)min(100, round((int)$o->taken / max(1, (int)$o->capacity) * 100)) : 0;
    $seats = (int)$o->taken . ($o->capacity !== null ? ' ' . get_string('off_of', $p, (int)$o->capacity) : '') .
        ($o->capacity ? html_writer::div(
            html_writer::span('', 'sr-progress-bar', ['style' => 'width:' . $pct . '%']),
            'sr-progress sr-progress-thin',
            ['aria-hidden' => 'true']
        ) : '');
    $table->data[] = [
        html_writer::link(new moodle_url($url, ['id' => $o->id]), html_writer::tag('strong', s($o->code)) . ' ' .
            format_string($o->title)) . ($termid ? '' : html_writer::div(format_string($o->termname), 'sr-msg-meta')),
        s($o->section),
        $teacher !== '' ? s($teacher) : html_writer::span(get_string('off_noteacher', $p), 'sr-tone-bad'),
        s(\local_studentrecords\local\selfreg::meets_text($o) ?: '-'),
        s((string)($o->room ?? '')),
        $seats,
        $status,
        html_writer::link(
            new moodle_url('/local/studentrecords/attendance.php', ['offer' => $o->id]),
            get_string('attendance', $p)
        ),
    ];
}
echo $table->data ? html_writer::div(html_writer::table($table), 'table-responsive') :
    $shell::empty_state(get_string('noofferings', $p));
echo html_writer::tag('p', get_string('off_note', $p), ['class' => 'sr-muted']);
echo $OUTPUT->footer();
