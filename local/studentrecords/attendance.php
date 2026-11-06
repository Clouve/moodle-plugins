<?php

/**
 * Attendance: class meetings of a course offering (add one or a weekly schedule), and taking attendance for a meeting.
 * Records staff, or teachers of the offering's linked Moodle course. From a Moodle course: the offerings linked to it.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\access;
use local_studentrecords\local\attendance;
use local_studentrecords\local\catalog;
use local_studentrecords\local\registration;
use local_studentrecords\local\staffarea;

$offerid = optional_param('offer', 0, PARAM_INT);
$meetingid = optional_param('meeting', 0, PARAM_INT);
$courseid = optional_param('course', 0, PARAM_INT);
$p = 'local_studentrecords';
require_login(null, false);

if ($courseid) {
    // From a Moodle course: the offerings linked to it.
    $course = get_course($courseid);
    require_login($course);
    require_capability('local/studentrecords:takeattendance', context_course::instance($courseid));
    $PAGE->set_url(new moodle_url('/local/studentrecords/attendance.php', ['course' => $courseid]));
    $PAGE->set_title(get_string('attendance', $p));
    $PAGE->set_heading(format_string($course->fullname));
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('attendance', $p));
    $items = [];
    foreach ($DB->get_records('local_studentrecords_offer', ['moodlecourseid' => $courseid]) as $o) {
        $o = registration::offer((int)$o->id);
        $items[] = html_writer::link(
            new moodle_url('/local/studentrecords/attendance.php', ['offer' => $o->id]),
            s($o->code . ' ' . $o->section) . ', ' . format_string($o->termname)
        );
    }
    echo $items ? html_writer::alist($items) : html_writer::tag('p', get_string('noofferings', $p));
    echo $OUTPUT->footer();
    die();
}

if ($meetingid) {
    $meeting = $DB->get_record(attendance::MEETINGS, ['id' => $meetingid], '*', MUST_EXIST);
    $offerid = (int)$meeting->offerid;
}
$offer = registration::offer($offerid);
if (access::can_manage_students()) {
    $url = new moodle_url('/local/studentrecords/attendance.php', array_filter(['offer' => $meetingid ? 0 : $offerid,
        'meeting' => $meetingid]));
    staffarea::start_page($url);
    $PAGE->navbar->add(get_string('attendance', $p), $url);
    $header = fn() => staffarea::header();
} else {
    require_login($offer->moodlecourseid ? get_course((int)$offer->moodlecourseid) : null, false);
    $url = new moodle_url('/local/studentrecords/attendance.php', array_filter(['offer' => $meetingid ? 0 : $offerid,
        'meeting' => $meetingid]));
    $PAGE->set_url($url);
    $PAGE->set_heading(get_string('pluginname', $p));
    $header = fn() => $OUTPUT->header();
}
if (!attendance::can_take($offer)) {
    throw new required_capability_exception(context_system::instance(), 'local/studentrecords:takeattendance', 'nopermissions', '');
}
$PAGE->set_title(get_string('attendance', $p));
$title = s($offer->code . ' ' . $offer->section) . ', ' . format_string($offer->termname);
$offerurl = new moodle_url('/local/studentrecords/attendance.php', ['offer' => $offerid]);

if ($meetingid) {
    $sheet = attendance::sheet($meeting);
    if (optional_param('savemarks', 0, PARAM_BOOL)) {
        require_sesskey();
        $marks = [];
        foreach (array_keys($sheet) as $regid) {
            $status = optional_param('status_' . $regid, '', PARAM_ALPHA);
            $hours = trim(optional_param('hours_' . $regid, '', PARAM_RAW_TRIMMED));
            $marks[$regid] = ['status' => $status, 'hours' => $hours === '' || !is_numeric($hours) ? (float)$meeting->hours :
                (float)$hours, 'note' => optional_param('note_' . $regid, '', PARAM_TEXT)];
        }
        try {
            attendance::save_marks($meetingid, $marks);
            redirect($offerurl, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
        } catch (moodle_exception $e) {
            redirect($url, s($e->getMessage()), null, \core\output\notification::NOTIFY_ERROR);
        }
    }
    echo $header();
    $meets = \local_studentrecords\local\selfreg::meets_text($offer, true);
    echo $OUTPUT->heading(get_string('att_title', $p, s($offer->code . ' ' . $offer->section) . ' '
        . format_string($offer->title)));
    echo html_writer::tag('p', userdate(
        (int)$meeting->meetingdate,
        get_string('strftimedaydate', 'langconfig'),
        catalog::timezone()
    ) . ($meets !== '' ? ' · ' . s($meets) : '') . ' · ' . get_string(
        'att_hours',
        $p,
        format_float((float)$meeting->hours, -1)
    ) . ' · ' . get_string('att_students', $p, count($sheet)), ['class' => 'sr-lead']);
    if (catalog::time_to_date((int)$meeting->meetingdate) > catalog::time_to_date(time())) {
        echo $OUTPUT->notification(get_string('err_futuremeeting', $p), 'info');
    }
    if (!$sheet) {
        echo \local_studentrecords\local\shell::empty_state(get_string('noregistered', $p));
        echo $OUTPUT->footer();
        die();
    }
    $rows = '';
    foreach ($sheet as $regid => $r) {
        $name = attendance::sheet_name($r);
        $rate = attendance::for_reg((object)['id' => $regid, 'offerid' => $meeting->offerid], null)['rate'];
        $choices = '';
        foreach (attendance::STATUSES as $st) {
            $id = 'st_' . $regid . '_' . $st;
            $attrs = ['type' => 'radio', 'name' => 'status_' . $regid, 'value' => $st, 'id' => $id, 'class' => 'sr-seg-input',
                'data-hours' => in_array($st, ['absent', 'excused'], true) ? '0' : format_float((float)$meeting->hours, -1)];
            if ((string)$r->status === $st) {
                $attrs['checked'] = 'checked';
            }
            $choices .= html_writer::empty_tag('input', $attrs) . html_writer::label(
                get_string('attend_' . $st, $p),
                $id,
                false,
                ['class' => 'sr-seg-label sr-seg-' . $st]
            );
        }
        $legend = html_writer::tag('legend', s($name), ['class' => 'sr-att-name']) .
            html_writer::div(get_string('att_termrate', $p, $rate === null ? '-' : format_float($rate, 0) . '%'), 'sr-msg-meta');
        $hours = html_writer::label(get_string('hoursattended', $p), 'h_' . $regid, false, ['class' => 'sr-only visually-hidden']) .
            html_writer::empty_tag('input', ['type' => 'text', 'inputmode' => 'decimal', 'name' => 'hours_' . $regid,
            'id' => 'h_' . $regid, 'class' => 'form-control sr-att-hours',
                'value' => $r->markid ? format_float((float)$r->hours, -1, false) :
            '', 'placeholder' => format_float((float)$meeting->hours, -1, false)]);
        $note = html_writer::label(get_string('note', $p), 'n_' . $regid, false, ['class' => 'sr-only visually-hidden']) .
            html_writer::empty_tag('input', ['type' => 'text', 'name' => 'note_' . $regid, 'id' => 'n_' . $regid,
            'class' => 'form-control', 'value' => (string)$r->note, 'placeholder' => get_string('att_optional', $p)]);
        $rows .= html_writer::tag('fieldset', html_writer::div($legend, 'sr-att-who') . html_writer::div($choices, 'sr-seg-group') .
            html_writer::div($hours, 'sr-att-h') . html_writer::div($note, 'sr-att-note'), ['class' => 'sr-att-row']);
    }
    $head = html_writer::div(
        html_writer::span(get_string('home_student', $p)) . html_writer::span(get_string('status', $p)) .
        html_writer::span(get_string('hours', $p)) . html_writer::span(get_string('note', $p)),
        'sr-att-head',
        ['aria-hidden' => 'true']
    );
    $form = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'meeting', 'value' => $meetingid]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'savemarks', 'value' => 1]);
    $form .= html_writer::div(html_writer::tag('button', get_string('att_allpresent', $p), ['type' => 'button',
        'class' => 'btn btn-outline-secondary', 'data-action' => 'sr-allpresent']), 'sr-actions justify-content-end mt-0');
    $form .= $head . $rows;
    $form .= html_writer::div(
        html_writer::tag('button', get_string('saveattendance', $p), ['type' => 'submit',
        'class' => 'btn btn-primary']) . html_writer::span('', 'sr-att-summary sr-muted', ['aria-live' => 'polite']),
        'sr-att-save'
    );
    echo html_writer::tag('form', $form, ['method' => 'post', 'action' => $url->out_omit_querystring(), 'class' => 'sr-att']);
    echo html_writer::tag(
        'p',
        get_string('marks_help', $p, format_float((float)$meeting->hours, -1)),
        ['class' => 'sr-muted small']
    );
    $PAGE->requires->js_amd_inline("
        (function() {
            const form = document.querySelector('form.sr-att');
            if (!form) {
                return;
            }
            const words = " . json_encode(array_combine(attendance::STATUSES, array_map(
        fn($st) => get_string('attend_' . $st, $p),
        attendance::STATUSES
    ))) . ";
            const summary = function() {
                const counts = {};
                form.querySelectorAll('input.sr-seg-input:checked').forEach(function(i) {
                    counts[i.value] = (counts[i.value] || 0) + 1;
                });
                form.querySelector('.sr-att-summary').textContent = Object.keys(words).filter(function(k) {
                    return counts[k];
                }).map(function(k) {
                    return counts[k] + ' ' + words[k].toLowerCase();
                }).join(' · ');
            };
            form.addEventListener('change', function(e) {
                if (e.target.classList.contains('sr-seg-input')) {
                    const row = e.target.closest('.sr-att-row');
                    const h = row.querySelector('.sr-att-hours');
                    if (h && (e.target.value === 'absent' || e.target.value === 'excused')) {
                        h.value = '0';
                    } else if (h && h.value === '0') {
                        h.value = '';
                    }
                    summary();
                }
            });
            form.querySelector('[data-action=sr-allpresent]').addEventListener('click', function() {
                form.querySelectorAll('input.sr-seg-input[value=present]').forEach(function(i) {
                    i.checked = true;
                    const h = i.closest('.sr-att-row').querySelector('.sr-att-hours');
                    if (h && h.value === '0') {
                        h.value = '';
                    }
                });
                summary();
            });
            summary();
        })();
    ");
    echo $OUTPUT->footer();
    die();
}

$forms = [];
foreach (['one', 'weekly'] as $mode) {
    $forms[$mode] = new \local_studentrecords\form\meeting(new moodle_url($offerurl, ['mode' => $mode]), ['mode' => $mode]);
    $forms[$mode]->set_data(['offer' => $offerid, 'mode' => $mode, 'meetingdate' => time()]);
}
$mode = optional_param('mode', '', PARAM_ALPHA);
if (isset($forms[$mode]) && ($data = $forms[$mode]->get_data())) {
    if ($mode === 'weekly') {
        $days = array_values(array_filter(range(1, 7), fn($d) => !empty($data->{'day' . $d})));
        $n = attendance::add_weekly($offerid, $days, (float)$data->hours);
        redirect($offerurl, get_string('meetingsadded', $p, $n), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $id = attendance::add_meeting(
        $offerid,
        catalog::time_to_date((int)$data->meetingdate),
        (float)$data->hours,
        (string)$data->topic
    );
    redirect($offerurl, get_string($id ? 'meetingsadded' : 'meetingexists', $p, 1), null, $id ?
        \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
}

echo $header();
echo $OUTPUT->heading(get_string('attendance', $p) . ': ' . $title);
$table = new html_table();
$table->head = [get_string('date'), get_string('meetinghours', $p), get_string('meetingtopic', $p),
    get_string('markedcount', $p), ''];
$today = catalog::time_to_date(time());
foreach (attendance::meetings($offerid) as $m) {
    $table->data[] = [catalog::show_date((int)$m->meetingdate), format_float((float)$m->hours, -1), s((string)$m->topic),
        (int)$m->marked, catalog::time_to_date((int)$m->meetingdate) <= $today ? html_writer::link(
            new moodle_url('/local/studentrecords/attendance.php', ['meeting' => $m->id]),
            get_string('takeattendance', $p)
        ) : get_string('notyet', $p)];
}
echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('nomeetings', $p));
echo $OUTPUT->heading(get_string('addmeeting', $p), 3);
$forms['one']->display();
echo $OUTPUT->heading(get_string('addweekly', $p), 3);
echo html_writer::tag('p', get_string('addweekly_intro', $p));
$forms['weekly']->display();
echo $OUTPUT->footer();
