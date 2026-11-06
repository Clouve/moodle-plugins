<?php

/**
 * Admins: every course with its Course code and Credit units; missing values are flagged.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_academictranscript\local\coursefields;

admin_externalpage_setup('local_academictranscript_coursecheck');
\local_academictranscript\local\access::require_setup();
$PAGE->set_url(new moodle_url('/local/academictranscript/coursecheck.php'));

$courses = $DB->get_records_select('course', 'id <> ?', [SITEID], 'shortname', 'id, shortname, fullname');
$fields = coursefields::get_resolved(array_keys($courses));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursecheck', 'local_academictranscript'));
echo html_writer::tag('p', get_string('coursecheckintro', 'local_academictranscript'));

$missing = 0;
$table = new html_table();
$table->head = [get_string('shortname'), get_string('fullname'), get_string('col_code', 'local_academictranscript'),
    get_string('hours', 'local_academictranscript'), get_string('unittype', 'local_academictranscript'),
    get_string('term', 'local_academictranscript'), get_string('status'), ''];
$unitmenu = \local_academictranscript\local\programs::unittype_menu();
$termmenu = \local_academictranscript\local\terms::menu(false);
foreach ($courses as $c) {
    $f = $fields[$c->id];
    $status = [];
    if ($f['code'] === '') {
        $status[] = get_string('missingcode', 'local_academictranscript');
    }
    if ($f['hours'] === null) {
        $status[] = get_string('missinghours', 'local_academictranscript');
    }
    $termid = \local_academictranscript\local\terms::id_for_option($f['termindex']);
    if (!$termid) {
        $status[] = get_string('missingterm', 'local_academictranscript');
    }
    if ($status) {
        $missing++;
    }
    $table->data[] = [
        s($c->shortname),
        format_string($c->fullname, true, ['context' => context_course::instance($c->id)]),
        s($f['code']),
        s(coursefields::format_hours($f['hours'])),
        $f['unittype'] !== '' ? $unitmenu[$f['unittype']] : '',
        $termid ? ($termmenu[$termid] ?? '') : '',
        $status ? html_writer::span(implode(', ', $status), 'badge bg-warning text-dark') :
            html_writer::span(get_string('ok'), 'badge bg-success'),
        html_writer::link(new moodle_url('/course/edit.php', ['id' => $c->id]), get_string('editsettings')),
    ];
}
echo $OUTPUT->notification(get_string('coursecheckcount', 'local_academictranscript', $missing), $missing ? 'warning' : 'success');
echo html_writer::table($table);
echo $OUTPUT->footer();
