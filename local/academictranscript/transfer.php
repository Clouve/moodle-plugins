<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Admins: a student's transfer credit. Rows are never deleted; "Show on transcript: No" hides one.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_academictranscript\local\logger;

$userid = required_param('userid', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$edit = optional_param('edit', 0, PARAM_BOOL);
$user = core_user::get_user($userid, '*', MUST_EXIST);
require_login(null, false);
require_capability('local/academictranscript:managerecords', context_system::instance());

$url = new moodle_url('/local/academictranscript/transfer.php', ['userid' => $userid]);
$PAGE->set_url($url);
$PAGE->set_context(context_user::instance($userid));
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('transfercredits', 'local_academictranscript'));
$PAGE->set_heading(fullname($user));

$form = new \local_academictranscript\form\transfer(new moodle_url($url, ['edit' => 1]));
if ($id) {
    $form->set_data($DB->get_record('local_academictranscript_transfer', ['id' => $id, 'userid' => $userid], '*', MUST_EXIST));
} else {
    $form->set_data(['userid' => $userid, 'active' => 1]);
}
if ($form->is_cancelled()) {
    redirect($url);
} else if ($data = $form->get_data()) {
    $record = (object)[
        'userid' => $userid,
        'institution' => trim($data->institution),
        'coursecode' => trim((string)$data->coursecode),
        'title' => trim($data->title),
        'hours' => (float)$data->hours,
        'dateaccepted' => empty($data->dateaccepted) ? null : $data->dateaccepted,
        'note' => trim((string)$data->note),
        'active' => empty($data->active) ? 0 : 1,
        'usermodified' => $USER->id,
        'timemodified' => time(),
    ];
    if (!empty($data->id)) {
        $record->id = (int)$data->id;
        $DB->get_record('local_academictranscript_transfer', ['id' => $record->id, 'userid' => $userid], 'id', MUST_EXIST);
        $DB->update_record('local_academictranscript_transfer', $record);
    } else {
        $record->id = $DB->insert_record('local_academictranscript_transfer', $record);
    }
    logger::log('transfer', $userid, 0, "transfer {$record->id} {$record->coursecode} {$record->hours} active {$record->active}");
    redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('transfercredits', 'local_academictranscript') . ': ' . fullname($user));
if ($edit || $id) {
    $form->display();
} else {
    echo html_writer::div($OUTPUT->single_button(
        new moodle_url($url, ['edit' => 1]),
        get_string('addtransfer', 'local_academictranscript'),
        'get'
    ) . ' ' .
        $OUTPUT->single_button(
            new moodle_url('/local/academictranscript/index.php', ['userid' => $userid]),
            get_string('viewtranscript', 'local_academictranscript'),
            'get'
        ), 'd-flex gap-2 mb-3');
}
$rows = $DB->get_records('local_academictranscript_transfer', ['userid' => $userid], 'institution, id');
if ($rows) {
    $table = new html_table();
    $table->head = [get_string('institution', 'local_academictranscript'), get_string('col_course', 'local_academictranscript'),
        get_string('col_title', 'local_academictranscript'), get_string('hours', 'local_academictranscript'),
        get_string('dateaccepted', 'local_academictranscript'), get_string('showontranscript', 'local_academictranscript'), ''];
    foreach ($rows as $r) {
        $table->data[] = [s($r->institution), s($r->coursecode), s($r->title), format_float($r->hours, 1),
            $r->dateaccepted ? userdate($r->dateaccepted, get_string('strftimedate', 'langconfig')) : '',
            $r->active ? get_string('yes') : get_string('no'),
            html_writer::link(new moodle_url($url, ['id' => $r->id]), get_string('edit'))];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
