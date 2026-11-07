<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests for the registration and grade pages: who gets in, and what a direct request can do.
 *
 * A page is run the way Moodle runs it: it reads its request from $_GET or $_POST and its output is collected. A page that
 * redirects, or stops with an error, ends with an exception, as every page does in a PHPUnit test.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @coversNothing
 */
final class registration_pages_test extends \advanced_testcase {
    /** @var \local_studentrecords_generator */
    private $gen;

    /**
     * Start each test with a clean site and a scale.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        shell::reset();
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $this->gen->create_scale();
    }

    /**
     * Forget the request of a test.
     */
    protected function tearDown(): void {
        $_GET = [];
        $_POST = [];
        parent::tearDown();
    }

    /**
     * Run a page and give back its HTML.
     *
     * @param string $file page file in the plugin
     * @return string
     */
    private function run_page(string $file): string {
        global $CFG, $DB, $PAGE, $OUTPUT, $USER, $SITE, $COURSE, $SESSION;
        // The test's own messages used the theme of this page. A real request starts with none.
        $PAGE->reset_theme_and_output();
        ob_start();
        try {
            require(__DIR__ . '/../../' . $file);
        } finally {
            $output = ob_get_clean();
        }
        $this->assertDebuggingNotCalled();
        return $output;
    }

    /**
     * The exception a page stops with.
     *
     * @param string $file page file in the plugin
     * @return \moodle_exception
     */
    private function page_error(string $file): \moodle_exception {
        try {
            $this->run_page($file);
        } catch (\moodle_exception $e) {
            return $e;
        }
        $this->fail('The page did not stop.');
    }

    /**
     * A section with a registered student.
     *
     * @param array $offer more fields of the section
     * @return array student, offer, regid, term
     */
    private function registered(array $offer = []): array {
        $term = $this->gen->create_term();
        $section = $this->gen->create_offering(['courseid' => $this->gen->create_course()->id, 'termid' => $term->id] + $offer);
        $user = $this->getDataGenerator()->create_user();
        $student = $this->gen->create_student(['userid' => $user->id, 'studentid' => 'S-1']);
        $regid = $this->gen->create_registration(['studentid' => $student->id, 'offerid' => $section->id]);
        return ['student' => $student, 'offer' => $section, 'regid' => $regid, 'term' => $term];
    }

    /**
     * Someone who holds these Student Records capabilities at system level.
     *
     * @param string[] $capabilities names after local/studentrecords:
     * @return \stdClass the user
     */
    private function user_with(array $capabilities): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $role = create_role('Staff ' . $user->id, 'staff' . $user->id, '');
        foreach ($capabilities as $capability) {
            assign_capability('local/studentrecords:' . $capability, CAP_ALLOW, $role, SYSCONTEXTID);
        }
        role_assign($role, $user->id, SYSCONTEXTID);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * A student with a grade of B, and a request to change it to A that waits for a decision.
     *
     * @return array regid, student, id (of the request)
     */
    private function waiting_change(): array {
        $r = $this->registered();
        $this->setAdminUser();
        registration::set_grade($r['regid'], 'B', null, 'staff');
        $id = academic::request_change($r['regid'], 'A', null, 'The exam was marked again');
        return ['regid' => $r['regid'], 'student' => $r['student'], 'id' => $id];
    }

    /**
     * An approver who doesn't manage students opens the page and gets what deciding needs: the request and its buttons. The
     * student's name is no link, because the record isn't theirs to open, and past decisions aren't offered.
     */
    public function test_an_approver_opens_the_grade_change_page(): void {
        $c = $this->waiting_change();
        $this->setUser($this->user_with(['approvegradechange']));

        $html = $this->run_page('gradechange.php');

        $this->assertStringContainsString('The exam was marked again', $html);
        $this->assertStringContainsString(s(students::display_name($c['student'])), $html);
        $this->assertStringContainsString('value="approve"', $html);
        $this->assertStringContainsString('value="reject"', $html);
        $this->assertStringNotContainsString('student.php?id=' . $c['student']->id, $html);
        $this->assertStringNotContainsString('state=approved', $html);
        $this->assertStringNotContainsString('state=all', $html);
    }

    /**
     * An approver's decision from the page is applied, and it is theirs.
     */
    public function test_an_approver_decides_from_the_page(): void {
        global $DB;
        $c = $this->waiting_change();
        $approver = $this->user_with(['approvegradechange']);
        $this->setUser($approver);

        $_POST = ['action' => 'approve', 'change' => $c['id'], 'sesskey' => sesskey()];

        $this->assertSame('redirecterrordetected', $this->page_error('gradechange.php')->errorcode);
        $this->assertSame('A', $DB->get_field(registration::REG, 'grade', ['id' => $c['regid']]));
        $change = $DB->get_record(academic::CHANGES, ['id' => $c['id']]);
        $this->assertSame('approved', $change->state);
        $this->assertEquals($approver->id, $change->decidedby);
    }

    /**
     * Past decisions stay with records staff, whatever the address asks for.
     *
     * @dataProvider decided_states_provider
     * @param string $state
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('decided_states_provider')]
    public function test_an_approver_sees_no_past_decisions(string $state): void {
        $c = $this->waiting_change();
        $this->setUser($this->user_with(['approvegradechange']));
        academic::decide_change($c['id'], $state === 'approved', 'Marked once is enough');
        $this->setUser($this->user_with(['approvegradechange']));

        $_GET = ['state' => $state];
        $html = $this->run_page('gradechange.php');

        $this->assertStringNotContainsString('The exam was marked again', $html);
        $this->assertStringNotContainsString('Marked once is enough', $html);
    }

    /**
     * Data for test_an_approver_sees_no_past_decisions.
     *
     * @return array
     */
    public static function decided_states_provider(): array {
        return ['approved' => ['approved'], 'rejected' => ['rejected'], 'all' => ['all']];
    }

