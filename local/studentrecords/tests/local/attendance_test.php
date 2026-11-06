<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(attendance::class)]
/**
 * Tests for attendance: weekly schedules, rates, the last date of attendance, who may take it, and saving the marks.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\attendance
 */
final class attendance_test extends \advanced_testcase {
    /**
     * A section linked to a Moodle course, with a registered student and a meeting today.
     *
     * @param \stdClass $course
     * @return array gen, student, offer, regid, meetingid
     */
    private function setup_meeting(\stdClass $course): array {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $term = $gen->create_term();
        $offer = $gen->create_offering(['courseid' => $gen->create_course()->id, 'termid' => $term->id,
            'moodlecourseid' => $course->id]);
        $student = $gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-1']);
        $regid = $gen->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        $meetingid = $gen->create_meeting(['offerid' => $offer->id, 'date' => catalog::time_to_date(time()), 'hours' => 3]);
        return ['gen' => $gen, 'student' => $student, 'offer' => $offer, 'regid' => $regid, 'meetingid' => $meetingid];
    }

    /**
     * A teacher takes attendance in their own course and not in another teacher's; records staff in any.
     */
    public function test_a_teacher_takes_attendance_only_in_their_own_course(): void {
        $this->resetAfterTest();
        $mine = $this->getDataGenerator()->create_course();
        $theirs = $this->getDataGenerator()->create_course();
        $s = $this->setup_meeting($mine);
        $other = $s['gen']->create_offering(['courseid' => $s['offer']->courseid, 'termid' => $s['offer']->termid,
            'section' => '02', 'moodlecourseid' => $theirs->id]);
        $unlinked = $s['gen']->create_offering(['courseid' => $s['offer']->courseid, 'termid' => $s['offer']->termid,
            'section' => '03']);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $mine->id, 'editingteacher');
        $this->setUser($teacher);

        $this->assertTrue(attendance::can_take($s['offer']));
        $this->assertFalse(attendance::can_take($other));
        $this->assertFalse(attendance::can_take($unlinked));

