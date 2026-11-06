<?php

namespace local_academictranscript\local;

/**
 * Final grades that teachers post go into Student Records when it is the transcript source.
 *
 * The student's registration gets the grade on the school's grading scale. Student Records keeps its grade freeze and
 * never replaces a grade the Registrar set. Later changes are grade change requests that the Registrar approves there.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class srpost {
    /** @var string Student Records registration class */
    const REG = '\\local_studentrecords\\local\\registration';

    /**
     * Whether postings go into Student Records.
     *
     * @return bool
     */
    public static function active(): bool {
        return srsource::enabled() && method_exists(self::REG, 'teacher_post');
    }

    /**
     * Where the posting goes in Student Records, or null when not active or the student has no Student Records file.
     *
     * @param int $courseid
     * @param int $userid
     * @return array|null see registration::teacher_post_target()
     */
    public static function target(int $courseid, int $userid): ?array {
        if (!self::active()) {
            return null;
        }
        $target = \local_studentrecords\local\registration::teacher_post_target($courseid, $userid);
        return $target['state'] === 'norecord' ? null : $target;
    }

    /**
     * The registration that got a posted grade, or null when the row is not linked to one.
     *
     * A course can run more than once, so this is the way to find the attempt of a posted row. target() is for what comes
     * next.
     *
     * @param \stdClass $posted
     * @return array|null regid, grade
     */
    public static function posted_target(\stdClass $posted): ?array {
        global $DB;
        if (!self::active() || empty($posted->srregid)) {
            return null;
        }
        $grade = $DB->get_field('local_studentrecords_reg', 'grade', ['id' => $posted->srregid]);
        return $grade === false ? null : ['regid' => (int)$posted->srregid, 'grade' => (string)$grade];
    }

    /**
     * Whether a student's transcript comes from Student Records.
     *
     * @param int $userid
     * @return bool
     */
    public static function has_file(int $userid): bool {
        return self::active() && \local_studentrecords\local\students::get_by_user($userid) !== null;
    }

    /**
     * Why the grade cannot go into Student Records, or '' when it can.
     *
     * @param array $target
     * @return string
     */
    public static function problem(array $target): string {
        if ($target['state'] === 'ok') {
            return '';
        }
        return get_string('err_teacherpost_' . $target['state'], 'local_studentrecords', (object)$target);
    }

    /**
     * Letter on the school's grading scale (Student Records) for a percentage or a hand-entered code.
     *
     * @param float|null $percent
     * @param string $code
     * @return string|null
     */
    public static function letter(?float $percent, string $code): ?string {
        return \local_studentrecords\local\registration::teacher_letter($percent, $code);
    }

    /**
     * Save the posted grade on the Student Records registration.
     *
     * @param int $courseid
     * @param int $userid
     * @param float|null $percent
     * @param string $grade
     * @return string letter saved
     */
    public static function post(int $courseid, int $userid, ?float $percent, string $grade): string {
        return \local_studentrecords\local\registration::teacher_post($courseid, $userid, $percent, $grade);
    }

    /**
     * Letters and marks (I, P, NP, W, NC and others) of the school's grading scale, for the change request menu.
     *
     * @return array letter => letter
     */
    public static function letters(): array {
        $catalog = '\\local_studentrecords\\local\\catalog';
        return method_exists($catalog, 'grade_menu') ? $catalog::grade_menu() : $catalog::letters();
    }

    /**
     * Whether a grade change for the registration is already waiting in Student Records.
     *
     * @param int $regid
     * @return bool
     */
    public static function change_pending(int $regid): bool {
        global $DB;
        return $DB->record_exists(\local_studentrecords\local\academic::CHANGES, ['regid' => $regid, 'state' => 'pending']);
    }

    /**
     * Ask for a grade change in Student Records. The Registrar approves it there.
     *
     * @param int $regid
     * @param string $newgrade
     * @param string $reason
     * @return int request id
     */
    public static function request_change(int $regid, string $newgrade, string $reason): int {
        return \local_studentrecords\local\academic::request_change($regid, $newgrade, null, $reason);
    }
}
