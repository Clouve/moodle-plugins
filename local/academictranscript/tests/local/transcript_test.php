<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_academictranscript\local;

#[\PHPUnit\Framework\Attributes\CoversClass(transcript::class)]
/**
 * Tests for building a transcript.
 *
 * The PHPUnit site starts with the standard scale (12 letters) and the standard marks (P, NP, I, NC, W, TR, IP).
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_academictranscript\local\transcript
 */
final class transcript_test extends \advanced_testcase {
    /**
     * Start each test with an empty request cache.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        scale::reset_cache();
    }

    /**
     * Transcript data is written as version 1, and the data version constant says so.
     */
    public function test_build_uses_data_version_one(): void {
        $user = $this->getDataGenerator()->create_user();

        $this->assertSame(1, transcript::DATA_VERSION);
        $this->assertSame(1, transcript::build($user->id)['version']);
    }

    /**
     * Posted grades and courses still being taken are grouped by term. A course not yet posted shows as IP, and
     * transfer credit has its own group. Hours earned add up over both, and the GPA counts only graded hours.
     */
    public function test_build_groups_attempts_by_term_and_sums_totals(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $fall = $generator->create_term(['name' => 'Fall 2025', 'startdate' => make_timestamp(2025, 8, 25),
            'enddate' => make_timestamp(2025, 12, 15)]);
        $spring = $generator->create_term(['name' => 'Spring 2026', 'startdate' => make_timestamp(2026, 1, 12),
            'enddate' => make_timestamp(2026, 5, 8)]);
        $program = $generator->create_program(['name' => 'Business Administration', 'unittype' => 'credit']);
        $user = $this->getDataGenerator()->create_user();
        $generator->create_student_record(['userid' => $user->id, 'programid' => $program->id]);
        $bus101 = $generator->create_course_with_fields(['fullname' => 'BUS 101 - Introduction to Business',
            'code' => 'BUS 101', 'hours' => 3, 'hourtype' => 'credit', 'termid' => $fall->id]);
        $bus102 = $generator->create_course_with_fields(['fullname' => 'BUS 102 - Accounting',
            'code' => 'BUS 102', 'hours' => 3, 'hourtype' => 'credit', 'termid' => $spring->id]);
        $this->getDataGenerator()->enrol_user($user->id, $bus101->id, 'student');
        $this->getDataGenerator()->enrol_user($user->id, $bus102->id, 'student');
        $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $bus101->id, 'termid' => $fall->id,
            'coursecode' => 'BUS 101', 'coursename' => 'Introduction to Business', 'grade' => 'A']);
        $generator->set_course_total($bus102->id, $user->id, 85);
        $generator->create_transfer(['userid' => $user->id, 'institution' => 'Valley College', 'coursecode' => 'ENG 101',
            'title' => 'English Composition', 'hours' => 3]);
        $generator->create_transfer(['userid' => $user->id, 'institution' => 'Valley College', 'coursecode' => 'ART 101',
            'title' => 'Drawing', 'hours' => 2, 'active' => 0]);

        $data = transcript::build($user->id);

        $this->assertSame([], $data['problems']);
        $this->assertSame('Business Administration', $data['program']);
        $this->assertSame('credit', $data['unittype']);
        $this->assertSame('Cr', $data['unitshort']);

        // BUS 101 was posted, so it is on the transcript once, with the grade that was posted.
        $this->assertCount(2, $data['terms']);
        $fallblock = $data['terms'][0];
        $this->assertSame('Fall 2025', $fallblock['name']);
        $this->assertSame(make_timestamp(2025, 8, 25), $fallblock['startdate']);
        $this->assertSame(make_timestamp(2025, 12, 15), $fallblock['enddate']);
        $this->assertCount(1, $fallblock['rows']);
        $posted = $fallblock['rows'][0];
        $this->assertSame('BUS 101', $posted['code']);
        $this->assertSame('Introduction to Business', $posted['title']);
        $this->assertSame(3.0, $posted['hours']);
        $this->assertSame('A', $posted['grade']);
        $this->assertSame(12.0, $posted['qp']);
        $this->assertTrue($posted['posted']);
        $this->assertSame(3.0, $fallblock['earned']);
        $this->assertSame(3.0, $fallblock['gpahours']);
        $this->assertSame(12.0, $fallblock['qp']);
        $this->assertSame('4.00', $fallblock['gpa']);

        // BUS 102 is not posted: the course total of 85 does not matter yet, the course shows as in progress.
        $springblock = $data['terms'][1];
        $this->assertSame('Spring 2026', $springblock['name']);
        $this->assertCount(1, $springblock['rows']);
        $inprogress = $springblock['rows'][0];
        $this->assertSame('BUS 102', $inprogress['code']);
        $this->assertSame('Accounting', $inprogress['title']);
        $this->assertSame('IP', $inprogress['grade']);
        $this->assertNull($inprogress['qp']);
        $this->assertFalse($inprogress['posted']);
        $this->assertSame(0.0, $springblock['earned']);
        $this->assertSame('', $springblock['gpa']);

        // The transfer credit that was switched off is not on the transcript.
        $this->assertCount(1, $data['transfer']);
        $this->assertSame('Valley College', $data['transfer'][0]['institution']);
        $this->assertSame(3.0, $data['transfer'][0]['earned']);
        $this->assertCount(1, $data['transfer'][0]['rows']);
        $this->assertSame('ENG 101', $data['transfer'][0]['rows'][0]['code']);
        $this->assertSame('TR', $data['transfer'][0]['rows'][0]['grade']);

        $summary = $data['summary'];
        $this->assertSame(['earned' => 3.0, 'gpahours' => 3.0, 'qp' => 12.0, 'gpa' => '4.00'], $summary['institutional']);
        $this->assertSame(3.0, $summary['transfer']['earned']);
        $this->assertSame(6.0, $summary['overall']['earned']);
        $this->assertSame(3.0, $summary['overall']['gpahours']);
        $this->assertSame('4.00', $summary['overall']['gpa']);
    }

    /**
     * Transfer hours are added in whole hundredths, so they add up exactly on every PHP version. A student with 6.68
     * hours earned and transfer rows of 1.13 and 5.84 hours at one institution has 6.97 transfer hours and 13.65 hours in all.
     * As floats, that total is 13.649999999999999, which PHP 8.4 shows with one decimal as 13.6 and PHP 8.3 as 13.7.
     */
    public function test_build_adds_transfer_hours_exactly(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall 2025']);
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $course->id, 'termid' => $term->id,
            'coursecode' => 'LAB 101', 'hours' => 6.68, 'grade' => 'A']);
        $generator->create_transfer(['userid' => $user->id, 'institution' => 'Valley College', 'coursecode' => 'ENG 101',
            'hours' => 1.13]);
        $generator->create_transfer(['userid' => $user->id, 'institution' => 'Valley College', 'coursecode' => 'ART 101',
            'hours' => 5.84]);

        $data = transcript::build($user->id);

        $this->assertSame(6.68, $data['summary']['institutional']['earned']);
        $this->assertCount(1, $data['transfer']);
        $this->assertSame(6.97, $data['transfer'][0]['earned']);
        $this->assertSame(6.97, $data['summary']['transfer']['earned']);
        $this->assertSame(13.65, $data['summary']['overall']['earned']);
    }

    /**
     * Each institution has its own transfer hours, and the transfer row of the summary adds all of them. As floats, 0.1 plus
     * 0.2 is 0.30000000000000004. A student with no hours earned here has the transfer hours as the overall hours.
     */
    public function test_build_keeps_the_transfer_hours_of_each_institution_apart(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $user = $this->getDataGenerator()->create_user();
        $generator->create_transfer(['userid' => $user->id, 'institution' => 'Valley College', 'hours' => 0.1]);
        $generator->create_transfer(['userid' => $user->id, 'institution' => 'Bay College', 'hours' => 2.45]);
        $generator->create_transfer(['userid' => $user->id, 'institution' => 'Valley College', 'hours' => 0.2]);

        $data = transcript::build($user->id);

        $this->assertSame(['Bay College', 'Valley College'], array_column($data['transfer'], 'institution'));
        $this->assertSame([2.45, 0.3], array_column($data['transfer'], 'earned'));
        $this->assertSame(2.75, $data['summary']['transfer']['earned']);
        $this->assertSame(0.0, $data['summary']['institutional']['earned']);
        $this->assertSame(2.75, $data['summary']['overall']['earned']);
    }

    /**
     * A course the student takes that has no code, no hours or no term is listed as a problem, by name. A course
     * with all its fields is not.
     */
    public function test_build_lists_problems_for_courses_missing_fields(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall 2026']);
        $user = $this->getDataGenerator()->create_user();
        $mystery = $this->getDataGenerator()->create_course(['fullname' => 'Mystery course']);
        $complete = $generator->create_course_with_fields(['fullname' => 'BUS 101 - Introduction to Business',
            'code' => 'BUS 101', 'hours' => 3, 'hourtype' => 'credit', 'termid' => $term->id]);
        $this->getDataGenerator()->enrol_user($user->id, $mystery->id, 'student');
        $this->getDataGenerator()->enrol_user($user->id, $complete->id, 'student');

        $data = transcript::build($user->id);

        $this->assertSame([
            get_string('problem_nocode', 'local_academictranscript', 'Mystery course'),
            get_string('problem_nohours', 'local_academictranscript', 'Mystery course'),
            get_string('problem_noterm', 'local_academictranscript', 'Mystery course'),
        ], $data['problems']);
    }

    /**
     * A course whose hours are of the other kind than the student's program is a problem. The same kind is not.
     */
    public function test_build_flags_unit_type_mismatch(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall 2026']);
        $program = $generator->create_program(['name' => 'Welding Certificate', 'unittype' => 'clock']);
        $user = $this->getDataGenerator()->create_user();
        $generator->create_student_record(['userid' => $user->id, 'programid' => $program->id]);
        $credit = $generator->create_course_with_fields(['fullname' => 'WELD 101 - Basics', 'code' => 'WELD 101',
            'hours' => 3, 'hourtype' => 'credit', 'termid' => $term->id]);
        $clock = $generator->create_course_with_fields(['fullname' => 'WELD 102 - Safety', 'code' => 'WELD 102',
            'hours' => 40, 'hourtype' => 'clock', 'termid' => $term->id]);
        $this->getDataGenerator()->enrol_user($user->id, $credit->id, 'student');
        $this->getDataGenerator()->enrol_user($user->id, $clock->id, 'student');

        $data = transcript::build($user->id);

        $this->assertSame('clock', $data['unittype']);
        $this->assertSame('Hrs', $data['unitshort']);
        $this->assertSame([get_string('problem_unittype', 'local_academictranscript', [
            'course' => 'WELD 101 - Basics',
            'unit' => get_string('creditlabel_default', 'local_academictranscript'),
        ])], $data['problems']);
    }

    /**
     * When a course is taken again, only the latest attempt counts in the GPA and hours earned. The earlier attempt
     * stays on the transcript with a footnote.
     */
    public function test_build_marks_repeated_attempts(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $fall = $generator->create_term(['name' => 'Fall 2025', 'startdate' => make_timestamp(2025, 8, 25),
            'enddate' => make_timestamp(2025, 12, 15)]);
        $spring = $generator->create_term(['name' => 'Spring 2026', 'startdate' => make_timestamp(2026, 1, 12),
            'enddate' => make_timestamp(2026, 5, 8)]);
        $user = $this->getDataGenerator()->create_user();
        $course = $generator->create_course_with_fields(['fullname' => 'BIO 101 - Biology', 'code' => 'BIO 101',
            'hours' => 3, 'hourtype' => 'credit']);
        $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $course->id, 'termid' => $fall->id,
            'coursecode' => 'BIO 101', 'grade' => 'F']);
        $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $course->id, 'termid' => $spring->id,
            'coursecode' => 'BIO 101', 'grade' => 'B']);

        $data = transcript::build($user->id);

        $this->assertCount(2, $data['terms']);
        $first = $data['terms'][0]['rows'][0];
        $second = $data['terms'][1]['rows'][0];
        $this->assertSame('F', $first['grade']);
        $this->assertSame([get_string('footnote_repeated', 'local_academictranscript')], $first['notes']);
        $this->assertFalse($first['ingpa']);
        $this->assertFalse($first['earned']);
        $this->assertSame('B', $second['grade']);
        $this->assertSame([], $second['notes']);
        $this->assertTrue($second['ingpa']);
        $this->assertTrue($second['earned']);
        $this->assertSame(['earned' => 3.0, 'gpahours' => 3.0, 'qp' => 9.0, 'gpa' => '3.00'], $data['summary']['institutional']);
    }

    /**
     * Names are plain text in the transcript data, as they were typed: an ampersand stays "&" and is not turned into
     * "&amp;" (the HTML and the PDF escape names when they print them). That holds for the title of a course in
     * progress, a problem that names the course, the program, the label of a mark in a footnote, and the descriptions
     * in the transcript key.
     */
    public function test_build_keeps_names_as_plain_text(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall 2026']);
        $program = $generator->create_program(['name' => 'Arts & Sciences']);
        $user = $this->getDataGenerator()->create_user();
        $generator->create_student_record(['userid' => $user->id, 'programid' => $program->id]);
        // A course in progress, without hours, so that a problem names it too.
        $current = $generator->create_course_with_fields(['fullname' => 'Health & Safety (Level 1)', 'code' => 'HLT 110',
            'hourtype' => 'credit', 'termid' => $term->id]);
        $this->getDataGenerator()->enrol_user($user->id, $current->id, 'student');
        // A pass that was posted without a note: the footnote is the label of the mark.
        $earlier = $this->getDataGenerator()->create_course();
        $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $earlier->id, 'termid' => $term->id,
            'coursecode' => 'PE 100', 'grade' => 'P']);
        scale::save_marks([['code' => 'P', 'label' => 'Pass & credit', 'description' => 'Passed & earned credit',
            'earns' => 1]]);
        scale::replace_grades([
            ['letter' => 'G', 'minpct' => 50, 'maxpct' => 100, 'points' => 4.0, 'ingpa' => 1, 'earns' => 1,
                'description' => 'Good & solid'],
            ['letter' => 'U', 'minpct' => 0, 'maxpct' => 49, 'points' => 0.0, 'ingpa' => 1, 'earns' => 0,
                'description' => 'Not yet'],
        ]);

        $data = transcript::build($user->id);

        $this->assertSame('Arts & Sciences', $data['program']);
        $this->assertSame(
            [get_string('problem_nohours', 'local_academictranscript', 'Health & Safety (Level 1)')],
            $data['problems']
        );
        $rows = array_merge(...array_column($data['terms'], 'rows'));
        $this->assertSame('Health & Safety (Level 1)', array_column($rows, 'title', 'code')['HLT 110']);
        $this->assertSame(['Pass & credit'], array_column($rows, 'notes', 'code')['PE 100']);
        $descriptions = array_column(scale::scale_rows(), 'description', 'grade');
        $this->assertSame('Good & solid', $descriptions['G']);
        $this->assertSame('Passed & earned credit', $descriptions['P']);
    }

    /**
     * Names are plain text also when the site setting "formatstringstriptags" is off. Moodle then runs its HTML cleaner
     * on names, which writes "&" as "&amp;", whatever the escape option of format_string() says.
     */
    public function test_build_keeps_names_as_plain_text_when_tags_are_not_stripped(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall 2026']);
        $program = $generator->create_program(['name' => 'Arts & Sciences']);
        $user = $this->getDataGenerator()->create_user();
        $generator->create_student_record(['userid' => $user->id, 'programid' => $program->id]);
        $course = $generator->create_course_with_fields(['fullname' => 'Health & Safety (Level 1)', 'code' => 'HLT 110',
            'hourtype' => 'credit', 'termid' => $term->id]);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        set_config('formatstringstriptags', 0);
        // The formatter keeps the strings it has done, whatever the setting says, so it starts again.
        \core\di::reset_container();

        $data = transcript::build($user->id);

        $this->assertSame('Arts & Sciences', $data['program']);
        $this->assertSame(
            [get_string('problem_nohours', 'local_academictranscript', 'Health & Safety (Level 1)')],
            $data['problems']
        );
        $this->assertSame('Health & Safety (Level 1)', $data['terms'][0]['rows'][0]['title']);
    }

    /**
     * The Student ID is the one in the transcript details. Without one, the ID number of the user profile is used.
     */
    public function test_build_student_id_falls_back_to_profile(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $user = $this->getDataGenerator()->create_user(['idnumber' => 'S-1001']);

        $this->assertSame('S-1001', transcript::build($user->id)['studentid']);

        $generator->create_student_record(['userid' => $user->id, 'studentid' => '  ']);
        $this->assertSame('S-1001', transcript::build($user->id)['studentid']);

        $DB->set_field('local_academictranscript_student', 'studentid', 'X9', ['userid' => $user->id]);
        $this->assertSame('X9', transcript::build($user->id)['studentid']);
    }

    /**
     * An Official transcript is not issued without saying who it is for.
     */
    public function test_issue_requires_recipient(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->setAdminUser();

        $error = $this->failure(fn() => transcript::issue($user->id, '  '));

        $this->assertSame('recipientrequired', $error->errorcode);
        $this->assertSame(0, $DB->count_records('local_academictranscript_issue'));
    }

    /**
     * An Official transcript is not issued while a course has a problem. The message lists the problems.
     */
    public function test_issue_refuses_when_problems_exist(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Mystery course']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setAdminUser();

        $error = $this->failure(fn() => transcript::issue($user->id, 'Valley College'));

        $this->assertSame('cannotissue', $error->errorcode);
        $this->assertStringContainsString(
            get_string('problem_nocode', 'local_academictranscript', 'Mystery course'),
            $error->getMessage()
        );
        $this->assertSame(0, $DB->count_records('local_academictranscript_issue'));
        $this->assertSame(0, $DB->count_records('local_academictranscript_log', ['action' => 'issue']));
    }

    /**
     * Issuing stores the transcript as it is now, with the transcript key, a verification code and a transcript
     * number. It also fires the event and writes the log.
     */
    public function test_issue_freezes_snapshot_and_code(): void {
        global $DB, $USER;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall 2025']);
        $user = $this->getDataGenerator()->create_user();
        $course = $generator->create_course_with_fields(['fullname' => 'BUS 101 - Introduction to Business',
            'code' => 'BUS 101', 'hours' => 3, 'hourtype' => 'credit', 'termid' => $term->id]);
        $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $course->id, 'termid' => $term->id,
            'coursecode' => 'BUS 101', 'coursename' => 'Introduction to Business', 'grade' => 'A']);
        $before = transcript::build($user->id);
        $this->setAdminUser();
        $sink = $this->redirectEvents();

        $issue = transcript::issue($user->id, 'Valley College Registrar');

        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{24}$/', $issue->verifycode);
        $this->assertSame(sprintf('T-%s-%06d', date('Y', $issue->timecreated), $issue->id), $issue->transcriptno);

        $stored = $DB->get_record('local_academictranscript_issue', ['id' => $issue->id], '*', MUST_EXIST);
        $this->assertSame($issue->verifycode, $stored->verifycode);
        $this->assertSame($issue->transcriptno, $stored->transcriptno);
        $this->assertSame('Valley College Registrar', $stored->recipient);
        $this->assertEquals($user->id, $stored->userid);
        $this->assertEquals($USER->id, $stored->issuedby);

        $snapshot = json_decode($stored->snapshot, true);
        $this->assertSame(transcript::DATA_VERSION, $snapshot['version']);
        $this->assertSame('Valley College Registrar', $snapshot['recipient']);
        $this->assertSame($stored->transcriptno, $snapshot['transcriptno']);
        $this->assertCount(19, $snapshot['key']);
        $this->assertEquals($before['terms'], $snapshot['terms']);
        $this->assertEquals($before['summary'], $snapshot['summary']);

        $events = array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof \local_academictranscript\event\official_issued
        ));
        $this->assertCount(1, $events);
        $this->assertEquals($issue->id, $events[0]->objectid);
        $this->assertEquals($user->id, $events[0]->relateduserid);
        $this->assertEquals(\context_user::instance($user->id)->id, $events[0]->contextid);

        $log = $DB->get_record('local_academictranscript_log', ['action' => 'issue'], '*', MUST_EXIST);
        $this->assertEquals($user->id, $log->studentid);
        $this->assertEquals($issue->id, $log->issueid);
        $this->assertEquals($USER->id, $log->userid);
        $this->assertSame('to: Valley College Registrar', $log->info);
    }

    /**
     * New verification codes have 24 characters and never use the letters and digits that look alike (I, O, 0, 1).
     * Many codes are made, because one code could miss a bad character by chance.
     */
    public function test_new_verifycode_avoids_look_alike_characters(): void {
        for ($i = 0; $i < 50; $i++) {
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{24}$/', transcript::new_verifycode());
        }
    }

    /**
     * A code is shown in groups of four letters and digits, and typed input is cleaned up before it is looked up.
     */
    public function test_clean_code_normalises_typed_input(): void {
        $this->assertSame('ABCD-EFGH-JKLM-NPQR-STUV-WXYZ', transcript::format_code('ABCDEFGHJKLMNPQRSTUVWXYZ'));
        $this->assertSame('ABCDEFGHJKLM', transcript::clean_code(' abcd-efgh jklm '));
        $this->assertSame('ABCDEFGHJKLMNPQRSTUVWXYZ', transcript::clean_code('abcd-efgh-jklm-npqr-stuv-wxyz'));
    }

    /**
     * The dates of a term are written as month, day and two-digit year. No date gives no text.
     */
    public function test_shortdate_uses_term_date_format(): void {
        $this->setTimezone('UTC', 'UTC');

        $this->assertSame('08/26/24', transcript::shortdate(make_timestamp(2024, 8, 26, 12, 0, 0, 'UTC')));
        $this->assertSame('', transcript::shortdate(0));
    }

    /**
     * The term date format is a language string, so a translation or a site's own customisation changes it.
     */
    public function test_shortdate_follows_the_language_string(): void {
        global $CFG;

        $this->setTimezone('UTC', 'UTC');
        $folder = $CFG->langlocalroot . '/en_local';
        check_dir_exists($folder, true, true);
        file_put_contents($folder . '/local_academictranscript.php', "<?php\n\$string['strftimetermdate'] = '%d.%m.%Y';\n");
        get_string_manager()->reset_caches();

        $this->assertSame('26.08.2024', transcript::shortdate(make_timestamp(2024, 8, 26, 12, 0, 0, 'UTC')));
    }

    /**
     * The name of the school is the School name setting, without the spaces around it. The name of the site is only the
     * fallback.
     */
    public function test_school_name_is_the_setting_when_there_is_one(): void {
        $this->set_site_name('Test Academy');
        set_config('schoolname', '  Riverside College ', 'local_academictranscript');

        $this->assertSame('Riverside College', transcript::school_name());
    }

    /**
     * Without a School name setting, the name of the school is the full name of the site, as plain text: an ampersand is
     * not written as "&amp;". A setting that holds only spaces counts as empty.
     */
    public function test_school_name_falls_back_to_the_site_name_as_plain_text(): void {
        $this->set_site_name('Smith & Sons College');

        set_config('schoolname', '', 'local_academictranscript');
        $this->assertSame('Smith & Sons College', transcript::school_name());

        set_config('schoolname', '   ', 'local_academictranscript');
        $this->assertSame('Smith & Sons College', transcript::school_name());
    }

    /**
     * Give the site a full name.
     *
     * @param string $fullname
     */
    private function set_site_name(string $fullname): void {
        global $DB, $SITE;

        $DB->set_field('course', 'fullname', $fullname, ['id' => SITEID]);
        $SITE->fullname = $fullname;
    }

    /**
     * Run code that must fail with a moodle_exception, and return the exception.
     *
     * @param callable $callback
     * @return \moodle_exception
     */
    private function failure(callable $callback): \moodle_exception {
        try {
            $callback();
        } catch (\moodle_exception $exception) {
            return $exception;
        }
        $this->fail('The code did not throw a moodle_exception.');
    }
}
