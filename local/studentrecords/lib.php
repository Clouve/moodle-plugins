<?php

/**
 * Navigation and file serving.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

/**
 * "Student records" in the course's More menu for teachers (students of that course only).
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_studentrecords_extend_navigation_course($navigation, $course, $context) {
    global $DB;
    if ($course->id != SITEID && has_capability('local/studentrecords:viewstudents', $context)) {
        $navigation->add(
            get_string('studentrecords', 'local_studentrecords'),
            new moodle_url('/local/studentrecords/students.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_studentrecords',
            new pix_icon('i/report', '')
        );
    }
    if (
        $course->id != SITEID && has_capability('local/studentrecords:takeattendance', $context) &&
            $DB->record_exists('local_studentrecords_offer', ['moodlecourseid' => $course->id])
    ) {
        $navigation->add(
            get_string('attendance', 'local_studentrecords'),
            new moodle_url('/local/studentrecords/attendance.php', ['course' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_studentrecords_attendance',
            new pix_icon('i/checked', '')
        );
    }
}

/**
 * Student Records theme: the Student Records menu for the left drawer on Student Records pages.
 *
 * @param moodle_page $page
 * @return array|null ['heading' => text, 'html' => menu] or null
 */
function local_studentrecords_theme_drawer($page) {
    return \local_studentrecords\local\shell::drawer($page);
}

/**
 * Student Records theme: the card on the Dashboard and on course and activity pages.
 *
 * @param moodle_page $page
 * @return string HTML or ''
 */
function local_studentrecords_theme_card($page) {
    return \local_studentrecords\local\cards::for_page($page);
}

/**
 * Student Records theme: a short note in the top bar ("No LMS access" for staff without courses).
 *
 * @return string
 */
function local_studentrecords_theme_topbar_note() {
    global $USER;
    return \local_studentrecords\local\home::is_nolms((int)$USER->id) ? get_string('nolms', 'local_studentrecords') : '';
}

/**
 * "Student record" on the profile, when the viewer may see it.
 *
 * @param core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 */
function local_studentrecords_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    global $DB;
    if (
        !$DB->record_exists('local_studentrecords_student', ['userid' => $user->id]) ||
            !\local_studentrecords\local\access::can_view_student((int)$user->id)
    ) {
        return;
    }
    $node = new core_user\output\myprofile\node(
        'miscellaneous',
        'local_studentrecords',
        get_string('studentrecord', 'local_studentrecords'),
        null,
        new moodle_url('/local/studentrecords/student.php', ['userid' => $user->id])
    );
    $tree->add_node($node);
}

/**
 * Send No LMS staff back to the Student Records home page from course, Dashboard and My courses pages.
 *
 * @param stdClass|int|null $courseorid
 * @param bool|null $autologinguest
 * @param stdClass|null $cm
 * @param bool|null $setwantsurltome
 * @param bool|null $preventredirect
 */
function local_studentrecords_after_require_login(
    $courseorid = null,
    $autologinguest = null,
    $cm = null,
    $setwantsurltome = null,
    $preventredirect = null
) {
    global $SCRIPT, $USER;
    if ($preventredirect || CLI_SCRIPT || AJAX_SCRIPT || WS_SERVER || !isloggedin() || isguestuser()) {
        return;
    }
    $courseid = is_object($courseorid) ? (int)$courseorid->id : (int)$courseorid;
    if (
        !\local_studentrecords\local\home::is_lms_page((string)$SCRIPT, $courseid, (int)SITEID) ||
            !\local_studentrecords\local\home::is_nolms((int)$USER->id)
    ) {
        return;
    }
    redirect(new moodle_url(\local_studentrecords\local\home::URL));
}

/**
 * Serve the plugin's files. Who may read which file is decided by access::can_read_file().
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
function local_studentrecords_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }
    require_login(null, false);
    $itemid = (int)array_shift($args);
    if (!\local_studentrecords\local\access::can_read_file($filearea, $itemid)) {
        return false;
    }
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'local_studentrecords', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    if ($filearea === 'studentdocs') {
        $owner = \local_studentrecords\local\students::get($itemid);
        \local_studentrecords\local\audit::log('view', 'studentdoc', $itemid, (int)$owner->userid);
    }
    send_stored_file($file, 0, 0, true, $options);
}
