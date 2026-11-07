<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(outside::class)]
/**
 * Tests for outside report rules that need no database: Clearinghouse status and column names.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\outside
 */
final class outside_test extends \advanced_testcase {
    /**
     * Start each test as an admin on a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A student in two courses of a term, of 3 and 4 hours.
     *
     * @param string $name term name
     * @return array student and term
     */
    private function student_in_two_courses(string $name = 'Spring'): array {
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $term = $sr->create_term(['name' => $name]);
        $user = $this->getDataGenerator()->create_user();
        $student = $sr->create_student(['userid' => $user->id, 'studentid' => 'S1', 'loadtype' => 'parttime']);
        foreach ([3, 4] as $hours) {
            $offer = $sr->create_offering(['courseid' => $sr->create_course(['hours' => $hours])->id, 'termid' => $term->id]);
            $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        }
        return [$student, $term];
    }

    /**
     * The hours of a term add up every course. Two registrations with the same status used to count as one, because the
     * query keyed its rows on the status.
     */
    public function test_term_load_counts_every_course(): void {
        [$student, $term] = $this->student_in_two_courses();
        $load = outside::term_load((int)$student->id, (int)$term->id);
        $this->assertEquals(7.0, $load['hours']);
        $this->assertFalse($load['allended']);
        $this->assertTrue($load['any']);
    }

    /**
     * Clearinghouse rows list each student of the term once, with the hours of all their courses.
     */
    public function test_nsc_rows(): void {
        [$student, $term] = $this->student_in_two_courses();
        $rows = outside::nsc_rows((int)$term->id);
        $this->assertCount(1, $rows);
        $this->assertSame($student->studentid, $rows[0][10]);
        $this->assertSame('H', $rows[0][4], '7 hours is half-time');
        $this->assertSame('7', $rows[0][14]);
    }

    /**
     * A saved export runs on the database, lists its population and gives plain text.
     */
    public function test_run_a_saved_export(): void {
        global $DB;
        [$student, $term] = $this->student_in_two_courses();
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $program = $sr->create_program(['name' => 'Arts & Sciences']);
        $DB->set_field(students::TABLE, 'programid', $program->id, ['id' => $student->id]);
        $id = outside::save_export(['name' => 'State', 'purpose' => 'state', 'population' => 'term', 'termid' => $term->id,
            'fields' => ['studentid', 'programname', 'level', 'termhours', 'gpa', 'sex', 'ipeds']]);
        $result = outside::run($DB->get_record(outside::EXPORTS, ['id' => $id]));
        $this->assertCount(1, $result['rows']);
        $this->assertSame('S1', $result['rows'][0][0]);
        $this->assertSame('Arts & Sciences', $result['rows'][0][1]);
        $this->assertSame('Undergraduate', $result['rows'][0][2]);
        $this->assertSame('7', $result['rows'][0][3]);
    }

    /**
     * An export knows when it lists sex, ethnicity or race.
     */
    public function test_has_demographics(): void {
        $this->assertTrue(outside::has_demographics((object)['fields' => 'studentid,ipeds']));
        $this->assertFalse(outside::has_demographics((object)['fields' => 'studentid,legallastname']));
    }

    /**
     * Headcount and completions run on the database.
     */
    public function test_counts(): void {
        global $DB;
        [$student, $term] = $this->student_in_two_courses();
        $grid = outside::headcount((int)$term->startdate, (int)$term->enddate);
        $this->assertSame(1, $grid['']['unknown']['notreported']);
        $DB->set_field(students::TABLE, 'awarddate', (int)$term->enddate, ['id' => $student->id]);
        $done = outside::completions((int)$term->startdate, (int)$term->enddate);
        $this->assertSame(1, $done['programs'][0]['count']);
    }

    /**
     * The only part of the Counts tab without race, ethnicity or sex is the list of completions by program.
     */
    public function test_counts_show_demographics(): void {
        $this->assertTrue(outside::counts_show_demographics('headcount', ''));
        $this->assertTrue(outside::counts_show_demographics('headcount', 'programs'));
        $this->assertTrue(outside::counts_show_demographics('completions', ''));
        $this->assertFalse(outside::counts_show_demographics('completions', 'programs'));
    }

    /**
     * The starter exports are added once.
     */
    public function test_add_starters(): void {
        $this->assertSame(3, outside::add_starters());
        $this->assertSame(0, outside::add_starters());
    }

    /**
     * Clearinghouse status: graduated, leave, withdrawn, full-time, half-time, less than half-time.
     */
    public function test_nsc_status(): void {
        $s = fn($status, $load, $hours, $ended = false, $awarded = false) => outside::nsc_status(['status' => $status,
            'loadtype' => $load, 'hours' => $hours, 'allended' => $ended, 'awarded' => $awarded], 12, 6);
        $this->assertSame('G', $s('active', 'fulltime', 12, false, true));
        $this->assertSame('G', $s('graduated', null, 0));
        $this->assertSame('A', $s('onleave', 'fulltime', 12));
        $this->assertSame('W', $s('withdrawn', 'fulltime', 12));
        $this->assertSame('W', $s('active', 'parttime', 0, true), 'every course of the term dropped or withdrawn');
        $this->assertSame('F', $s('active', 'fulltime', 3), 'full-time on the record');
        $this->assertSame('F', $s('active', 'parttime', 12));
        $this->assertSame('H', $s('active', 'parttime', 6));
        $this->assertSame('L', $s('active', 'parttime', 3));
    }

    /**
     * Column names: own names line by line; empty lines keep the field's own name.
     */
    public function test_headers(): void {
        $labels = ['studentid' => 'Student ID', 'dob' => 'Date of birth', 'sex' => 'Sex'];
        $fields = ['studentid', 'dob', 'sex'];
        $this->assertSame(['ID', 'Date of birth', 'Gender'], outside::headers($fields, "ID\n\nGender", $labels));
        $this->assertSame(['Student ID', 'Sex'], outside::headers(['studentid', 'sex'], '', $labels));
        $this->assertSame(['X', 'Sex'], outside::headers(['studentid', 'sex'], "X\r\n", $labels));
    }
}
