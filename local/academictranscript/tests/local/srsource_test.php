<?php

namespace local_academictranscript\local;

#[\PHPUnit\Framework\Attributes\CoversClass(srsource::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(transcript::class)]
/**
 * Tests for reading a transcript from Student Records.
 *
 * The tests that need Student Records itself are skipped when it is not installed.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_academictranscript\local\srsource
 * @covers     \local_academictranscript\local\transcript
 */
final class srsource_test extends \advanced_testcase {
    /**
     * Start each test with a clean site and an empty request cache.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        scale::reset_cache();
    }

    /**
     * Completed with a grade gives the grade, withdrawn gives W and registered gives IP. Drops and ungraded rows are left
     * out.
     */
    public function test_attempts(): void {
        $row = fn($status, $grade, $code) => ['status' => $status, 'grade' => $grade, 'code' => $code, 'title' => 'T ' . $code,
            'hours' => 3, 'termid' => 1, 'courseid' => 7, 'timeregistered' => 100];

        $out = srsource::attempts([$row('completed', 'A-', 'MBA5521'), $row('withdrawn', null, 'MBA5522'),
            $row('registered', null, 'MBA5523'), $row('dropped', null, 'MBA5524'), $row('completed', null, 'MBA5525')]);

        $this->assertSame(['A-', 'W', scale::INPROGRESS], array_column($out, 'grade'));
        $this->assertSame(['MBA5521', 'MBA5522', 'MBA5523'], array_column($out, 'code'));
        $this->assertSame([true, true, false], array_column($out, 'posted'));
        $this->assertSame(PHP_INT_MAX, $out[2]['time']);
        $this->assertSame(3.0, $out[0]['hours']);
    }

    /**
     * Transfer credit is grouped by institution, marked TR, earned, and not in the GPA.
     */
    public function test_transfer(): void {
        [$groups, $total] = srsource::transfer([
            ['institution' => 'College A', 'extcourse' => 'ENG 101', 'credits' => 3, 'note' => ''],
            ['institution' => 'College B', 'extcourse' => 'MAT 100', 'credits' => 4, 'note' => 'Evaluated'],
            ['institution' => 'College A', 'extcourse' => 'HIS 101', 'credits' => 3, 'note' => null],
        ]);

        $this->assertSame(10.0, $total);
        $this->assertSame(['College A', 'College B'], array_column($groups, 'institution'));
        $this->assertSame(6.0, $groups[0]['earned']);
        $this->assertSame(scale::TRANSFER, $groups[0]['rows'][0]['grade']);
        $this->assertFalse($groups[0]['rows'][0]['ingpa']);
        $this->assertSame(['Evaluated'], $groups[1]['rows'][0]['notes']);
    }

    /**
     * Fractional hours add up exactly: 0.1 + 0.2 is 0.3 here, not 0.30000000000000004.
     */
    public function test_transfer_hours_add_up_exactly(): void {
        [$groups, $total] = srsource::transfer([
            ['institution' => 'College A', 'extcourse' => 'A', 'credits' => 0.1],
            ['institution' => 'College A', 'extcourse' => 'B', 'credits' => 0.2],
        ]);

        $this->assertSame(0.3, $total);
        $this->assertSame(0.3, $groups[0]['earned']);
    }

    /**
     * With the setting on its default, Student Records is not the source.
     */
    public function test_not_enabled_by_default(): void {
        $this->assertFalse(srsource::enabled());
        $this->assertFalse(srpost::active());
    }