    /**
     * The approve capability opens nothing else: no request form, no student record, no records staff home.
     */
    public function test_an_approver_gets_nothing_else(): void {
        $c = $this->waiting_change();
        $this->setUser($this->user_with(['approvegradechange']));

        $_GET = ['reg' => $c['regid']];
        $this->assertInstanceOf(\required_capability_exception::class, $this->page_error('gradechange.php'));

        $_GET = ['id' => $c['student']->id];
        $this->assertInstanceOf(\required_capability_exception::class, $this->page_error('student.php'));

        $_GET = [];
        $this->assertInstanceOf(\required_capability_exception::class, $this->page_error('students.php'));
    }

    /**
     * A preferred name the student keeps out of the directory stays hidden from an approver, and records staff still see it.
     */
    public function test_an_approver_does_not_see_a_hidden_preferred_name(): void {
        $c = $this->waiting_change();
        students::save(['id' => $c['student']->id, 'preferredname' => 'Secretname', 'ferpaoptout' => 1]);

        $this->setUser($this->user_with(['approvegradechange']));
        $this->assertStringNotContainsString('Secretname', $this->run_page('gradechange.php'));

        $this->setUser($this->user_with(['managestudents', 'approvegradechange']));
        $this->assertStringContainsString('Secretname', $this->run_page('gradechange.php'));
    }

    /**
     * An approver can't decide a change they asked for: it isn't on their list, and a direct post is refused.
     */
    public function test_an_approver_does_not_decide_their_own_request(): void {
        global $DB;
        $r = $this->registered();
        $this->setAdminUser();
        registration::set_grade($r['regid'], 'B', null, 'staff');
        $this->setUser($this->user_with(['approvegradechange']));
        $id = academic::request_change($r['regid'], 'A', null, 'The exam was marked again');

        $this->assertStringNotContainsString('The exam was marked again', $this->run_page('gradechange.php'));

        $_POST = ['action' => 'approve', 'change' => $id, 'sesskey' => sesskey()];
        $this->assertSame('redirecterrordetected', $this->page_error('gradechange.php')->errorcode);
        $this->assertSame('pending', $DB->get_field(academic::CHANGES, 'state', ['id' => $id]));
    }

    /**
     * Nobody decides a change to their own grade. An approver doesn't get it on their list, records staff see it with a note
     * and no buttons, and a direct post is refused and changes nothing.
     */
    public function test_nobody_decides_their_own_grade(): void {
        global $DB;
        $c = $this->waiting_change();
        $own = \core_user::get_user((int)$c['student']->userid);
        $role = create_role('Approver', 'approveronly', '');
        assign_capability('local/studentrecords:approvegradechange', CAP_ALLOW, $role, SYSCONTEXTID);
        role_assign($role, $own->id, SYSCONTEXTID);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($own);

        $html = $this->run_page('gradechange.php');

        $this->assertStringNotContainsString('The exam was marked again', $html);
        $this->assertStringNotContainsString('value="approve"', $html);

        assign_capability('local/studentrecords:managestudents', CAP_ALLOW, $role, SYSCONTEXTID);
        accesslib_clear_all_caches_for_unit_testing();

        $html = $this->run_page('gradechange.php');

        $this->assertStringContainsString('The exam was marked again', $html);
        $this->assertStringContainsString(get_string('changeownrecord', 'local_studentrecords'), $html);
        $this->assertStringNotContainsString('value="approve"', $html);

        $_POST = ['action' => 'approve', 'change' => $c['id'], 'sesskey' => sesskey()];
        $this->assertSame('redirecterrordetected', $this->page_error('gradechange.php')->errorcode);
        $this->assertSame('B', $DB->get_field(registration::REG, 'grade', ['id' => $c['regid']]));
    }

    /**
     * Records staff keep the whole page: every tab, the link to the record, and the note when someone else must decide.
     */
    public function test_records_staff_keep_the_whole_grade_change_page(): void {
        $c = $this->waiting_change();
        $this->setUser($this->user_with(['managestudents']));

        $html = $this->run_page('gradechange.php');

        $this->assertStringContainsString('state=approved', $html);
        $this->assertStringContainsString('state=all', $html);
        $this->assertStringContainsString('student.php?id=' . $c['student']->id, $html);
        $this->assertStringContainsString(get_string('changeneedsapprover', 'local_studentrecords'), $html);
        $this->assertStringNotContainsString('value="approve"', $html);
    }

    /**
     * Each request has its facts in a row, each with its label for phones. The reason and then the decision take the whole
     * width under them, so the request is read first and the buttons need no sideways scrolling.
     */
    public function test_the_decision_comes_after_the_request_at_full_width(): void {
        $this->waiting_change();
        $this->setUser($this->user_with(['approvegradechange']));

        $html = $this->run_page('gradechange.php');

        $this->assertSame(8, substr_count($html, 'data-label='));
        $this->assertMatchesRegularExpression('~<td[^>]*colspan="8".*The exam was marked again.*value="approve"~s', $html);
    }

