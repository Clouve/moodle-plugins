<?php

/**
 * Student Records home page: the start page after login. One home per role (student, records staff, finance,
 * financial aid, teacher); people with several roles switch with the menu. "Go to my courses" only for LMS users.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\home;
use local_studentrecords\local\homes;
use local_studentrecords\local\shell;
use local_studentrecords\local\staffarea;

require_login();
$p = 'local_studentrecords';
$view = homes::pick(optional_param('view', '', PARAM_ALPHA), homes::views());
$syscontext = context_system::instance();
$PAGE->set_context($syscontext);
$PAGE->set_url(new moodle_url(home::URL, $view !== '' ? ['view' => $view] : []));
$PAGE->set_pagelayout('standard');
$PAGE->add_body_class('sr-page');
$title = $view !== '' ? homes::title($view) : get_string('pluginname', $p);
$navkeys = ['student' => 'myhome', 'staff' => 'staffhome', 'finance' => 'financehome', 'aid' => 'aidhome',
    'teacher' => 'myclasses', 'atrisk' => 'atrisk'];
$pagename = $view !== '' ? get_string('nav_' . $navkeys[$view], $p) : get_string('pluginname', $p);
$PAGE->set_title($pagename . ' - ' . get_string('pluginname', $p));
$PAGE->set_heading($title);
$PAGE->navbar->ignore_active();
$PAGE->navbar->add(get_string('pluginname', $p), new moodle_url(home::URL));

// A plain student with only one course and nothing in Student Records goes straight to that course (after any login).
// Never site admins, Student Records or transcript staff, or teachers of that course.
if ($view === '' && !staffarea::can_enter() && !is_siteadmin()) {
    $mycourses = array_keys(array_filter(enrol_get_my_courses('id, visible'), fn($c) => $c->visible ||
        has_capability('moodle/course:viewhiddencourses', context_course::instance($c->id))));
    $target = home::single_course_target(
        (bool)\local_studentrecords\local\students::get_by_user((int)$USER->id),
        (bool)shell::groups(),
        $mycourses,
        home::is_staff_user(count($mycourses) === 1 ? (int)reset($mycourses) : 0),
        home::is_nolms((int)$USER->id)
    );
    if ($target) {
        redirect(new moodle_url('/course/view.php', ['id' => $target]));
    }
}

$body = '';
if ($view !== '') {
    $body = homes::$view();
} else if (staffarea::can_enter()) {
    redirect(new moodle_url(staffarea::URL));
}

echo $OUTPUT->header();
echo shell::inline_menu();
if ($view === '') {
    $links = home::shows_courses((int)$USER->id) ? [[new moodle_url('/my/courses.php'), get_string('gotomycourses', $p)]] : [];
    if ($links) {
        echo shell::quick_links($links);
    } else {
        echo $OUTPUT->notification(get_string('homenothing', $p), \core\output\notification::NOTIFY_INFO);
    }
} else {
    echo $body;
}
echo $OUTPUT->footer();
