<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(students::class)]
/**
 * Tests for student records.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\students
 */
final class students_test extends \advanced_testcase {
    /**
     * Start each test as an admin on a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The preferred name is shown, unless the viewer may not see directory details.
     */
    public function test_display_name(): void {
        $s = (object)['legalfirstname' => 'Ana', 'legalmiddlename' => '', 'legallastname' => 'Ruiz', 'preferredname' => 'Annie'];
        $this->assertSame('Ana Ruiz (Annie)', students::display_name($s));
        $this->assertSame('Ana Ruiz', students::display_name($s, true));
    }

    /**
     * Saving writes the change history, the status history and the audit log, and a new status is noted.
     */
    public function test_save(): void {
        global $DB;
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $user = $this->getDataGenerator()->create_user();
        $student = $sr->create_student(['userid' => $user->id, 'studentid' => 'S1', 'status' => 'applicant']);

        students::save(['id' => $student->id, 'status' => 'admitted', 'statusnote' => 'Offer sent']);

        $this->assertSame('admitted', students::get((int)$student->id)->status);
        $this->assertTrue($DB->record_exists('local_studentrecords_stathis', ['studentid' => $student->id,
            'newstatus' => 'admitted', 'note' => 'Offer sent']));
        $this->assertTrue($DB->record_exists('local_studentrecords_log', ['action' => 'update', 'objecttype' => 'student',
            'objectid' => $student->id]));
    }

    /**
     * Saving keeps the Moodle user of a record, whatever the data says.
     */
    public function test_save_keeps_the_user(): void {
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $student = $sr->create_student(['userid' => $user->id, 'studentid' => 'S1']);

        students::save(['id' => $student->id, 'userid' => $other->id]);

        $this->assertEquals($user->id, students::get((int)$student->id)->userid);
    }

    /**
     * Every optional field on the record is in the list that a deletion request clears.
     */
    public function test_personal_list_covers_the_reporting_fields(): void {
        foreach (['residency', 'citizenship', 'firstgen', 'highschool', 'hsyear', 'visatype', 'sevisid'] as $field) {
            $this->assertContains($field, students::PERSONAL);
        }
    }

    /**
     * A deletion for a user without a student record does nothing.
     */
    public function test_erase_without_a_record(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        students::erase_personal((int)$user->id);
        $this->assertFalse($DB->record_exists('local_studentrecords_log', ['action' => 'privacyerase']));
    }
}
