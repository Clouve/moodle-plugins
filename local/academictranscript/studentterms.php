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
 * Admins: override the term of a course for one student (e.g. a repeat in the same course shell).
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_academictranscript\local\access;
use local_academictranscript\local\coursefields;
use local_academictranscript\local\logger;
use local_academictranscript\local\terms;

$userid = required_param('userid', PARAM_INT);
$user = core_user::get_user($userid, '*', MUST_EXIST);
require_login(null, false);
require_capability('local/academictranscript:managerecords', context_system::instance());

$url = new moodle_url('/local/academictranscript/studentterms.php', ['userid' => $userid]);
$PAGE->set_url($url);
$PAGE->set_context(context_user::instance($userid));
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('courseterms', 'local_academictranscript'));
$PAGE->set_heading(fullname($user));

$courseids = access::student_courseids($userid);
$courses = $courseids ? $DB->get_records_list('course', 'id', $courseids, 'fullname', 'id, fullname, shortname') : [];
$fields = coursefields::get($courseids);
$menu = terms::menu(false);

if (data_submitted() && confirm_sesskey()) {
    $overrides = optional_param_array('termid', [], PARAM_INT);
    foreach ($courses as $c) {
        if (!array_key_exists($c->id, $overrides)) {
            continue;
        }
        $new = (int)$overrides[$c->id];
        $new = isset($menu[$new]) ? $new : 0;
        $row = $DB->get_record('local_academictranscript_code', ['userid' => $userid, 'courseid' => $c->id]);
        $old = $row ? (int)$row->termid : 0;
        if ($new === $old) {
            continue;
        }
        if ($row) {
            $DB->update_record('local_academictranscript_code', (object)['id' => $row->id, 'termid' => $new ?: null,
                'usermodified' => $USER->id, 'timemodified' => time()]);
        } else {
            $DB->insert_record('local_academictranscript_code', (object)['userid' => $userid, 'courseid' => $c->id,
                'termid' => $new ?: null, 'usermodified' => $USER->id, 'timemodified' => time()]);
        }
        logger::log('termoverride', $userid, 0, "course {$c->id} term $old -> $new");
    }
    redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('courseterms', 'local_academictranscript') . ': ' . fullname($user));
echo html_writer::tag('p', get_string('coursetermsintro', 'local_academictranscript'));
if (!$courses) {
    echo $OUTPUT->notification(get_string('nocourses', 'local_academictranscript'), 'info');
} else {
    $rows = $DB->get_records('local_academictranscript_code', ['userid' => $userid], '', 'courseid, termid');
    $table = new html_table();
    $table->head = [get_string('course'), get_string('coursetermfield', 'local_academictranscript'),
        get_string('termoverride', 'local_academictranscript')];
    foreach ($courses as $c) {
        $courseterm = terms::id_for_option($fields[$c->id]['termindex']);
        $table->data[] = [format_string($c->fullname, true, ['context' => context_course::instance($c->id)]),
            $courseterm ? ($menu[$courseterm] ?? '') : get_string('termnotset', 'local_academictranscript'),
            html_writer::select(
                [0 => get_string('usecourseterm', 'local_academictranscript')] + $menu,
                "termid[{$c->id}]",
                (int)($rows[$c->id]->termid ?? 0),
                false,
                ['class' => 'custom-select']
            )];
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::table($table);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('savechanges')]);
    echo html_writer::end_tag('form');
}
echo $OUTPUT->single_button(
    new moodle_url('/local/academictranscript/index.php', ['userid' => $userid]),
    get_string('viewtranscript', 'local_academictranscript'),
    'get'
);
echo $OUTPUT->footer();
