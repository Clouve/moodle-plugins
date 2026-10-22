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
 * Admins: the term list (name, start, end). Terms can be added and edited; they are never deleted,
 * because posted grades and course settings point to them.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_academictranscript\local\terms;

admin_externalpage_setup('local_academictranscript_terms');
// This page does not wait for the setup. The last step of the setup wizard asks for at least one term, so an admin has to
// be able to add one before the setup is finished.
$url = new moodle_url('/local/academictranscript/terms.php');
$PAGE->set_url($url);

$id = optional_param('id', 0, PARAM_INT);
$edit = optional_param('edit', 0, PARAM_BOOL);
$form = new \local_academictranscript\form\term(new moodle_url($url, ['edit' => 1]));
if ($id) {
    $form->set_data($DB->get_record('local_academictranscript_term', ['id' => $id], '*', MUST_EXIST));
}
if ($form->is_cancelled()) {
    redirect($url);
} else if ($data = $form->get_data()) {
    terms::save($data);
    redirect($url, get_string('termsaved', 'local_academictranscript'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('terms', 'local_academictranscript'));
echo html_writer::tag('p', get_string('termsintro', 'local_academictranscript'));

if ($edit || $id) {
    $form->display();
} else {
    echo $OUTPUT->single_button(new moodle_url($url, ['edit' => 1]), get_string('addterm', 'local_academictranscript'), 'get');
}

$all = terms::all();
if ($all) {
    $table = new html_table();
    $table->head = [get_string('termname', 'local_academictranscript'), get_string('termstart', 'local_academictranscript'),
        get_string('termend', 'local_academictranscript'), ''];
    foreach ($all as $term) {
        $table->data[] = [format_string($term->name), userdate($term->startdate, get_string('strftimedate', 'langconfig')),
            userdate($term->enddate, get_string('strftimedate', 'langconfig')),
            html_writer::link(new moodle_url($url, ['id' => $term->id]), get_string('edit'))];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
