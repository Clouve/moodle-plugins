<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(graduation::class)]
/**
 * Tests for graduation: application, review against the degree audit, and conferral.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\graduation
 */
final class graduation_test extends \advanced_testcase {
    /**
     * A student with a program, signed in as the admin.
     *
     * @return \stdClass
     */
    private function student(): \stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        return $gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-1',
            'programid' => $gen->create_program()->id]);
    }

    /**
     * Approved with a note although the audit is not complete, conferred, and written on the record.
     */
    public function test_apply_review_confer(): void {
        global $DB;
        $student = $this->student();
        $id = graduation::apply($student, 0, str_repeat('a', 300));
        $this->assertSame(255, \core_text::strlen(graduation::applications((int)$student->id)[0]->note));
        try {
            graduation::apply($student, 0);
            $this->fail('A second open application was saved.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_gradappopen', $e->errorcode);
        }
        try {
            graduation::review($id, true, ' ');
            $this->fail('An incomplete audit was approved without a note.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_auditincomplete', $e->errorcode);
        }
        graduation::review($id, true, 'Waived by the dean');

        $date = catalog::date_to_time('2030-05-20');
        graduation::confer($id, $date, 'Bachelor of Arts', 'Cum laude');

        $record = students::get((int)$student->id);
        $this->assertSame('Bachelor of Arts, Cum laude', $record->awardname);
        $this->assertEquals($date, $record->awarddate);
        $this->assertSame('graduated', $record->status);
        $this->assertSame('conferred', $DB->get_field(graduation::TABLE, 'state', ['id' => $id]));
    }

    /**
     * A conferral that fails halfway leaves the record as it was and no open transaction.
     */
    public function test_a_failed_conferral_rolls_back(): void {
        global $DB;
        $this->preventResetByRollback();
        $student = $this->student();
        $id = graduation::apply($student, 0);
        graduation::review($id, true, 'Note');
        try {
            graduation::confer($id, time(), str_repeat('x', 300), '');
            $this->fail('An award name too long for its column was saved.');
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(\moodle_exception::class, $e);
        }
        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame('approved', $DB->get_field(graduation::TABLE, 'state', ['id' => $id]));
        $this->assertNull(students::get((int)$student->id)->awardname);
    }

    /**
     * Honors come from the school's rules with the GPA as the record shows it.
     */
    public function test_honors(): void {
        $rules = graduation::parse_honors('3.9 Summa cum laude; 3.5 Cum laude');
        $this->assertSame('Summa cum laude', graduation::honors_for($rules, 3.9));
        $this->assertSame('Cum laude', graduation::honors_for($rules, 3.89));
        $this->assertSame('', graduation::honors_for($rules, 3.49));
        $this->assertSame('', graduation::honors_for($rules, null));
    }

    /**
     * The person whose application it is can't review it or confer it, whatever their role. Someone else can.
     */
    public function test_nobody_reviews_or_confers_their_own_application(): void {
        global $DB;
        $student = $this->student();
        $id = graduation::apply($student, 0);
        $role = (int)$DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($role, (int)$student->userid, \context_system::instance()->id);
        $this->setUser((int)$student->userid);

        try {
            graduation::review($id, true, 'Fine by me');
            $this->fail('A person approved their own graduation.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_gradownapp', $e->errorcode);
        }
        $this->assertSame('applied', $DB->get_field(graduation::TABLE, 'state', ['id' => $id]));

        $this->setAdminUser();
        graduation::review($id, true, 'Waived');
        $this->setUser((int)$student->userid);

        try {
            graduation::confer($id, time(), 'Bachelor of Arts', '');
            $this->fail('A person conferred their own degree.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_gradownapp', $e->errorcode);
        }
        $this->assertSame('approved', $DB->get_field(graduation::TABLE, 'state', ['id' => $id]));
        $this->assertNull(students::get((int)$student->id)->awardname);
    }

    /**
     * A program with nothing to audit is no complete audit: approving needs a note like any other incomplete audit.
     */
    public function test_a_program_with_nothing_to_audit_needs_a_note(): void {
        global $DB;
        $student = $this->student();
        $DB->set_field('local_studentrecords_program', 'totalhours', null, ['id' => $student->programid]);
        $id = graduation::apply($student, 0);

        try {
            graduation::review($id, true, ' ');
            $this->fail('An audit with nothing to check was approved without a note.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_auditincomplete', $e->errorcode);
        }

        graduation::review($id, true, 'Checked by hand');

        $this->assertSame('incomplete', $DB->get_field(graduation::TABLE, 'auditresult', ['id' => $id]));
    }
}