    /**
     * The transcript of a student with a file in Student Records comes from there: the legal name, the Student ID, the
     * program, terms, grades and transfer credit. Names with an ampersand stay plain text, so they are escaped only once.
     */
    public function test_transcript_reads_student_records(): void {
        global $DB;
        [$user, $student, $sr] = $this->student();
        $sr->create_scale();
        $program = $sr->create_program(['name' => 'Arts & Sciences']);
        $DB->set_field('local_studentrecords_student', 'programid', $program->id, ['id' => $student->id]);
        $term = $sr->create_term(['name' => 'Fall & Winter']);
        $course = $sr->create_course(['code' => 'BIO 101', 'title' => 'Cells & Life', 'hours' => 3]);
        $offer = $sr->create_offering(['courseid' => $course->id, 'termid' => $term->id]);
        $regid = $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        \local_studentrecords\local\registration::set_grade($regid, 'A', null, 'staff');

        $data = transcript::build($user->id);

        $this->assertSame(srsource::STUDENTRECORDS, $data['source']);
        $this->assertSame($user->firstname . ' ' . $user->lastname, $data['fullname']);
        $this->assertSame('S-1001', $data['studentid']);
        $this->assertSame('Arts & Sciences', $data['program']);
        $this->assertSame('Fall & Winter', $data['terms'][0]['name']);
        $this->assertSame('Cells & Life', $data['terms'][0]['rows'][0]['title']);
        $this->assertSame('A', $data['terms'][0]['rows'][0]['grade']);
        $this->assertSame([], $data['problems']);
        $this->assertSame('4.00', $data['summary']['institutional']['gpa']);
    }

    /**
     * A student without a file in Student Records keeps the transcript from this plugin's own data.
     */
    public function test_student_without_a_file_uses_own_data(): void {
        $this->student();
        $other = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $generator->create_posted_grade(['userid' => $other->id, 'courseid' => $this->getDataGenerator()->create_course()->id,
            'termid' => $generator->create_term()->id, 'grade' => 'A']);

        $data = transcript::build($other->id);

        $this->assertArrayNotHasKey('source', $data);
        $this->assertSame('A', $data['terms'][0]['rows'][0]['grade']);
    }

    /**
     * A grade that isn't on the scale in Student Records has no grade points, so the transcript can't be right. It is a
     * problem, and a problem stops an Official transcript.
     */
    public function test_grade_missing_from_the_scale_stops_an_official_transcript(): void {
        [$user, $student, $sr] = $this->student();
        $term = $sr->create_term();
        $course = $sr->create_course(['code' => 'ART 200']);
        $offer = $sr->create_offering(['courseid' => $course->id, 'termid' => $term->id]);
        $regid = $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        \local_studentrecords\local\registration::set_grade($regid, 'AU', null, 'staff');

        $data = transcript::build($user->id);

        $this->assertCount(1, $data['problems']);
        $this->assertStringContainsString('AU', $data['problems'][0]);
        $this->assertStringContainsString('ART 200', $data['problems'][0]);
        try {
            transcript::issue($user->id, 'Office');
            $this->fail('The transcript was issued.');
        } catch (\moodle_exception $e) {
            $this->assertSame('cannotissue', $e->errorcode);
        }
    }

    /**
     * The transcript's last entry is the newest change to a course or transfer row in Student Records.
     */
    public function test_last_entry_comes_from_student_records(): void {
        global $DB;
        [$user, $student, $sr] = $this->student();
        $offer = $sr->create_offering(['courseid' => $sr->create_course()->id, 'termid' => $sr->create_term()->id]);
        $regid = $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        $DB->set_field('local_studentrecords_reg', 'timemodified', 1700000000, ['id' => $regid]);

        $this->assertSame(1700000000, transcript::build($user->id)['lastentry']);
    }

