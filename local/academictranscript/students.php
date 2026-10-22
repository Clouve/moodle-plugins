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
 * Students' transcripts: admins see every student, teachers see the students of their own courses.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_academictranscript\local\access;

$courseid = optional_param('courseid', 0, PARAM_INT);
$syscontext = context_system::instance();

if ($courseid) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($courseid);
    require_capability('local/academictranscript:issueofficial', $context);
    $PAGE->set_url(new moodle_url('/local/academictranscript/students.php', ['courseid' => $courseid]));
    $PAGE->set_pagelayout('incourse');
    $PAGE->set_title(get_string('studentstranscripts', 'local_academictranscript'));
    $PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));
    $courseids = [$courseid];
} else if (
    has_capability('local/academictranscript:managerecords', $syscontext)
        || has_capability('local/academictranscript:issueofficial', $syscontext)
) {
    admin_externalpage_setup('local_academictranscript_students');
    \local_academictranscript\local\access::require_setup();
    $courseids = null; // All courses.
} else {
    // A teacher arriving without a course: list the students of every course they teach.
    require_login(null, false);
    $PAGE->set_context($syscontext);
    $PAGE->set_url(new moodle_url('/local/academictranscript/students.php'));
    $PAGE->set_pagelayout('report');
    $PAGE->set_title(get_string('studentstranscripts', 'local_academictranscript'));
    $PAGE->set_heading(get_string('studentstranscripts', 'local_academictranscript'));
    $courseids = [];
    foreach (enrol_get_my_courses('id') as $c) {
        if (has_capability('local/academictranscript:issueofficial', context_course::instance($c->id))) {
            $courseids[] = $c->id;
        }
    }
    if (!$courseids) {
        throw new required_capability_exception($syscontext, 'local/academictranscript:issueofficial', 'nopermissions', '');
    }
}

// Students (student-archetype role assignments in course contexts).
$roleids = array_keys(get_archetype_roles('student'));
[$rolesql, $params] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
$coursesql = '';
if ($courseids !== null) {
    [$insql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
    $coursesql = "AND ctx.instanceid $insql";
    $params += $cparams;
}
$params['level'] = CONTEXT_COURSE;
$namefields = implode(', ', array_map(fn($f) => "u.$f", \core_user\fields::get_name_fields()));
$sql = "SELECT DISTINCT u.id, u.email, u.idnumber, $namefields
          FROM {role_assignments} ra
          JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :level
          JOIN {user} u ON u.id = ra.userid AND u.deleted = 0
         WHERE ra.roleid $rolesql $coursesql AND ctx.instanceid <> " . SITEID . "
      ORDER BY u.lastname, u.firstname";
$students = $DB->get_records_sql($sql, $params);
$records = $DB->get_records('local_academictranscript_student', null, '', 'userid, studentid');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('studentstranscripts', 'local_academictranscript'));
if (!$students) {
    echo $OUTPUT->notification(get_string('nostudents', 'local_academictranscript'), 'info');
} else {
    $isadmin = access::is_admin();
    $table = new html_table();
    $table->head = [get_string('studentname', 'local_academictranscript'), get_string('email'),
        get_string('studentid', 'local_academictranscript'), ''];
    foreach ($students as $s) {
        $links = html_writer::link(
            new moodle_url('/local/academictranscript/index.php', ['userid' => $s->id]),
            get_string('viewtranscript', 'local_academictranscript')
        );
        if ($isadmin) {
            $links .= ' | ' . html_writer::link(
                new moodle_url('/local/academictranscript/record.php', ['userid' => $s->id]),
                get_string('editdetails', 'local_academictranscript')
            );
        }
        $sid = isset($records[$s->id]) && trim((string)$records[$s->id]->studentid) !== '' ?
            $records[$s->id]->studentid : $s->idnumber;
        $table->data[] = [fullname($s), s($s->email), s($sid), $links];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