        $this->setAdminUser();
        $this->assertTrue(attendance::can_take($other));
    }

    /**
     * A teacher sees the legal first name of a student who opted out of directory information, and the preferred name of
     * one who didn't. Records staff see both.
     */
    public function test_the_sheet_honours_the_ferpa_opt_out(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $s = $this->setup_meeting($course);
        $DB->update_record(students::TABLE, (object)['id' => $s['student']->id, 'legalfirstname' => 'Ana',
            'legallastname' => 'Ruiz', 'preferredname' => 'Annie', 'ferpaoptout' => 1]);
        $second = $s['gen']->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-2']);
        $DB->update_record(students::TABLE, (object)['id' => $second->id, 'legalfirstname' => 'Robert',
            'legallastname' => 'Lee', 'preferredname' => 'Bob', 'ferpaoptout' => 0]);
        $regid = $s['gen']->create_registration(['studentid' => $second->id, 'offerid' => $s['offer']->id]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $sheet = attendance::sheet($DB->get_record(attendance::MEETINGS, ['id' => $s['meetingid']]));

        $this->setUser($teacher);
        $this->assertSame('Ana Ruiz', attendance::sheet_name($sheet[$s['regid']]));
        $this->assertSame('Bob Lee', attendance::sheet_name($sheet[$regid]));

        $this->setAdminUser();
        $this->assertSame('Annie Ruiz', attendance::sheet_name($sheet[$s['regid']]));
    }

    /**
     * A section linked to a course that was deleted is no error: only records staff reach it.
     */
    public function test_a_deleted_course_stops_nobody_with_an_error(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $s = $this->setup_meeting($course);
        $DB->set_field(registration::OFFER, 'moodlecourseid', 999999, ['id' => $s['offer']->id]);
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(attendance::can_take(registration::offer((int)$s['offer']->id)));
    }

    /**
     * Marks are saved for students of the meeting only, a second save updates the mark, and hours can't exceed the meeting.
     */
    public function test_save_marks(): void {
        global $DB;
        $s = $this->setup_meeting($this->getDataGenerator()->create_course());
        $outsider = $s['gen']->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-2']);
        $section = $s['gen']->create_offering(['courseid' => $s['offer']->courseid, 'termid' => $s['offer']->termid,
            'section' => '02']);
        $otherreg = $s['gen']->create_registration(['studentid' => $outsider->id, 'offerid' => $section->id]);
        $this->setAdminUser();

        $n = attendance::save_marks($s['meetingid'], [
            $s['regid'] => ['status' => 'present', 'hours' => 9, 'note' => 'On time'],
            $otherreg => ['status' => 'present', 'hours' => 3],
        ]);

        $this->assertSame(1, $n);
        $mark = $DB->get_record(attendance::MARKS, ['regid' => $s['regid']]);
        $this->assertSame('present', $mark->status);
        $this->assertEquals(3, $mark->hours);
        $this->assertFalse($DB->record_exists(attendance::MARKS, ['regid' => $otherreg]));

        attendance::save_marks($s['meetingid'], [$s['regid'] => ['status' => 'absent', 'hours' => 3]]);
        $mark = $DB->get_record(attendance::MARKS, ['regid' => $s['regid']]);
        $this->assertSame('absent', $mark->status);
        $this->assertEquals(0, $mark->hours);
        $this->assertSame(1, $DB->count_records(attendance::MARKS));
    }

    /**
     * A meeting in the future takes no marks, and the summary of a registration reads its marks and meetings.
     */
    public function test_marks_and_summary(): void {
        $s = $this->setup_meeting($this->getDataGenerator()->create_course());
        $this->setAdminUser();
        $future = $s['gen']->create_meeting(['offerid' => $s['offer']->id, 'date' => '2031-01-06', 'hours' => 2]);
        try {
            attendance::save_marks($future, [$s['regid'] => ['status' => 'present', 'hours' => 2]]);
            $this->fail('A future meeting took marks.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_futuremeeting', $e->errorcode);
        }
        attendance::save_marks($s['meetingid'], [$s['regid'] => ['status' => 'late', 'hours' => 2]]);
        $summary = attendance::for_reg((object)['id' => $s['regid'], 'offerid' => $s['offer']->id], null);
        $this->assertSame(100.0, $summary['rate']);
        $this->assertSame(2.0, $summary['hours']);
        $this->assertSame(2, $summary['meetings']);
        $this->assertEquals(5, $summary['scheduled']);
        $this->assertCount(2, attendance::meetings((int)$s['offer']->id));
    }

    /**
     * The attendance report reads every section of a term and lists the students under the minimum rate.
     */
    public function test_report(): void {
        $s = $this->setup_meeting($this->getDataGenerator()->create_course());
        $this->setAdminUser();
        attendance::save_marks($s['meetingid'], [$s['regid'] => ['status' => 'absent', 'hours' => 0]]);

        $report = attendance::report((int)$s['offer']->termid);

        $this->assertCount(1, $report['offers']);
        $this->assertSame(1, $report['offers'][0]['students']);
        $this->assertCount(1, $report['under']);
        $this->assertSame(0.0, $report['under'][0]['rate']);
    }

    /**
     * The rate is exact on every PHP version: 23 of 80 is 28.75%, shown as 28.8. PHP 8.4 gives 28.7 through round().
     */
    public function test_rate_is_exact(): void {
        $marks = [];
        for ($i = 0; $i < 80; $i++) {
            $marks[] = ['status' => $i < 23 ? 'present' : 'absent', 'hours' => 1, 'date' => '2027-01-11'];
        }
        $this->assertSame(28.8, attendance::summary($marks)['rate']);
    }

    /**
     * A Monday to Thursday schedule over Spring 2027 (2027-01-11 to 2027-04-30): 16 weeks, 64 meetings.
     */
    public function test_weekly_days(): void {
        $days = attendance::weekly_days('2027-01-11', '2027-04-30', [1, 2, 3, 4]);
        $this->assertCount(64, $days);
        $this->assertSame('2027-01-11', $days[0]);
        $this->assertSame('2027-04-29', end($days));
        $this->assertSame(['2027-01-12', '2027-01-14'], attendance::weekly_days('2027-01-11', '2027-01-17', [2, 4]));
        $this->assertSame([], attendance::weekly_days('2027-01-11', '2027-01-10', [1]));
    }

    /**
     * Rate: present and late out of the meetings marked, excused left out; hours; last day present or late.
     */
    public function test_summary(): void {
        $s = attendance::summary([
            ['status' => 'present', 'hours' => 5, 'date' => '2027-01-11'],
            ['status' => 'late', 'hours' => 4, 'date' => '2027-01-12'],
            ['status' => 'absent', 'hours' => 0, 'date' => '2027-01-13'],
            ['status' => 'excused', 'hours' => 0, 'date' => '2027-01-14'],
            ['status' => 'absent', 'hours' => 0, 'date' => '2027-01-18'],
        ]);
        $this->assertSame([2, 1, 1, 2, 1, 5], [$s['present'] + $s['late'], $s['present'], $s['late'], $s['absent'],
            $s['excused'], $s['marked']]);
        $this->assertSame(50.0, $s['rate']);
        $this->assertSame(9.0, $s['hours']);
        $this->assertSame('2027-01-12', $s['last']);
        $none = attendance::summary([['status' => 'excused', 'hours' => 0, 'date' => '2027-01-11']]);
        $this->assertNull($none['rate']);
        $this->assertSame('', $none['last']);
    }

    /**
     * Last date of attendance: Moodle course access counts only when the school says so.
     */
    public function test_last_date(): void {
        $this->assertSame('2027-01-12', attendance::last_date('2027-01-12', '2027-02-01', false));
        $this->assertSame('2027-02-01', attendance::last_date('2027-01-12', '2027-02-01', true));
        $this->assertSame('2027-01-12', attendance::last_date('2027-01-12', '', true));
        $this->assertSame('2027-02-01', attendance::last_date('', '2027-02-01', true));
    }
}
