<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(academic::class)]
/**
 * Tests for holds and for grade changes: a second person approves, and nothing gets around that.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\academic
 */
final class academic_test extends \advanced_testcase {
    /**
     * Each test starts with a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A student with a completed course, graded B by staff, and two members of staff who may approve grade changes.
     *
     * @return array student, regid, user, term, registrar, dean
     */
    private function graded_course(): array {
        global $DB;
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $gen->create_scale();
        $term = $gen->create_term();
        $offer = $gen->create_offering(['courseid' => $gen->create_course()->id, 'termid' => $term->id]);
        $user = $this->getDataGenerator()->create_user();
        $student = $gen->create_student(['userid' => $user->id, 'studentid' => 'S-1']);
        $regid = $gen->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        $this->setAdminUser();
        registration::set_grade($regid, 'B', 85.0, 'staff');
        $managerrole = (int)$DB->get_field('role', 'id', ['shortname' => 'manager']);
        $syscontext = \context_system::instance();
        $registrar = $this->getDataGenerator()->create_user();
        $dean = $this->getDataGenerator()->create_user();
        role_assign($managerrole, $registrar->id, $syscontext->id);
        role_assign($managerrole, $dean->id, $syscontext->id);
        return ['student' => $student, 'regid' => $regid, 'user' => $user, 'term' => $term, 'registrar' => $registrar,
            'dean' => $dean];
    }

    /**
     * Run some code and give back the error code of the Moodle exception it ends with.
     *
     * @param callable $work
     * @return string
     */
    private function error_of(callable $work): string {
        try {
            $work();
        } catch (\moodle_exception $e) {
            return $e->errorcode;
        }
        $this->fail('No exception was thrown.');
    }

    /**
     * A second person's approval applies the grade, also after the grade freeze, and it stays against the gradebook.
     */
    public function test_a_second_person_approves(): void {
        global $DB;
        $c = $this->graded_course();
        $DB->set_field('local_studentrecords_term', 'gradefreeze', time() - 3 * DAYSECS, ['id' => $c['term']->id]);
        $this->setUser($c['registrar']);
        $id = academic::request_change($c['regid'], 'A', 91.5, 'The exam was marked again.');
        $this->assertSame('B', $DB->get_field(registration::REG, 'grade', ['id' => $c['regid']]));

        $this->setUser($c['dean']);
        academic::decide_change($id, true, 'Agreed');

        $reg = $DB->get_record(registration::REG, ['id' => $c['regid']]);
        $this->assertSame('A', $reg->grade);
        $this->assertSame('change', $reg->gradesource);
        $this->assertEquals(91.5, $reg->gradepct);
        $change = $DB->get_record(academic::CHANGES, ['id' => $id]);
        $this->assertSame('approved', $change->state);
        $this->assertEquals($c['dean']->id, $change->decidedby);
    }

    /**
     * The person who asked can't approve their own request, nor reject it.
     */
    public function test_the_person_who_asked_cannot_decide(): void {
        global $DB;
        $c = $this->graded_course();
        $this->setUser($c['registrar']);
        $id = academic::request_change($c['regid'], 'A', null, 'Reason');

        $this->assertSame('err_changeself', $this->error_of(fn() => academic::decide_change($id, true, '')));
        $this->assertSame('err_changeself', $this->error_of(fn() => academic::decide_change($id, false, '')));

        $this->assertSame('B', $DB->get_field(registration::REG, 'grade', ['id' => $c['regid']]));
        $this->assertSame('pending', $DB->get_field(academic::CHANGES, 'state', ['id' => $id]));
    }

    /**
     * Someone without the approve capability can't decide, and the student can't decide a change to their own grade even
     * with it.
     */
    public function test_only_an_approver_who_is_not_the_student_decides(): void {
        global $DB;
        $c = $this->graded_course();
        $this->setUser($c['registrar']);
        $id = academic::request_change($c['regid'], 'A', null, 'Reason');

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assert_cannot_decide($id);

        $role = (int)$DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($role, $c['user']->id, \context_system::instance()->id);
        $this->setUser($c['user']);
        $this->assertSame('err_changeownrecord', $this->error_of(fn() => academic::decide_change($id, true, '')));
        $this->assertSame('B', $DB->get_field(registration::REG, 'grade', ['id' => $c['regid']]));
    }

