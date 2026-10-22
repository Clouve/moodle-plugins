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
 * Admins: approve or reject requested changes to posted grades.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_academictranscript\local\posting;

admin_externalpage_setup('local_academictranscript_approvals');
\local_academictranscript\local\access::require_setup();
$url = new moodle_url('/local/academictranscript/approvals.php');
$PAGE->set_url($url);

$changeid = optional_param('changeid', 0, PARAM_INT);
$decision = optional_param('decision', '', PARAM_ALPHA);
if ($changeid && in_array($decision, ['approve', 'reject'])) {
    require_sesskey();
    posting::decide($changeid, $decision === 'approve', optional_param('decisionnote', '', PARAM_TEXT));
    redirect(
        $url,
        get_string($decision === 'approve' ? 'changeapproved' : 'changerejected', 'local_academictranscript'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$sql = "SELECT c.*, p.userid, p.coursecode, p.coursename, t.name AS termname
          FROM {local_academictranscript_change} c
          JOIN {local_academictranscript_posted} p ON p.id = c.postedid
     LEFT JOIN {local_academictranscript_term} t ON t.id = p.termid";

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('approvals', 'local_academictranscript'));

$pending = $DB->get_records_sql("$sql WHERE c.status = 'pending' ORDER BY c.timerequested", []);
if (!$pending) {
    echo $OUTPUT->notification(get_string('nopending', 'local_academictranscript'), 'info');
} else {
    $table = new html_table();
    $table->head = [get_string('studentname', 'local_academictranscript'), get_string('col_course', 'local_academictranscript'),
        get_string('term', 'local_academictranscript'), get_string('change', 'local_academictranscript'),
        get_string('reason', 'local_academictranscript'), get_string('requestedby', 'local_academictranscript'), ''];
    foreach ($pending as $c) {
        $form = html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-flex gap-1'])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'changeid', 'value' => $c->id])
            . html_writer::empty_tag('input', ['type' => 'text', 'name' => 'decisionnote', 'size' => 18, 'maxlength' => 255,
                'class' => 'form-control form-control-sm', 'placeholder' => get_string('decisionnote', 'local_academictranscript'),
                'aria-label' => get_string('decisionnote', 'local_academictranscript')])
            . html_writer::tag('button', get_string('approve', 'local_academictranscript'), [
                'type' => 'submit', 'name' => 'decision',
                'value' => 'approve', 'class' => 'btn btn-sm btn-primary'])
            . html_writer::tag('button', get_string('reject', 'local_academictranscript'), [
                'type' => 'submit', 'name' => 'decision',
                'value' => 'reject', 'class' => 'btn btn-sm btn-secondary'])
            . html_writer::end_tag('form');
        $student = core_user::get_user($c->userid);
        $table->data[] = [$student ? fullname($student) : $c->userid, s($c->coursecode . ' ' . $c->coursename),
            s((string)$c->termname), s($c->oldgrade . ' → ' . $c->newgrade . ($c->newnote !== $c->oldnote ?
                ' (' . get_string('footnote', 'local_academictranscript') . ': ' . $c->newnote . ')' : '')),
            s($c->reason), fullname(core_user::get_user($c->requestedby)) . '<br><small>' .
            userdate($c->timerequested, get_string('strftimedatetimeshort', 'langconfig')) . '</small>', $form];
    }
    echo html_writer::table($table);
}

$decided = $DB->get_records_sql("$sql WHERE c.status <> 'pending' ORDER BY c.timedecided DESC", [], 0, 100);
if ($decided) {
    echo $OUTPUT->heading(get_string('changehistory', 'local_academictranscript'), 4);
    $table = new html_table();
    $table->head = [get_string('studentname', 'local_academictranscript'), get_string('col_course', 'local_academictranscript'),
        get_string('change', 'local_academictranscript'), get_string('reason', 'local_academictranscript'),
        get_string('status'), get_string('decidedby', 'local_academictranscript')];
    foreach ($decided as $c) {
        $student = core_user::get_user($c->userid);
        $table->data[] = [$student ? fullname($student) : $c->userid, s($c->coursecode), s($c->oldgrade . ' → ' . $c->newgrade),
            s($c->reason), get_string('changestatus_' . $c->status, 'local_academictranscript'),
            fullname(core_user::get_user($c->decidedby)) . ', ' . userdate(
                $c->timedecided,
                get_string('strftimedatetimeshort', 'langconfig')
            ) . ($c->decisionnote ? ': ' . s($c->decisionnote) : '')];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
