<?php

/**
 * Site administration > Plugins > Local plugins: one link to the Student Records staff area, for admins. The staff
 * pages themselves are outside Site administration (their own menu in the staff area).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_studentrecords_staff',
        get_string('staffarea', 'local_studentrecords'),
        new moodle_url('/local/studentrecords/staff.php'),
        'moodle/site:config'
    ));
}
