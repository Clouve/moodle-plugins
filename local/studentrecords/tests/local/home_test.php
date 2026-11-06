<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(home::class)]
/**
 * Tests for the home page rules: which pages No LMS staff are sent away from.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\home
 */
final class home_test extends \basic_testcase {
    /**
     * Course, Dashboard and My courses pages are LMS pages; Student Records, profile and login pages are not.
     */
    public function test_is_lms_page(): void {
        $lms = ['/my/', '/my/index.php', '/my/courses.php', '/course/view.php', '/course/index.php', '/mod/quiz/view.php',
            '/enrol/index.php', '/grade/report/index.php'];
        foreach ($lms as $script) {
            $this->assertTrue(home::is_lms_page($script, 0, 1), $script);
        }
        $other = [home::URL, '/local/studentrecords/student.php', '/user/profile.php', '/login/logout.php', '/index.php',
            '/user/edit.php', '/message/index.php', '/admin/index.php'];
        foreach ($other as $script) {
            $this->assertFalse(home::is_lms_page($script, 0, 1), $script);
            $this->assertFalse(home::is_lms_page($script, 1, 1), $script . ' site course');
        }
        $this->assertTrue(home::is_lms_page('/local/studentrecords/students.php', 5, 1));
        $this->assertFalse(home::is_lms_page('/courseware/x.php', 0, 1));
    }

    /**
     * Straight to the course: no file, no Student Records pages and exactly one course.
     */
    public function test_single_course_target(): void {
        $this->assertSame(7, home::single_course_target(false, false, [7]));
        $this->assertNull(home::single_course_target(false, false, [7], false, true), 'the course would send No LMS staff back');
        $this->assertNull(home::single_course_target(true, false, [7]));
        $this->assertNull(home::single_course_target(false, true, [7]));
        $this->assertNull(home::single_course_target(false, false, []));
        $this->assertNull(home::single_course_target(false, false, [7, 8]));
        $this->assertNull(home::single_course_target(false, false, [7], true));
    }
}
