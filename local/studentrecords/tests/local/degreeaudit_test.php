<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(degreeaudit::class)]
/**
 * Tests for the degree audit and honors.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\degreeaudit
 */
final class degreeaudit_test extends \advanced_testcase {
    /**
     * A program of two required courses: one passed, one in progress, with the audit read from the database.
     */
    public function test_run_on_the_database(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $gen->create_scale();
        $program = $gen->create_program();
        $DB->update_record('local_studentrecords_program', (object)['id' => $program->id, 'totalhours' => 6, 'mingpa' => 2]);
        $term = $gen->create_term();
        $student = $gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-1',
            'programid' => $program->id]);
        $regs = [];
        foreach (['ENG 101', 'MAT 101'] as $i => $code) {
            $course = $gen->create_course(['code' => $code]);
            $DB->insert_record('local_studentrecords_progcrs', (object)['programid' => $program->id, 'courseid' => $course->id,
                'groupname' => 'Core', 'requirement' => 'required', 'sortorder' => $i]);
            $offer = $gen->create_offering(['courseid' => $course->id, 'termid' => $term->id]);
            $regs[] = $gen->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        }
        registration::set_grade($regs[0], 'A', null, 'staff');

        $audit = degreeaudit::run(students::get((int)$student->id));

        $this->assertFalse($audit['complete']);
        $this->assertTrue($audit['completeifpassed']);
        $this->assertSame(3.0, $audit['earned']);
        $this->assertSame(3.0, $audit['inprogress']);

        registration::set_grade($regs[1], 'F', null, 'staff');
        $this->assertFalse(degreeaudit::run(students::get((int)$student->id))['completeifpassed']);
    }

    /**
     * A substitution names two real courses, different ones, with a reason.
     */
    public function test_substitution_checks(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $student = $gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-1']);
        $a = (int)$gen->create_course()->id;
        $b = (int)$gen->create_course()->id;
        foreach ([[$a, $a, 'Reason'], [$a, $b, ' '], [$a, 999999, 'Reason']] as [$req, $sub, $note]) {
            try {
                degreeaudit::add_substitution($student, $req, $sub, $note);
                $this->fail('A bad substitution was saved.');
            } catch (\moodle_exception $e) {
                $this->assertSame('err_substitution', $e->errorcode);
            }
        }
        $this->assertSame(0, $DB->count_records('local_studentrecords_subst'));
        degreeaudit::add_substitution($student, $a, $b, 'Reason');
        $this->assertCount(1, degreeaudit::substitutions((int)$student->id));
    }

    /**
     * A small MBA-like outline: 2 required courses, two tracks of 1 course each, an elective group of 3 hours.
     *
     * @return array
     */
    protected static function outline(): array {
        $row = fn($id, $group, $req, $hours = 3) => ['courseid' => $id, 'code' => 'C' . $id, 'title' => 'Course ' . $id,
            'hours' => $hours, 'groupname' => $group, 'requirement' => $req];
        return [$row(1, 'Core (6 credits)', 'required'), $row(2, 'Core (6 credits)', 'required'),
            $row(3, 'Finance Track', 'track'), $row(4, 'Marketing Track', 'track'),
            $row(5, 'Electives (3 credits)', 'elective'), $row(6, 'Electives (3 credits)', 'elective')];
    }

    /**
     * Complete when required groups, one track, electives, hours and GPA are met; otherwise the open items are listed.
     */
    public function test_evaluate(): void {
        $met = fn($s = 'met') => ['state' => $s, 'letter' => 'A', 'term' => 'Fall', 'source' => 'course'];
        $program = ['totalhours' => 12, 'mingpa' => 3.0];
        $all = [1 => $met(), 2 => $met(), 4 => $met(), 6 => $met()];
        $r = degreeaudit::evaluate(self::outline(), $all, 0.0, $program, 3.5);
        $this->assertTrue($r['complete']);
        $this->assertSame('Marketing Track', $r['track']);
        $this->assertEquals(12.0, $r['earned']);

        $r = degreeaudit::evaluate(self::outline(), [1 => $met(), 2 => $met('inprogress'), 3 => $met()], 0.0, $program, 2.5);
        $this->assertFalse($r['complete']);
        $this->assertSame('Finance Track', $r['track']);
        $this->assertEquals(6.0, $r['earned']);
        $this->assertEquals(3.0, $r['inprogress']);
        $keys = array_map(fn($m) => $m[0] . ':' . (is_string($m[1]) ? $m[1] : ''), $r['missing']);
        $this->assertSame(['audit_groupnotmet:Core (6 credits)', 'audit_groupnotmet:Electives (3 credits)', 'audit_hours:',
            'audit_gpa:'], $keys);

        // A course not passed does not count; transfer credit without an equivalent counts toward the hours.
        $r = degreeaudit::evaluate(
            self::outline(),
            [1 => $met(), 2 => $met('notpassed'), 3 => $met(), 5 => $met()],
            3.0,
            ['totalhours' => 12, 'mingpa' => null],
            null
        );
        $this->assertEquals(12.0, $r['earned']);
        $this->assertFalse($r['complete']);
        $this->assertTrue($r['gpaok']);
    }

    /**
     * Hours a group asks for, from its name.
     */
    public function test_group_hours(): void {
        $this->assertEquals(33.0, degreeaudit::group_hours('General Education Courses (33 credits)'));
        $this->assertEquals(3.0, degreeaudit::group_hours('Capstone (3 credits)'));
        $this->assertNull(degreeaudit::group_hours('Finance Specialization'));
        $this->assertEquals(320.0, degreeaudit::group_hours('Level 1 (320 clock hours)'));
    }

    /**
     * Honors rules and the honors for a GPA.
     */
    public function test_honors(): void {
        $rules = graduation::parse_honors("3.5 Cum laude; 3.9 Summa cum laude\n3.7: Magna cum laude");
        $this->assertSame([[3.9, 'Summa cum laude'], [3.7, 'Magna cum laude'], [3.5, 'Cum laude']], $rules);
        $this->assertSame('Magna cum laude', graduation::honors_for($rules, 3.75));
        $this->assertSame('Summa cum laude', graduation::honors_for($rules, 3.9));
        $this->assertSame('', graduation::honors_for($rules, 3.2));
        $this->assertSame('', graduation::honors_for($rules, null));
        $this->assertSame([], graduation::parse_honors(''));
    }

    /**
     * An attempt the repeat rule leaves out meets no requirement: a failed retake under "latest" undoes the earlier pass,
     * as it does on the record and the transcript.
     */
    public function test_an_attempt_left_out_meets_no_requirement(): void {
        global $DB;
        if (\core_component::get_component_directory('local_academictranscript') === null) {
            $this->markTestSkipped('Academic Transcript is not installed.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $gen->create_scale();
        $program = $gen->create_program();
        $DB->update_record('local_studentrecords_program', (object)['id' => $program->id, 'totalhours' => 3]);
        $student = $gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-1',
            'programid' => $program->id]);
        $course = $gen->create_course(['code' => 'ENG 101']);
        $DB->insert_record('local_studentrecords_progcrs', (object)['programid' => $program->id, 'courseid' => $course->id,
            'groupname' => 'Core', 'requirement' => 'required', 'sortorder' => 1]);
        $regs = [];
        foreach (['2030-01-14', '2030-08-19'] as $start) {
            $offer = $gen->create_offering(['courseid' => $course->id, 'termid' => $gen->create_term(['startdate' => $start])->id]);
            $regs[] = $gen->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        }
        registration::set_grade($regs[0], 'A', null, 'staff');
        $this->assertSame(3.0, degreeaudit::run(students::get((int)$student->id))['earned']);

        registration::set_grade($regs[1], 'F', null, 'staff');

        $this->assertSame(0.0, degreeaudit::run(students::get((int)$student->id))['earned']);
    }

    /**
     * A student of a 12 hour program with no course list: one class passed, one failed and one in progress, of 3 hours each,
     * 6 hours of transfer credit that count toward the program and 9 that don't.
     *
     * @param array $program more fields of the program
     * @return array student (id), regid (of the class in progress)
     */
    private function student_with_no_course_list(array $program = []): array {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $gen->create_scale();
        $programid = (int)$gen->create_program()->id;
        $DB->update_record('local_studentrecords_program', (object)($program + ['id' => $programid, 'totalhours' => 12]));
        $student = $gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-1',
            'programid' => $programid]);
        $term = $gen->create_term();
        $regid = 0;
        foreach (['A', 'F', ''] as $letter) {
            $offer = $gen->create_offering(['courseid' => $gen->create_course()->id, 'termid' => $term->id]);
            $regid = $gen->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
            if ($letter !== '') {
                registration::set_grade($regid, $letter, null, 'staff');
            }
        }
        foreach ([6 => 1, 9 => 0] as $credits => $counts) {
            $DB->insert_record('local_studentrecords_xfer', (object)['studentid' => $student->id, 'institution' => 'Riverside',
                'credits' => $credits, 'countsprogram' => $counts, 'timecreated' => time()]);
        }
        return ['student' => (int)$student->id, 'regid' => $regid];
    }

    /**
     * With no course list the audit counts the student's earned credits, and transfer credit only when it counts toward the
     * program. A failed class earns nothing, and a class in progress is in progress, not earned.
     */
    public function test_no_course_list_counts_the_earned_credits(): void {
        $s = $this->student_with_no_course_list();

        $audit = degreeaudit::run(students::get($s['student']));

        $this->assertSame([], $audit['groups']);
        $this->assertSame(9.0, $audit['earned']);
        $this->assertSame(3.0, $audit['inprogress']);
        $this->assertSame(3.0, $audit['atschool']);
        $this->assertFalse($audit['hoursok']);
        $this->assertSame([['audit_hours', ['earned' => 9.0, 'total' => 12.0]]], $audit['missing']);
    }

    /**
     * Hours and GPA are all a program with no course list asks for: the audit is complete when they are met, and it can be
     * approved as conditional while the last classes are in progress.
     */
    public function test_no_course_list_is_complete_with_hours_and_gpa(): void {
        $s = $this->student_with_no_course_list();
        $audit = degreeaudit::run(students::get($s['student']));
        $this->assertFalse($audit['complete']);
        $this->assertTrue($audit['completeifpassed']);

        registration::set_grade($s['regid'], 'A', null, 'staff');

        $audit = degreeaudit::run(students::get($s['student']));
        $this->assertSame(12.0, $audit['earned']);
        $this->assertSame(0.0, $audit['inprogress']);
        $this->assertTrue($audit['complete']);
    }

    /**
     * A class in progress that ends in a fail leaves the hours short.
     */
    public function test_no_course_list_stays_open_when_the_last_class_fails(): void {
        $s = $this->student_with_no_course_list();

        registration::set_grade($s['regid'], 'F', null, 'staff');

        $audit = degreeaudit::run(students::get($s['student']));
        $this->assertSame(9.0, $audit['earned']);
        $this->assertFalse($audit['complete']);
        $this->assertFalse($audit['completeifpassed']);
    }

    /**
     * The minimum GPA still applies with no course list: the hours are met, and the audit stays open.
     */
    public function test_no_course_list_keeps_the_minimum_gpa(): void {
        $s = $this->student_with_no_course_list(['mingpa' => 3, 'totalhours' => 9]);

        $audit = degreeaudit::run(students::get($s['student']));

        $this->assertTrue($audit['hoursok']);
        $this->assertFalse($audit['gpaok']);
        $this->assertFalse($audit['complete']);
    }

    /**
     * The credits needed at the school still apply with no course list, and the class in progress can bring them.
     */
    public function test_no_course_list_keeps_the_credits_needed_at_the_school(): void {
        $s = $this->student_with_no_course_list(['residenthours' => 6, 'totalhours' => 9]);

        $audit = degreeaudit::run(students::get($s['student']));

        $this->assertTrue($audit['hoursok']);
        $this->assertFalse($audit['complete']);
        $this->assertTrue($audit['completeifpassed']);
    }

    /**
     * A retake in progress of a class already passed earns nothing new, so the audit doesn't count those hours twice.
     */
    public function test_no_course_list_counts_a_retake_once(): void {
        global $DB;
        if (\core_component::get_component_directory('local_academictranscript') === null) {
            $this->markTestSkipped('Academic Transcript is not installed.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $gen->create_scale();
        $program = $gen->create_program();
        $DB->update_record('local_studentrecords_program', (object)['id' => $program->id, 'totalhours' => 6]);
        $student = $gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-1',
            'programid' => $program->id]);
        $course = $gen->create_course();
        $regs = [];
        foreach (['2030-01-14', '2030-08-19'] as $start) {
            $offer = $gen->create_offering(['courseid' => $course->id, 'termid' => $gen->create_term(['startdate' => $start])->id]);
            $regs[] = $gen->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        }
        registration::set_grade($regs[0], 'B', null, 'staff');

        $audit = degreeaudit::run(students::get((int)$student->id));
        $this->assertSame(3.0, $audit['earned']);
        $this->assertSame(0.0, $audit['inprogress']);
        $this->assertFalse($audit['completeifpassed']);

        registration::set_grade($regs[1], 'A', null, 'staff');

        $this->assertSame(3.0, degreeaudit::run(students::get((int)$student->id))['earned']);
    }

    /**
     * With a course list nothing changes: a class that isn't on the list earns nothing here and isn't in progress here.
     */
    public function test_a_course_list_counts_only_what_fits_it(): void {
        global $DB;
        $s = $this->student_with_no_course_list();
        $student = students::get($s['student']);
        $course = $this->getDataGenerator()->get_plugin_generator('local_studentrecords')->create_course();
        $DB->insert_record('local_studentrecords_progcrs', (object)['programid' => $student->programid, 'courseid' => $course->id,
            'groupname' => 'Core', 'requirement' => 'required', 'sortorder' => 1]);

        $audit = degreeaudit::run($student);

        $this->assertSame(6.0, $audit['earned']);
        $this->assertSame(0.0, $audit['inprogress']);
        $this->assertFalse($audit['completeifpassed']);
    }

    /**
     * The record card and the student file show the same earned credits as the audit.
     */
    public function test_no_course_list_on_the_record_card_and_the_file(): void {
        $s = $this->student_with_no_course_list();

        $card = record_view::render(students::get($s['student']));
        $glance = student_file::glance(students::get($s['student']));

        $this->assertStringContainsString('9 of 12 credits', $card);
        $this->assertStringContainsString('75%', $card);
        $this->assertStringContainsString('9 / 12', $glance);
    }

    /**
     * A program with no course list, no total hours and no minimum GPA has nothing to audit: not complete, not even if the
     * classes in progress are passed, with the reason in what is still needed.
     *
     * @dataProvider nothing_to_audit_provider
     * @param float|null $totalhours
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nothing_to_audit_provider')]
    public function test_a_program_with_nothing_to_audit(?float $totalhours): void {
        $s = $this->student_with_no_course_list(['totalhours' => $totalhours]);

        $audit = degreeaudit::run(students::get($s['student']));

        $this->assertFalse($audit['auditable']);
        $this->assertFalse($audit['complete']);
        $this->assertFalse($audit['completeifpassed']);
        $this->assertSame('audit_nothingtocheck', $audit['missing'][0][0]);
    }

    /**
     * Data for test_a_program_with_nothing_to_audit.
     *
     * @return array
     */
    public static function nothing_to_audit_provider(): array {
        return ['no total hours' => [null], 'zero total hours' => [0.0]];
    }

    /**
     * A minimum GPA is something to audit, and so are total hours, and so is a course list.
     */
    public function test_one_requirement_is_enough_to_audit(): void {
        $s = $this->student_with_no_course_list(['totalhours' => null, 'mingpa' => 1]);

        $audit = degreeaudit::run(students::get($s['student']));

        $this->assertTrue($audit['auditable']);
        $this->assertTrue($audit['complete']);
    }

    /**
     * Only courses in the program's unit count with no course list: clock hours don't complete a credit program. The same
     * goes for classes in progress and for the credits earned at the school.
     */
    public function test_no_course_list_counts_only_the_unit_of_the_program(): void {
        global $DB;
        $s = $this->student_with_no_course_list(['totalhours' => 60]);
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $term = $gen->create_term(['startdate' => '2030-08-19']);
        foreach (['A', ''] as $letter) {
            $offer = $gen->create_offering(['courseid' => $gen->create_course(['hours' => 600, 'unittype' => 'clock'])->id,
                'termid' => $term->id]);
            $regid = $gen->create_registration(['studentid' => $s['student'], 'offerid' => $offer->id]);
            if ($letter !== '') {
                registration::set_grade($regid, $letter, null, 'staff');
            }
        }

        $audit = degreeaudit::run(students::get($s['student']));

        $this->assertSame(9.0, $audit['earned']);
        $this->assertSame(3.0, $audit['inprogress']);
        $this->assertSame(3.0, $audit['atschool']);
        $this->assertFalse($audit['complete']);
        $this->assertFalse($audit['completeifpassed']);
    }

    /**
     * A student with no transfer credit yet, in a program of 12 hours with no course list, with one class of 3 hours.
     *
     * @return array student (id), courseid, regid
     */
    private function student_in_one_class(): array {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $gen->create_scale();
        $programid = (int)$gen->create_program()->id;
        $DB->set_field('local_studentrecords_program', 'totalhours', 12, ['id' => $programid]);
        $student = $gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-1',
            'programid' => $programid]);
        $course = $gen->create_course();
        $offer = $gen->create_offering(['courseid' => $course->id, 'termid' => $gen->create_term()->id]);
        return ['student' => (int)$student->id, 'courseid' => (int)$course->id,
            'regid' => $gen->create_registration(['studentid' => $student->id, 'offerid' => $offer->id])];
    }

    /**
     * Transfer credit equivalent to a course.
     *
     * @param array $s from student_in_one_class()
     * @param float $credits
     */
    private function add_equivalent_transfer(array $s, float $credits = 3): void {
        global $DB;
        $DB->insert_record('local_studentrecords_xfer', (object)['studentid' => $s['student'], 'institution' => 'Riverside',
            'credits' => $credits, 'equivcourseid' => $s['courseid'], 'countsprogram' => 1, 'timecreated' => time()]);
    }

    /**
     * A course and the transfer credit equivalent to it count once with no course list, as they do with a list.
     */
    public function test_no_course_list_counts_a_course_and_its_transfer_once(): void {
        $s = $this->student_in_one_class();
        $this->add_equivalent_transfer($s);
        registration::set_grade($s['regid'], 'A', null, 'staff');

        $this->assertSame(3.0, degreeaudit::run(students::get($s['student']))['earned']);
    }

    /**
     * Two transfers equivalent to the same course count once.
     */
    public function test_no_course_list_counts_two_transfers_of_one_course_once(): void {
        $s = $this->student_in_one_class();
        $this->add_equivalent_transfer($s);
        $this->add_equivalent_transfer($s);

        $this->assertSame(3.0, degreeaudit::run(students::get($s['student']))['earned']);
    }

    /**
     * The transfer counts when the course here wasn't passed, and a class in progress that it covers adds nothing.
     */
    public function test_no_course_list_keeps_a_transfer_until_the_course_is_passed(): void {
        $s = $this->student_in_one_class();
        $this->add_equivalent_transfer($s);

        $audit = degreeaudit::run(students::get($s['student']));
        $this->assertSame(3.0, $audit['earned']);
        $this->assertSame(0.0, $audit['inprogress']);

        registration::set_grade($s['regid'], 'F', null, 'staff');

        $this->assertSame(3.0, degreeaudit::run(students::get($s['student']))['earned']);
    }

    /**
     * Nobody approves a substitution on their own record: it can finish the audit. Another person can.
     */
    public function test_nobody_approves_their_own_substitution(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $student = $gen->create_user_student();
        $required = (int)$gen->create_course()->id;
        $taken = (int)$gen->create_course()->id;
        role_assign((int)$DB->get_field('role', 'id', ['shortname' => 'manager']), (int)$student->userid, SYSCONTEXTID);
        $this->setUser((int)$student->userid);

        try {
            degreeaudit::add_substitution($student, $required, $taken, 'Close enough');
            $this->fail('A person approved their own substitution.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_ownrecord', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('local_studentrecords_subst'));

        $this->setAdminUser();
        degreeaudit::add_substitution($student, $required, $taken, 'Approved by the dean');
        $this->assertSame(1, $DB->count_records('local_studentrecords_subst'));
    }

    /**
     * With no course list a course counts once however often it was passed. With no Academic Transcript the repeat rule is
     * "all", so every attempt counts on the record, but the program still gets the hours of the course once.
     */
    public function test_no_course_list_counts_a_course_passed_twice_once(): void {
        global $DB;
        $s = $this->student_in_one_class();
        set_config('repeatrule', 'all', 'local_academictranscript');
        $student = students::get($s['student']);
        $DB->set_field('local_studentrecords_program', 'totalhours', 6, ['id' => $student->programid]);
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $term = $gen->create_term(['startdate' => '2030-08-19']);
        $offer = $gen->create_offering(['courseid' => $s['courseid'], 'termid' => $term->id]);
        $second = $gen->create_registration(['studentid' => $s['student'], 'offerid' => $offer->id]);
        registration::set_grade($s['regid'], 'B', null, 'staff');
        registration::set_grade($second, 'A', null, 'staff');

        $audit = degreeaudit::run(students::get($s['student']));

        $this->assertSame(6.0, registration::history($student)['cumulative']['earned']);
        $this->assertSame(3.0, $audit['earned']);
        $this->assertSame(3.0, $audit['atschool']);
        $this->assertFalse($audit['complete']);
    }
}
