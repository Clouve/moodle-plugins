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
 * Request a change to a posted grade (teachers of the course, admins). An admin must approve it.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_academictranscript\local\posting;

$id = required_param('id', PARAM_INT);
$posted = $DB->get_record('local_academictranscript_posted', ['id' => $id], '*', MUST_EXIST);
$course = get_course($posted->courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/academictranscript:postgrades', $context);

$url = new moodle_url('/local/academictranscript/change.php', ['id' => $id]);
$back = new moodle_url('/local/academictranscript/post.php', ['courseid' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('requestchange', 'local_academictranscript'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));

$student = core_user::get_user($posted->userid, '*', MUST_EXIST);
$term = $DB->get_field('local_academictranscript_term', 'name', ['id' => $posted->termid]);
$current = $posted->grade . ' — ' . fullname($student) . ' — ' . $posted->coursecode . ' — ' . $term;
$form = new \local_academictranscript\form\change_request($url, ['current' => $current]);
$form->set_data(['id' => $id, 'newgrade' => $posted->grade, 'newnote' => $posted->note]);

$pending = $DB->get_record('local_academictranscript_change', ['postedid' => $id, 'status' => 'pending']);
if ($form->is_cancelled()) {
    redirect($back);
} else if (!$pending && ($data = $form->get_data())) {
    posting::request_change($id, $data->newgrade, (string)$data->newnote, $data->reason);
    redirect($back, get_string('changerequested', 'local_academictranscript'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('requestchange', 'local_academictranscript'));
if ($pending) {
    echo $OUTPUT->notification(get_string('changepending', 'local_academictranscript'), 'info');
    echo $OUTPUT->continue_button($back);
} else {
    echo html_writer::tag('p', get_string('changeintro', 'local_academictranscript'));
    $form->display();
}

$history = $DB->get_records('local_academictranscript_change', ['postedid' => $id], 'timerequested DESC');
if ($history) {
    echo $OUTPUT->heading(get_string('changehistory', 'local_academictranscript'), 4);
    $table = new html_table();
    $table->head = [get_string('date'), get_string('change', 'local_academictranscript'),
        get_string('reason', 'local_academictranscript'), get_string('requestedby', 'local_academictranscript'),
        get_string('status'), get_string('decidedby', 'local_academictranscript')];
    foreach ($history as $c) {
        $table->data[] = [userdate($c->timerequested, get_string('strftimedatetimeshort', 'langconfig')),
            s($c->oldgrade . ' → ' . $c->newgrade), s($c->reason), fullname(core_user::get_user($c->requestedby)),
            get_string('changestatus_' . $c->status, 'local_academictranscript'),
            $c->decidedby ? fullname(core_user::get_user($c->decidedby)) .
                ($c->decisionnote ? ': ' . s($c->decisionnote) : '') : ''];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
