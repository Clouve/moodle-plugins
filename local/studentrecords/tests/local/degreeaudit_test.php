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
}