    /**
     * A grade posted here before the school turned on Student Records would drop off the transcript. It is a problem, and
     * a problem stops an Official transcript.
     */
    public function test_a_posted_grade_missing_from_student_records_is_a_problem(): void {
        [$user] = $this->student();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $this->getDataGenerator()->create_course()->id,
            'termid' => $generator->create_term()->id, 'coursecode' => 'OLD 100', 'grade' => 'B']);

        $problems = transcript::build($user->id)['problems'];

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('OLD 100', $problems[0]);
    }

    /**
     * A posted grade whose course is graded in Student Records is on the transcript, so it is no problem.
     */
    public function test_a_posted_grade_found_in_student_records_is_no_problem(): void {
        [$user, $student, $sr] = $this->student();
        $sr->create_scale();
        $offer = $sr->create_offering(['courseid' => $sr->create_course(['code' => 'BIO 101'])->id,
            'termid' => $sr->create_term()->id]);
        $regid = $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        \local_studentrecords\local\registration::set_grade($regid, 'A', null, 'staff');
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $this->getDataGenerator()->create_course()->id,
            'termid' => $generator->create_term()->id, 'coursecode' => 'BIO 101', 'grade' => 'A']);

        $this->assertSame([], transcript::build($user->id)['problems']);
    }

    /**
     * Make a student in Student Records and use that plugin as the source, or skip the test without it.
     *
     * @return array The Moodle user, the student record, and the generator of Student Records
     */
    private function student(): array {
        if (!class_exists('\\local_studentrecords\\local\\registration')) {
            $this->markTestSkipped('Student Records is not installed.');
        }
        set_config('source', srsource::STUDENTRECORDS, 'local_academictranscript');
        $this->setAdminUser();
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $user = $this->getDataGenerator()->create_user();
        return [$user, $sr->create_student(['userid' => $user->id, 'studentid' => 'S-1001']), $sr];
    }

    /**
     * Student Records and the transcript show the same GPA and hours under each repeat rule, in the terms and in total: a
     * better retake, a worse retake, a pass mark, a retake in progress, a withdrawal and a third attempt.
     *
     * @dataProvider repeat_rule_provider
     * @param string $rule
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('repeat_rule_provider')]
    public function test_student_records_and_the_transcript_agree_on_repeats(string $rule): void {
        [$user, $student, $sr] = $this->student();
        set_config('repeatrule', $rule, 'local_academictranscript');
        scale::reset_cache();
        $sr->create_scale();
        $terms = [$sr->create_term(['name' => 'Fall', 'startdate' => '2029-08-20', 'enddate' => '2029-12-14']),
            $sr->create_term(['name' => 'Spring', 'startdate' => '2030-01-14', 'enddate' => '2030-05-03']),
            $sr->create_term(['name' => 'Summer', 'startdate' => '2030-06-03', 'enddate' => '2030-08-02'])];
        $plan = [['BIO 101', ['C', 'A']], ['MAT 100', ['A', 'F']], ['ENG 100', ['F', 'P']], ['HIS 200', ['B', null]],
            ['ART 150', ['C', 'W']], ['CHE 110', ['B', 'B', 'C']]];
        foreach ($plan as [$code, $grades]) {
            $course = $sr->create_course(['code' => $code, 'hours' => 3]);
            foreach ($grades as $i => $grade) {
                $offer = $sr->create_offering(['courseid' => $course->id, 'termid' => $terms[$i]->id]);
                $regid = $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
                if ($grade === 'W') {
                    \local_studentrecords\local\registration::end($regid, 'withdraw', true, 'test');
                } else if ($grade !== null) {
                    \local_studentrecords\local\registration::set_grade($regid, $grade, null, 'staff');
                }
            }
        }

        $transcript = transcript::build($user->id);
        $record = \local_studentrecords\local\registration::history($student);

        $this->assertSame([], $transcript['problems']);
        $total = $transcript['summary']['institutional'];
        $this->assertSame($total['earned'], $record['cumulative']['earned']);
        $this->assertSame($total['gpahours'], $record['cumulative']['gpahours']);
        $this->assertSame($total['gpa'], number_format($record['cumulative']['gpa'], 2));
        foreach ($transcript['terms'] as $i => $term) {
            $totals = $record['terms'][$i]['totals'];
            $this->assertSame($term['earned'], $totals['earned'], $term['name']);
            $this->assertSame($term['gpahours'], $totals['gpahours'], $term['name']);
            $this->assertSame($term['gpa'], $totals['gpa'] === null ? '' : number_format($totals['gpa'], 2), $term['name']);
        }
    }

    /**
     * With a file in Student Records the transcript counts on that plugin's scale, so a letter set differently in the two
     * plugins can't give two GPAs. This plugin's own scale gives B 3.3 here, and Student Records gives it 3.
     */
    public function test_the_transcript_counts_on_the_scale_of_student_records(): void {
        global $DB;
        [$user, $student, $sr] = $this->student();
        $sr->create_scale();
        $DB->insert_record('local_academictranscript_grade', (object)['letter' => 'B', 'minpct' => 80, 'maxpct' => 89.99,
            'points' => 3.3, 'ingpa' => 1, 'earns' => 1, 'description' => '', 'percentlabel' => '', 'active' => 1,
            'timemodified' => time()]);
        scale::reset_cache();
        $this->grade($student, $sr, 'BIO 101', 'B');

        $transcript = transcript::build($user->id);
        $record = \local_studentrecords\local\registration::history($student);

        $this->assertSame([], $transcript['problems']);
        $this->assertSame('3.00', $transcript['summary']['overall']['gpa']);
        $this->assertSame(3.0, $record['cumulative']['gpa']);
        $this->assertSame(9.0, $transcript['terms'][0]['rows'][0]['qp']);
    }

    /**
     * A grade below the program's minimum passing grade earns nothing on the transcript, as in Student Records. It stays in
     * the GPA in both, and the row says why it earned nothing.
     */
    public function test_a_grade_below_the_program_minimum_earns_nothing(): void {
        global $DB;
        [$user, $student, $sr] = $this->student();
        $sr->create_scale();
        $program = $sr->create_program(['name' => 'Nursing']);
        $DB->set_field('local_studentrecords_student', 'programid', $program->id, ['id' => $student->id]);
        $student->programid = $program->id;
        $DB->insert_record('local_studentrecords_minpass', (object)['programid' => $program->id, 'minletter' => 'B',
            'timemodified' => time()]);
        $this->grade($student, $sr, 'BIO 101', 'C');

        $transcript = transcript::build($user->id);
        $record = \local_studentrecords\local\registration::history($student);

        $row = $transcript['terms'][0]['rows'][0];
        $this->assertFalse($row['earned']);
        $this->assertContains(get_string('footnote_belowminimum', 'local_academictranscript'), $row['notes']);
        $this->assertSame(0.0, $record['cumulative']['earned']);
        $this->assertSame(0.0, $transcript['summary']['overall']['earned']);
        $this->assertSame(3.0, $transcript['summary']['overall']['gpahours']);
        $this->assertSame('2.00', $transcript['summary']['overall']['gpa']);
    }

    /**
     * A failing grade already earns nothing, so it needs no note about the minimum.
     */
    public function test_a_failing_grade_has_no_note_about_the_minimum(): void {
        [$user, $student, $sr] = $this->student();
        $sr->create_scale();
        $this->grade($student, $sr, 'BIO 101', 'F');

        $row = transcript::build($user->id)['terms'][0]['rows'][0];

        $this->assertFalse($row['earned']);
        $this->assertNotContains(get_string('footnote_belowminimum', 'local_academictranscript'), $row['notes']);
    }

    /**
     * Transfer credit marked in GPA counts in the overall GPA, as in Student Records, and its row shows the letter and quality
     * points, so a reader can add the GPA up. It stays out of the institutional GPA and out of every term.
     */
    public function test_transfer_credit_marked_in_gpa_counts_in_the_overall_gpa(): void {
        [$user, $student, $sr] = $this->student();
        $sr->create_scale();
        $this->grade($student, $sr, 'BIO 101', 'C');
        $this->transfer($student, ['credits' => 3, 'grade' => 'A', 'ingpa' => 1]);

        $transcript = transcript::build($user->id);
        $record = \local_studentrecords\local\registration::history($student);

        $this->assertSame('2.00', $transcript['summary']['institutional']['gpa']);
        $this->assertSame('', $transcript['summary']['transfer']['gpa']);
        $this->assertSame(3.0, $transcript['summary']['transfer']['gpahours']);
        $this->assertSame(12.0, $transcript['summary']['transfer']['qp']);
        $this->assertSame('3.00', $transcript['summary']['overall']['gpa']);
        $this->assertSame(6.0, $transcript['summary']['overall']['gpahours']);
        $this->assertSame(18.0, $transcript['summary']['overall']['qp']);
        $this->assertSame(6.0, $transcript['summary']['overall']['earned']);
        $this->assertSame(3.0, $record['cumulative']['gpa']);
        $this->assertSame(6.0, $record['cumulative']['earned']);
        $row = $transcript['transfer'][0]['rows'][0];
        $this->assertSame('A', $row['grade']);
        $this->assertSame(12.0, $row['qp']);
        $this->assertSame('2.00', $transcript['terms'][0]['gpa']);
    }

    /**
     * Transfer credit that isn't marked in GPA stays as it was: TR, no quality points, and only earned hours in the totals.
     */
    public function test_transfer_credit_not_in_gpa_stays_out_of_the_gpa(): void {
        [$user, $student, $sr] = $this->student();
        $sr->create_scale();
        $this->grade($student, $sr, 'BIO 101', 'C');
        $this->transfer($student, ['credits' => 3, 'grade' => 'A', 'ingpa' => 0]);

        $transcript = transcript::build($user->id);

        $this->assertSame('2.00', $transcript['summary']['overall']['gpa']);
        $this->assertSame(3.0, $transcript['summary']['overall']['gpahours']);
        $this->assertSame(6.0, $transcript['summary']['overall']['earned']);
        $this->assertSame(0.0, $transcript['summary']['transfer']['qp']);
        $row = $transcript['transfer'][0]['rows'][0];
        $this->assertSame(scale::TRANSFER, $row['grade']);
        $this->assertNull($row['qp']);
    }

    /**
     * A withdrawal is on every transcript, so W counts as known even when the scale in Student Records doesn't list it.
     */
    public function test_a_withdrawal_needs_no_row_on_the_scale(): void {
        [$user, $student, $sr] = $this->student();
        $sr->create_scale();
        $term = $sr->create_term();
        $course = $sr->create_course(['code' => 'ART 200']);
        $offer = $sr->create_offering(['courseid' => $course->id, 'termid' => $term->id]);
        $regid = $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        \local_studentrecords\local\registration::end($regid, 'withdraw', true, 'test');

        $data = transcript::build($user->id);

        $this->assertSame([], $data['problems']);
        $this->assertSame('W', $data['terms'][0]['rows'][0]['grade']);
    }

    /**
     * A student's transcript prints the key of the scale it was counted on: the letters and marks of Student Records, and
     * W, IP and TR, which the transcript prints whatever the scale holds. An issued copy stores that key.
     */
    public function test_the_key_shows_the_scale_of_student_records(): void {
        [$user, $student, $sr] = $this->student();
        $sr->create_scale();
        $this->grade($student, $sr, 'BIO 101', 'A');

        $data = transcript::build($user->id);
        $key = transcript::key_rows($data);

        $letters = array_column(array_filter($key, fn($r) => !$r['ismark']), 'grade');
        $marks = array_column(array_filter($key, fn($r) => $r['ismark']), 'grade');
        $this->assertSame(['A', 'B', 'C', 'F'], $letters);
        foreach (['P', 'I', 'W', scale::INPROGRESS, scale::TRANSFER] as $mark) {
            $this->assertContains($mark, $marks);
        }
        $this->assertSame('4.0', $key[0]['points']);
        $this->assertSame('90-100%', $key[0]['percent']);
        $issue = transcript::issue($user->id, 'Office');
        $stored = json_decode($issue->snapshot, true);
        $this->assertSame($key, $stored['key']);
    }

    /**
     * A student without a file there keeps this plugin's scale, in the key and in the GPA.
     */
    public function test_a_student_without_a_file_keeps_this_plugins_scale(): void {
        $this->student();
        $other = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $generator->create_posted_grade(['userid' => $other->id, 'courseid' => $this->getDataGenerator()->create_course()->id,
            'termid' => $generator->create_term()->id, 'grade' => 'A-']);

        $data = transcript::build($other->id);

        $this->assertSame(scale::scale_rows(), transcript::key_rows($data));
        $this->assertSame([], $data['problems']);
        $this->assertSame('3.70', $data['summary']['overall']['gpa']);
    }

    /**
     * The repeat rule decides on the scale of Student Records too: under "highest" this plugin's scale would keep the first
     * attempt, because it rates B over A here, and Student Records has A over B.
     */
    public function test_repeats_are_decided_on_the_scale_of_student_records(): void {
        global $DB;
        [$user, $student, $sr] = $this->student();
        set_config('repeatrule', 'highest', 'local_academictranscript');
        $sr->create_scale();
        foreach (['A' => 1.0, 'B' => 4.0] as $letter => $points) {
            $DB->insert_record('local_academictranscript_grade', (object)['letter' => $letter, 'minpct' => $points * 10,
                'maxpct' => $points * 10 + 5, 'points' => $points, 'ingpa' => 1, 'earns' => 1, 'description' => '',
                'percentlabel' => '', 'active' => 1, 'timemodified' => time()]);
        }
        scale::reset_cache();
        $course = $sr->create_course(['code' => 'BIO 101', 'hours' => 3]);
        foreach (['B' => '2029-08-20', 'A' => '2030-01-14'] as $letter => $start) {
            $term = $sr->create_term(['startdate' => $start, 'enddate' => substr($start, 0, 4) . '-12-30']);
            $offer = $sr->create_offering(['courseid' => $course->id, 'termid' => $term->id]);
            $regid = $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
            \local_studentrecords\local\registration::set_grade($regid, $letter, null, 'staff');
        }

        $record = \local_studentrecords\local\registration::history($student);
        $transcript = transcript::build($user->id);

        $this->assertSame(4.0, $record['cumulative']['gpa']);
        $this->assertSame('4.00', $transcript['summary']['overall']['gpa']);
    }

    /**
     * A school can list W or IP on the scale as a letter: the form ticks "counts in GPA", and an import does too. A withdrawal
     * or a course in progress still counts in nothing, on the record or the transcript, a retake in progress leaves the
     * earlier grade counting, and the key lists each code once.
     */
    public function test_a_withdrawal_or_a_course_in_progress_counts_in_nothing(): void {
        global $DB;
        [$user, $student, $sr] = $this->student();
        $sr->create_scale();
        foreach (['W', 'IP'] as $code) {
            $DB->insert_record('local_studentrecords_grade', (object)['letter' => $code, 'points' => 0, 'ingpa' => 1,
                'earns' => 1, 'sortorder' => 90, 'timemodified' => time()]);
        }
        $this->grade($student, $sr, 'BIO 101', 'A');
        $course = $sr->create_course(['code' => 'ART 200', 'hours' => 3]);
        $offer = $sr->create_offering(['courseid' => $course->id, 'termid' => $sr->create_term()->id]);
        $regid = $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        \local_studentrecords\local\registration::end($regid, 'withdraw', true, 'test');
        $retake = $sr->create_course(['code' => 'MAT 100', 'hours' => 3]);
        $first = $sr->create_term(['startdate' => '2029-08-20', 'enddate' => '2029-12-14']);
        $second = $sr->create_term(['startdate' => '2030-01-14', 'enddate' => '2030-05-03']);
        $done = $sr->create_registration(['studentid' => $student->id, 'offerid' => $sr->create_offering(
            ['courseid' => $retake->id, 'termid' => $first->id]
        )->id]);
        \local_studentrecords\local\registration::set_grade($done, 'B', null, 'staff');
        $sr->create_registration(['studentid' => $student->id, 'offerid' => $sr->create_offering(
            ['courseid' => $retake->id, 'termid' => $second->id]
        )->id]);

        $transcript = transcript::build($user->id);
        $record = \local_studentrecords\local\registration::history($student);

        $this->assertSame([], $transcript['problems']);
        $this->assertSame('3.50', $transcript['summary']['overall']['gpa']);
        $this->assertSame(3.5, $record['cumulative']['gpa']);
        $this->assertSame(6.0, $transcript['summary']['overall']['gpahours']);
        $this->assertSame(6.0, $transcript['summary']['overall']['earned']);
        $this->assertSame(6.0, $record['cumulative']['earned']);
        $codes = array_column(transcript::key_rows($transcript), 'grade');
        $this->assertSame(1, count(array_keys($codes, 'W', true)));
        $this->assertSame(1, count(array_keys($codes, scale::INPROGRESS, true)));
    }

    /**
     * A W given as a final grade is only known when the scale has it. Without that it is a problem like any other grade off
     * the scale, even though a real withdrawal needs no row.
     */
    public function test_a_final_grade_of_w_off_the_scale_is_a_problem(): void {
        [$user, $student, $sr] = $this->student();
        $sr->create_scale();
        $this->grade($student, $sr, 'ART 200', 'W');

        $data = transcript::build($user->id);

        $this->assertCount(1, $data['problems']);
        $this->assertStringContainsString('ART 200', $data['problems'][0]);
    }

    /**
     * A letter with grade points kept out of the GPA earns its hours once under the repeat rule, on both sides. Its row shows
     * no quality points, because it counts for none.
     */
    public function test_a_letter_out_of_the_gpa_is_repeated_like_any_other(): void {
        global $DB;
        [$user, $student, $sr] = $this->student();
        $sr->create_scale();
        $DB->insert_record('local_studentrecords_grade', (object)['letter' => 'S', 'points' => 3, 'ingpa' => 0, 'earns' => 0,
            'sortorder' => 50, 'timemodified' => time()]);
        $course = $sr->create_course(['code' => 'ART 200', 'hours' => 3]);
        foreach (['2029-08-20', '2030-01-14'] as $start) {
            $term = $sr->create_term(['startdate' => $start, 'enddate' => substr($start, 0, 4) . '-12-30']);
            $regid = $sr->create_registration(['studentid' => $student->id, 'offerid' => $sr->create_offering(
                ['courseid' => $course->id, 'termid' => $term->id]
            )->id]);
            \local_studentrecords\local\registration::set_grade($regid, 'S', null, 'staff');
        }

        $transcript = transcript::build($user->id);
        $record = \local_studentrecords\local\registration::history($student);

        $this->assertSame(3.0, $transcript['summary']['overall']['earned']);
        $this->assertSame(3.0, $record['cumulative']['earned']);
        $this->assertSame(0.0, $transcript['summary']['overall']['gpahours']);
        $this->assertNull($transcript['terms'][0]['rows'][0]['qp']);
    }

    /**
     * Below the program's minimum, a letter kept out of the GPA gets the note too: the scale alone would have earned its
     * hours.
     */
    public function test_a_letter_out_of_the_gpa_below_the_minimum_says_so(): void {
        global $DB;
        [$user, $student, $sr] = $this->student();
        $sr->create_scale();
        $DB->insert_record('local_studentrecords_grade', (object)['letter' => 'S', 'points' => 1, 'ingpa' => 0, 'earns' => 0,
            'sortorder' => 3, 'timemodified' => time()]);
        $program = $sr->create_program(['name' => 'Nursing']);
        $DB->set_field('local_studentrecords_student', 'programid', $program->id, ['id' => $student->id]);
        $student->programid = $program->id;
        $DB->insert_record('local_studentrecords_minpass', (object)['programid' => $program->id, 'minletter' => 'B',
            'timemodified' => time()]);
        $this->grade($student, $sr, 'BIO 101', 'S');

        $row = transcript::build($user->id)['terms'][0]['rows'][0];

        $this->assertFalse($row['earned']);
        $this->assertContains(get_string('footnote_belowminimum', 'local_academictranscript'), $row['notes']);
    }

    /**
     * Hours and grade points with two decimals add up the same on the record and the transcript.
     */
    public function test_fractional_hours_agree(): void {
        [$user, $student, $sr] = $this->student();
        $sr->create_scale();
        foreach ([['BIO 101', 2.67, 'A'], ['MAT 100', 1.33, 'B'], ['ART 200', 1.25, 'C']] as [$code, $hours, $grade]) {
            $course = $sr->create_course(['code' => $code, 'hours' => $hours]);
            $regid = $sr->create_registration(['studentid' => $student->id, 'offerid' => $sr->create_offering(
                ['courseid' => $course->id, 'termid' => $sr->create_term()->id]
            )->id]);
            \local_studentrecords\local\registration::set_grade($regid, $grade, null, 'staff');
        }

        $transcript = transcript::build($user->id);
        $record = \local_studentrecords\local\registration::history($student);

        $this->assertSame(5.25, $transcript['summary']['overall']['earned']);
        $this->assertSame(5.25, $record['cumulative']['earned']);
        $this->assertSame(number_format($record['cumulative']['gpa'], 2), $transcript['summary']['overall']['gpa']);
    }

    /**
     * The key prints the grade points as the scale has them, and a bottom row as "Below" the next letter's minimum, whatever
     * the row's own maximum is. A letter with no percentages prints none.
     */
    public function test_the_key_keeps_the_grade_points_and_the_bottom_row(): void {
        global $DB;
        [$user, , $sr] = $this->student();
        $sr->create_scale([['A', 90, 100, 4], ['A-', 87, 89.99, 3.67], ['F', 0, 69.99, 0]]);
        $DB->insert_record('local_studentrecords_grade', (object)['letter' => 'H', 'points' => 2.5, 'ingpa' => 1, 'earns' => 0,
            'sortorder' => 3, 'timemodified' => time()]);

        $rows = [];
        foreach (srsource::key_rows() as $row) {
            $rows[$row['grade']] = $row;
        }

        $this->assertSame('3.67', $rows['A-']['points']);
        $this->assertSame('4.0', $rows['A']['points']);
        $this->assertSame(get_string('belowpercent', 'local_academictranscript', '87'), $rows['F']['percent']);
        $this->assertSame('', $rows['H']['percent']);
    }

    /**
     * Marks in the key: a description from Student Records wins, else this plugin's wording, and W, IP and TR stay in the key
     * even with this plugin's marks removed.
     */
    public function test_the_key_explains_every_mark(): void {
        global $DB;
        $this->student();
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $sr->create_scale();
        $DB->set_field('local_studentrecords_grade', 'description', 'Passed with credit', ['letter' => 'P']);
        $rows = [];
        foreach (srsource::key_rows() as $row) {
            $rows[$row['grade']] = $row;
        }
        $this->assertSame('Passed with credit', $rows['P']['description']);
        $this->assertSame(get_string('markdesc_i', 'local_academictranscript'), $rows['I']['description']);

        $DB->delete_records('local_academictranscript_mark');
        scale::reset_cache();
        $rows = [];
        foreach (srsource::key_rows() as $row) {
            $rows[$row['grade']] = $row;
        }

        foreach (['W' => 'w', scale::INPROGRESS => 'ip', scale::TRANSFER => 'tr'] as $code => $key) {
            $this->assertSame(get_string('markdesc_' . $key, 'local_academictranscript'), $rows[$code]['description']);
        }
    }

    /**
     * A mark's footnote is its description in Student Records.
     */
    public function test_a_mark_is_explained_in_the_words_of_student_records(): void {
        global $DB;
        [$user, $student, $sr] = $this->student();
        $sr->create_scale();
        $DB->set_field('local_studentrecords_grade', 'description', 'Passed with credit', ['letter' => 'P']);
        $this->grade($student, $sr, 'ART 200', 'P');

        $row = transcript::build($user->id)['terms'][0]['rows'][0];

        $this->assertContains('Passed with credit', $row['notes']);
    }

    /**
     * One completed course in a term of its own.
     *
     * @param \stdClass $student
     * @param \local_studentrecords_generator $sr
     * @param string $code
     * @param string $grade
     */
    private function grade(\stdClass $student, $sr, string $code, string $grade): void {
        $course = $sr->create_course(['code' => $code, 'hours' => 3]);
        $offer = $sr->create_offering(['courseid' => $course->id, 'termid' => $sr->create_term()->id]);
        $regid = $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        \local_studentrecords\local\registration::set_grade($regid, $grade, null, 'staff');
    }

    /**
     * One transfer credit row in Student Records.
     *
     * @param \stdClass $student
     * @param array $fields
     */
    private function transfer(\stdClass $student, array $fields): void {
        global $DB;
        $DB->insert_record('local_studentrecords_xfer', (object)($fields + ['studentid' => $student->id,
            'institution' => 'Riverside College', 'extcourse' => 'Biology', 'countsprogram' => 1, 'active' => 1,
            'timecreated' => time(), 'timemodified' => time()]));
    }

    /**
     * The repeat rules.
     *
     * @return array
     */
    public static function repeat_rule_provider(): array {
        return ['latest' => ['latest'], 'highest' => ['highest'], 'all' => ['all']];
    }
}
