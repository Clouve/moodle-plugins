<?php

namespace local_academictranscript;

use local_academictranscript\local\posting;
use local_academictranscript\local\requests;
use local_academictranscript\local\scale;
use local_academictranscript\local\transcript;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests for the pages that check a request, print a typed value, or print names.
 *
 * A page is run the way Moodle runs it: it reads its request from $_GET or $_POST and its output is collected. A page
 * that redirects, or stops with an error, ends with an exception, as every page does in a PHPUnit test.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @coversNothing
 */
final class pages_test extends \advanced_testcase {
    /**
     * Start each test with a clean site and an empty request cache.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        scale::reset_cache();
    }

    /**
     * Forget the admin tree that the admin pages built for the user of the test. Moodle keeps it in a global that a
     * test reset does not clear.
     */
    protected function tearDown(): void {
        global $ADMIN;
        $ADMIN = null;
        if (class_exists('\\local_studentrecords\\local\\shell')) {
            \local_studentrecords\local\shell::reset();
        }
        parent::tearDown();
    }

    /**
     * The code that somebody typed comes back in the box with the same characters. The page escapes it once for the
     * HTML, not twice.
     */
    public function test_verify_page_shows_the_typed_code_as_it_was_typed(): void {
        $_GET['code'] = 'A&B "C" \'D\'';

        $html = $this->run_page('verify.php');

        $this->assertSame(1, preg_match('/<input type="text" name="code" value="([^"]*)"/', $html, $matches));
        $this->assertSame('A&B "C" \'D\'', html_entity_decode($matches[1], ENT_QUOTES));
    }

    /**
     * A valid code confirms who, what, when and to whom. The page shows no grades, course names or date of birth, so a
     * code alone never gives a stranger the student's record.
     */
    public function test_verify_page_confirms_an_issue_without_grades(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $student = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz']);
        $course = $this->getDataGenerator()->create_course();
        $generator->create_student_record(['userid' => $student->id, 'dob' => make_timestamp(2001, 4, 12)]);
        $generator->create_posted_grade(['userid' => $student->id, 'courseid' => $course->id,
            'termid' => $generator->create_term()->id, 'coursecode' => 'BIO 101', 'coursename' => 'Cell Biology', 'grade' => 'A']);
        $this->setAdminUser();
        $issue = transcript::issue($student->id, 'Hill & Dale College');
        $this->setUser(null);

        $_GET['code'] = transcript::format_code($issue->verifycode);
        $html = $this->run_page('verify.php');

        $this->assertStringContainsString(get_string('verify_valid', 'local_academictranscript'), $html);
        $this->assertStringContainsString('Ana Ruiz', $html);
        $this->assertStringContainsString('Hill &amp; Dale College', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('Cell Biology', $html);
        $this->assertStringNotContainsString('BIO 101', $html);
        $this->assertStringNotContainsString('2001', $html);
    }

    /**
     * A code that matches nothing gets the help text, and a failed verification is logged.
     */
    public function test_verify_page_reports_an_unknown_code(): void {
        global $DB;

        $_GET['code'] = 'AAAA-BBBB-CCCC-DDDD-EEEE-FFFF';
        $html = $this->run_page('verify.php');

        $this->assertStringContainsString(get_string('verify_notfound', 'local_academictranscript'), $html);
        $this->assertTrue($DB->record_exists('local_academictranscript_log', ['action' => 'verify_fail']));
    }

    /**
     * A student's own page is My transcript: the transcript, the request form and the student's requests. Without Student
     * Records the fee note doesn't promise that the fee goes on an account.
     */
    public function test_my_transcript_shows_the_request_form(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $html = $this->run_page('index.php');

        $this->assertStringContainsString(get_string('mytranscript_title', 'local_academictranscript'), $html);
        $this->assertStringContainsString(get_string('req_title', 'local_academictranscript'), $html);
        $this->assertStringContainsString(get_string('req_feenote_nocharge', 'local_academictranscript'), $html);
        $this->assertStringContainsString(get_string('req_none', 'local_academictranscript'), $html);
    }

    /**
     * A request needs a good sesskey, and a student can only make one on the student's own page.
     */
    public function test_request_form_refuses_a_wrong_sesskey(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $_POST = ['action' => 'request', 'delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'now',
            'sesskey' => 'not-the-sesskey'];
        $error = $this->failure(fn() => $this->run_page('index.php'));

        $this->assertSame('invalidsesskey', $error->errorcode);
        $this->assertSame(0, $DB->count_records(requests::TABLE));
    }

    /**
     * A good request is saved and the page goes back to My transcript with a message.
     */
    public function test_request_form_saves_a_request(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $_POST = ['action' => 'request', 'delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'now',
            'sesskey' => sesskey()];
        $this->failure(fn() => $this->run_page('index.php'));

        $this->assertSame(1, $DB->count_records(requests::TABLE, ['userid' => $student->id, 'status' => 'pending']));
    }

    /**
     * A double click on the request form makes one request.
     */
    public function test_request_form_saves_a_double_click_once(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $_POST = ['action' => 'request', 'delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'now',
            'sesskey' => sesskey()];
        $this->failure(fn() => $this->run_page('index.php'));
        $this->failure(fn() => $this->run_page('index.php'));

        $this->assertSame(1, $DB->count_records(requests::TABLE, ['userid' => $student->id]));
    }

    /**
     * The second click of a double click doesn't say a new request went out. It says the request was sent once.
     */
    public function test_request_form_tells_a_double_click_it_was_sent_once(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $_POST = ['action' => 'request', 'delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'now',
            'sesskey' => sesskey()];
        $this->failure(fn() => $this->run_page('index.php'));
        $this->assertSame([], \core\notification::fetch());

        $this->failure(fn() => $this->run_page('index.php'));

        $notices = \core\notification::fetch();
        $this->assertCount(1, $notices);
        $this->assertSame(get_string('req_saved_repeat', 'local_academictranscript'), $notices[0]->get_message());
        $this->assertSame(\core\output\notification::NOTIFY_INFO, $notices[0]->get_message_type());
    }

    /**
     * A student who posts the form with another student's id makes no request for that student.
     */
    public function test_a_student_cannot_request_for_another_student(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $_GET['userid'] = $other->id;
        $_POST = ['action' => 'request', 'delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'now',
            'sesskey' => sesskey()];
        $this->failure(fn() => $this->run_page('index.php'));

        $this->assertSame(0, $DB->count_records(requests::TABLE));
    }

    /**
     * Without Student Records nothing can wait for grades or the degree, so the form doesn't offer it.
     */
    public function test_request_form_offers_no_timing_without_student_records(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $html = $this->run_page('index.php');

        $this->assertStringContainsString('<input type="hidden" name="timing" value="now"', $html);
        $this->assertStringNotContainsString('attime_degree', $html);
    }

    /**
     * Issue on the queue makes the transcript, and the request moves to Recently issued.
     */
    public function test_queue_issues_a_request(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $requestid = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'now']);
        $this->setAdminUser();

