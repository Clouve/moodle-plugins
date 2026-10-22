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
 * Course catalog: course code, title, credit or clock hours. Used for any Moodle course with the same
 * course code that has no hours set in its own settings.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_academictranscript\local\programs;

admin_externalpage_setup('local_academictranscript_catalog');
require_capability('local/academictranscript:configure', context_system::instance());
$p = 'local_academictranscript';
$url = new moodle_url('/local/academictranscript/catalog.php');
$PAGE->set_url($url);
$id = optional_param('id', 0, PARAM_INT);
$edit = optional_param('edit', 0, PARAM_BOOL);

$form = new \local_academictranscript\form\catalogcourse(new moodle_url($url, ['edit' => 1]));
if ($id) {
    $form->set_data($DB->get_record('local_academictranscript_catalog', ['id' => $id], '*', MUST_EXIST));
}
if ($form->is_cancelled()) {
    redirect($url);
} else if ($data = $form->get_data()) {
    if (!empty($data->id)) {
        $DB->set_field('local_academictranscript_catalog', 'code', trim($data->code), ['id' => $data->id]);
    }
    programs::save_course($data);
    redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('catalog', $p));
echo html_writer::tag('p', get_string('catalogintro', $p));
if ($edit || $id) {
    $form->display();
} else {
    echo $OUTPUT->single_button(new moodle_url($url, ['edit' => 1]), get_string('addcatalogcourse', $p), 'get');
}
$unitmenu = programs::unittype_menu();
$progmenu = programs::menu();
$table = new html_table();
$table->head = [get_string('col_course', $p), get_string('col_title', $p), get_string('hours', $p),
    get_string('unittype', $p), get_string('program', $p), get_string('active', $p), ''];
foreach ($DB->get_records('local_academictranscript_catalog', null, 'code') as $c) {
    $table->data[] = [s($c->code), s($c->title), format_float($c->hours, 1), $unitmenu[$c->unittype],
        $progmenu[(int)$c->programid] ?? '', $c->active ? get_string('yes') : get_string('no'),
        html_writer::link(new moodle_url($url, ['id' => $c->id]), get_string('edit'))];
}
if ($table->data) {
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
