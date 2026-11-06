<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(faculty::class)]
/**
 * Tests for faculty records.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\faculty
 */
final class faculty_test extends \advanced_testcase {
    /**
     * Start each test as an admin on a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The course menu is HTML, ready to print. The codes on the roster are plain text.
     */
    public function test_qualified_courses(): void {
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $course = $sr->create_course(['code' => 'A&B100', 'title' => 'Arts & Crafts']);
        $id = faculty::save(['firstname' => 'Lee', 'lastname' => 'Park', 'stafftype' => 'faculty']);
        faculty::add_qualifications($id, [$course->id]);

        $this->assertSame('A&amp;B100 Arts &amp; Crafts', faculty::qualified_courses($id)[$course->id]);
        $this->assertSame(['A&B100'], faculty::qualified_codes($id));
        $this->assertSame('A&B100', faculty::roster()[0]['courses']);
    }

    /**
     * A removed qualification is kept, marked, and no longer counts.
     */
    public function test_remove_qualification(): void {
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $course = $sr->create_course();
        $id = faculty::save(['firstname' => 'Lee', 'lastname' => 'Park', 'stafftype' => 'faculty']);
        faculty::add_qualifications($id, [$course->id]);
        $this->assertTrue(faculty::is_qualified($id, (int)$course->id));

        $this->assertSame(1, faculty::remove_qualifications($id, [$course->id]));

        $this->assertFalse(faculty::is_qualified($id, (int)$course->id));
        $this->assertCount(1, faculty::removed_qualifications($id));
    }

    /**
     * Saving a record forgets the cached No LMS flag, so the change counts at once.
     */
    public function test_saving_resets_no_lms(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->assertFalse(home::is_nolms((int)$user->id));
        faculty::save(['userid' => $user->id, 'firstname' => 'Lee', 'lastname' => 'Park', 'stafftype' => 'staff', 'nolms' => 1]);
        $this->assertTrue(home::is_nolms((int)$user->id));
        home::reset_cache();
    }

    /**
     * A site admin is never No LMS, so a staff record cannot lock an admin out of the site.
     */
    public function test_site_admin_is_never_no_lms(): void {
        global $USER;
        faculty::save(['userid' => $USER->id, 'firstname' => 'Lee', 'lastname' => 'Park', 'stafftype' => 'staff', 'nolms' => 1]);
        $this->assertFalse(home::is_nolms((int)$USER->id));
        home::reset_cache();
    }
}
