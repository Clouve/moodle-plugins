<?php

/**
 * Programs: list, and one program with its outline (courses by group).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\catalog;
use local_studentrecords\local\records;

$id = optional_param('id', -1, PARAM_INT);
$edit = optional_param('edit', 0, PARAM_BOOL);
require_login(null, false);
\local_studentrecords\local\staffarea::setup('programs');
require_capability('local/studentrecords:configure', context_system::instance());
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/programs.php');
$PAGE->set_url($url);
$num = fn($v) => $v === null || $v === '' ? '' : format_float($v, -1);

if ($id === 0 || ($id > 0 && $edit)) {
    $form = new \local_studentrecords\form\program(new moodle_url($url, ['id' => $id, 'edit' => 1]));
    if ($id) {
        $rec = $DB->get_record('local_studentrecords_program', ['id' => $id], '*', MUST_EXIST);
        foreach (['totalhours', 'trackhours', 'mingpa', 'residenthours'] as $f) {
            $rec->$f = $rec->$f === null ? '' : format_float($rec->$f, -1, false);
        }
        $form->set_data($rec);
    }
    if ($form->is_cancelled()) {
        redirect($id ? new moodle_url($url, ['id' => $id]) : $url);
    }
    if ($data = $form->get_data()) {
        $data->code = trim($data->code);
        $data->levelid = $data->levelid ?: null;
        $data->deliverymode = $data->deliverymode ?: null;
        $data->location = trim($data->location) ?: null;
        foreach (['totalhours', 'trackhours', 'mingpa', 'residenthours'] as $f) {
            $data->$f = $data->$f === '' ? null : (float)$data->$f;
        }
        if (!$id) {
            unset($data->id);
        }
        $newid = records::save('local_studentrecords_program', $data);
        \local_studentrecords\local\audit::log($id ? 'update' : 'create', 'program', $newid);
        redirect(new moodle_url($url, ['id' => $newid]), get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo \local_studentrecords\local\staffarea::header();
    echo $OUTPUT->heading(get_string($id ? 'editprogram' : 'addprogram', $p));
    $form->display();
    echo $OUTPUT->footer();
    die();
}

if ($id > 0) {
    $prog = $DB->get_record('local_studentrecords_program', ['id' => $id], '*', MUST_EXIST);
    $form = new \local_studentrecords\form\progcourse(new moodle_url($url, ['id' => $id]));
    $form->set_data(['id' => $id]);
    if ($data = $form->get_data()) {
        $group = trim((string)$data->groupname);
        if (
            !$DB->record_exists('local_studentrecords_progcrs', ['programid' => $id, 'courseid' => $data->courseid,
                'groupname' => $group])
        ) {
            records::save('local_studentrecords_progcrs', ['programid' => $id, 'courseid' => $data->courseid,
                'groupname' => $group, 'requirement' => $data->requirement,
                'sortorder' => $DB->count_records('local_studentrecords_progcrs', ['programid' => $id]) + 1]);
            \local_studentrecords\local\audit::log('update', 'program', $id, null, 'course added');
        }
        redirect(new moodle_url($url, ['id' => $id]));
    }
    echo \local_studentrecords\local\staffarea::header();
    echo $OUTPUT->heading(s($prog->code) . ' - ' . format_string($prog->name));
    $levels = catalog::levels();
    $unit = get_string('unit_' . $prog->unittype, $p);
    $facts = [
        get_string('prog_school', $p) => s((string)$prog->school),
        get_string('level', $p) => $prog->levelid ? format_string($levels[$prog->levelid] ?? '') : '',
        get_string('prog_credential', $p) => s((string)$prog->credential) . ' (' .
            get_string('cred_' . ($prog->credentialtype ?: 'other'), $p) . ', ' .
            get_string($prog->isdegree ? 'degree' : 'nondegree', $p) . ')',
        get_string('unittype', $p) => $unit,
        get_string('prog_totalhours', $p) => $num($prog->totalhours),
        get_string('prog_trackhours', $p) => $num($prog->trackhours),
        get_string('prog_mingpa', $p) => $num($prog->mingpa),
        get_string('prog_residenthours', $p) => $num($prog->residenthours),
        get_string('prog_deliverymode', $p) => $prog->deliverymode ? get_string('delivery_' . $prog->deliverymode, $p) : '',
        get_string('prog_location', $p) => s((string)$prog->location),
        get_string('prog_delivery', $p) => s((string)$prog->delivery),
        get_string('prog_maxtime', $p) => s((string)$prog->maxtime),
        get_string('prog_cipcode', $p) => s((string)$prog->cipcode),
        get_string('prog_graderules', $p) => s((string)$prog->graderules),
    ];
    $table = new html_table();
    foreach ($facts as $k => $v) {
        $table->data[] = [html_writer::tag('strong', $k), $v];
    }
    echo html_writer::table($table);
    echo html_writer::link(
        new moodle_url($url, ['id' => $id, 'edit' => 1]),
        get_string('editprogram', $p),
        ['class' => 'btn btn-secondary mb-3']
    );

    echo $OUTPUT->heading(get_string('outline', $p), 3);
    $sql = "SELECT pc.*, c.code, c.title, c.hours, c.unittype
              FROM {local_studentrecords_progcrs} pc
              JOIN {local_studentrecords_course} c ON c.id = pc.courseid
             WHERE pc.programid = ?
          ORDER BY pc.sortorder, pc.id";
    $rows = $DB->get_records_sql($sql, [$id]);
    $required = 0.0;
    $table = new html_table();
    $table->head = [get_string('groupname', $p), get_string('coursecode', $p), get_string('coursetitle', $p),
        get_string('hours', $p), get_string('requirement', $p)];
    foreach ($rows as $r) {
        if ($r->requirement === 'required') {
            $required += (float)$r->hours;
        }
        $table->data[] = [s($r->groupname), s($r->code), format_string($r->title), $num($r->hours),
            get_string('req_' . $r->requirement, $p)];
    }
    echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('nooutline', $p), 'info');
    $outline = $required + (float)$prog->trackhours;
    $a = (object)['required' => $num($required), 'track' => $num((float)$prog->trackhours), 'outline' => $num($outline),
        'total' => $num($prog->totalhours), 'unit' => $unit];
    echo $OUTPUT->notification(
        get_string('outlinecheck', $p, $a),
        ($prog->totalhours !== null && abs($outline - (float)$prog->totalhours) > 0.001) ? 'warning' : 'info'
    );
    echo $OUTPUT->heading(get_string('addtoprogram', $p), 4);
    $form->display();
    echo $OUTPUT->footer();
    die();
}

echo \local_studentrecords\local\staffarea::header();
$levels = catalog::levels();
$table = new html_table();
$table->head = [get_string('prog_code', $p), get_string('prog_name', $p), get_string('level', $p),
    get_string('prog_credential', $p), get_string('unittype', $p), get_string('prog_totalhours', $p),
    get_string('prog_deliverymode', $p), get_string('active', $p)];
foreach ($DB->get_records('local_studentrecords_program', null, 'code') as $prog) {
    $table->data[] = [html_writer::link(new moodle_url($url, ['id' => $prog->id]), s($prog->code)), format_string($prog->name),
        $prog->levelid ? format_string($levels[$prog->levelid] ?? '') : '', s((string)$prog->credential),
        get_string('unit_' . $prog->unittype, $p), $num($prog->totalhours),
        ($prog->deliverymode ? get_string('delivery_' . $prog->deliverymode, $p) : '') .
            ($prog->location ? ' (' . s($prog->location) . ')' : ''),
        $prog->active ? get_string('yes') : get_string('no')];
}
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('noprograms', $p), 'info');
echo html_writer::link(new moodle_url($url, ['id' => 0]), get_string('addprogram', $p), ['class' => 'btn btn-primary']);
echo $OUTPUT->footer();
