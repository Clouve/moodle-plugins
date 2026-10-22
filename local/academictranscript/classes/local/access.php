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

namespace local_academictranscript\local;

/**
 * Who may see and issue whose transcript.
 *
 * Admins (capability at system level) may act for any student. Teachers may act only
 * for users who hold a student role in one of the teacher's own courses.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /**
     * Course ids where the user holds a student-archetype role.
     *
     * @param int $userid
     * @return int[]
     */
    public static function student_courseids(int $userid): array {
        global $DB;
        $roleids = array_keys(get_archetype_roles('student'));
        if (!$roleids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $params['level'] = CONTEXT_COURSE;
        $sql = "SELECT DISTINCT ctx.instanceid
                  FROM {role_assignments} ra
                  JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :level
                 WHERE ra.userid = :userid AND ra.roleid $insql AND ctx.instanceid <> " . SITEID;
        return array_map('intval', $DB->get_fieldset_sql($sql, $params));
    }

    /**
     * Whether the current user is a staff member allowed to issue Official transcripts for $userid.
     *
     * @param int $userid student
     * @return bool
     */
    public static function can_issue(int $userid): bool {
        if (has_capability('local/academictranscript:issueofficial', \context_system::instance())) {
            return true;
        }
        foreach (self::student_courseids($userid) as $courseid) {
            if (has_capability('local/academictranscript:issueofficial', \context_course::instance($courseid))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the current user may view $userid's transcript.
     *
     * @param int $userid
     * @return bool
     */
    public static function can_view(int $userid): bool {
        global $USER;
        if ($userid == $USER->id && has_capability('local/academictranscript:viewown', \context_system::instance())) {
            return true;
        }
        return self::can_issue($userid);
    }

    /**
     * Send an admin to the setup wizard while setup is not finished.
     */
    public static function require_setup(): void {
        if (
            !get_config('local_academictranscript', 'setupcomplete')
                && has_capability('local/academictranscript:configure', \context_system::instance())
        ) {
            redirect(new \moodle_url('/local/academictranscript/setup.php'), get_string('setupfirst', 'local_academictranscript'));
        }
    }

    /**
     * Whether the current user is an admin for transcript purposes.
     *
     * @return bool
     */
    public static function is_admin(): bool {
        return has_capability('local/academictranscript:managerecords', \context_system::instance());
    }
}
