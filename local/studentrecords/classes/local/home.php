<?php

namespace local_studentrecords\local;

/**
 * The Student Records home page rules: who gets the "Go to my courses" link, and which staff never use the LMS.
 *
 * Site admins always get the courses link. Anyone else gets it with an active enrolment in a course they can
 * open, unless their staff record says "No LMS access". No LMS staff are sent back to the home page from
 * course, Dashboard and My courses pages.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class home {
    /** @var string The home page. */
    const URL = '/local/studentrecords/index.php';

    /** @var string[] Script paths of LMS pages that No LMS staff are sent away from. */
    const LMS_PATHS = ['/my/', '/course/', '/mod/', '/enrol/', '/grade/'];

    /** @var bool[] No LMS flag by user id, for this request. */
    protected static $nolms = [];

    /**
     * Whether the user's staff record says "No LMS access" (site admins never count as No LMS).
     *
     * @param int $userid
     * @return bool
     */
    public static function is_nolms(int $userid): bool {
        global $DB;
        if (!$userid || is_siteadmin($userid)) {
            return false;
        }
        if (!isset(self::$nolms[$userid])) {
            try {
                self::$nolms[$userid] = $DB->record_exists('local_studentrecords_faculty', ['userid' => $userid, 'nolms' => 1]);
            } catch (\dml_exception $e) {
                // Never block the site over this check (for example during an upgrade).
                return false;
            }
        }
        return self::$nolms[$userid];
    }

    /**
     * Forget the cached flags (after a staff record is saved, and in tests).
     */
    public static function reset_cache(): void {
        self::$nolms = [];
    }

    /**
     * Whether the user gets the "Go to my courses" link.
     *
     * @param int $userid
     * @return bool
     */
    public static function shows_courses(int $userid): bool {
        if (!$userid || isguestuser($userid)) {
            return false;
        }
        if (is_siteadmin($userid)) {
            return true;
        }
        if (self::is_nolms($userid)) {
            return false;
        }
        foreach (enrol_get_all_users_courses($userid, true, 'id, visible') as $course) {
            if (
                $course->visible ||
                    has_capability('moodle/course:viewhiddencourses', \context_course::instance($course->id), $userid)
            ) {
                return true;
            }
        }
        return false;
    }

    /** @var string[] System permissions that make a person staff (never sent straight to a course). */
    const STAFFCAPS = ['local/studentrecords:configure', 'local/studentrecords:managestudents',
        'local/studentrecords:viewstudents', 'local/studentrecords:viewfaculty', 'local/studentrecords:managefaculty',
        'local/studentrecords:viewbilling', 'local/studentrecords:managebilling', 'local/studentrecords:viewaid',
        'local/studentrecords:import', 'local/studentrecords:viewlog', 'local/academictranscript:issueofficial',
        'local/academictranscript:configure', 'moodle/site:config'];

    /**
     * Whether the current user is staff for the single-course rule: site admin, a staff permission above, or able to
     * edit the given course (a teacher there).
     *
     * @param int $courseid
     * @return bool
     */
    public static function is_staff_user(int $courseid): bool {
        if (is_siteadmin()) {
            return true;
        }
        $sys = \context_system::instance();
        foreach (self::STAFFCAPS as $cap) {
            if (get_capability_info($cap) && has_capability($cap, $sys)) {
                return true;
            }
        }
        return $courseid && has_capability('moodle/course:update', \context_course::instance($courseid));
    }

    /**
     * The course a person goes straight to from the Student Records home page: only when they have no Student Records
     * file, no Student Records pages and exactly one course (for example a demo account). Never for No LMS staff: the
     * course would send them back here, and round again.
     *
     * @param bool $hasrecord has a Student Records student file
     * @param bool $hassrpages sees any Student Records menu or staff page
     * @param int[] $courseids the person's visible courses
     * @param bool $isstaff site admin, any Student Records or transcript staff permission, or can edit the course
     * @param bool $nolms the person's staff record says No LMS access
     * @return int|null course id, or null to show the home page
     */
    public static function single_course_target(
        bool $hasrecord,
        bool $hassrpages,
        array $courseids,
        bool $isstaff = false,
        bool $nolms = false
    ): ?int {
        if ($isstaff || $nolms || $hasrecord || $hassrpages || count($courseids) !== 1) {
            return null;
        }
        return (int)reset($courseids);
    }

    /**
     * Whether a page is an LMS page: any course other than the site course, or a script under an LMS path.
     *
     * @param string $script script path from the Moodle root, for example /course/view.php
     * @param int $courseid course of the page, 0 if none
     * @param int $siteid the site course id
     * @return bool
     */
    public static function is_lms_page(string $script, int $courseid, int $siteid): bool {
        if ($courseid && $courseid != $siteid) {
            return true;
        }
        foreach (self::LMS_PATHS as $path) {
            if (str_starts_with($script, $path)) {
                return true;
            }
        }
        return false;
    }
}
