<?php

/**
 * Terms: dates and deadlines.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\catalog;
use local_studentrecords\local\records;

$edit = optional_param('edit', -1, PARAM_INT);
require_login(null, false);
\local_studentrecords\local\staffarea::setup('terms');
require_capability('local/studentrecords:configure', context_system::instance());
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/terms.php');
$PAGE->set_url($url);

if ($edit >= 0) {
    $form = new \local_studentrecords\form\term(new moodle_url($url, ['edit' => $edit]));
    if ($edit) {
        $form->set_data($DB->get_record('local_studentrecords_term', ['id' => $edit], '*', MUST_EXIST));
    }
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $data->name = trim($data->name);
        foreach (['adddropstart', 'adddropend', 'withdrawby', 'examstart', 'examend', 'gradefreeze'] as $f) {
            $data->$f = empty($data->$f) ? null : $data->$f;
        }
        // Last day to withdraw: always set. Calculated (school rule, else 60% of the term) when left empty, and
        // recalculated when it was calculated and is left as it was; a date staff pick is kept as theirs.
        $old = $edit ? $DB->get_record('local_studentrecords_term', ['id' => $edit]) : null;
        $kept = $old && $old->withdrawcalc && $data->withdrawby &&
            catalog::time_to_date((int)$data->withdrawby) === catalog::time_to_date((int)$old->withdrawby);
        if (!$data->withdrawby || $kept) {
            $data->withdrawby = catalog::withdraw_time((int)$data->startdate, (int)$data->enddate);
            $data->withdrawcalc = 1;
        } else {
            $data->withdrawcalc = 0;
        }
        $id = records::save('local_studentrecords_term', $data);
        \local_studentrecords\local\audit::log($edit ? 'update' : 'create', 'term', $id);
        redirect($url, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo \local_studentrecords\local\staffarea::header();
    echo $OUTPUT->heading(get_string($edit ? 'editterm' : 'addterm', $p));
    $form->display();
    echo $OUTPUT->footer();
    die();
}

echo \local_studentrecords\local\staffarea::header();
$d = fn($t) => $t ? catalog::show_date((int)$t) : '';
$range = fn($from, $to) => implode(' – ', array_filter([$d($from), $d($to)]));
$withdraw = fn($t) => $t->withdrawby ? $d($t->withdrawby) . ($t->withdrawcalc ?
    ' ' . html_writer::span(get_string('calculated', $p), 'badge bg-secondary') : '') : '';
$table = new html_table();
$table->head = [get_string('termname', $p), get_string('startdate', $p), get_string('enddate', $p),
    get_string('adddrop', $p), get_string('withdrawby', $p), get_string('exams', $p), get_string('gradefreeze', $p),
    get_string('termnotes', $p),
    get_string('active', $p), ''];
foreach ($DB->get_records('local_studentrecords_term', null, 'startdate, name') as $t) {
    $table->data[] = [format_string($t->name), $d($t->startdate), $d($t->enddate),
        $range($t->adddropstart, $t->adddropend), $withdraw($t),
        $range($t->examstart, $t->examend), $d($t->gradefreeze), s((string)$t->notes),
        $t->active ? get_string('yes') : get_string('no'),
        html_writer::link(new moodle_url($url, ['edit' => $t->id]), get_string('edit'))];
}
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('noterms', $p), 'info');
echo html_writer::link(new moodle_url($url, ['edit' => 0]), get_string('addterm', $p), ['class' => 'btn btn-primary']);
echo $OUTPUT->footer();