    /**
     * Deciding without the capability stops with the capability error.
     *
     * @param int $id change request
     */
    private function assert_cannot_decide(int $id): void {
        try {
            academic::decide_change($id, true, '');
            $this->fail('A user without the capability approved a grade change.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
    }

    /**
     * A request is decided once: a second click, or a second approver, gets an error and changes nothing.
     */
    public function test_a_request_is_decided_once(): void {
        global $DB;
        $c = $this->graded_course();
        $this->setUser($c['registrar']);
        $id = academic::request_change($c['regid'], 'A', null, 'Reason');
        $this->setUser($c['dean']);
        academic::decide_change($id, false, 'No');

        $this->assertSame('err_changedecided', $this->error_of(fn() => academic::decide_change($id, true, '')));

        $this->assertSame('rejected', $DB->get_field(academic::CHANGES, 'state', ['id' => $id]));
        $this->assertSame('B', $DB->get_field(registration::REG, 'grade', ['id' => $c['regid']]));
    }

    /**
     * A request can't be approved when the grade moved after it was made: the approver would see the wrong old grade.
     */
    public function test_a_stale_request_is_not_applied(): void {
        global $DB;
        $c = $this->graded_course();
        $this->setUser($c['registrar']);
        $id = academic::request_change($c['regid'], 'A', null, 'Reason');
        $DB->set_field(registration::REG, 'grade', 'C', ['id' => $c['regid']]);

        $this->setUser($c['dean']);
        $this->assertSame('err_changestale', $this->error_of(fn() => academic::decide_change($id, true, '')));

        $this->assertSame('C', $DB->get_field(registration::REG, 'grade', ['id' => $c['regid']]));
        academic::decide_change($id, false, 'The grade moved');
        $this->assertSame('rejected', $DB->get_field(academic::CHANGES, 'state', ['id' => $id]));
    }

    /**
     * A failure while applying the grade leaves the request waiting and no open transaction.
     */
    public function test_a_failed_approval_rolls_back(): void {
        global $DB;
        $this->preventResetByRollback();
        $c = $this->graded_course();
        $this->setUser($c['registrar']);
        $id = academic::request_change($c['regid'], 'A', null, 'Reason');
        $DB->set_field(registration::REG, 'status', 'dropped', ['id' => $c['regid']]);

        $this->setUser($c['dean']);
        $this->assertSame('err_gradenotactive', $this->error_of(fn() => academic::decide_change($id, true, '')));

        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame('pending', $DB->get_field(academic::CHANGES, 'state', ['id' => $id]));
    }

    /**
     * A request needs a completed course, a reason, a grade of the scale that differs, a percentage from 0 to 100, and no
     * other request waiting. Academic Transcript calls this directly, so the checks are here and not only in the form.
     */
    public function test_request_checks(): void {
        $c = $this->graded_course();
        $regid = $c['regid'];
        $this->setUser($c['registrar']);
        $this->assertSame('err_changereason', $this->error_of(fn() => academic::request_change($regid, 'A', null, ' ')));
        $this->assertSame(
            'err_teacherpost_notonscale',
            $this->error_of(fn() => academic::request_change($regid, 'Z', null, 'Reason'))
        );
        $this->assertSame('err_samegrade', $this->error_of(fn() => academic::request_change($regid, 'B', null, 'Reason')));
        $this->assertSame('err_pct', $this->error_of(fn() => academic::request_change($regid, 'A', 101.0, 'Reason')));
        academic::request_change($regid, 'P', null, 'A mark of the scale is a grade too');
        $this->assertSame('err_changepending', $this->error_of(fn() => academic::request_change($regid, 'A', null, 'Reason')));
    }

    /**
     * A course in progress has nothing to change.
     */
    public function test_request_needs_a_completed_course(): void {
        global $DB;
        $c = $this->graded_course();
        $DB->set_field(registration::REG, 'status', 'registered', ['id' => $c['regid']]);
        $this->assertSame(
            'err_changenotcompleted',
            $this->error_of(fn() => academic::request_change($c['regid'], 'A', null, 'Reason'))
        );
    }

    /**
     * Someone may decide a request that waits, with the approve capability, unless they asked for it or it is their own grade.
     */
    public function test_who_can_decide(): void {
        global $DB;
        $c = $this->graded_course();
        $this->setUser($c['registrar']);
        $id = academic::request_change($c['regid'], 'A', null, 'Reason');
        $change = academic::changes()[$id];
        $this->assertFalse(academic::can_decide($change), 'The person who asked decided.');

        $this->setUser($c['dean']);
        $this->assertTrue(academic::can_decide($change));

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(academic::can_decide($change), 'Someone without the capability decided.');

        role_assign((int)$DB->get_field('role', 'id', ['shortname' => 'manager']), $c['user']->id, \context_system::instance()->id);
        $this->setUser($c['user']);
        $this->assertFalse(academic::can_decide($change), 'The student decided on their own grade.');

        $this->setUser($c['dean']);
        academic::decide_change($id, false, 'No');
        $this->assertFalse(academic::can_decide(academic::changes('')[$id]), 'A decided request was decided again.');
    }

    /**
     * The list of requests joins course, term and student on every database.
     */
    public function test_changes_list(): void {
        $c = $this->graded_course();
        $this->setUser($c['registrar']);
        $id = academic::request_change($c['regid'], 'A', null, 'Reason');
        $this->assertArrayHasKey($id, academic::changes());
        $this->assertArrayHasKey($id, academic::changes('', (int)$c['student']->id));
        $this->assertSame([], academic::changes('approved'));
    }

    /**
     * A hold runs through its last day, as the form says, in the school's time zone, and a release ends it.
     */
    public function test_a_hold_runs_through_its_last_day(): void {
        $c = $this->graded_course();
        set_config('timezone', 'America/Chicago', 'local_studentrecords');
        $tz = catalog::timezone();
        $id = academic::place_hold($c['student'], ['holdtype' => 'records', 'reason' => 'Papers', 'blocksreg' => 1,
            'startdate' => make_timestamp(2030, 3, 1, 0, 0, 0, $tz), 'enddate' => make_timestamp(2030, 3, 12, 0, 0, 0, $tz)]);
        $hold = academic::holds((int)$c['student']->id)[0];
        $this->assertEquals($id, $hold->id);

        $this->assertTrue(academic::hold_active($hold, make_timestamp(2030, 3, 12, 18, 0, 0, $tz)));
        $this->assertFalse(academic::hold_active($hold, make_timestamp(2030, 3, 13, 0, 0, 1, $tz)));
        $this->assertSame('2030-03-12', catalog::time_to_date((int)$hold->enddate));
    }
}
