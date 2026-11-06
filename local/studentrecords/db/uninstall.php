<?php

/**
 * Uninstall: put back the start page, and remove the Finance and Financial Aid roles when nothing else uses them.
 *
 * Moodle drops the tables, settings, capabilities, tasks, message providers and stored files of the plugin on its own.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

/**
 * Uninstall hook. Safe to run again.
 *
 * A start page set to the Student Records home page would send everyone to a page that no longer exists after they log
 * in, so the site setting goes back to Moodle's default and the users' own choice is forgotten.
 *
 * Moodle takes the plugin's capabilities out of the two roles but leaves the roles. A role left empty would also look
 * finished to the plugin if it were installed again. So a role goes when it holds only capabilities of this plugin.
 * A role that a school gave other capabilities stays, because then it has a use of its own.
 *
 * @return bool
 */
function xmldb_local_studentrecords_uninstall(): bool {
    global $CFG, $DB;

    require_once($CFG->libdir . '/accesslib.php');

    $home = \local_studentrecords\local\home::URL;
    if ((string)($CFG->defaulthomepage ?? '') === $home) {
        set_config('defaulthomepage', get_default_home_page());
    }
    $preferences = $DB->get_records_select(
        'user_preferences',
        'name = ? AND ' . $DB->sql_compare_text('value') . ' = ' . $DB->sql_compare_text('?'),
        ['user_home_page_preference', $home]
    );
    foreach ($preferences as $preference) {
        $DB->delete_records('user_preferences', ['id' => $preference->id]);
        mark_user_preferences_changed($preference->userid);
    }

    $shortnames = [\local_studentrecords\local\billing::FINANCEROLE, \local_studentrecords\local\aid::ROLE];
    foreach ($DB->get_records_list('role', 'shortname', $shortnames) as $role) {
        $others = $DB->record_exists_select(
            'role_capabilities',
            'roleid = ? AND ' . $DB->sql_like('capability', '?', true, true, true),
            [$role->id, 'local/studentrecords:%']
        );
        if (!$others) {
            delete_role($role->id);
        }
    }
    return true;
}
