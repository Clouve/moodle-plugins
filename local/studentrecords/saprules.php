<?php

/**
 * Academic standing (SAP) rules, school-wide or per level, with values suggested from the academic rules.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\catalog;
use local_studentrecords\local\records;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\standing;

$edit = optional_param('edit', -1, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$p = 'local_studentrecords';
require_login(null, false);
staffarea::setup('saprules');
$url = new moodle_url('/local/studentrecords/saprules.php');
$fields = ['mingpa', 'mincompletion', 'maxyears', 'maxtimeframe', 'maxtransfer'];
$levels = catalog::levels();

if ($action === 'suggest') {
    require_sesskey();
    $n = 0;
    foreach (standing::suggestions() as $levelid => $values) {
        if (
            !$levelid || !isset($levels[$levelid]) || $DB->record_exists(standing::RULES, ['levelid' => $levelid]) ||
                !array_filter($values, fn($v) => $v !== null)
        ) {
            continue;
        }
        records::save(standing::RULES, ['levelid' => $levelid, 'active' => 1] + $values);
        $n++;
    }
    \local_studentrecords\local\audit::log('create', 'saprule', null, null, 'from academic rules: ' . $n);
    redirect($url, get_string('saprulescreated', $p, $n), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($edit >= 0) {
    $form = new \local_studentrecords\form\saprule(new moodle_url($url, ['edit' => $edit]));
    if ($edit) {
        $rec = $DB->get_record(standing::RULES, ['id' => $edit], '*', MUST_EXIST);
        foreach ($fields as $f) {
            $rec->$f = $rec->$f === null ? '' : format_float($rec->$f, -1, false);
        }
        $rec->levelid = (int)$rec->levelid;
        $form->set_data($rec);
    }
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $record = ['levelid' => $data->levelid ?: null, 'active' => (int)$data->active];
        foreach ($fields as $f) {
            $record[$f] = $data->$f === '' ? null : (float)$data->$f;
        }
        if ($edit) {
            $record['id'] = $edit;
        }
        $id = records::save(standing::RULES, $record);
        \local_studentrecords\local\audit::log($edit ? 'update' : 'create', 'saprule', $id);
        redirect($url, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo staffarea::header();
    $form->display();
    echo $OUTPUT->footer();
    die();
}

echo staffarea::header();
echo html_writer::tag('p', get_string('saprules_intro', $p));
$num = fn($v) => $v === null ? '' : format_float($v, -1);
$table = new html_table();
$table->head = array_merge(
    [get_string('level', $p)],
    array_map(fn($f) => get_string('sap_' . $f, $p), $fields),
    [get_string('active', $p), '']
);
foreach ($DB->get_records(standing::RULES, null, 'levelid') as $r) {
    $line = [$r->levelid ? format_string($levels[$r->levelid] ?? '') : get_string('allprograms', $p)];
    foreach ($fields as $f) {
        $line[] = $num($r->$f);
    }
    $line[] = $r->active ? get_string('yes') : get_string('no');
    $line[] = html_writer::link(new moodle_url($url, ['edit' => $r->id]), get_string('edit'));
    $table->data[] = $line;
}
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('nosaprules', $p), 'info');
echo html_writer::link(new moodle_url($url, ['edit' => 0]), get_string('addsaprule', $p), ['class' => 'btn btn-primary mb-3']);

echo $OUTPUT->heading(get_string('sapsuggestions', $p), 3);
$table = new html_table();
$table->head = array_merge([get_string('level', $p)], array_map(
    fn($f) => get_string('sap_' . $f, $p),
    ['mingpa', 'mincompletion', 'maxyears', 'maxtransfer']
));
foreach (standing::suggestions() as $levelid => $v) {
    if (!$levelid || !isset($levels[$levelid])) {
        continue;
    }
    $table->data[] = [format_string($levels[$levelid]), $num($v['mingpa']), $num($v['mincompletion']), $num($v['maxyears']),
        $num($v['maxtransfer'])];
}
echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('none'));
echo $OUTPUT->single_button(
    new moodle_url($url, ['action' => 'suggest', 'sesskey' => sesskey()]),
    get_string('usesuggestions', $p),
    'post'
);
echo $OUTPUT->footer();
