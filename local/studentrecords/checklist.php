<?php

/**
 * Application checklist setup: the documents required for admission (all programs, a level, a credential type or
 * a program). Documents are switched off, never removed.
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
\local_studentrecords\local\staffarea::setup('checklist');
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/checklist.php');
$PAGE->set_url($url);

if ($edit >= 0) {
    $form = new \local_studentrecords\form\reqdoc(new moodle_url($url, ['edit' => $edit]));
    if ($edit) {
        $rec = $DB->get_record('local_studentrecords_reqdoc', ['id' => $edit], '*', MUST_EXIST);
        $rec->levelid = (int)$rec->levelid;
        $rec->programid = (int)$rec->programid;
        $rec->credentialtype = (string)$rec->credentialtype;
        $form->set_data($rec);
    } else {
        $form->set_data(['sortorder' => $DB->count_records('local_studentrecords_reqdoc') + 1]);
    }
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $data->name = trim($data->name);
        foreach (['levelid', 'programid'] as $f) {
            $data->$f = $data->$f ?: null;
        }
        $data->credentialtype = $data->credentialtype ?: null;
        if (!$edit) {
            unset($data->id);
        }
        $id = records::save('local_studentrecords_reqdoc', $data);
        \local_studentrecords\local\audit::log($edit ? 'update' : 'create', 'reqdoc', $id);
        redirect($url, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo \local_studentrecords\local\staffarea::header();
    echo $OUTPUT->heading(get_string($edit ? 'editreqdoc' : 'addreqdoc', $p));
    $form->display();
    echo $OUTPUT->footer();
    die();
}

echo \local_studentrecords\local\staffarea::header();
echo html_writer::tag('p', get_string('checklist_intro', $p));
$levels = catalog::levels();
$programs = catalog::programs(false);
$table = new html_table();
$table->head = [get_string('reqdoc_name', $p), get_string('reqdoc_required', $p), get_string('appliesto', $p),
    get_string('active', $p), ''];
foreach ($DB->get_records('local_studentrecords_reqdoc', null, 'active DESC, sortorder, name') as $r) {
    $scope = array_filter([$r->levelid ? format_string($levels[$r->levelid] ?? '') : '',
        $r->credentialtype ? get_string('cred_' . $r->credentialtype, $p) : '',
        $r->programid ? ($programs[$r->programid] ?? '') : '']);
    $table->data[] = [format_string($r->name) . ($r->description ? html_writer::div(s($r->description), 'small text-muted') : ''),
        $r->required ? get_string('yes') : get_string('no'), $scope ? implode(' + ', $scope) : get_string('allprograms', $p),
        $r->active ? get_string('yes') : get_string('no'), html_writer::link(
            new moodle_url($url, ['edit' => $r->id]),
            get_string('edit')
        )];
}
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('nochecklist', $p), 'info');
echo html_writer::link(new moodle_url($url, ['edit' => 0]), get_string('addreqdoc', $p), ['class' => 'btn btn-primary me-2']);
echo html_writer::link(new moodle_url('/local/studentrecords/import.php'), get_string('importtitle', $p));
echo $OUTPUT->footer();
