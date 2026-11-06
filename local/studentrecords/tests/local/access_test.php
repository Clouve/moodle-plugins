<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(access::class)]
/**
 * Tests for who may open which record and which file.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\access
 */
final class access_test extends \advanced_testcase {
    /** @var \stdClass A student of the first course. */
    private \stdClass $student;

    /** @var \stdClass A student of the second course. */
    private \stdClass $otherstudent;

    /** @var \stdClass A teacher of the first course. */
    private \stdClass $teacher;

    /** @var \stdClass The first course. */
    private \stdClass $course;

    /**
     * Two courses, a teacher of the first and a student in each.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $sr = $gen->get_plugin_generator('local_studentrecords');
        $this->course = $gen->create_course();
        $other = $gen->create_course();
        $this->teacher = $gen->create_user();
        $gen->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->setAdminUser();
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $this->course->id, 'student');
        $this->student = $sr->create_student(['userid' => $user->id, 'studentid' => 'S1']);
        $otheruser = $gen->create_user();
        $gen->enrol_user($otheruser->id, $other->id, 'student');
        $this->otherstudent = $sr->create_student(['userid' => $otheruser->id, 'studentid' => 'S2']);
    }

    /**
     * A user with one capability at system level.
     *
     * @param string[] $caps
     * @return \stdClass
     */
    private function user_with(array $caps): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Staff ' . $user->id, 'staff' . $user->id, '');
        foreach ($caps as $cap) {
            assign_capability('local/studentrecords:' . $cap, CAP_ALLOW, $roleid, SYSCONTEXTID);
        }
        role_assign($roleid, $user->id, SYSCONTEXTID);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * A teacher sees the students of their own course and no others.
     */
    public function test_teacher_sees_only_own_students(): void {
        $this->setUser($this->teacher);
        $this->assertTrue(access::can_view_student((int)$this->student->userid));
        $this->assertFalse(access::can_view_student((int)$this->otherstudent->userid));
        $this->assertFalse(access::sees_all_students());
    }

    /**
     * A student who is no longer active in the course is no longer the teacher's student.
     */
    public function test_teacher_loses_a_suspended_student(): void {
        global $DB;
        $enrol = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual']);
        enrol_get_plugin('manual')->update_user_enrol($enrol, (int)$this->student->userid, ENROL_USER_SUSPENDED);
        $this->setUser($this->teacher);
        $this->assertFalse(access::can_view_student((int)$this->student->userid));
    }

    /**
     * A student sees their own record and not a classmate's. A student who may not view their own record does not.
     */
    public function test_student_sees_only_own_record(): void {
        $this->setUser((int)$this->student->userid);
        $this->assertTrue(access::can_view_student((int)$this->student->userid));
        $this->assertFalse(access::can_view_student((int)$this->otherstudent->userid));
        $this->assertFalse(access::can_manage_students());

        $this->expectException(\required_capability_exception::class);
        access::require_view_student((int)$this->otherstudent->userid);
    }

    /**
     * Records staff see everyone. Viewing is not managing.
     */
    public function test_records_staff(): void {
        $this->setUser($this->user_with(['viewstudents']));
        $this->assertTrue(access::sees_all_students());
        $this->assertTrue(access::can_view_student((int)$this->otherstudent->userid));
        $this->assertFalse(access::can_manage_students());
    }

    /**
     * A FERPA opt-out hides directory details from teachers and view-only staff, not from the student or from managers.
     */
    public function test_hide_directory(): void {
        $this->student->ferpaoptout = 1;
        $this->setUser($this->teacher);
        $this->assertTrue(access::hide_directory($this->student));
        $this->setUser($this->user_with(['viewstudents']));
        $this->assertTrue(access::hide_directory($this->student));
        $this->setUser((int)$this->student->userid);
        $this->assertFalse(access::hide_directory($this->student));
        $this->setUser($this->user_with(['managestudents']));
        $this->assertFalse(access::hide_directory($this->student));

        $this->student->ferpaoptout = 0;
        $this->setUser($this->teacher);
        $this->assertFalse(access::hide_directory($this->student));
    }

    /**
     * Student documents: the student and staff who manage students, no one else.
     */
    public function test_can_read_student_documents(): void {
        $id = (int)$this->student->id;
        $this->setUser((int)$this->student->userid);
        $this->assertTrue(access::can_read_file('studentdocs', $id));
        $this->assertFalse(access::can_read_file('studentdocs', (int)$this->otherstudent->id));
        $this->assertFalse(access::can_read_file('studentdocs', 99999));
        $this->setUser($this->teacher);
        $this->assertFalse(access::can_read_file('studentdocs', $id));
        $this->setUser($this->user_with(['viewstudents']));
        $this->assertFalse(access::can_read_file('studentdocs', $id));
        $this->setUser($this->user_with(['managestudents']));
        $this->assertTrue(access::can_read_file('studentdocs', $id));
    }

    /**
     * A student who lost the right to view their own record loses their documents too.
     */
    public function test_cannot_read_own_documents_without_viewown(): void {
        global $CFG;
        assign_capability('local/studentrecords:viewown', CAP_PROHIBIT, (int)$CFG->defaultuserroleid, SYSCONTEXTID, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser((int)$this->student->userid);
        $this->assertFalse(access::can_read_file('studentdocs', (int)$this->student->id));
    }

    /**
     * Credentials and imported files need their capability, the logo a login, and other file areas are not served.
     */
    public function test_can_read_other_files(): void {
        $this->setUser((int)$this->student->userid);
        $this->assertFalse(access::can_read_file('credential', 1));
        $this->assertFalse(access::can_read_file('importfile', 1));
        $this->assertTrue(access::can_read_file('logo', 0));
        $this->assertFalse(access::can_read_file('anythingelse', 0));
        $this->setUser($this->user_with(['viewfaculty']));
        $this->assertTrue(access::can_read_file('credential', 1));
        $this->assertFalse(access::can_read_file('importfile', 1));
        $this->setUser($this->user_with(['import']));
        $this->assertTrue(access::can_read_file('importfile', 1));
        $this->setUser(0);
        $this->assertFalse(access::can_read_file('logo', 0));
    }
}
