<?php

namespace local_studentrecords;

use local_studentrecords\local\home;

/**
 * Hook callbacks.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class hook_callbacks {
    /**
     * "Student Records" in Site administration > Appearance > Navigation > Start page for users.
     *
     * @param \core_user\hook\extend_default_homepage $hook
     */
    public static function extend_default_homepage(\core_user\hook\extend_default_homepage $hook): void {
        $hook->add_option(new \core\url(home::URL), new \core\lang_string('pluginname', 'local_studentrecords'));
    }

    /**
     * Send No LMS staff back to the Student Records home page from LMS pages, including pages that do not call
     * require_login() (for example the course enrolment page). Course pages are also covered by
     * local_studentrecords_after_require_login().
     *
     * @param \core\hook\after_config $hook
     */
    public static function after_config(\core\hook\after_config $hook): void {
        global $CFG, $SCRIPT, $USER;
        if (
            CLI_SCRIPT || AJAX_SCRIPT || WS_SERVER || during_initial_install() || !empty($CFG->upgraderunning) ||
                !isloggedin() || isguestuser() || is_siteadmin()
        ) {
            return;
        }
        if (home::is_lms_page((string)$SCRIPT, 0, (int)SITEID) && home::is_nolms((int)$USER->id)) {
            redirect(new \moodle_url(home::URL));
        }
    }

    /**
     * Top menu: one "Student Records" tab for everyone with Student Records pages (students, staff, teachers),
     * marked as current on Student Records pages; no Dashboard or My courses for No LMS staff.
     *
     * @param \core\hook\navigation\primary_extend $hook
     */
    public static function primary_extend(\core\hook\navigation\primary_extend $hook): void {
        global $PAGE, $USER;
        if (!isloggedin() || isguestuser()) {
            return;
        }
        $primary = $hook->get_primaryview();
        try {
            $groups = local\shell::groups();
        } catch (\dml_exception $e) {
            // Never break the menu over this check (for example during an upgrade).
            $groups = [];
        }
        if ($groups) {
            $node = $primary->add(
                get_string('pluginname', 'local_studentrecords'),
                new \moodle_url(home::URL),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_studentrecords'
            );
            if (!empty($PAGE->has_set_url()) && local\shell::is_sr_url($PAGE->url)) {
                $node->make_active();
            }
        }
        if (!home::is_nolms((int)$USER->id)) {
            return;
        }
        foreach (['myhome', 'mycourses'] as $key) {
            if ($node = $primary->get($key)) {
                $node->remove();
            }
        }
    }

    /**
     * Course tabs: "Attendance" right after "Grades" (not in "More") for teachers of a course linked to an offering.
     *
     * @param \core\hook\navigation\secondary_extend $hook
     */
    public static function secondary_extend(\core\hook\navigation\secondary_extend $hook): void {
        $secondary = $hook->get_secondaryview();
        $node = $secondary->find('local_studentrecords_attendance', null);
        if (!$node) {
            return;
        }
        $node->remove();
        $keys = $secondary->get_children_key_list();
        $after = array_search('grades', $keys, true);
        $before = ($after !== false && isset($keys[$after + 1])) ? $keys[$after + 1] : null;
        $node->set_force_into_more_menu(false);
        $secondary->add_node($node, $before);
    }
}