    /**
     * Without manage students or the approve capability the page stays closed.
     */
    public function test_the_grade_change_page_stays_closed_to_others(): void {
        $this->waiting_change();
        $this->setUser($this->user_with(['viewstudents']));

        $this->assertInstanceOf(\required_capability_exception::class, $this->page_error('gradechange.php'));
    }

    /**
     * Before the freeze the grade page opens for a graded course, so staff can correct the grade on record.
     */
    public function test_register_page_opens_for_a_graded_course(): void {
        $r = $this->registered();
        $this->setAdminUser();
        registration::set_grade($r['regid'], 'B', null, 'staff');

        $_GET = ['id' => $r['student']->id, 'action' => 'grade', 'reg' => $r['regid']];

        $this->assertStringContainsString('name="grade"', $this->run_page('register.php'));
    }

    /**
     * A course with no grade yet shows the form, with course names that contain an ampersand escaped once.
     */
    public function test_register_page_shows_the_grade_form(): void {
        global $DB;
        $r = $this->registered();
        $DB->set_field('local_studentrecords_course', 'title', 'Health & Safety', ['id' => $r['offer']->courseid]);
        $this->setAdminUser();
        $_GET = ['id' => $r['student']->id, 'action' => 'grade', 'reg' => $r['regid']];

        $html = $this->run_page('register.php');

        $this->assertStringContainsString('Health &amp; Safety', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * A registration of another student can't be reached through this student's page.
     */
    public function test_register_page_checks_the_registration_belongs_to_the_student(): void {
        $r = $this->registered();
        $other = $this->gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-2']);
        $this->setAdminUser();
        $_GET = ['id' => $other->id, 'action' => 'drop', 'reg' => $r['regid']];

        $this->assertInstanceOf(\dml_missing_record_exception::class, $this->page_error('register.php'));
    }

    /**
     * Only records staff open the registration page.
     */
    public function test_register_page_needs_managestudents(): void {
        $r = $this->registered();
        $this->setUser((int)$r['student']->userid);
        $_GET = ['id' => $r['student']->id, 'action' => 'add'];

        $this->assertInstanceOf(\required_capability_exception::class, $this->page_error('register.php'));
    }

    /**
     * A decision on a grade change needs a good sesskey: the page says so and changes nothing, where it used to carry on
     * and show the list.
     */
    public function test_grade_change_page_refuses_a_wrong_sesskey(): void {
        global $DB;
        $r = $this->registered();
        $this->setAdminUser();
        registration::set_grade($r['regid'], 'B', null, 'staff');
        $other = $this->getDataGenerator()->create_user();
        role_assign((int)$DB->get_field('role', 'id', ['shortname' => 'manager']), $other->id, \context_system::instance()->id);
        $id = academic::request_change($r['regid'], 'A', null, 'Reason');
        $this->setUser($other);

        $_POST = ['action' => 'approve', 'change' => $id, 'sesskey' => 'not-the-sesskey'];

        $this->assertSame('invalidsesskey', $this->page_error('gradechange.php')->errorcode);
        $this->assertSame('pending', $DB->get_field(academic::CHANGES, 'state', ['id' => $id]));
    }

    /**
     * The person who asked for a change sees no buttons, and a direct post is refused and changes nothing.
     */
    public function test_grade_change_page_refuses_the_person_who_asked(): void {
        global $DB;
        $r = $this->registered();
        $this->setAdminUser();
        registration::set_grade($r['regid'], 'B', null, 'staff');
        $id = academic::request_change($r['regid'], 'A', null, 'Reason');

        $_POST = ['action' => 'approve', 'change' => $id, 'sesskey' => sesskey()];

        $this->assertSame('redirecterrordetected', $this->page_error('gradechange.php')->errorcode);
        $this->assertSame('B', $DB->get_field(registration::REG, 'grade', ['id' => $r['regid']]));
        $this->assertSame('pending', $DB->get_field(academic::CHANGES, 'state', ['id' => $id]));
    }

    /**
     * Self-registration actions need a good sesskey.
     */
    public function test_my_registration_refuses_a_wrong_sesskey(): void {
        global $DB;
        $this->gen->set_settings(['selfreg' => 1]);
        $term = $this->gen->create_term();
        $offer = $this->gen->create_offering(['courseid' => $this->gen->create_course()->id, 'termid' => $term->id]);
        $student = $this->gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-1']);
        $this->setUser((int)$student->userid);

        $_POST = ['action' => 'add', 'offer' => $offer->id, 'termid' => $term->id, 'sesskey' => 'not-the-sesskey'];

        $this->assertSame('invalidsesskey', $this->page_error('myregistration.php')->errorcode);
        $this->assertSame(0, $DB->count_records(selfreg::CART));
    }

    /**
     * Attendance looks nothing up for somebody who isn't signed in.
     */
    public function test_attendance_needs_a_login_first(): void {
        $this->setUser(null);
        $_GET = ['offer' => 999999];

        $this->assertSame('redirecterrordetected', $this->page_error('attendance.php')->errorcode);
    }

    /**
     * A section of another teacher's course, for a teacher who is only a student there, signed in as that teacher.
     *
     * @return \stdClass the section
     */
    private function other_teachers_section(): \stdClass {
        $mine = $this->getDataGenerator()->create_course();
        $theirs = $this->getDataGenerator()->create_course();
        $r = $this->registered(['moodlecourseid' => $mine->id]);
        $other = $this->gen->create_offering(['courseid' => $r['offer']->courseid, 'termid' => $r['term']->id, 'section' => '02',
            'moodlecourseid' => $theirs->id]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $mine->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $theirs->id, 'student');
        $this->setUser($teacher);
        return $other;
    }

    /**
     * A teacher doesn't open the attendance of another teacher's section.
     */
    public function test_attendance_page_refuses_another_teachers_section(): void {
        $other = $this->other_teachers_section();

        $_GET = ['offer' => $other->id];

        $this->assertInstanceOf(\required_capability_exception::class, $this->page_error('attendance.php'));
    }

    /**
     * A teacher doesn't reach a meeting of another teacher's section by its number either.
     */
    public function test_attendance_page_refuses_another_teachers_meeting(): void {
        $other = $this->other_teachers_section();
        $today = catalog::time_to_date(time());

        $_GET = ['meeting' => $this->gen->create_meeting(['offerid' => $other->id, 'date' => $today, 'hours' => 3])];

        $this->assertInstanceOf(\required_capability_exception::class, $this->page_error('attendance.php'));
    }

    /**
     * Saving marks needs a good sesskey.
     */
    public function test_attendance_page_refuses_a_wrong_sesskey(): void {
        global $DB;
        $r = $this->registered();
        $today = catalog::time_to_date(time());
        $meetingid = $this->gen->create_meeting(['offerid' => $r['offer']->id, 'date' => $today, 'hours' => 3]);
        $this->setAdminUser();

        $_POST = ['meeting' => $meetingid, 'savemarks' => 1, 'status_' . $r['regid'] => 'present', 'sesskey' => 'not-the-sesskey'];

        $this->assertSame('invalidsesskey', $this->page_error('attendance.php')->errorcode);
        $this->assertSame(0, $DB->count_records(attendance::MARKS));
    }

    /**
     * Pulling grades from the gradebook writes grades, so the setup capability alone isn't enough.
     */
    public function test_offerings_page_needs_managestudents_to_pull_grades(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $r = $this->registered(['moodlecourseid' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $role = create_role('Setup', 'setuponly', '');
        assign_capability('local/studentrecords:configure', CAP_ALLOW, $role, \context_system::instance()->id);
        role_assign($role, $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $_POST = ['id' => $r['offer']->id, 'action' => 'moodlegrades', 'sesskey' => sesskey()];
        $this->assertInstanceOf(\required_capability_exception::class, $this->page_error('offerings.php'));
        $this->assertNull($DB->get_field(registration::REG, 'grade', ['id' => $r['regid']]));
    }

    /**
     * A student opens their own degree audit.
     */
    public function test_degree_audit_opens_for_the_student(): void {
        $user = $this->getDataGenerator()->create_user();
        $program = $this->gen->create_program();
        $mine = $this->gen->create_student(['userid' => $user->id, 'studentid' => 'S-1', 'programid' => $program->id]);
        $this->setUser($user);

        $_GET = ['id' => $mine->id];

        $this->assertStringContainsString('S-1', $this->run_page('degreeaudit.php'));
    }

    /**
     * A student doesn't open another student's degree audit by changing the number.
     */
    public function test_degree_audit_refuses_another_student(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->gen->create_student(['userid' => $user->id, 'studentid' => 'S-1']);
        $other = $this->gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-2']);
        $this->setUser($user);

        $_GET = ['id' => $other->id];

        $this->assertInstanceOf(\required_capability_exception::class, $this->page_error('degreeaudit.php'));
    }

    /**
     * Staff who see students but not billing don't get the student's balance from the degree audit.
     */
    public function test_degree_audit_hides_the_balance_from_staff_without_billing(): void {
        $this->gen->set_settings(['billing' => 1]);
        $student = $this->gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-1',
            'programid' => $this->gen->create_program()->id]);
        $this->setAdminUser();
        billing::add_line(['studentid' => $student->id, 'txntype' => 'charge', 'amount' => 123.45, 'description' => 'Fee']);
        $user = $this->getDataGenerator()->create_user();
        $role = create_role('Adviser', 'adviseronly', '');
        assign_capability('local/studentrecords:viewstudents', CAP_ALLOW, $role, \context_system::instance()->id);
        role_assign($role, $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $_GET = ['id' => $student->id];
        $html = $this->run_page('degreeaudit.php');

        $this->assertStringNotContainsString('123.45', $html);
    }

    /**
     * A student of a program with no course list, who passed one class.
     *
     * @param array $program more fields of the program
     * @return \stdClass the student
     */
    private function student_with_no_course_list(array $program = []): \stdClass {
        global $DB;
        $r = $this->registered();
        $this->setAdminUser();
        registration::set_grade($r['regid'], 'A', null, 'staff');
        $programid = (int)$this->gen->create_program()->id;
        $DB->update_record('local_studentrecords_program', (object)($program + ['id' => $programid, 'totalhours' => 12]));
        $DB->set_field(students::TABLE, 'programid', $programid, ['id' => $r['student']->id]);
        return $r['student'];
    }

    /**
     * With no course list the audit says so and shows the hours as a requirement, and no list of courses is promised.
     */
    public function test_degree_audit_of_a_program_with_no_course_list(): void {
        $student = $this->student_with_no_course_list();

        $_GET = ['id' => $student->id];
        $html = $this->run_page('degreeaudit.php');

        $p = 'local_studentrecords';
        $this->assertStringContainsString(get_string('da_nocourselist', $p, 'credit hours'), $html);
        $this->assertStringContainsString(get_string('audit_hoursearned', $p), $html);
        $this->assertStringContainsString('3 of 12 Credit hours', $html);
        $this->assertStringContainsString(get_string('da_totals', $p), $html);
        $this->assertStringNotContainsString(get_string('da_coursebycourse', $p), $html);
        $this->assertStringNotContainsString(get_string('audit_track', $p), $html);
    }

    /**
     * With no course list and no total hours there is nothing to measure progress against, so no progress bar shows. The
     * minimum GPA is still something to audit.
     *
     * @dataProvider no_total_hours_provider
     * @param float|null $totalhours
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('no_total_hours_provider')]
    public function test_degree_audit_with_no_course_list_and_no_total_hours(?float $totalhours): void {
        $student = $this->student_with_no_course_list(['totalhours' => $totalhours, 'mingpa' => 2]);

        $_GET = ['id' => $student->id];
        $html = $this->run_page('degreeaudit.php');

        $this->assertStringContainsString('3 of - Credit hours', $html);
        $this->assertStringNotContainsString('role="progressbar"', $html);
    }

    /**
     * Data for tests of a program with no total hours.
     *
     * @return array
     */
    public static function no_total_hours_provider(): array {
        return ['not set' => [null], 'zero' => [0.0]];
    }

    /**
     * With nothing to audit the page says so in plain words and says what is needed. Nothing reads as met.
     *
     * @dataProvider no_total_hours_provider
     * @param float|null $totalhours
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('no_total_hours_provider')]
    public function test_degree_audit_of_a_program_with_nothing_to_audit(?float $totalhours): void {
        $student = $this->student_with_no_course_list(['totalhours' => $totalhours]);

        $_GET = ['id' => $student->id];
        $html = $this->run_page('degreeaudit.php');

        $p = 'local_studentrecords';
        $this->assertStringContainsString(get_string('audit_incomplete', $p), $html);
        $this->assertStringContainsString(get_string('da_nothingtocheck', $p), $html);
        $this->assertStringContainsString(get_string('da_notauditable', $p), $html);
        $this->assertStringContainsString(get_string('audit_nothingtocheck', $p), $html);
        $this->assertStringNotContainsString(get_string('audit_complete', $p), $html);
        $this->assertStringNotContainsString(get_string('da_readyfinal', $p), $html);
        $row = $this->first_requirement($html);
        $this->assertStringContainsString('3 Credit hours', $row);
        $this->assertStringContainsString(get_string('notset', $p), $row);
        $this->assertStringNotContainsString(get_string('da_met', $p), $row);
    }

    /**
     * The first row of the requirements of a degree audit.
     *
     * @param string $html
     * @return string
     */
    private function first_requirement(string $html): string {
        $this->assertSame(1, preg_match('~<ul class="sr-reqs"><li>(.*?)</li>~s', $html, $m));
        return $m[1];
    }

    /**
     * The hours wait on grades when the classes in progress would finish them. When they wouldn't, the hours are not met.
     */
    public function test_degree_audit_hours_wait_on_the_classes_in_progress(): void {
        global $DB;
        $student = $this->student_with_no_course_list(['totalhours' => 6]);
        $offer = $this->gen->create_offering(['courseid' => $this->gen->create_course()->id,
            'termid' => $this->gen->create_term()->id]);
        $this->gen->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);

        $_GET = ['id' => $student->id];
        $row = $this->first_requirement($this->run_page('degreeaudit.php'));

        $p = 'local_studentrecords';
        $this->assertStringContainsString('3 of 6 Credit hours', $row);
        $this->assertStringContainsString(get_string('da_pending', $p), $row);

        $DB->set_field('local_studentrecords_program', 'totalhours', 9, ['id' => students::get((int)$student->id)->programid]);
        $row = $this->first_requirement($this->run_page('degreeaudit.php'));

        $this->assertStringContainsString(get_string('da_notmet', $p), $row);
        $this->assertStringNotContainsString(get_string('da_pending', $p), $row);
    }

    /**
     * The note of a program with no course list names the program's own unit.
     */
    public function test_degree_audit_note_names_the_unit_of_the_program(): void {
        global $DB;
        $student = $this->student_with_no_course_list();
        $_GET = ['id' => $student->id];
        $this->assertStringContainsString('all the credit hours the student earned', $this->run_page('degreeaudit.php'));

        $DB->set_field(
            'local_studentrecords_program',
            'unittype',
            'clock',
            ['id' => students::get((int)$student->id)->programid]
        );
        $html = $this->run_page('degreeaudit.php');

        $this->assertStringContainsString('all the clock hours the student earned', $html);
        $this->assertStringNotContainsString('credit the student earned', $html);
    }

    /**
     * The program name shows as it is: escaped once, with its code.
     */
    public function test_degree_audit_escapes_the_program_once(): void {
        global $DB;
        $student = $this->student_with_no_course_list();
        $DB->set_field(
            'local_studentrecords_program',
            'name',
            'Arts & Sciences',
            ['id' => students::get((int)$student->id)->programid]
        );

        $_GET = ['id' => $student->id];
        $html = $this->run_page('degreeaudit.php');

        $this->assertStringContainsString('Arts &amp; Sciences', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * A student who is a member of staff too, signed in, with a final grade of C and an application to graduate.
     *
     * @return array regid, student, app (id of the application), role
     */
    private function staff_member_with_a_grade(): array {
        global $DB;
        $r = $this->registered();
        $this->setAdminUser();
        registration::set_grade($r['regid'], 'C', null, 'staff');
        $DB->set_field(students::TABLE, 'programid', $this->gen->create_program()->id, ['id' => $r['student']->id]);
        $student = students::get((int)$r['student']->id);
        $app = graduation::apply($student, 0);
        $role = create_role('Staff', 'staffrole', '');
        foreach (['managestudents', 'viewstudents'] as $capability) {
            assign_capability('local/studentrecords:' . $capability, CAP_ALLOW, $role, SYSCONTEXTID);
        }
        role_assign($role, (int)$student->userid, SYSCONTEXTID);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser((int)$student->userid);
        return ['regid' => $r['regid'], 'student' => $student, 'app' => $app, 'offercourse' => $r['offer']->courseid];
    }

    /**
     * Transfer credit and an admission decision on a person's own record are refused before a form is offered, and the
     * record stays as it was. The prior education on the same page, which decides nothing, still opens.
     */
    public function test_your_own_record_takes_no_transfer_credit_or_decision(): void {
        global $DB;
        $s = $this->staff_member_with_a_grade();

        foreach (['xfer', 'decision'] as $part) {
            $_GET = ['id' => $s['student']->id, 'part' => $part];
            $this->assertSame('err_ownrecord', $this->page_error('studentpart.php')->errorcode, $part);
        }

        $_GET = ['id' => $s['student']->id, 'part' => 'educ'];
        $this->assertStringContainsString('name="school"', $this->run_page('studentpart.php'));
        $this->assertSame(0, $DB->count_records('local_studentrecords_xfer'));
    }

    /**
     * A person's own record offers no transfer credit or decision. Another person's page does.
     */
    public function test_your_own_record_offers_no_transfer_credit_or_decision(): void {
        $s = $this->staff_member_with_a_grade();
        $history = registration::history($s['student']);

        $this->assertStringNotContainsString('part=xfer', student_sections::transfers($s['student'], true, $history));
        $this->assertStringNotContainsString('part=decision', student_sections::decisions($s['student'], true));

        $this->setUser($this->user_with(['managestudents']));

        $this->assertStringContainsString('part=xfer', student_sections::transfers($s['student'], true, $history));
        $this->assertStringContainsString('part=decision', student_sections::decisions($s['student'], true));
    }

    /**
     * The edit form of a person's own record shows the status, program and award without letting them change, and a forged
     * post changes none of them. The contact details still save.
     */
    public function test_the_edit_form_of_your_own_record(): void {
        global $DB;
        $s = $this->staff_member_with_a_grade();
        $DB->update_record(students::TABLE, (object)['id' => $s['student']->id, 'awardname' => 'Certificate',
            'awarddate' => 1893456000]);
        $_GET = ['id' => $s['student']->id, 'edit' => 1];

        $html = $this->run_page('student.php');

        $this->assertStringContainsString(get_string('ownrecord_note', 'local_studentrecords'), $html);
        $this->assertDoesNotMatchRegularExpression('~<select[^>]*name="(status|programid)"~', $html);
        $this->assertMatchesRegularExpression('~<input[^>]*name="awardname"[^>]*disabled~', $html);
        $this->assertMatchesRegularExpression('~<input[^>]*name="statusnote"[^>]*disabled~', $html);
        $this->assertDoesNotMatchRegularExpression('~<input[^>]*name="phone"[^>]*disabled~', $html);

        \local_studentrecords\form\student::mock_submit(['id' => $s['student']->id, 'studentid' => $s['student']->studentid,
            'legalfirstname' => $s['student']->legalfirstname, 'legallastname' => $s['student']->legallastname,
            'phone' => '555-0100', 'status' => 'graduated', 'awardname' => 'Doctor of Everything', 'programid' => 0,
            'homecountry' => '', 'loadtype' => '']);
        $_POST += ['edit' => 1];
        $this->assertSame('redirecterrordetected', $this->page_error('student.php')->errorcode);

        $saved = students::get((int)$s['student']->id);
        $this->assertSame('555-0100', $saved->phone);
        $this->assertSame('active', $saved->status);
        $this->assertSame('Certificate', $saved->awardname);
        $this->assertEquals(1893456000, $saved->awarddate);
        $this->assertEquals($s['student']->programid, $saved->programid);
        $this->assertSame(0, $DB->count_records_select(
            'local_studentrecords_log',
            "action = 'update' AND info = ?",
            ['status graduated']
        ));
    }

    /**
     * The form to add a student says so when a person picks themselves.
     */
    public function test_the_add_form_refuses_yourself(): void {
        $this->setUser($this->user_with(['managestudents', 'viewstudents']));
        global $USER;

        $errors = (new \local_studentrecords\form\student(null, ['id' => 0]))->validation(['id' => 0, 'userid' => $USER->id,
            'studentid' => 'S-9', 'status' => 'active'], []);

        $this->assertSame(get_string('err_ownrecord', 'local_studentrecords'), $errors['userid']);
    }

    /**
     * The degree audit offers no substitution on a person's own record, and a direct post is refused and adds nothing.
     */
    public function test_the_degree_audit_offers_no_substitution_on_your_own_record(): void {
        global $DB;
        $s = $this->staff_member_with_a_grade();
        $required = (int)$this->gen->create_course(['code' => 'REQ 100'])->id;
        $DB->insert_record('local_studentrecords_progcrs', (object)['programid' => $s['student']->programid,
            'courseid' => $required, 'groupname' => 'Core', 'requirement' => 'required', 'sortorder' => 1]);

        $_GET = ['id' => $s['student']->id];
        $this->assertStringNotContainsString('name="subst"', $this->run_page('degreeaudit.php'));

        $this->setUser($this->user_with(['managestudents', 'viewstudents']));
        $this->assertStringContainsString('name="subst"', $this->run_page('degreeaudit.php'));

        $this->setUser((int)$s['student']->userid);
        $_POST = ['subst' => 1, 'reqcourse' => $required, 'subcourse' => (int)$s['offercourse'], 'note' => 'Close enough',
            'sesskey' => sesskey()];
        $this->assertSame('redirecterrordetected', $this->page_error('degreeaudit.php')->errorcode);

        $this->assertSame(0, $DB->count_records('local_studentrecords_subst'));
    }

    /**
     * The admissions page offers no decision on a person's own application, and a direct post is refused.
     */
    public function test_the_admissions_page_offers_no_decision_on_your_own_application(): void {
        global $DB;
        $s = $this->staff_member_with_a_grade();
        $DB->set_field(students::TABLE, 'status', 'applicant', ['id' => $s['student']->id]);

        $_GET = ['report' => 'applicants', 'show' => 'all', 'a' => $s['student']->id];
        $html = $this->run_page('admissions.php');

        $this->assertStringContainsString(get_string('err_ownrecord', 'local_studentrecords'), $html);
        $this->assertStringNotContainsString('name="decide"', $html);

        $_GET += ['decide' => 'admitted', 'sesskey' => sesskey()];
        $this->assertSame('err_ownrecord', $this->page_error('admissions.php')->errorcode);
        $this->assertSame(0, $DB->count_records('local_studentrecords_admdec'));
    }

    /**
     * The grade page refuses a person's own grade before it offers a form, and says why.
     */
    public function test_the_grade_page_refuses_your_own_grade(): void {
        global $DB;
        $s = $this->staff_member_with_a_grade();

        $_GET = ['id' => $s['student']->id, 'action' => 'grade', 'reg' => $s['regid']];

        $this->assertSame('err_gradeown', $this->page_error('register.php')->errorcode);
        $this->assertSame('C', $DB->get_field(registration::REG, 'grade', ['id' => $s['regid']]));
    }

    /**
     * A person's own record offers a grade change request and no way to enter the grade. Another record offers both.
     */
    public function test_your_own_record_offers_no_grade_entry(): void {
        $s = $this->staff_member_with_a_grade();

        $html = student_sections::academic($s['student'], true);

        $this->assertStringNotContainsString('action=grade', $html);
        $this->assertStringContainsString('gradechange.php?reg=' . $s['regid'], $html);

        $this->setUser($this->user_with(['managestudents']));
        $this->assertStringContainsString('action=grade', student_sections::academic($s['student'], true));
    }

    /**
     * The graduation page offers no review or conferral of a person's own application, says who decides, and refuses a direct
     * address. Cancelling stays.
     */
    public function test_the_graduation_page_offers_no_decision_on_your_own_application(): void {
        $s = $this->staff_member_with_a_grade();
        $note = get_string('gradownapp', 'local_studentrecords');

        $html = $this->run_page('graduation.php');

        $this->assertStringNotContainsString('action=review', $html);
        $this->assertStringContainsString('action=cancel', $html);
        $this->assertStringContainsString($note, $html);

        $_GET = ['app' => $s['app'], 'action' => 'review'];
        $this->assertSame('err_gradownapp', $this->page_error('graduation.php')->errorcode);

        $this->setAdminUser();
        graduation::review($s['app'], true, 'Waived');
        $this->setUser((int)$s['student']->userid);

        $_GET = ['state' => 'approved'];
        $html = $this->run_page('graduation.php');

        $this->assertStringNotContainsString('action=confer', $html);
        $this->assertStringContainsString($note, $html);

        $_GET = ['app' => $s['app'], 'action' => 'confer'];
        $this->assertSame('err_gradownapp', $this->page_error('graduation.php')->errorcode);
    }

    /**
     * The degree audit offers no approval of a person's own application, and another person's page still does.
     */
    public function test_the_degree_audit_offers_no_approval_of_your_own_application(): void {
        $s = $this->staff_member_with_a_grade();

        $_GET = ['id' => $s['student']->id];
        $html = $this->run_page('degreeaudit.php');

        $this->assertStringNotContainsString('name="gradreview"', $html);
        $this->assertStringContainsString(get_string('gradownapp', 'local_studentrecords'), $html);

        $this->setUser($this->user_with(['managestudents', 'viewstudents']));
        $this->assertStringContainsString('name="gradreview"', $this->run_page('degreeaudit.php'));
    }

    /**
     * A program with a course list keeps its course by course tables and shows no note about a missing list.
     */
    public function test_degree_audit_of_a_program_with_a_course_list(): void {
        global $DB;
        $student = $this->student_with_no_course_list();
        $DB->insert_record('local_studentrecords_progcrs', (object)['programid' => students::get((int)$student->id)->programid,
            'courseid' => $this->gen->create_course()->id, 'groupname' => 'Core', 'requirement' => 'required', 'sortorder' => 1]);

        $_GET = ['id' => $student->id];
        $html = $this->run_page('degreeaudit.php');

        $p = 'local_studentrecords';
        $this->assertStringNotContainsString(get_string('da_nocourselist', $p, 'credit hours'), $html);
        $this->assertStringContainsString(get_string('da_coursebycourse', $p), $html);
        $this->assertStringContainsString(get_string('audit_track', $p), $html);
        $this->assertStringContainsString('role="progressbar"', $html);
    }

    /**
     * Running and confirming the standing needs a good sesskey.
     */
    public function test_standing_page_refuses_a_wrong_sesskey(): void {
        global $DB;
        $r = $this->registered();
        $this->setAdminUser();

        $_POST = ['termid' => $r['term']->id, 'action' => 'run', 'sesskey' => 'not-the-sesskey'];

        $this->assertSame('invalidsesskey', $this->page_error('standing.php')->errorcode);
        $this->assertSame(0, $DB->count_records(standing::TABLE));
    }

    /**
     * Every list page opens on a site with data on the database under test, says nothing to the debug log, and escapes
     * names with an ampersand once.
     *
     * @dataProvider list_page_provider
     * @param string $file page file in the plugin
     * @param array $get request of the page, with termid set from the term of the test
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('list_page_provider')]
    public function test_list_pages_open(string $file, array $get): void {
        global $DB;
        $program = $this->gen->create_program(['name' => 'Arts & Sciences', 'code' => 'A&S', 'level' => 'Under & Over']);
        $term = $this->gen->create_term(['name' => 'Fall & Winter']);
        $course = $this->gen->create_course(['title' => 'Health & Safety']);
        $offer = $this->gen->create_offering(['courseid' => $course->id, 'termid' => $term->id]);
        $student = $this->gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-1',
            'programid' => $program->id]);
        $regid = $this->gen->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        $levelid = (int)$DB->get_field('local_studentrecords_level', 'id', ['name' => 'Under & Over']);
        $DB->insert_record('local_studentrecords_reqdoc', (object)['name' => 'Transcript', 'levelid' => $levelid,
            'programid' => $program->id, 'timemodified' => time()]);
        $DB->insert_record('local_studentrecords_policy', (object)['rulekey' => 'good_standing_gpa', 'title' => 'Good standing',
            'levelid' => $levelid, 'value' => '2.0', 'sortorder' => 1, 'timemodified' => time()]);
        $DB->insert_record('local_studentrecords_minpass', (object)['levelid' => $levelid, 'minletter' => 'C',
            'timemodified' => time()]);
        $DB->insert_record(standing::RULES, (object)['levelid' => $levelid, 'mingpa' => 2, 'active' => 1,
            'timemodified' => time()]);
        $this->setAdminUser();
        registration::set_grade($regid, 'B', 85.0, 'staff');
        standing::run((int)$term->id);
        $other = $this->getDataGenerator()->create_user();
        role_assign((int)$DB->get_field('role', 'id', ['shortname' => 'manager']), $other->id, \context_system::instance()->id);
        $this->setUser($other);
        academic::request_change($regid, 'A', null, 'Reason');
        $this->setAdminUser();

        $_GET = isset($get['termid']) ? ['termid' => $term->id] : $get;
        $html = $this->run_page($file);

        $this->assertNotEmpty($html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * Data for test_list_pages_open.
     *
     * @return array
     */
    public static function list_page_provider(): array {
        return [
            'terms' => ['terms.php', []],
            'courses' => ['courses.php', []],
            'courses search' => ['courses.php', ['search' => 'Health & Safety']],
            'programs' => ['programs.php', []],
            'roster' => ['roster.php', []],
            'checklist' => ['checklist.php', []],
            'grading' => ['grading.php', []],
            'academic standing rules' => ['saprules.php', []],
            'grade changes' => ['gradechange.php', []],
            'offerings' => ['offerings.php', []],
            'offerings of a term' => ['offerings.php', ['termid' => 1]],
            'academic standing of a term' => ['standing.php', ['termid' => 1]],
        ];
    }

    /**
     * A date picked in a form is a day in the school's time zone, whatever the time zone of the person picking it. A person
     * in Auckland setting a term of a school in Chicago doesn't move it a day back.
     */
    public function test_term_dates_stay_on_the_school_day(): void {
        global $USER;
        $this->gen->set_settings(['timezone' => 'America/Chicago']);
        $this->setAdminUser();
        $USER->timezone = 'Pacific/Auckland';
        \local_studentrecords\form\term::mock_submit(['id' => 0, 'name' => 'Spring', 'active' => 1, 'notes' => '',
            'startdate' => ['day' => 14, 'month' => 1, 'year' => 2030], 'enddate' => ['day' => 3, 'month' => 5, 'year' => 2030]]);

        $data = (new \local_studentrecords\form\term())->get_data();

        $this->assertSame('2030-01-14', catalog::time_to_date((int)$data->startdate));
        $this->assertSame('2030-05-03', catalog::time_to_date((int)$data->enddate));
    }

    /**
     * Term dates show in the school's date format, in the school's time zone.
     */
    public function test_terms_page_shows_dates_in_the_date_format(): void {
        $term = $this->gen->create_term(['startdate' => '2030-01-14']);
        $this->setAdminUser();

        $html = $this->run_page('terms.php');

        $this->assertStringContainsString(catalog::show_date((int)$term->startdate), $html);
        $this->assertStringNotContainsString('2030-01-14', $html);
    }

    /**
     * Names with an ampersand are escaped once on the graduation list.
     */
    public function test_graduation_page_escapes_names_once(): void {
        global $DB;
        $program = $this->gen->create_program(['name' => 'Arts & Sciences', 'code' => 'A&S']);
        $term = $this->gen->create_term(['name' => 'Fall & Winter']);
        $student = $this->gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-1',
            'programid' => $program->id]);
        $this->setAdminUser();
        graduation::apply($student, (int)$term->id);

        $html = $this->run_page('graduation.php');

        $this->assertStringContainsString('A&amp;S - Arts &amp; Sciences', $html);
        $this->assertStringContainsString('Fall &amp; Winter', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }
}
