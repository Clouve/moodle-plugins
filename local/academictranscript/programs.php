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
 * Programs: name, credential, unit type (credit hours or clock hours). Never deleted; set inactive instead.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_academictranscript\local\programs;

admin_externalpage_setup('local_academictranscript_programs');
require_capability('local/academictranscript:configure', context_system::instance());
$p = 'local_academictranscript';
$url = new moodle_url('/local/academictranscript/programs.php');
$PAGE->set_url($url);
$id = optional_param('id', 0, PARAM_INT);
$edit = optional_param('edit', 0, PARAM_BOOL);

$form = new \local_academictranscript\form\program(new moodle_url($url, ['edit' => 1]));
if ($id) {
    $form->set_data($DB->get_record('local_academictranscript_program', ['id' => $id], '*', MUST_EXIST));
}
if ($form->is_cancelled()) {
    redirect($url);
} else if ($data = $form->get_data()) {
    programs::save($data);
    redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('programs', $p));
echo html_writer::tag('p', get_string('programsintro', $p));
if ($edit || $id) {
    $form->display();
} else {
    echo $OUTPUT->single_button(new moodle_url($url, ['edit' => 1]), get_string('addprogram', $p), 'get');
}
$unitmenu = programs::unittype_menu();
$table = new html_table();
$table->head = [get_string('programname', $p), get_string('credential', $p), get_string('unittype', $p),
    get_string('totalhours', $p), get_string('active', $p), ''];
foreach (programs::all(false) as $prog) {
    $table->data[] = [format_string($prog->name), s((string)$prog->credential), $unitmenu[$prog->unittype],
        $prog->totalhours === null ? '' : format_float($prog->totalhours, 1), $prog->active ? get_string('yes') : get_string('no'),
        html_writer::link(new moodle_url($url, ['id' => $prog->id]), get_string('edit'))];
}
if ($table->data) {
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
