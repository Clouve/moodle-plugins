<?php

/**
 * Academic standing per term: run (proposes each registered student's standing), then staff confirm (and may choose
 * another standing). Confirmed standings are kept as the history.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\catalog;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\standing;
use local_studentrecords\local\students;

$termid = optional_param('termid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$p = 'local_studentrecords';
require_login(null, false);
staffarea::setup('standing', new moodle_url('/local/studentrecords/standing.php', array_filter(['termid' => $termid])));
$url = new moodle_url('/local/studentrecords/standing.php', ['termid' => $termid]);
$terms = array_map('format_string', catalog::terms());

if ($termid && $action) {
    require_sesskey();
    if ($action === 'run') {
        [$proposed, $kept] = standing::run($termid);
        redirect(
            $url,
            get_string('standingrun', $p, (object)['proposed' => $proposed, 'kept' => $kept]),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    if ($action === 'confirm') {
        $ids = optional_param_array('ids', [], PARAM_INT);
        $changes = optional_param_array('standing', [], PARAM_ALPHA);
        $n = standing::confirm($ids, $changes, optional_param('note', '', PARAM_TEXT));
        redirect($url, get_string('standingconfirmed', $p, $n), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo staffarea::header();
echo html_writer::tag('p', get_string('standing_intro', $p));
echo $OUTPUT->single_select(new moodle_url('/local/studentrecords/standing.php'), 'termid', [0 => get_string('choosedots')] +
    $terms, $termid, null, null, ['label' => get_string('term', $p)]);
if (has_capability('local/studentrecords:configure', context_system::instance())) {
    echo html_writer::div(html_writer::link(
        new moodle_url('/local/studentrecords/saprules.php'),
        get_string('saprules', $p)
    ), 'my-2');
}
if ($termid) {
    echo $OUTPUT->single_button(
        new moodle_url($url, ['action' => 'run', 'sesskey' => sesskey()]),
        get_string('runstanding', $p),
        'post'
    );
    $rows = $DB->get_records_sql("SELECT st.*, s.studentid AS sid, s.legalfirstname, s.legalmiddlename, s.legallastname,
                                         s.preferredname
                                    FROM {" . standing::TABLE . "} st
                                    JOIN {" . students::TABLE . "} s ON s.id = st.studentid
                                   WHERE st.termid = ?
                                ORDER BY s.legallastname, s.legalfirstname", [$termid]);
    $options = [];
    foreach (standing::LADDER as $l) {
        $options[$l] = get_string('standing_' . $l, $p);
    }
    $num = fn($v, $d = 2) => $v === null ? '-' : format_float($v, $d);
    $table = new html_table();
    $table->head = ['', get_string('studentid', $p), get_string('name'), get_string('gpa', $p),
        get_string('completionrate', $p), get_string('yearsenrolled', $p), get_string('previousstanding', $p),
        get_string('standing', $p), get_string('standingreasons', $p), get_string('standingstate', $p)];
    foreach ($rows as $r) {
        $proposed = $r->state === 'proposed';
        $reasons = array_map(fn($k) => get_string('reason_' . $k, $p), array_filter(explode(',', (string)$r->reasons)));
        $table->data[] = [
            $proposed ? html_writer::checkbox('ids[]', $r->id, true, '', ['aria-label' => students::display_name($r)]) : '',
            s($r->sid), html_writer::link(
                new moodle_url('/local/studentrecords/student.php', ['id' => $r->studentid]),
                s(students::display_name($r))
            ), $num($r->gpa), $r->completion === null ? '-' : $num($r->completion, 1) . '%',
            $num($r->years, 1), $r->previous ? get_string('standing_' . $r->previous, $p) : '-',
            $proposed ? html_writer::select($options, 'standing[' . $r->id . ']', $r->standing, false, [
                'aria-label' => get_string('standing', $p)]) : get_string('standing_' . $r->standing, $p),
            s(implode(', ', $reasons)), get_string('standingstate_' . $r->state, $p)];
    }
    if ($table->data) {
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'confirm']);
        echo html_writer::table($table);
        echo html_writer::label(get_string('notes', $p), 'stnote', true, ['class' => 'me-2']);
        echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'note', 'id' => 'stnote',
            'class' => 'form-control d-inline-block w-auto me-2']);
        echo html_writer::tag('button', get_string('confirmstanding', $p), ['type' => 'submit', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
    } else {
        echo $OUTPUT->notification(get_string('nostanding', $p), 'info');
    }
}
echo $OUTPUT->footer();
