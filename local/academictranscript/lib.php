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
 * Navigation and file callbacks.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_academictranscript\local\access;

/**
 * "Academic transcript" link on the profile page (own profile, or a student's profile for staff).
 *
 * @param core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 * @return void
 */
function local_academictranscript_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    if (isguestuser($user) || !isloggedin() || isguestuser()) {
        return;
    }
    if (!access::can_view($user->id)) {
        return;
    }
    $url = new moodle_url('/local/academictranscript/index.php', ['userid' => $user->id]);
    $node = new core_user\output\myprofile\node(
        'reports',
        'local_academictranscript',
        get_string('studenttranscript', 'local_academictranscript'),
        null,
        $url
    );
    $tree->add_node($node);
}

/**
 * Course "More" menu: transcript codes and students' transcripts for teachers.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 * @return void
 */
function local_academictranscript_extend_navigation_course($navigation, $course, $context) {
    if ($course->id == SITEID) {
        return;
    }
    if (has_capability('local/academictranscript:entercodes', $context)) {
        $navigation->add(
            get_string('transcriptcodes', 'local_academictranscript'),
            new moodle_url('/local/academictranscript/codes.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_academictranscript_codes',
            new pix_icon('i/report', '')
        );
    }
    if (has_capability('local/academictranscript:postgrades', $context)) {
        $navigation->add(
            get_string('postgrades', 'local_academictranscript'),
            new moodle_url('/local/academictranscript/post.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_academictranscript_post',
            new pix_icon('i/report', '')
        );
    }
    if (has_capability('local/academictranscript:issueofficial', $context)) {
        $navigation->add(
            get_string('studentstranscripts', 'local_academictranscript'),
            new moodle_url('/local/academictranscript/students.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_academictranscript_students',
            new pix_icon('i/report', '')
        );
    }
}

/**
 * Serve the logo, signature and seal images (settings preview) and uploaded policy/catalog documents (admins).
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function local_academictranscript_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    // Only the images shown on the settings page; the signing certificate is never served.
    if ($context->contextlevel != CONTEXT_SYSTEM || !in_array($filearea, ['logo', 'signatureimage', 'sealimage', 'sourcedocs'])) {
        return false;
    }
    require_login();
    if ($filearea === 'sourcedocs') {
        require_capability('local/academictranscript:configure', $context);
        $itemid = (int)array_shift($args);
        $filename = array_pop($args);
        $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
        $file = get_file_storage()->get_file(
            $context->id,
            'local_academictranscript',
            'sourcedocs',
            $itemid,
            $filepath,
            $filename
        );
        if (!$file) {
            return false;
        }
        send_stored_file($file, 0, 0, $forcedownload, $options);
    }
    if ($filearea !== 'logo') {
        require_capability('local/academictranscript:managerecords', $context);
    }
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', array_slice($args, 1)) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'local_academictranscript', $filearea, 0, $filepath, $filename);
    if (!$file) {
        $files = get_file_storage()->get_area_files($context->id, 'local_academictranscript', $filearea, 0, 'id', false);
        $file = reset($files);
    }
    if (!$file) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}