        $_POST = ['do' => 'issue', 'request' => $requestid, 'sesskey' => sesskey()];
        $this->failure(fn() => $this->run_page('requests.php'));

        $this->assertSame('issued', $DB->get_field(requests::TABLE, 'status', ['id' => $requestid]));
        $this->assertSame(1, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));
    }

    /**
     * A second click on Issue on the queue makes no second transcript, and Reissue still makes a new copy.
     */
    public function test_queue_issues_once_and_reissue_stays_separate(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $requestid = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'now']);
        $this->setAdminUser();

        $_POST = ['do' => 'issue', 'request' => $requestid, 'sesskey' => sesskey()];
        $this->failure(fn() => $this->run_page('requests.php'));
        $this->failure(fn() => $this->run_page('requests.php'));
        $this->assertSame(1, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));

        $DB->set_field(requests::TABLE, 'timeissued', time() - requests::REPEATWINDOW - 1, ['id' => $requestid]);
        $_POST['do'] = 'reissue';
        $this->failure(fn() => $this->run_page('requests.php'));
        $this->failure(fn() => $this->run_page('requests.php'));
        $this->assertSame(2, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));
    }

    /**
     * Cancel and Issue on the same request: whichever comes second changes nothing.
     *
     * @dataProvider second_action_provider
     * @param string $first The action that gets there first
     * @param string $second The action that comes second
     * @param string $status The status of the request afterwards
     * @param int $issues How many transcripts there are afterwards
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('second_action_provider')]
    public function test_queue_changes_nothing_on_the_second_of_cancel_and_issue(
        string $first,
        string $second,
        string $status,
        int $issues
    ): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $requestid = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'now']);
        $this->setAdminUser();

        foreach ([$first, $second] as $do) {
            $_POST = ['do' => $do, 'request' => $requestid, 'sesskey' => sesskey()];
            $this->failure(fn() => $this->run_page('requests.php'));
        }

        $this->assertSame($status, $DB->get_field(requests::TABLE, 'status', ['id' => $requestid]));
        $this->assertSame($issues, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));
    }

    /**
     * Data for test_queue_changes_nothing_on_the_second_of_cancel_and_issue.
     *
     * @return array
     */
    public static function second_action_provider(): array {
        return [
            'issue first' => ['issue', 'cancel', 'issued', 1],
            'cancel first' => ['cancel', 'issue', 'cancelled', 0],
        ];
    }

    /**
     * A double click on the issue form of a student's page makes one Official transcript, and another recipient makes
     * another.
     */
    public function test_issue_form_makes_one_copy_for_a_double_click(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->make_manager();

        $_GET['userid'] = $student->id;
        $_POST = ['action' => 'issue', 'recipient' => 'Valley College', 'sesskey' => sesskey()];
        $this->failure(fn() => $this->run_page('index.php'));
        $this->assertSame([], \core\notification::fetch());
        $this->failure(fn() => $this->run_page('index.php'));
        $this->assertSame(1, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));
        $notices = \core\notification::fetch();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('so no new copy was made', $notices[0]->get_message());
        $this->assertSame(\core\output\notification::NOTIFY_INFO, $notices[0]->get_message_type());

        $_POST['recipient'] = 'Hill College';
        $this->failure(fn() => $this->run_page('index.php'));
        $this->assertSame(2, $DB->count_records('local_academictranscript_issue', ['userid' => $student->id]));
    }

    /**
     * My Transcript stays the marked item of the Student Records menu on My transcript, for a student and for staff with a
     * student file, and it isn't marked on the staff view.
     *
     * @dataProvider menu_mark_provider
     * @param bool $staff Whether the user is staff
     * @param array $get What the address says
     * @param bool $marked Whether My Transcript is marked
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('menu_mark_provider')]
    public function test_my_transcript_is_marked_in_the_menu(bool $staff, array $get, bool $marked): void {
        if (!class_exists('\\local_studentrecords\\local\\shell')) {
            $this->markTestSkipped('Student Records is not installed.');
        }
        $user = $staff ? $this->make_manager() : $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        $this->getDataGenerator()->get_plugin_generator('local_studentrecords')->create_student(['userid' => $user->id,
            'studentid' => 'S-1']);
        $this->setUser($user);
        \local_studentrecords\local\shell::reset();
        $_GET = isset($get['self']) ? ['userid' => $user->id] : $get;

        $html = $this->run_page('index.php');

        $label = preg_quote(get_string('nav_mytranscript', 'local_studentrecords'), '/');
        $this->assertSame($marked ? 1 : 0, preg_match('/aria-current="page"[^>]*>' . $label . '<\/a>/', $html));
    }

    /**
     * Data for test_my_transcript_is_marked_in_the_menu.
     *
     * @return array
     */
    public static function menu_mark_provider(): array {
        return [
            'a student from the menu' => [false, ['view' => 'mine'], true],
            'a student from the profile' => [false, ['self' => 1], true],
            'a student with nothing in the address' => [false, [], true],
            'staff from the menu' => [true, ['view' => 'mine'], true],
        ];
    }

    /**
     * Staff who open a student's page never make a request for that student by posting the form.
     */
    public function test_staff_cannot_request_for_a_student(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        set_config('setupcomplete', 1, 'local_academictranscript');

        $_GET['userid'] = $student->id;
        $_POST = ['action' => 'request', 'delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'now',
            'sesskey' => sesskey()];
        $this->run_page('index.php');

        $this->assertSame(0, $DB->count_records(requests::TABLE));
    }

    /**
     * With Student Records as the source, the scale pages say which students they cover, and without it they don't.
     */
    public function test_the_scale_pages_say_which_students_they_cover(): void {
        set_config('setupcomplete', 1, 'local_academictranscript');
        $this->setAdminUser();
        $notice = get_string('scale_usesstudentrecords', 'local_academictranscript');
        $this->assertStringNotContainsString($notice, $this->run_page('grading.php'));
        if (!class_exists('\\local_studentrecords\\local\\registration')) {
            $this->markTestSkipped('Student Records is not installed.');
        }
        set_config('source', 'studentrecords', 'local_academictranscript');

        $this->assertStringContainsString($notice, $this->run_page('grading.php'));
        $this->assertStringContainsString($notice, $this->run_page('marks.php'));
    }

    /**
     * A staff member's own page has the same tools as any student's page, however it is opened: Edit student details, Transfer
     * credit, Course terms and the issue form. The tabs say this is the staff view, and My transcript is beside it.
     *
     * @dataProvider own_address_provider
     * @param bool $named Whether the address names the user
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('own_address_provider')]
    public function test_staff_keep_their_tools_on_their_own_page(bool $named): void {
        $manager = $this->make_manager();
        if ($named) {
            $_GET['userid'] = $manager->id;
        }

        $html = $this->run_page('index.php');

        foreach (['editdetails', 'transfercredits', 'courseterms', 'issueofficial', 'view_staff'] as $key) {
            $this->assertStringContainsString(get_string($key, 'local_academictranscript'), $html);
        }
        $this->assertStringContainsString(get_string('mytranscript_title', 'local_academictranscript'), $html);
        $this->assertStringContainsString('view=mine', $html);
        $this->assertStringNotContainsString(get_string('req_title', 'local_academictranscript'), $html);
    }

    /**
     * Data for test_staff_keep_their_tools_on_their_own_page.
     *
     * @return array
     */
    public static function own_address_provider(): array {
        return ['no user in the address' => [false], 'their own id in the address' => [true]];
    }

    /**
     * The problems that stop an Official transcript are listed on a staff member's own page too.
     */
    public function test_staff_see_the_problems_on_their_own_page(): void {
        $manager = $this->make_manager();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Night Class']);
        $this->getDataGenerator()->enrol_user($manager->id, $course->id, 'student');

        $html = $this->run_page('index.php');

        $this->assertStringContainsString(get_string('problemsheading', 'local_academictranscript'), $html);
        $this->assertStringContainsString('Course "Night Class" has no hours.', $html);
        $this->assertStringContainsString(get_string('issueown', 'local_academictranscript'), $html);
    }

    /**
     * A staff member can still open My transcript and ask for an Official transcript there, as any student does. The tabs say
     * which view this is, and the tools stay on the staff view.
     */
    public function test_staff_can_open_my_transcript(): void {
        $this->make_manager();
        $_GET['view'] = 'mine';

        $html = $this->run_page('index.php');

        $this->assertStringContainsString(get_string('req_title', 'local_academictranscript'), $html);
        $this->assertStringContainsString(get_string('view_staff', 'local_academictranscript'), $html);
        foreach (['editdetails', 'transfercredits', 'courseterms', 'issueofficial'] as $key) {
            $this->assertStringNotContainsString(get_string($key, 'local_academictranscript'), $html);
        }
    }

    /**
     * The form on My transcript posts without any view in the address. A staff member's request is saved all the same.
     */
    public function test_staff_can_request_their_own_transcript(): void {
        global $DB;
        $manager = $this->make_manager();

        $_POST = ['action' => 'request', 'delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'now',
            'sesskey' => sesskey()];
        $this->failure(fn() => $this->run_page('index.php'));

        $this->assertSame(1, $DB->count_records(requests::TABLE, ['userid' => $manager->id]));
    }

    /**
     * A role that only manages student records has the three record buttons on its own page, and no issue form.
     */
    public function test_records_staff_have_the_record_tools_on_their_own_page(): void {
        $user = $this->getDataGenerator()->create_user();
        $role = create_role('Records clerk', 'recordsclerk', '');
        assign_capability('local/academictranscript:managerecords', CAP_ALLOW, $role, SYSCONTEXTID);
        role_assign($role, $user->id, SYSCONTEXTID);
        set_config('setupcomplete', 1, 'local_academictranscript');
        $this->setUser($user);

        $html = $this->run_page('index.php');

        foreach (['editdetails', 'transfercredits', 'courseterms', 'view_staff'] as $key) {
            $this->assertStringContainsString(get_string($key, 'local_academictranscript'), $html);
        }
        $this->assertStringNotContainsString(get_string('issueofficial', 'local_academictranscript'), $html);
    }

    /**
     * Someone without those permissions has My transcript and nothing else, whatever the address says: a student, and a
     * teacher who can issue only for the students of a course.
     *
     * @dataProvider plain_user_provider
     * @param string $role The role in a course, or '' for none
     * @param array $get What the address says
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('plain_user_provider')]
    public function test_my_transcript_is_all_a_user_without_tools_gets(string $role, array $get): void {
        $user = $this->getDataGenerator()->create_user();
        if ($role !== '') {
            $this->getDataGenerator()->enrol_user($user->id, $this->getDataGenerator()->create_course()->id, $role);
        }
        $this->setUser($user);
        $_GET = $get + (isset($get['self']) ? ['userid' => $user->id] : []);

        $html = $this->run_page('index.php');

        $this->assertStringContainsString(get_string('req_title', 'local_academictranscript'), $html);
        foreach (['view_staff', 'editdetails', 'transfercredits', 'courseterms', 'issueofficial'] as $key) {
            $this->assertStringNotContainsString(get_string($key, 'local_academictranscript'), $html);
        }
    }

    /**
     * Data for test_my_transcript_is_all_a_user_without_tools_gets.
     *
     * @return array
     */
    public static function plain_user_provider(): array {
        return [
            'a user in no course' => ['', []],
            'a student' => ['student', []],
            'a student with their own id in the address' => ['student', ['self' => 1]],
            'a student who asks for the staff view' => ['student', ['view' => 'staff']],
            'a student who asks for My transcript' => ['student', ['view' => 'mine']],
            'a teacher' => ['editingteacher', []],
        ];
    }

    /**
     * On another student's page there is one view, so there are no tabs, and asking for My transcript changes nothing.
     */
    public function test_another_students_page_has_no_tabs(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->make_manager();
        $_GET = ['userid' => $student->id, 'view' => 'mine'];

        $html = $this->run_page('index.php');

        $this->assertStringContainsString(get_string('editdetails', 'local_academictranscript'), $html);
        $this->assertStringNotContainsString(get_string('view_staff', 'local_academictranscript'), $html);
        $this->assertStringNotContainsString(get_string('req_title', 'local_academictranscript'), $html);
    }

    /**
     * The student's page prints the key of the scale the transcript was counted on.
     */
    public function test_the_student_page_prints_the_key_of_student_records(): void {
        global $DB;
        if (!class_exists('\\local_studentrecords\\local\\registration')) {
            $this->markTestSkipped('Student Records is not installed.');
        }
        set_config('source', 'studentrecords', 'local_academictranscript');
        set_config('setupcomplete', 1, 'local_academictranscript');
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $sr->create_scale();
        $DB->set_field('local_studentrecords_grade', 'description', 'Excellent work', ['letter' => 'A']);
        $sr->create_student(['userid' => $user->id, 'studentid' => 'S-1']);

        $_GET['userid'] = $user->id;
        $html = $this->run_page('index.php');

        $this->assertStringContainsString('Excellent work', $html);
    }

    /**
     * The codes page shows a student with a file the letter and the marks of Student Records.
     */
    public function test_the_codes_page_follows_student_records_for_a_student_with_a_file(): void {
        global $DB;
        if (!class_exists('\\local_studentrecords\\local\\registration')) {
            $this->markTestSkipped('Student Records is not installed.');
        }
        set_config('source', 'studentrecords', 'local_academictranscript');
        set_config('setupcomplete', 1, 'local_academictranscript');
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $term = $generator->create_term();
        $course = $generator->create_course_with_fields(['code' => 'BIO 101', 'hours' => 3, 'hourtype' => 'credit',
            'termid' => $term->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $generator->set_course_total($course->id, $user->id, 92.0);
        $sr->create_scale();
        $DB->insert_record('local_studentrecords_grade', (object)['letter' => 'AU', 'ingpa' => 0, 'earns' => 0, 'sortorder' => 9,
            'timemodified' => time()]);
        $offer = $sr->create_offering(['courseid' => $sr->create_course(['code' => 'BIO 101'])->id,
            'termid' => $sr->create_term()->id, 'moodlecourseid' => $course->id]);
        $student = $sr->create_student(['userid' => $user->id, 'studentid' => 'S-1']);
        // On Moodle 4.5 the email about the registration sets up the theme, and then the page can't set its course.
        $messages = $this->redirectMessages();
        $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);

        $_GET['courseid'] = $course->id;
        $html = $this->run_page('codes.php');
        $messages->close();

        $this->assertStringContainsString('92.00% (A)', $html);
        $this->assertStringContainsString('value="AU"', $html);
    }

    /**
     * The Marks step of the setup wizard says which students it covers, as the Marks page does.
     */
    public function test_the_marks_step_says_which_students_it_covers(): void {
        if (!class_exists('\\local_studentrecords\\local\\registration')) {
            $this->markTestSkipped('Student Records is not installed.');
        }
        set_config('source', 'studentrecords', 'local_academictranscript');
        $this->setAdminUser();

        $_GET['step'] = 'marks';
        $html = $this->run_page('setup.php');

        $this->assertStringContainsString(get_string('scale_usesstudentrecords', 'local_academictranscript'), $html);
    }

    /**
     * Staff can't issue their own Official transcript: their page has a line saying so in place of the form, and a forged
     * post issues nothing.
     */
    public function test_staff_cannot_issue_their_own_transcript(): void {
        global $DB, $USER;
        set_config('setupcomplete', 1, 'local_academictranscript');
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $generator->create_student_record(['userid' => $USER->id, 'studentid' => 'S-1']);

        $html = $this->run_page('index.php');

        $this->assertStringContainsString(get_string('issueown', 'local_academictranscript'), $html);
        $this->assertStringNotContainsString('name="recipient"', $html);

        $_POST = ['action' => 'issue', 'recipient' => 'Office', 'sesskey' => sesskey()];
        $this->failure(fn() => $this->run_page('index.php'));
        $this->assertSame(0, $DB->count_records('local_academictranscript_issue'));
    }

    /**
     * On the queue, a person's own request shows the reason in place of Issue, and someone else's keeps the button.
     */
    public function test_the_queue_offers_no_issue_on_ones_own_request(): void {
        global $USER;
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $other = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz']);
        requests::create((int)$USER->id, ['delivery' => 'pickup', 'recipient' => 'Own copy', 'timing' => 'now']);

        $html = $this->run_page('requests.php');

        $this->assertStringContainsString(get_string('issueown', 'local_academictranscript'), $html);
        $this->assertStringNotContainsString('name="do" value="issue"', $html);

        requests::create((int)$other->id, ['delivery' => 'pickup', 'recipient' => 'Their copy', 'timing' => 'now']);
        $this->assertStringContainsString('name="do" value="issue"', $this->run_page('requests.php'));
    }

    /**
     * During a hold in Student Records the student's page has no issue form and says why, and posting the form issues
     * nothing.
     */
    public function test_a_hold_stops_issuing_from_the_student_page(): void {
        global $DB;
        if (!class_exists('\\local_studentrecords\\local\\registration')) {
            $this->markTestSkipped('Student Records is not installed.');
        }
        set_config('source', 'studentrecords', 'local_academictranscript');
        set_config('setupcomplete', 1, 'local_academictranscript');
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $student = $sr->create_student(['userid' => $user->id, 'studentid' => 'S-1']);
        \local_studentrecords\local\academic::place_hold($student, ['holdtype' => 'financial', 'reason' => 'Unpaid & overdue',
            'blocksreg' => 1, 'startdate' => time() - 60]);

        $_GET['userid'] = $user->id;
        $html = $this->run_page('index.php');

        $this->assertStringContainsString('Unpaid &amp; overdue', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('name="recipient"', $html);

        $_POST = ['action' => 'issue', 'recipient' => 'Office', 'sesskey' => sesskey()];
        $this->failure(fn() => $this->run_page('index.php'));

        $this->assertSame(0, $DB->count_records('local_academictranscript_issue'));
    }

    /**
     * A transcript too old to draw says so, and the staff member goes back to the student. It doesn't end in a PHP error.
     */
    public function test_downloading_a_transcript_too_old_to_draw_explains_itself(): void {
        global $DB;
        $this->setAdminUser();
        $student = $this->getDataGenerator()->create_user();
        $issue = transcript::issue($student->id, 'Valley College');
        $oldest = json_encode(['fullname' => 'Ana Ruiz', 'rows' => []]);
        $DB->set_field('local_academictranscript_issue', 'snapshot', $oldest, ['id' => $issue->id]);
        $_GET['id'] = $issue->id;

        $exception = $this->failure(fn() => $this->run_page('issued.php'));

        $this->assertSame('issuedoldlayout', $exception->errorcode);
        $this->assertStringContainsString('userid=' . $student->id, $exception->link);
    }

    /**
     * The queue is for those who may issue Official transcripts.
     */
    public function test_queue_needs_the_issue_capability(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $error = $this->failure(fn() => $this->run_page('requests.php'));

        $this->assertSame('nopermissions', $error->errorcode);
    }

    /**
     * The queue shows a pending request with the recipient escaped once, on a site without Student Records.
     */
    public function test_queue_lists_a_pending_request(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        requests::create($student->id, ['delivery' => 'mail', 'recipient' => 'Hill & Dale College',
            'address' => "1 Campus Way\nSpringfield", 'timing' => 'now']);
        $this->setAdminUser();

        $html = $this->run_page('requests.php');

        $this->assertStringContainsString('Hill &amp; Dale College', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringContainsString(get_string('queue_issue', 'local_academictranscript'), $html);
    }

    /**
     * A pending request has a way to cancel it, with a place for the reason. The queue says the fee stays only when a fee
     * went on the account.
     */
    public function test_queue_offers_cancel_on_a_pending_request(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $requestid = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'now']);
        $this->setAdminUser();
        $word = get_string('queue_cancelfee', 'local_academictranscript', '$10.00');

        $html = $this->run_page('requests.php');

        $this->assertStringContainsString(get_string('queue_cancel', 'local_academictranscript'), $html);
        $this->assertStringContainsString('name="do" value="cancel"', $html);
        $this->assertStringContainsString('id="queuecancel' . $requestid . '"', $html);
        $this->assertStringNotContainsString($word, $html);

        $DB->update_record(requests::TABLE, (object)['id' => $requestid, 'fee' => 10, 'charged' => 1]);
        $this->assertStringContainsString($word, $this->run_page('requests.php'));
    }

    /**
     * Cancel takes the request off the queue, keeps the reason and logs it. It needs a good sesskey, like every action here.
     */
    public function test_queue_cancels_a_request(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $requestid = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'now']);
        $this->setAdminUser();

        $_POST = ['do' => 'cancel', 'request' => $requestid, 'note' => 'Asked by phone', 'sesskey' => 'not-the-sesskey'];
        $this->assertSame('invalidsesskey', $this->failure(fn() => $this->run_page('requests.php'))->errorcode);
        $this->assertSame('pending', $DB->get_field(requests::TABLE, 'status', ['id' => $requestid]));

        $_POST['sesskey'] = sesskey();
        $this->failure(fn() => $this->run_page('requests.php'));

        $request = $DB->get_record(requests::TABLE, ['id' => $requestid]);
        $this->assertSame('cancelled', $request->status);
        $this->assertSame('Asked by phone', $request->note);
        $this->assertTrue($DB->record_exists('local_academictranscript_log', ['action' => 'request_cancelled',
            'info' => $requestid . ' Asked by phone']));
        $_POST = [];
        $this->assertStringNotContainsString('id="queuecancel' . $requestid . '"', $this->run_page('requests.php'));
    }

    /**
     * Requests waiting for grades and requests on hold can be cancelled from the queue too.
     */
    public function test_queue_offers_cancel_on_waiting_and_held_requests(): void {
        global $DB;
        if (!class_exists('\\local_studentrecords\\local\\registration')) {
            $this->markTestSkipped('Student Records is not installed.');
        }
        set_config('source', \local_academictranscript\local\srsource::STUDENTRECORDS, 'local_academictranscript');
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $held = $this->getDataGenerator()->create_user();
        $waiting = $this->getDataGenerator()->create_user();
        $file = $sr->create_student(['userid' => $held->id, 'studentid' => 'S-1']);
        $sr->create_student(['userid' => $waiting->id, 'studentid' => 'S-2']);
        $this->setUser($held);
        $heldid = requests::create($held->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'now']);
        $this->setUser($waiting);
        $waitingid = requests::create($waiting->id, ['delivery' => 'pickup', 'recipient' => 'Me', 'timing' => 'degree']);
        $this->setAdminUser();
        \local_studentrecords\local\academic::place_hold($file, ['holdtype' => 'financial', 'reason' => 'Unpaid fees',
            'blocksreg' => 1, 'startdate' => time() - 60]);
        $this->assertSame('onhold', requests::state($DB->get_record(requests::TABLE, ['id' => $heldid])));
        $this->assertSame('waiting', requests::state($DB->get_record(requests::TABLE, ['id' => $waitingid])));

        $html = $this->run_page('requests.php');

        $this->assertStringContainsString('id="queuecancel' . $heldid . '"', $html);
        $this->assertStringContainsString('id="queuecancel' . $waitingid . '"', $html);

        requests::cancel($heldid, 'Student asked');
        requests::cancel($waitingid, '');
        $this->assertSame(2, $DB->count_records(requests::TABLE, ['status' => 'cancelled']));
    }

    /**
     * Issuing from the queue needs a good sesskey, and nothing is issued without it.
     */
    public function test_queue_refuses_a_wrong_sesskey(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $requestid = requests::create($student->id, ['delivery' => 'pickup', 'recipient' => 'Myself', 'timing' => 'now']);
        $this->setAdminUser();

        $_POST = ['do' => 'issue', 'request' => $requestid, 'sesskey' => 'not-the-sesskey'];
        $error = $this->failure(fn() => $this->run_page('requests.php'));

        $this->assertSame('invalidsesskey', $error->errorcode);
        $this->assertSame('pending', $DB->get_field(requests::TABLE, 'status', ['id' => $requestid]));
    }

    /**
     * A decision with a wrong sesskey is refused with an error, and the request stays open. The page used to ignore
     * it without a word.
     */
    public function test_approvals_page_refuses_a_wrong_sesskey(): void {
        global $DB;

        $changeid = $this->request_change();

        $_POST = ['changeid' => $changeid, 'decision' => 'approve', 'sesskey' => 'not-the-sesskey'];
        $error = $this->failure(fn() => $this->run_page('approvals.php'));

        $this->assertSame('invalidsesskey', $error->errorcode);
        $this->assertSame('pending', $DB->get_field('local_academictranscript_change', 'status', ['id' => $changeid]));
    }

    /**
     * With the right sesskey, the decision is made and the page sends the admin back to the list.
     */
    public function test_approvals_page_decides_with_the_right_sesskey(): void {
        global $DB;

        $changeid = $this->request_change();

        $_POST = ['changeid' => $changeid, 'decision' => 'reject', 'sesskey' => sesskey()];
        $error = $this->failure(fn() => $this->run_page('approvals.php'));

        $this->assertSame('redirecterrordetected', $error->errorcode);
        $this->assertSame('rejected', $DB->get_field('local_academictranscript_change', 'status', ['id' => $changeid]));
    }

    /**
     * The approvals page offers the buttons to someone who may decide.
     */
    public function test_approvals_page_offers_the_buttons(): void {
        $this->request_change();

        $html = $this->run_page('approvals.php');

        $this->assertStringContainsString('name="decision"', $html);
        $this->assertStringNotContainsString(get_string('changeownrequest', 'local_academictranscript'), $html);
    }

    /**
     * The approvals page has no buttons on a request of the person who reads it, or on their own grade, and says why.
     *
     * @dataProvider own_change_provider
     * @param bool $ownrequest Whether the admin asked for the change
     * @param bool $owngrade Whether it is the admin's grade
     * @param string $reason The language string id of the reason
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('own_change_provider')]
    public function test_approvals_page_has_no_buttons_on_your_own(bool $ownrequest, bool $owngrade, string $reason): void {
        $this->request_change($owngrade ? get_admin() : null, $ownrequest ? get_admin() : null);

        $html = $this->run_page('approvals.php');

        $this->assertStringContainsString(get_string($reason, 'local_academictranscript'), $html);
        $this->assertStringNotContainsString('name="decision"', $html);
    }

    /**
     * A decision on your own request or grade that reaches the page anyway changes nothing.
     *
     * @dataProvider own_change_provider
     * @param bool $ownrequest Whether the admin asked for the change
     * @param bool $owngrade Whether it is the admin's grade
     * @param string $reason The language string id of the reason, which the page shows after its redirect
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('own_change_provider')]
    public function test_approvals_page_refuses_a_decision_on_your_own(bool $ownrequest, bool $owngrade, string $reason): void {
        global $DB;
        $changeid = $this->request_change($owngrade ? get_admin() : null, $ownrequest ? get_admin() : null);

        $_POST = ['changeid' => $changeid, 'decision' => 'approve', 'sesskey' => sesskey()];
        $this->assertSame('redirecterrordetected', $this->failure(fn() => $this->run_page('approvals.php'))->errorcode);

        $this->assertSame('pending', $DB->get_field('local_academictranscript_change', 'status', ['id' => $changeid]));
        $this->assertSame('B', $DB->get_field_sql('SELECT p.grade FROM {local_academictranscript_posted} p
                                                     JOIN {local_academictranscript_change} c ON c.postedid = p.id
                                                    WHERE c.id = ?', [$changeid]));
    }

    /**
     * Data for the tests of a change on your own request or grade.
     *
     * @return array
     */
    public static function own_change_provider(): array {
        return [
            'your own request' => [true, false, 'changeownrequest'],
            'your own grade, asked by someone else' => [false, true, 'changeownrecord'],
            'your own grade, asked by you' => [true, true, 'changeownrecord'],
        ];
    }

    /**
     * The post page has no box on a person's own row, and says why. The other students keep theirs.
     */
    public function test_post_page_has_no_box_on_your_own_row(): void {
        [$course, $manager, $other] = $this->make_manager_who_studies();

        $_GET['courseid'] = $course->id;
        $html = $this->run_page('post.php');

        $this->assertStringContainsString(s(get_string('ownposting', 'local_academictranscript')), $html);
        $this->assertStringNotContainsString('name="post[' . $manager->id . ']"', $html);
        $this->assertStringContainsString('name="post[' . $other->id . ']"', $html);
    }

    /**
     * A post for a person's own row that reaches the page anyway changes nothing, and another student in the same post is
     * saved.
     */
    public function test_post_page_refuses_your_own_row(): void {
        global $DB;
        [$course, $manager, $other] = $this->make_manager_who_studies();

        $_GET['courseid'] = $course->id;
        $_POST = ['post' => [$manager->id => 1, $other->id => 1], 'sesskey' => sesskey()];
        $this->assertSame('redirecterrordetected', $this->failure(fn() => $this->run_page('post.php'))->errorcode);

        $this->assertSame(0, $DB->count_records('local_academictranscript_posted', ['userid' => $manager->id]));
        $this->assertSame(1, $DB->count_records('local_academictranscript_posted', ['userid' => $other->id]));
    }

    /**
     * The Course codes check prints the name of a course and of its term with an ampersand escaped once.
     */
    public function test_course_check_page_escapes_names_once(): void {
        $this->make_names_with_ampersand();

        $html = $this->run_page('coursecheck.php');

        $this->assertStringContainsString('Health &amp; Safety (Level 1)', $html);
        $this->assertStringContainsString('Fall &amp; Winter', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * The page for posting grades prints the term of a student with an ampersand escaped once.
     */
    public function test_post_page_escapes_the_term_once(): void {
        $names = $this->make_names_with_ampersand();

        $_GET['courseid'] = $names['course']->id;
        $html = $this->run_page('post.php');

        $this->assertStringContainsString('Fall &amp; Winter', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * The page where teachers enter codes prints the code and the label of a mark in its menu with an ampersand escaped
     * once.
     */
    public function test_codes_page_escapes_mark_codes_and_labels_once(): void {
        $names = $this->make_names_with_ampersand();
        scale::save_marks([
            ['code' => 'P', 'label' => 'Pass & credit', 'earns' => 1],
            ['code' => 'X&Y', 'label' => 'Extra', 'earns' => 1],
        ]);

        $_GET['courseid'] = $names['course']->id;
        $html = $this->run_page('codes.php');

        $this->assertStringContainsString('P - Pass &amp; credit', $html);
        $this->assertStringContainsString('X&amp;Y - Extra', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * The page for the terms of a student prints the course name and the terms, in the table and in the menu, with an
     * ampersand escaped once.
     */
    public function test_student_terms_page_escapes_names_once(): void {
        $names = $this->make_names_with_ampersand();

        $_GET['userid'] = $names['student']->id;
        $html = $this->run_page('studentterms.php');

        $this->assertStringContainsString('Health &amp; Safety (Level 1)', $html);
        // Once in the table, and once in the menu of terms.
        $this->assertSame(2, substr_count($html, 'Fall &amp; Winter'));
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * The course catalog prints the program of a course with an ampersand escaped once.
     */
    public function test_catalog_page_escapes_the_program_once(): void {
        $this->make_names_with_ampersand();

        $html = $this->run_page('catalog.php');

        $this->assertStringContainsString('Arts &amp; Sciences', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * The transcript page prints the program, the course and the problems that name a course with an ampersand escaped
     * once. A name with angle brackets in it is shown as text, not as markup.
     */
    public function test_transcript_page_escapes_names_once(): void {
        $names = $this->make_names_with_ampersand();
        // Entities that are typed in a name become characters in its plain text, so this name has "<b>" in it.
        $angle = $this->getDataGenerator()->create_course(['fullname' => 'Math &lt;b&gt; Basics']);
        $this->getDataGenerator()->enrol_user($names['student']->id, $angle->id, 'student');

        $_GET['userid'] = $names['student']->id;
        $html = $this->run_page('index.php');

        $this->assertStringContainsString('Arts &amp; Sciences', $html);
        $this->assertStringContainsString('Health &amp; Safety (Level 1)', $html);
        // The problems are printed in a notification, which cleans its message, so a quote shows as a quote. The
        // ampersand is escaped by the cleaner too, but only the escaping of the plain text keeps the "<b>" from
        // becoming markup.
        $this->assertStringContainsString('Course "Health &amp; Safety (Level 1)" has no hours.', $html);
        $this->assertStringContainsString('Course "Math &lt;b&gt; Basics" has no course code.', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * A guest is turned away from the transcript page with Moodle's own message for guests.
     */
    public function test_transcript_page_refuses_guests(): void {
        $this->setGuestUser();

        $error = $this->failure(fn() => $this->run_page('index.php'));

        $this->assertSame('noguest', $error->errorcode);
    }

    /**
     * The Terms page opens before the setup is finished. The last step of the setup wizard asks for at least one term, so
     * an admin has to be able to add one before the setup is finished.
     */
    public function test_terms_page_opens_before_the_setup_is_finished(): void {
        global $OUTPUT;

        $this->setAdminUser();
        set_config('setupcomplete', 0, 'local_academictranscript');

        $html = $this->run_page('terms.php');

        $this->assertStringContainsString($OUTPUT->heading('Terms'), $html);
        $this->assertStringContainsString('Add a term', $html);
    }

    /**
     * The last step of the setup wizard lists what is still open. The term check links to the Terms page, where the
     * term is added, and keeps its wording. It links whether the check is open or done.
     *
     * @dataProvider setup_term_check_provider
     * @param bool $hasterm Whether a term exists
     * @param string $mark The mark in front of the check
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('setup_term_check_provider')]
    public function test_setup_wizard_links_the_term_check_to_the_terms_page(bool $hasterm, string $mark): void {
        if ($hasterm) {
            $this->getDataGenerator()->get_plugin_generator('local_academictranscript')->create_term();
        }
        $this->setAdminUser();
        $_GET['step'] = 'done';

        $html = $this->run_page('setup.php');

        $this->assertMatchesRegularExpression(
            '~' . $mark . ' <a href="[^"]*/local/academictranscript/terms\.php">At least one term is set \(Terms page\)</a>~u',
            $html
        );
    }

    /**
     * Data for test_setup_wizard_links_the_term_check_to_the_terms_page.
     *
     * @return array
     */
    public static function setup_term_check_provider(): array {
        return [
            'no term yet' => [false, '✖'],
            'a term exists' => [true, '✔'],
        ];
    }

    /**
     * The other admin pages still send the admin to the setup wizard until the setup is finished.
     */
    public function test_other_admin_pages_wait_for_the_setup(): void {
        $this->setAdminUser();
        set_config('setupcomplete', 0, 'local_academictranscript');

        $error = $this->failure(fn() => $this->run_page('log.php'));

        $this->assertSame('redirecterrordetected', $error->errorcode);
    }

    /**
     * Run a page of the plugin and return what it printed.
     *
     * The page runs inside this method, so this method has the variables that Moodle gives a page at the top level.
     * A page that returns without an exception must not have triggered a debugging message. Moodle 5.3 reports such a
     * message as a notice only, so it is checked here.
     *
     * Two kinds of page cannot be run this way. A page that calls die() ends the whole PHPUnit run, not only the test:
     * codes.php and post.php do that for a course without students, so a test gives the course a student first. A
     * page that declares a function or a class can be required once only, because PHP refuses to declare it a second
     * time, so a second test that runs it fails.
     *
     * @param string $file the page, such as verify.php
     * @return string
     */
    private function run_page(string $file): string {
        global $CFG, $DB, $PAGE, $OUTPUT, $USER, $SITE, $COURSE, $SESSION;

        ob_start();
        try {
            require(__DIR__ . '/../' . $file);
        } finally {
            $output = ob_get_clean();
        }
        $this->assertDebuggingNotCalled();
        return $output;
    }

    /**
     * Sign in as the admin, finish the setup, and make a student who takes a course. The course (no hours, so that it
     * is a problem for the transcript), its term and the program of the student have an ampersand in their names.
     *
     * @return array with the keys student, course, term and program
     */
    private function make_names_with_ampersand(): array {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall & Winter']);
        $program = $generator->create_program(['name' => 'Arts & Sciences']);
        $generator->create_catalog_course(['programid' => $program->id]);
        $course = $generator->create_course_with_fields(['fullname' => 'Health & Safety (Level 1)', 'code' => 'HLT 110',
            'hourtype' => 'credit', 'termid' => $term->id]);
        $student = $this->getDataGenerator()->create_user();
        $generator->create_student_record(['userid' => $student->id, 'programid' => $program->id]);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setAdminUser();
        set_config('setupcomplete', 1, 'local_academictranscript');

        return ['student' => $student, 'course' => $course, 'term' => $term, 'program' => $program];
    }

    /**
     * Sign in as a manager with the setup finished. A manager, not the admin, because a site admin passes every check.
     *
     * @return \stdClass the user
     */
    private function make_manager(): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        role_assign($DB->get_field('role', 'id', ['shortname' => 'manager']), $user->id, SYSCONTEXTID);
        set_config('setupcomplete', 1, 'local_academictranscript');
        $this->setUser($user);
        return $user;
    }

    /**
     * Sign in as a manager who is also a student of a course, with another student beside them. Both have a course total.
     *
     * @return array the course, the manager and the other student
     */
    private function make_manager_who_studies(): array {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term();
        $course = $generator->create_course_with_fields(['code' => 'BIO 101', 'hours' => 3, 'hourtype' => 'credit',
            'termid' => $term->id]);
        $manager = $this->make_manager();
        $other = $this->getDataGenerator()->create_user();
        foreach ([$manager, $other] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
            $generator->set_course_total($course->id, $user->id, 90);
        }
        return [$course, $manager, $other];
    }

    /**
     * Ask for a change to the posted grade of a student, then sign in as the admin.
     *
     * @param \stdClass|null $student Whose grade it is, or null for a new student
     * @param \stdClass|null $requester Who asks for the change, or null for a new user
     * @return int id of the change request, which is waiting
     */
    private function request_change(?\stdClass $student = null, ?\stdClass $requester = null): int {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall 2026']);
        $course = $this->getDataGenerator()->create_course();
        $student = $student ?? $this->getDataGenerator()->create_user();
        $posted = $generator->create_posted_grade(['userid' => $student->id, 'courseid' => $course->id,
            'termid' => $term->id, 'grade' => 'B']);
        $this->setUser($requester ?? $this->getDataGenerator()->create_user());
        $changeid = posting::request_change($posted->id, 'A', '', 'The exam was marked again.');
        $this->setAdminUser();
        set_config('setupcomplete', 1, 'local_academictranscript');

        return $changeid;
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
