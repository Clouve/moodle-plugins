<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(registration::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(grading::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(catalog::class)]
/**
 * Tests for registration: term windows, credits and GPA, percentage to letter, the seats of a section, Moodle enrolment,
 * final grades and the teacher posting that Academic Transcript calls.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\registration
 * @covers     \local_studentrecords\local\grading
 * @covers     \local_studentrecords\local\catalog
 */
final class registration_test extends \advanced_testcase {
    /**
     * Each test starts with a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A scale, a term, a catalog course in a section, and a student.
     *
     * @param array $offer more fields of the section, such as moodlecourseid or capacity
     * @return array generator, student, offer, term, user
     */
    private function setup_section(array $offer = []): array {
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $gen->create_scale();
        $term = $gen->create_term();
        $course = $gen->create_course();
        $user = $this->getDataGenerator()->create_user();
        $student = $gen->create_student(['userid' => $user->id, 'studentid' => 'S-1']);
        $section = $gen->create_offering(['courseid' => $course->id, 'termid' => $term->id] + $offer);
        return ['gen' => $gen, 'student' => $student, 'offer' => $section, 'term' => $term, 'user' => $user];
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
     * Add and drop until the end of add/drop; withdraw after it until the last day to withdraw (Spring 2027).
     */
    public function test_window(): void {
        $spring = ['startdate' => '2027-01-11', 'enddate' => '2027-04-30', 'adddropend' => '2027-01-19',
            'withdrawby' => '2027-03-17'];
        $this->assertNull(registration::window_problem('add', '2026-12-01', $spring));
        $this->assertNull(registration::window_problem('add', '2027-01-19', $spring));
        $this->assertSame('err_addclosed', registration::window_problem('add', '2027-01-20', $spring));
        $this->assertNull(registration::window_problem('drop', '2027-01-19', $spring));
        $this->assertSame('err_dropclosed', registration::window_problem('drop', '2027-01-20', $spring));
        $this->assertSame('err_withdrawearly', registration::window_problem('withdraw', '2027-01-19', $spring));
        $this->assertNull(registration::window_problem('withdraw', '2027-01-20', $spring));
        $this->assertNull(registration::window_problem('withdraw', '2027-03-17', $spring));
        $this->assertSame('err_withdrawclosed', registration::window_problem('withdraw', '2027-03-18', $spring));
        $noadddrop = ['startdate' => '2027-01-11', 'enddate' => '2027-04-30', 'adddropend' => '', 'withdrawby' => ''];
        $this->assertNull(registration::window_problem('add', '2027-04-30', $noadddrop));
        $this->assertSame('err_dropclosed', registration::window_problem('drop', '2027-01-12', $noadddrop));
        $this->assertNull(registration::window_problem('withdraw', '2027-04-30', $noadddrop));
        $this->assertSame('err_unknownaction', registration::window_problem('delete', '2027-01-12', $spring));
    }

    /**
     * Credits attempted and earned, GPA: withdrawals attempted not in GPA, failed courses in GPA not earned,
     * drops ignored, marks without points not in GPA, courses in progress attempted only.
     */
    public function test_totals(): void {
        $rows = [
            ['hours' => 3, 'status' => 'completed', 'letter' => 'A', 'points' => 4.0, 'passed' => true],
            ['hours' => 3, 'status' => 'completed', 'letter' => 'B', 'points' => 3.0, 'passed' => true],
            ['hours' => 3, 'status' => 'completed', 'letter' => 'F', 'points' => 0.0, 'passed' => false],
            ['hours' => 3, 'status' => 'withdrawn', 'letter' => 'W', 'points' => null, 'passed' => null],
            ['hours' => 3, 'status' => 'dropped', 'letter' => '', 'points' => null, 'passed' => null],
            ['hours' => 1, 'status' => 'completed', 'letter' => 'P', 'points' => null, 'passed' => true],
            ['hours' => 3, 'status' => 'registered', 'letter' => '', 'points' => null, 'passed' => null],
        ];
        $t = registration::totals($rows);
        $this->assertEquals(16.0, $t['attempted']);
        $this->assertEquals(7.0, $t['earned']);
        $this->assertEquals(9.0, $t['gpahours']);
        $this->assertEquals(2.33, $t['gpa']);
        $this->assertNull(registration::totals([])['gpa']);
    }

    /**
     * Percentage to letter on an example scale, with rounding off (gaps between ranges get the lower letter).
     */
    public function test_letter_for_pct(): void {
        $scale = [['letter' => 'A', 'minpct' => 94, 'points' => 4], ['letter' => 'A-', 'minpct' => 90, 'points' => 3.7],
            ['letter' => 'B+', 'minpct' => 86, 'points' => 3.3], ['letter' => 'F', 'minpct' => 0, 'points' => 0]];
        $this->assertSame('A', grading::letter_for_pct($scale, 100, false));
        $this->assertSame('A', grading::letter_for_pct($scale, 94, false));
        $this->assertSame('A-', grading::letter_for_pct($scale, 93.5, false));
        $this->assertSame('B+', grading::letter_for_pct($scale, 89.99, false));
        $this->assertSame('F', grading::letter_for_pct($scale, 12, false));
        $this->assertNull(grading::letter_for_pct([], 50, false));
    }

    /**
     * The boundary values give the same letter here and in Academic Transcript's calculator, with rounding on and off.
     *
     * @dataProvider boundary_provider
     * @param float $percent
     * @param string $rounded the letter with rounding on
     * @param string $exact the letter with rounding off
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('boundary_provider')]
    public function test_letter_matches_the_transcript(float $percent, string $rounded, string $exact): void {
        $rows = [['A', 90, 100, 4], ['B', 80, 89.99, 3], ['F', 0, 79.99, 0]];
        $scale = array_map(fn($r) => ['letter' => $r[0], 'minpct' => $r[1], 'maxpct' => $r[2], 'points' => $r[3]], $rows);
        $this->assertSame($rounded, grading::letter_for_pct($scale, $percent));
        $this->assertSame($rounded, grading::letter_for_pct($scale, $percent, true));
        $this->assertSame($exact, grading::letter_for_pct($scale, $percent, false));
        $calculator = '\\local_academictranscript\\local\\calculator';
        if (class_exists($calculator)) {
            $grades = array_map(fn($r) => ['letter' => $r[0], 'min' => $r[1], 'max' => $r[2], 'points' => $r[3], 'ingpa' => 1,
                'earns' => 1], $rows);
            $this->assertSame((new $calculator($grades, [], 'latest', true))->letter($percent), $rounded);
            $this->assertSame((new $calculator($grades, [], 'latest', false))->letter($percent), $exact);
        }
    }

    /**
     * Data for test_letter_matches_the_transcript.
     *
     * @return array
     */
    public static function boundary_provider(): array {
        return [
            'a half rounds up' => [89.5, 'A', 'B'],
            'a half with a float error' => [89.499999999999986, 'A', 'B'],
            'just under a half' => [89.4999, 'B', 'B'],
            'on the minimum' => [90.0, 'A', 'A'],
            'a float error under the minimum' => [89.999999999999986, 'A', 'A'],
            'the top' => [100.0, 'A', 'A'],
            'a half at a lower minimum' => [79.5, 'B', 'F'],
            'zero' => [0.0, 'F', 'F'],
        ];
    }

    /**
     * Student Records follows the rounding setting of Academic Transcript, and rounds nothing when it isn't installed.
     */
    public function test_teacher_letter_follows_the_rounding_setting(): void {
        $this->setup_section();
        if (\core_component::get_component_directory('local_academictranscript') === null) {
            $this->assertFalse(catalog::round_percent());
            $this->assertSame('B', registration::teacher_letter(89.5, ''));
            return;
        }
        set_config('roundpercent', 1, 'local_academictranscript');
        $this->assertSame('A', registration::teacher_letter(89.5, ''));
        $this->assertSame('A', registration::teacher_letter(89.499999999999986, ''));
        set_config('roundpercent', 0, 'local_academictranscript');
        $this->assertSame('B', registration::teacher_letter(89.5, ''));
    }

    /**
     * A letter from the gradebook comes from the exact percentage, not from one already rounded to four places. 89.49996
     * is a B when the total is rounded to a whole percent, and would be an A after rounding to 89.5 first.
     */
    public function test_letter_from_the_gradebook_uses_the_exact_percentage(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        set_config('roundpercent', 1, 'local_academictranscript');
        $course = $this->getDataGenerator()->create_course();
        $s = $this->setup_section(['moodlecourseid' => $course->id]);
        $regid = $s['gen']->create_registration(['studentid' => $s['student']->id, 'offerid' => $s['offer']->id]);
        $record = $this->getDataGenerator()->create_grade_item(['courseid' => $course->id, 'itemtype' => 'manual',
            'itemname' => 'Final', 'grademin' => 0, 'grademax' => 100]);
        \grade_item::fetch(['id' => $record->id])->update_final_grade((int)$s['user']->id, 89.49996, 'local_studentrecords');
        grade_regrade_final_grades($course->id);

        $this->assertSame([1, 0, 0, 0], registration::grades_from_moodle((int)$s['offer']->id));

        $reg = $DB->get_record(registration::REG, ['id' => $regid]);
        $this->assertSame('B', $reg->grade);
        $this->assertSame('moodle', $reg->gradesource);
        $this->assertEqualsWithDelta(89.5, (float)$reg->gradepct, 0.0001);
    }

    /**
     * Quality points and GPA are exact: a GPA halfway between two hundredths rounds up on every PHP version (PHP 8.4 gives
     * 3.47 for this one through round()), and fractions of an hour add up without a float error.
     */
    public function test_totals_are_exact(): void {
        $row = fn($hours, $points) => ['hours' => $hours, 'status' => 'completed', 'letter' => 'X', 'points' => $points,
            'passed' => true];
        $this->assertSame(3.48, registration::totals([$row(1, 4), $row(3, 3.3)])['gpa']);
        $this->assertSame(2.73, registration::totals([$row(0.5, 4), $row(1.5, 2.3)])['gpa']);
        $t = registration::totals([$row(0.1, 4), $row(0.2, 4)]);
        $this->assertSame(0.3, $t['attempted']);
        $this->assertSame(0.3, $t['earned']);
        $this->assertSame(1.2, $t['qualitypoints']);
        $this->assertSame(4.0, $t['gpa']);
    }

    /**
     * The academic history reads its figures from the database on every database.
     */
    public function test_history_counts_grades_transfers_and_drops(): void {
        global $DB;
        $s = $this->setup_section();
        $gen = $s['gen'];
        $course = $gen->create_course(['hours' => 1]);
        $second = $gen->create_offering(['courseid' => $course->id, 'termid' => $s['term']->id]);
        $dropped = $gen->create_offering(['courseid' => $gen->create_course()->id, 'termid' => $s['term']->id]);
        $regid = $gen->create_registration(['studentid' => $s['student']->id, 'offerid' => $s['offer']->id]);
        $other = $gen->create_registration(['studentid' => $s['student']->id, 'offerid' => $second->id]);
        $gone = $gen->create_registration(['studentid' => $s['student']->id, 'offerid' => $dropped->id]);
        $this->setAdminUser();
        registration::set_grade($regid, 'B', 85.0, 'staff');
        registration::set_grade($other, 'A', 95.0, 'staff');
        registration::end($gone, 'drop', true, 'test');
        $DB->insert_record('local_studentrecords_xfer', (object)['studentid' => $s['student']->id, 'institution' => 'Riverside',
            'credits' => 3, 'grade' => 'A', 'ingpa' => 1, 'active' => 1, 'timecreated' => time()]);

        $history = registration::history($s['student']);

        $this->assertCount(1, $history['terms']);
        $this->assertCount(3, $history['terms'][0]['rows']);
        $this->assertSame(7.0, $history['cumulative']['attempted']);
        $this->assertSame(7.0, $history['cumulative']['earned']);
        // A 3 credits B, a 1 credit A and a 3 credits transfer A: 25 points over 7 hours.
        $this->assertSame(3.57, $history['cumulative']['gpa']);
        $this->assertSame(3.25, $history['terms'][0]['totals']['gpa']);
    }

    /**
     * A failed mark or grade doesn't count as a passed prerequisite; a rule added during the request is seen at once.
     */
    public function test_pass_rules_are_read_fresh(): void {
        global $DB;
        $s = $this->setup_section();
        $course = (int)$s['offer']->courseid;
        $this->assertTrue(registration::result_passes($s['student'], $course, 'C'));
        $this->assertFalse(registration::result_passes($s['student'], $course, 'F'));
        $this->assertTrue(registration::result_passes($s['student'], $course, 'P'));
        $this->assertFalse(registration::result_passes($s['student'], $course, 'I'));
        $program = $s['gen']->create_program();
        $DB->set_field(students::TABLE, 'programid', $program->id, ['id' => $s['student']->id]);
        $DB->insert_record('local_studentrecords_minpass', (object)['programid' => $program->id, 'minletter' => 'B',
            'timemodified' => time()]);
        $this->assertFalse(registration::result_passes(students::get((int)$s['student']->id), $course, 'C'));
    }

    /**
     * A blocking hold stops a registration, even with a staff override; a released one doesn't.
     */
    public function test_a_hold_cannot_be_overridden(): void {
        global $DB;
        $s = $this->setup_section();
        $this->setAdminUser();
        $hold = academic::place_hold($s['student'], ['holdtype' => 'financial', 'reason' => 'Balance', 'blocksreg' => 1,
            'startdate' => time() - 100]);
        $add = fn() => registration::add($s['student'], (int)$s['offer']->id, true, 'override');
        $this->assertSame('err_hold', $this->error_of($add));
        $this->assertSame(0, $DB->count_records(registration::REG));
        academic::release_hold($hold, 'Paid');
        $this->assertGreaterThan(0, $add());
    }

    /**
     * A missing prerequisite stops a registration unless staff override it with a reason; passing it, or transfer credit
     * for it, opens the course.
     */
    public function test_prerequisites(): void {
        global $DB;
        $s = $this->setup_section();
        $gen = $s['gen'];
        $first = $gen->create_course(['code' => 'BIO 100']);
        $DB->insert_record('local_studentrecords_prereq', (object)['courseid' => $s['offer']->courseid,
            'reqcourseid' => $first->id]);
        $this->assertSame(['BIO 100'], registration::missing_prereqs($s['student'], (int)$s['offer']->courseid));
        $this->assertSame('err_prereqs', $this->error_of(fn() => registration::add($s['student'], (int)$s['offer']->id)));
        $this->assertSame(
            'err_overridenote',
            $this->error_of(fn() => registration::add($s['student'], (int)$s['offer']->id, true, ' '))
        );

        $offer = $gen->create_offering(['courseid' => $first->id, 'termid' => $s['term']->id]);
        $regid = $gen->create_registration(['studentid' => $s['student']->id, 'offerid' => $offer->id]);
        $this->setAdminUser();
        registration::set_grade($regid, 'F', null, 'staff');
        $this->assertSame(['BIO 100'], registration::missing_prereqs($s['student'], (int)$s['offer']->courseid));
        $DB->insert_record('local_studentrecords_xfer', (object)['studentid' => $s['student']->id, 'institution' => 'Riverside',
            'credits' => 3, 'equivcourseid' => $first->id, 'active' => 1, 'timecreated' => time()]);
        $this->assertSame([], registration::missing_prereqs($s['student'], (int)$s['offer']->courseid));
    }

    /**
     * The last seat goes to one student, and a seat held for a student offered it from the waitlist is taken for others.
     */
    public function test_a_full_section_takes_nobody_else(): void {
        $s = $this->setup_section(['capacity' => 1]);
        $gen = $s['gen'];
        $other = $gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-2']);
        registration::add($s['student'], (int)$s['offer']->id);
        $this->assertSame('err_full', $this->error_of(fn() => registration::add($other, (int)$s['offer']->id)));
        $this->assertSame('err_alreadyregistered', $this->error_of(fn() => registration::add($s['student'], (int)$s['offer']->id)));
    }

    /**
     * While another request holds the seats of a section, a registration waits and then stops with a message: it doesn't
     * count the seats at the same time. The file lock works the same on every database, also inside one process.
     */
    public function test_a_registration_waits_for_the_lock_on_the_seats(): void {
        global $CFG, $DB;
        $s = $this->setup_section(['capacity' => 1]);
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        $lock = \core\lock\lock_config::get_lock_factory('local_studentrecords')->get_lock('offer' . $s['offer']->id, 0);
        $this->assertNotFalse($lock);
        try {
            $this->assertSame('err_busy', $this->error_of(fn() => registration::add($s['student'], (int)$s['offer']->id)));
        } finally {
            $lock->release();
        }
        $this->assertSame(0, $DB->count_records(registration::REG));
        $this->assertGreaterThan(0, registration::add($s['student'], (int)$s['offer']->id));
    }

    /**
     * A registration that fails halfway leaves nothing behind and no open transaction, so the next class of a cart and the
     * rest of the page still work.
     */
    public function test_a_failed_registration_rolls_back(): void {
        global $DB;
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course();
        $DB->delete_records('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $s = $this->setup_section(['moodlecourseid' => $course->id]);

        $this->assertSame('err_nomanual', $this->error_of(fn() => registration::add($s['student'], (int)$s['offer']->id)));

        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame(0, $DB->count_records(registration::REG));
    }

    /**
     * A course whose manual method is disabled gives no access, so the registration says so instead of hiding it.
     */
    public function test_a_disabled_manual_method_stops_the_registration(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['courseid' => $course->id, 'enrol' => 'manual']);
        $s = $this->setup_section(['moodlecourseid' => $course->id]);
        $this->assertSame('err_nomanual', $this->error_of(fn() => registration::add($s['student'], (int)$s['offer']->id)));
    }

    /**
     * A registration enrols the student, a drop suspends the enrolment and keeps it and the role, and registering again
     * activates it. A drop with no manual method left doesn't fail.
     */
    public function test_a_drop_suspends_and_never_removes(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $s = $this->setup_section(['moodlecourseid' => $course->id]);
        $this->setAdminUser();
        $userid = (int)$s['user']->id;
        $context = \context_course::instance($course->id);
        $regid = registration::add($s['student'], (int)$s['offer']->id);
        $this->assertTrue(is_enrolled($context, $userid, '', true));

        registration::end($regid, 'drop');

        $this->assertSame('dropped', $DB->get_field(registration::REG, 'status', ['id' => $regid]));
        $this->assertFalse(is_enrolled($context, $userid, '', true));
        $this->assertTrue(is_enrolled($context, $userid));
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        $this->assertTrue(user_has_role_assignment($userid, $roleid, $context->id));

        registration::add($s['student'], (int)$s['offer']->id);
        $this->assertTrue(is_enrolled($context, $userid, '', true));

        $DB->delete_records('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        registration::end($regid, 'drop');
        $this->assertSame('dropped', $DB->get_field(registration::REG, 'status', ['id' => $regid]));
    }

    /**
     * A drop or withdrawal keeps the term dates, unless staff override them with a reason.
     */
    public function test_end_keeps_the_term_dates(): void {
        global $DB;
        $s = $this->setup_section();
        $regid = $s['gen']->create_registration(['studentid' => $s['student']->id, 'offerid' => $s['offer']->id]);
        $this->setAdminUser();
        $this->assertSame('err_withdrawearly', $this->error_of(fn() => registration::end($regid, 'withdraw')));
        $this->assertSame('err_overridenote', $this->error_of(fn() => registration::end($regid, 'withdraw', true)));
        registration::end($regid, 'withdraw', true, 'Moved away');
        $this->assertSame('withdrawn', $DB->get_field(registration::REG, 'status', ['id' => $regid]));
        $this->assertSame('err_notregistered', $this->error_of(fn() => registration::end($regid, 'drop')));
    }

    /**
     * The offerings list counts seats, and sections copy from the term before, without their Moodle course.
     */
    public function test_offers_and_copy_from_last_term(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $s = $this->setup_section(['moodlecourseid' => $course->id]);
        $gen = $s['gen'];
        $gen->create_registration(['studentid' => $s['student']->id, 'offerid' => $s['offer']->id]);
        $next = $gen->create_term(['startdate' => '2030-08-19', 'enddate' => '2030-12-13']);
        $this->setAdminUser();

        $this->assertEquals(1, registration::offers((int)$s['term']->id, true)[$s['offer']->id]->taken);
        $this->assertEquals($s['term']->id, registration::previous_term((int)$next->id)->id);
        $this->assertNull(registration::previous_term((int)$s['term']->id));

        $this->assertSame(1, registration::copy_sections((int)$s['term']->id, (int)$next->id));
        $this->assertSame(0, registration::copy_sections((int)$s['term']->id, (int)$next->id));

        $copy = $DB->get_record(registration::OFFER, ['termid' => $next->id]);
        $this->assertNull($copy->moodlecourseid);
        $this->assertSame('open', $copy->status);
    }

    /**
     * Only a course in progress or completed takes a final grade: a direct post can't turn a drop into a passed course.
     */
    public function test_a_dropped_course_takes_no_grade(): void {
        global $DB;
        $s = $this->setup_section();
        $regid = $s['gen']->create_registration(['studentid' => $s['student']->id, 'offerid' => $s['offer']->id]);
        $this->setAdminUser();
        registration::end($regid, 'drop');

        $this->assertSame('err_gradenotactive', $this->error_of(fn() => registration::set_grade($regid, 'A', null, 'staff')));

        $reg = $DB->get_record(registration::REG, ['id' => $regid]);
        $this->assertSame('dropped', $reg->status);
        $this->assertNull($reg->grade);
    }

    /**
     * Staff correct a grade until the term's grade freeze, and the change history keeps the old value. After the freeze
     * only an approved grade change writes.
     */
    public function test_staff_correct_a_grade_until_the_freeze(): void {
        global $DB;
        $s = $this->setup_section();
        $regid = $s['gen']->create_registration(['studentid' => $s['student']->id, 'offerid' => $s['offer']->id]);
        $this->setAdminUser();
        registration::set_grade($regid, 'B', 85.0, 'staff');

        registration::set_grade($regid, 'A', 95.0, 'staff');

        $this->assertSame('A', $DB->get_field(registration::REG, 'grade', ['id' => $regid]));
        $this->assertTrue($DB->record_exists_select(
            'local_studentrecords_history',
            'tablename = ? AND recordid = ? AND fieldname = ? AND ' . $DB->sql_compare_text('oldvalue') . ' = ' .
                $DB->sql_compare_text('?'),
            [registration::REG, $regid, 'grade', 'B']
        ));

        $offer = $DB->get_record(registration::OFFER, ['id' => $s['offer']->id]);
        $DB->set_field('local_studentrecords_term', 'gradefreeze', time() - 3 * DAYSECS, ['id' => $offer->termid]);
        $this->assertSame('err_frozen', $this->error_of(fn() => registration::set_grade($regid, 'B', 85.0, 'staff')));
        $this->assertSame('A', $DB->get_field(registration::REG, 'grade', ['id' => $regid]));
    }

    /**
     * The gradebook and teachers never replace a grade staff set or a grade change set, and the gradebook keeps a teacher's.
     * Only a grade change uses the grade change source.
     */
    public function test_kept_grades_stay_whoever_writes(): void {
        global $DB;
        $s = $this->setup_section();
        $regid = $s['gen']->create_registration(['studentid' => $s['student']->id, 'offerid' => $s['offer']->id]);
        $this->setAdminUser();
        registration::set_grade($regid, 'B', null, 'staff');
        foreach (['teacher', 'moodle'] as $source) {
            $this->assertSame('err_gradekept', $this->error_of(fn() => registration::set_grade($regid, 'A', null, $source)));
        }
        $DB->set_field(registration::REG, 'gradesource', 'teacher', ['id' => $regid]);
        $this->assertSame('err_gradekept', $this->error_of(fn() => registration::set_grade($regid, 'A', null, 'moodle')));
        $this->assertSame('B', $DB->get_field(registration::REG, 'grade', ['id' => $regid]));

        $this->expectException(\coding_exception::class);
        registration::set_grade($regid, 'A', null, 'staff', true);
    }

    /**
     * After the grade freeze nothing but a grade change writes a grade.
     */
    public function test_the_freeze_stops_every_source(): void {
        global $DB;
        $s = $this->setup_section();
        $regid = $s['gen']->create_registration(['studentid' => $s['student']->id, 'offerid' => $s['offer']->id]);
        $DB->set_field('local_studentrecords_term', 'gradefreeze', time() - 3 * DAYSECS, ['id' => $s['term']->id]);
        $this->setAdminUser();
        foreach (['staff', 'teacher', 'moodle'] as $source) {
            $this->assertSame('err_frozen', $this->error_of(fn() => registration::set_grade($regid, 'A', null, $source)));
        }
        registration::set_grade($regid, 'A', null, 'change', true);
        $this->assertSame('A', $DB->get_field(registration::REG, 'grade', ['id' => $regid]));
    }

    /**
     * The teacher posting target for every state Academic Transcript handles, and every state has its message.
     */
    public function test_teacher_post_target_states(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $s = $this->setup_section(['moodlecourseid' => $course->id]);
        $gen = $s['gen'];
        $userid = (int)$s['user']->id;
        $this->setAdminUser();

        $stranger = $this->getDataGenerator()->create_user();
        $this->assertSame('norecord', registration::teacher_post_target($course->id, (int)$stranger->id)['state']);
        $this->assertSame('noreg', registration::teacher_post_target($course->id, $userid)['state']);

        $regid = $gen->create_registration(['studentid' => $s['student']->id, 'offerid' => $s['offer']->id]);
        $target = registration::teacher_post_target($course->id, $userid);
        $this->assertSame(['state' => 'ok', 'regid' => $regid, 'grade' => '', 'freeze' => ''], $target);

        $section = $gen->create_offering(['courseid' => $s['offer']->courseid, 'termid' => $s['term']->id, 'section' => '02',
            'moodlecourseid' => $course->id]);
        $second = $gen->create_registration(['studentid' => $s['student']->id, 'offerid' => $section->id]);
        $this->assertSame('several', registration::teacher_post_target($course->id, $userid)['state']);
        registration::end($second, 'drop');

        $freeze = time() - 3 * DAYSECS;
        $DB->set_field('local_studentrecords_term', 'gradefreeze', $freeze, ['id' => $s['term']->id]);
        $target = registration::teacher_post_target($course->id, $userid);
        $this->assertSame('frozen', $target['state']);
        $this->assertSame(catalog::time_to_date($freeze), $target['freeze']);
        $DB->set_field('local_studentrecords_term', 'gradefreeze', null, ['id' => $s['term']->id]);

        registration::set_grade($regid, 'B', null, 'staff');
        $target = registration::teacher_post_target($course->id, $userid);
        $this->assertSame('kept', $target['state']);
        $this->assertSame('B', $target['grade']);

        foreach (['norecord', 'noreg', 'several', 'frozen', 'kept', 'posted'] as $state) {
            $this->assertTrue(get_string_manager()->string_exists('err_teacherpost_' . $state, 'local_studentrecords'));
        }
    }

    /**
     * A hand-entered mark on the scale is saved, and a posted grade isn't posted over. A course that runs again needs a new
     * registration, and a change to a grade goes through a grade change request.
     */
    public function test_a_teacher_grade_is_not_posted_over(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $s = $this->setup_section(['moodlecourseid' => $course->id]);
        $regid = $s['gen']->create_registration(['studentid' => $s['student']->id, 'offerid' => $s['offer']->id]);
        $userid = (int)$s['user']->id;
        $this->setAdminUser();

        registration::teacher_post($course->id, $userid, null, 'I');

        $this->assertSame('posted', registration::teacher_post_target($course->id, $userid)['state']);
        $this->assertSame(
            'err_teacherpost_posted',
            $this->error_of(fn() => registration::teacher_post($course->id, $userid, 95.0, ''))
        );
        $this->assertSame('I', $DB->get_field(registration::REG, 'grade', ['id' => $regid]));
    }

    /**
     * A teacher's posting completes the course with the letter of the scale, refuses what isn't on the scale, and never
     * touches a grade staff set.
     */
    public function test_teacher_post(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $s = $this->setup_section(['moodlecourseid' => $course->id]);
        $regid = $s['gen']->create_registration(['studentid' => $s['student']->id, 'offerid' => $s['offer']->id]);
        $userid = (int)$s['user']->id;
        $this->setAdminUser();

        $this->assertSame(
            'err_teacherpost_notonscale',
            $this->error_of(fn() => registration::teacher_post($course->id, $userid, null, 'NC'))
        );
        $this->assertSame('A', registration::teacher_post($course->id, $userid, 95.0, ''));
        $reg = $DB->get_record(registration::REG, ['id' => $regid]);
        $this->assertSame('completed', $reg->status);
        $this->assertSame('teacher', $reg->gradesource);

        $DB->set_field(registration::REG, 'gradesource', 'staff', ['id' => $regid]);
        $this->assertSame(
            'err_teacherpost_kept',
            $this->error_of(fn() => registration::teacher_post($course->id, $userid, 70.0, ''))
        );
        $this->assertSame('A', $DB->get_field(registration::REG, 'grade', ['id' => $regid]));
    }

    /**
     * With Academic Transcript installed, the repeat rule is its setting, the latest attempt by default. Without it, every
     * attempt counts.
     */
    public function test_repeat_rule(): void {
        if (\core_component::get_component_directory('local_academictranscript') === null) {
            set_config('repeatrule', 'latest', 'local_academictranscript');
            $this->assertSame('all', catalog::repeat_rule());
            return;
        }
        $this->assertSame('latest', catalog::repeat_rule());
        foreach (['latest', 'highest', 'all'] as $rule) {
            set_config('repeatrule', $rule, 'local_academictranscript');
            $this->assertSame($rule, catalog::repeat_rule());
        }
        set_config('repeatrule', 'something else', 'local_academictranscript');
        $this->assertSame('latest', catalog::repeat_rule());
    }

    /**
     * A student who took a course and took it again a term later.
     *
     * @param string $rule the repeat rule of Academic Transcript
     * @param string $first grade of the first attempt
     * @param string|null $second grade of the retake, null while it is in progress
     * @return array student, history, first and second row
     */
    private function retake(string $rule, string $first, ?string $second): array {
        if (\core_component::get_component_directory('local_academictranscript') === null) {
            $this->markTestSkipped('Academic Transcript is not installed.');
        }
        set_config('repeatrule', $rule, 'local_academictranscript');
        $s = $this->setup_section();
        $later = $s['gen']->create_term(['startdate' => '2030-08-19', 'enddate' => '2030-12-13']);
        $offer = $s['gen']->create_offering(['courseid' => $s['offer']->courseid, 'termid' => $later->id]);
        $this->setAdminUser();
        $one = $s['gen']->create_registration(['studentid' => $s['student']->id, 'offerid' => $s['offer']->id]);
        $two = $s['gen']->create_registration(['studentid' => $s['student']->id, 'offerid' => $offer->id]);
        registration::set_grade($one, $first, null, 'staff');
        if ($second !== null) {
            registration::set_grade($two, $second, null, 'staff');
        }
        $history = registration::history($s['student']);
        return ['student' => $s['student'], 'history' => $history, 'first' => $history['terms'][0]['rows'][0],
            'second' => $history['terms'][1]['rows'][0]];
    }

    /**
     * Under "latest" the retake replaces the first attempt, in the term totals too. The first attempt stays on the record
     * and still counts as attempted.
     */
    public function test_history_counts_the_latest_attempt(): void {
        ['history' => $history, 'first' => $first, 'second' => $second] = $this->retake('latest', 'C', 'A');

        $this->assertTrue($first->repeatexcluded);
        $this->assertFalse($second->repeatexcluded);
        $this->assertSame(6.0, $history['cumulative']['attempted']);
        $this->assertSame(3.0, $history['cumulative']['earned']);
        $this->assertSame(3.0, $history['cumulative']['gpahours']);
        $this->assertSame(4.0, $history['cumulative']['gpa']);
        $this->assertSame(3.0, $history['terms'][0]['totals']['attempted']);
        $this->assertSame(0.0, $history['terms'][0]['totals']['earned']);
        $this->assertNull($history['terms'][0]['totals']['gpa']);
    }

    /**
     * Under "highest" a worse retake changes nothing.
     */
    public function test_history_counts_the_highest_attempt(): void {
        ['history' => $history, 'first' => $first, 'second' => $second] = $this->retake('highest', 'A', 'F');

        $this->assertFalse($first->repeatexcluded);
        $this->assertTrue($second->repeatexcluded);
        $this->assertSame(3.0, $history['cumulative']['earned']);
        $this->assertSame(4.0, $history['cumulative']['gpa']);
    }

    /**
     * Under "all" both attempts earn hours and count in the GPA.
     */
    public function test_history_counts_every_attempt(): void {
        ['history' => $history, 'first' => $first] = $this->retake('all', 'C', 'A');

        $this->assertFalse($first->repeatexcluded);
        $this->assertSame(6.0, $history['cumulative']['earned']);
        $this->assertSame(3.0, $history['cumulative']['gpa']);
    }

    /**
     * A retake in progress leaves the first grade counting until its own grade comes in.
     */
    public function test_a_retake_in_progress_replaces_nothing(): void {
        ['history' => $history, 'first' => $first] = $this->retake('latest', 'C', null);

        $this->assertFalse($first->repeatexcluded);
        $this->assertSame(3.0, $history['cumulative']['earned']);
        $this->assertSame(2.0, $history['cumulative']['gpa']);
    }

    /**
     * A withdrawal from the retake is no result, even when the scale here has a W row with 0 points. The transcript's calculator
     * decides, so the record and the transcript stay together.
     */
    public function test_a_withdrawn_retake_replaces_nothing(): void {
        global $DB;
        ['student' => $student] = $this->retake('latest', 'C', null);
        $DB->insert_record('local_studentrecords_grade', (object)['letter' => 'W', 'points' => 0, 'ingpa' => 0, 'earns' => 0,
            'sortorder' => 90, 'timemodified' => time()]);
        $regid = (int)$DB->get_field(registration::REG, 'id', ['studentid' => $student->id, 'status' => 'registered']);
        registration::end($regid, 'withdraw', true, 'test');

        $history = registration::history($student);

        $this->assertFalse($history['terms'][0]['rows'][0]->repeatexcluded);
        $this->assertSame(3.0, $history['cumulative']['earned']);
        $this->assertSame(2.0, $history['cumulative']['gpa']);
    }

    /**
     * The record shows which attempt doesn't count, to staff and to the student.
     */
    public function test_the_record_marks_the_attempt_that_does_not_count(): void {
        ['student' => $student] = $this->retake('latest', 'C', 'A');
        $marker = get_string('repeatnotcounted', 'local_studentrecords');

        $this->assertSame(1, substr_count(record_view::render($student), $marker));
        $this->assertSame(1, substr_count(student_sections::academic($student, false), $marker));
    }
}
