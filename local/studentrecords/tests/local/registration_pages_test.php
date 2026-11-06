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
