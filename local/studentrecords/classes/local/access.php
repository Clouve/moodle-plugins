<?php

namespace local_studentrecords\local;

/**
 * Who may open which record.
 *
 * Staff with viewstudents or managestudents at system level see every student. A teacher sees a student only
 * when the teacher holds viewstudents in a course the student is actively enrolled in. Students may see their own
 * record.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class access {
    /**
     * Whether a student record is the current user's own. Staff don't set their own grade or decide their own graduation.
     *
     * @param \stdClass $student record
     * @return bool
     */
    public static function is_own(\stdClass $student): bool {
        global $USER;
        return (int)$student->userid === (int)$USER->id;
    }

    /**
     * Whether the current user may see all students.
     *
     * @return bool
     */
    public static function sees_all_students(): bool {
        $sys = \context_system::instance();
        return has_capability('local/studentrecords:managestudents', $sys) ||
            has_capability('local/studentrecords:viewstudents', $sys);
    }

    /**
     * Whether the current user may manage (edit) student records.
     *
     * @return bool
     */
    public static function can_manage_students(): bool {
        return has_capability('local/studentrecords:managestudents', \context_system::instance());
    }

    /**
     * Courses in which the current user may see this student (the student is actively enrolled there).
     *
     * @param int $studentuserid
     * @return int[] course ids
     */
    public static function shared_courses(int $studentuserid): array {
        $out = [];
        foreach (enrol_get_users_courses($studentuserid, true, 'id') as $course) {
            if (has_capability('local/studentrecords:viewstudents', \context_course::instance($course->id))) {
                $out[] = (int)$course->id;
            }
        }
        return $out;
    }

    /**
     * Whether the current user may open this student's record.
     *
     * @param int $studentuserid
     * @return bool
     */
    public static function can_view_student(int $studentuserid): bool {
        global $USER;
        if (self::sees_all_students()) {
            return true;
        }
        if ($studentuserid == $USER->id) {
            return has_capability('local/studentrecords:viewown', \context_system::instance());
        }
        return (bool)self::shared_courses($studentuserid);
    }

    /**
     * Throw unless the current user may open this student's record.
     *
     * @param int $studentuserid
     */
    public static function require_view_student(int $studentuserid): void {
        if (!self::can_view_student($studentuserid)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/studentrecords:viewstudents',
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Whether the current user may read a file of the plugin. Student documents: the student, and staff who manage
     * students (the file names are shown to no one else). Faculty credentials: staff who view faculty records.
     * Imported files: import staff. The school logo: anyone logged in. No other file area is served.
     *
     * @param string $filearea
     * @param int $itemid
     * @return bool
     */
    public static function can_read_file(string $filearea, int $itemid): bool {
        global $DB, $USER;
        $sys = \context_system::instance();
        switch ($filearea) {
            case 'studentdocs':
                $owner = (int)$DB->get_field(students::TABLE, 'userid', ['id' => $itemid]);
                if (!$owner) {
                    return false;
                }
                return self::can_manage_students() || ($owner === (int)$USER->id &&
                    has_capability('local/studentrecords:viewown', $sys));
            case 'credential':
                return has_capability('local/studentrecords:viewfaculty', $sys);
            case 'importfile':
                return has_capability('local/studentrecords:import', $sys);
            case 'logo':
                return isloggedin();
        }
        return false;
    }

    /**
     * Whether directory information (contact details, addresses) must be hidden from the current user.
     *
     * @param \stdClass $student record
     * @return bool
     */
    public static function hide_directory(\stdClass $student): bool {
        global $USER;
        return !empty($student->ferpaoptout) && $student->userid != $USER->id && !self::can_manage_students();
    }
}
