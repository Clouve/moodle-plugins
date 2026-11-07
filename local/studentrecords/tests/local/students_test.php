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
     * Working out an automatic student ID and saving the record happen under one lock, so two new students can't get the same
     * number. The lock outlasts the commit: until then the next request couldn't see the number was taken.
     */
    public function test_automatic_ids_are_given_one_at_a_time(): void {
        global $CFG, $DB;
        require_once(__DIR__ . '/../fixtures/recording_lock_factory.php');
        $this->preventResetByRollback();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $generator->set_settings(['idauto' => 1, 'idprefix' => 'RVC', 'idyear' => 0]);
        $user = $this->getDataGenerator()->create_user();
        $typed = $this->getDataGenerator()->create_user();
        $CFG->lock_factory = recording_lock_factory::class;
        recording_lock_factory::$notes = [];
        recording_lock_factory::$state = fn() => [$DB->count_records(students::TABLE), $DB->is_transaction_started()];
        $record = ['legalfirstname' => 'Ana', 'legallastname' => 'Silva', 'status' => 'applicant'];

        $id = students::save($record + ['userid' => $user->id]);

        $this->assertSame('RVC-0001', students::get($id)->studentid);
        $expected = [['taken', 'studentno', [0, false]], ['released', 'studentno', [1, false]]];
        $this->assertSame($expected, recording_lock_factory::$notes);

        recording_lock_factory::$notes = [];
        students::save($record + ['userid' => $typed->id, 'studentid' => 'TYPED1']);
        students::save(['id' => $id, 'studentid' => '', 'preferredname' => 'Ana']);

        $this->assertSame([], recording_lock_factory::$notes);
        $this->assertSame('RVC-0001', students::get($id)->studentid);
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

    /**
     * A student who is a member of staff too, signed in.
     *
     * @return \stdClass the student
     */
    private function staff_member_with_a_record(): \stdClass {
        global $DB;
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $student = $gen->create_user_student(['programid' => $gen->create_program()->id]);
        $role = (int)$DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($role, (int)$student->userid, \context_system::instance()->id);
        $this->setUser((int)$student->userid);
        return $student;
    }

    /**
     * Nobody changes what decides a degree on their own record, and the record stays as it was.
     *
     * @dataProvider deciding_field_provider
     * @param string $field
     * @param mixed $value
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('deciding_field_provider')]
    public function test_nobody_changes_what_decides_a_degree_on_their_own_record(string $field, $value): void {
        $student = $this->staff_member_with_a_record();

        try {
            students::save(['id' => $student->id, $field => $value]);
            $this->fail('A person changed their own ' . $field . '.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_ownrecord', $e->errorcode);
        }

        $this->assertEquals($student->$field, students::get((int)$student->id)->$field);
    }

    /**
     * Data for test_nobody_changes_what_decides_a_degree_on_their_own_record.
     *
     * @return array
     */
    public static function deciding_field_provider(): array {
        return [
            'status' => ['status', 'graduated'],
            'award' => ['awardname', 'Doctor of Everything'],
            'award date' => ['awarddate', 1893456000],
            'program' => ['programid', 999999],
        ];
    }

    /**
     * Staff keep their own contact details, and saving a form with the deciding fields as they are is no change to them.
     */
    public function test_staff_edit_the_rest_of_their_own_record(): void {
        $student = $this->staff_member_with_a_record();

        students::save(['id' => $student->id, 'phone' => '555-0100', 'status' => $student->status,
            'programid' => $student->programid, 'awardname' => '', 'awarddate' => null]);

        $this->assertSame('555-0100', students::get((int)$student->id)->phone);
    }

    /**
     * Nobody makes their own student record: it could start as Graduated, with an award. Another person can.
     */
    public function test_nobody_creates_their_own_record(): void {
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $record = ['userid' => $user->id, 'studentid' => 'S-9', 'legalfirstname' => 'A', 'legallastname' => 'B',
            'status' => 'graduated', 'awardname' => 'Doctor of Everything'];

        try {
            students::save($record);
            $this->fail('A person created their own record.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_ownrecord', $e->errorcode);
        }
        $this->assertNull(students::get_by_user((int)$user->id));

        $this->setAdminUser();
        $this->assertGreaterThan(0, students::save($record));
    }

    /**
     * Another member of staff can change all of it.
     */
    public function test_another_person_changes_what_decides_a_degree(): void {
        $student = $this->staff_member_with_a_record();
        $this->setAdminUser();

        students::save(['id' => $student->id, 'status' => 'withdrawn', 'awardname' => 'Certificate']);

        $saved = students::get((int)$student->id);
        $this->assertSame('withdrawn', $saved->status);
        $this->assertSame('Certificate', $saved->awardname);
    }
}
