<?php

namespace local_studentrecords;

use local_studentrecords\local\audit;
use local_studentrecords\local\drafts;
use local_studentrecords\local\home;
use local_studentrecords\local\importer;
use local_studentrecords\local\records;
use local_studentrecords\local\shell;
use local_studentrecords\local\students;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests for the records, import and report pages.
 *
 * A page is run the way Moodle runs it: it reads its request from $_GET or $_POST and its output is collected. A page that
 * redirects, or stops with an error, ends with an exception, as every page does in a PHPUnit test. A page that ends with
 * die() cannot be run to its end here, so the tests stop before that or use a page without one.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @coversNothing
 */
final class records_pages_test extends \advanced_testcase {
    /** @var \stdClass The course of the first student. */
    private \stdClass $course;

    /** @var \stdClass A teacher of the course. */
    private \stdClass $teacher;

    /** @var \stdClass A student of the course. */
    private \stdClass $student;

    /** @var \stdClass A student of another course. */
    private \stdClass $other;

    /**
     * Two courses, a teacher of the first and a student in each, as the admin.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        home::reset_cache();
        shell::reset();
        $gen = $this->getDataGenerator();
        $sr = $gen->get_plugin_generator('local_studentrecords');
        $this->setAdminUser();
        $this->course = $gen->create_course();
        $othercourse = $gen->create_course();
        $this->teacher = $gen->create_user();
        $gen->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $user = $gen->create_user(['firstname' => 'Ann', 'lastname' => 'Lee']);
        $gen->enrol_user($user->id, $this->course->id, 'student');
        $otheruser = $gen->create_user(['firstname' => 'Bo', 'lastname' => 'Ray']);
        $gen->enrol_user($otheruser->id, $othercourse->id, 'student');
        $this->student = $sr->create_student(['userid' => $user->id, 'studentid' => 'S1']);
        $this->other = $sr->create_student(['userid' => $otheruser->id, 'studentid' => 'S2']);
    }

    /**
     * Forget the admin tree and the cached flags that a page built for the user of the test.
     */
    protected function tearDown(): void {
        global $ADMIN;
        $ADMIN = null;
        home::reset_cache();
        shell::reset();
        parent::tearDown();
    }

    /**
     * The students list of a course shows that course's students, for its teacher.
     */
    public function test_teacher_course_list_shows_only_that_course(): void {
        $this->setUser($this->teacher);
        $_GET['courseid'] = $this->course->id;
        $html = $this->run_page('students.php');
        $this->assertStringContainsString('Lee', $html);
        $this->assertStringNotContainsString('Ray', $html);
    }

    /**
     * The list of every student is for records staff, not teachers.
     */
    public function test_teacher_cannot_open_the_list_of_all_students(): void {
        $this->setUser($this->teacher);
        $this->expectException(\required_capability_exception::class);
        $this->run_page('students.php');
    }

    /**
     * A teacher of another course cannot list this one.
     */
    public function test_teacher_of_another_course_cannot_list_it(): void {
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $other->id, 'editingteacher');
        $this->setUser($user);
        $_GET['courseid'] = $this->course->id;
        $this->expectException(\moodle_exception::class);
        $this->run_page('students.php');
    }

    /**
     * A FERPA opt-out hides the preferred name from the course list, and it cannot be found by search either.
     */
    public function test_course_list_honours_the_opt_out(): void {
        students::save(['id' => $this->student->id, 'preferredname' => 'Secretname', 'ferpaoptout' => 1]);
        $this->setUser($this->teacher);
        $_GET = ['courseid' => $this->course->id];
        $html = $this->run_page('students.php');
        $this->assertStringContainsString('Lee', $html);
        $this->assertStringNotContainsString('Secretname', $html);
    }

    /**
     * Searching for an opted-out student's preferred name tells a teacher nothing.
     */
    public function test_search_does_not_reveal_a_hidden_preferred_name(): void {
        students::save(['id' => $this->student->id, 'preferredname' => 'Secretname', 'ferpaoptout' => 1]);
        $this->setUser($this->teacher);
        $_GET = ['courseid' => $this->course->id, 'search' => 'Secretname'];
        $html = $this->run_page('students.php');
        $this->assertStringContainsString(get_string('nostudents', 'local_studentrecords'), $html);
    }

    /**
     * A student cannot open a classmate's record by changing the number.
     */
    public function test_student_cannot_open_another_record(): void {
        $this->setUser((int)$this->student->userid);
        $_GET['id'] = $this->other->id;
        $this->expectException(\required_capability_exception::class);
        $this->run_page('student.php');
    }

    /**
     * Asking for a user who has no record says the same as asking for one who has: nothing about whether a record exists.
     */
    public function test_a_user_id_does_not_reveal_who_has_a_record(): void {
        $nobody = $this->getDataGenerator()->create_user();
        $this->setUser((int)$this->student->userid);
        $_GET['userid'] = $nobody->id;
        $this->expectException(\required_capability_exception::class);
        $this->run_page('student.php');
    }

    /**
     * A teacher does not open the record of a student outside their courses, and edit needs the manage capability.
     */
    public function test_teacher_cannot_open_or_edit_other_records(): void {
        $this->setUser($this->teacher);
        $_GET['id'] = $this->other->id;
        try {
            $this->run_page('student.php');
            $this->fail('No exception');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString('nopermissions', $e->errorcode);
        }
        $_GET = ['id' => $this->student->id, 'edit' => 1];
        $this->expectException(\required_capability_exception::class);
        $this->run_page('student.php');
    }

    /**
     * A teacher sees neither visa details nor a hidden preferred name. Every view is logged.
     */
    public function test_teacher_view_of_a_record(): void {
        global $DB;
        set_config('f1', '1', 'local_studentrecords');
        students::save(['id' => $this->student->id, 'preferredname' => 'Secretname', 'ferpaoptout' => 1, 'isf1' => 1,
            'visatype' => 'F-1', 'sevisid' => 'N0012345678']);
        $this->setUser($this->teacher);
        $_GET = ['id' => $this->student->id, 'courseid' => $this->course->id];
        $html = $this->run_page('student.php');
        $this->assertStringContainsString('Lee', $html);
        $this->assertStringNotContainsString('N0012345678', $html);
        $this->assertStringNotContainsString('Secretname', $html);
        $this->assertTrue($DB->record_exists('local_studentrecords_log', ['action' => 'view', 'objecttype' => 'student',
            'objectid' => $this->student->id, 'userid' => $this->teacher->id]));
    }

    /**
     * A student sees their own visa details.
     */
    public function test_student_sees_own_visa_details(): void {
        set_config('f1', '1', 'local_studentrecords');
        students::save(['id' => $this->student->id, 'isf1' => 1, 'sevisid' => 'N0012345678']);
        $this->setUser((int)$this->student->userid);
        $_GET['id'] = $this->student->id;
        $this->assertStringContainsString('N0012345678', $this->run_page('student.php'));
    }

    /**
     * "View as student" is for records staff, is logged, changes nothing and has no form to post.
     */
    public function test_view_as_is_logged_and_read_only(): void {
        global $DB;
        $before = $DB->count_records('local_studentrecords_history');
        $_GET['id'] = $this->student->id;
        $html = $this->run_page('viewas.php');
        $this->assertTrue($DB->record_exists('local_studentrecords_log', ['action' => 'view', 'objecttype' => 'student',
            'objectid' => $this->student->id, 'info' => 'view as student']));
        $this->assertSame($before, $DB->count_records('local_studentrecords_history'));
        $body = substr($html, (int)strpos($html, 'sr-viewas'));
        $this->assertStringNotContainsString('method="post"', $body);
    }

    /**
     * A student cannot use "View as student" to see a classmate.
     */
    public function test_student_cannot_view_as_another(): void {
        $this->setUser((int)$this->student->userid);
        $_GET['id'] = $this->other->id;
        $this->expectException(\required_capability_exception::class);
        $this->run_page('viewas.php');
    }

    /**
     * Letters are issued by records staff. A student gets no letter through the page, their own or another's.
     */
    public function test_student_cannot_get_a_letter_of_someone_else(): void {
        $letter = \local_studentrecords\local\letters::issue($this->other, 'Bank');
        $this->setUser((int)$this->student->userid);
        $_GET['letter'] = $letter;
        $this->expectException(\required_capability_exception::class);
        $this->run_page('letter.php');
    }

    /**
     * A page that changes data refuses a request without the session key.
     *
     * @dataProvider sesskey_provider
     * @param string $page
     * @param array $params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sesskey_provider')]
    public function test_state_changes_need_the_session_key(string $page, array $params): void {
        $params = array_map(fn($v) => $v === 'STUDENT' ? $this->student->id : $v, $params);
        if ($page === 'ferpa.php') {
            $this->setUser((int)$this->student->userid);
        }
        $_GET = $params + ['sesskey' => 'wrong'];
        try {
            $this->run_page($page);
            $this->fail('No exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidsesskey', $e->errorcode);
        }
    }

    /**
     * Data for test_state_changes_need_the_session_key.
     *
     * @return array
     */
    public static function sesskey_provider(): array {
        return [
            'FERPA notice' => ['ferpa.php', ['ack' => 1]],
            'admissions decision' => ['admissions.php', ['decide' => 'denied', 'a' => 'STUDENT']],
            'starter exports' => ['outsidereports.php', ['starters' => 1]],
            'approve all items' => ['import.php', ['id' => 1, 'action' => 'approveall']],
            'reject items' => ['import.php', ['id' => 1, 'action' => 'reject']],
        ];
    }

    /**
     * Revoking a release authorization needs the session key too.
     */
    public function test_revoke_needs_the_session_key(): void {
        global $DB;
        $id = $DB->insert_record('local_studentrecords_relauth', (object)['studentid' => $this->student->id,
            'recipient' => 'Bank', 'scope' => 'all', 'fromdate' => time()]);
        $_GET = ['id' => $this->student->id, 'part' => 'revoke', 'item' => $id, 'confirm' => 1, 'sesskey' => 'wrong'];
        try {
            $this->run_page('studentpart.php');
            $this->fail('No exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidsesskey', $e->errorcode);
        }
        $this->assertEmpty($DB->get_field('local_studentrecords_relauth', 'timerevoked', ['id' => $id]));
    }

    /**
     * The form to edit an emergency contact posts the student's id, not the contact's. The id of the row used to win, so
     * editing worked only when the two numbers happened to be the same.
     */
    public function test_editing_a_row_keeps_the_student_id(): void {
        foreach (range(1, 3) as $n) {
            $row = records::save('local_studentrecords_emerg', ['studentid' => $this->other->id, 'name' => 'Other ' . $n,
                'phone' => '555'], 'form');
        }
        $row = records::save('local_studentrecords_emerg', ['studentid' => $this->student->id, 'name' => 'Maria',
            'phone' => '555-0101'], 'form');
        $this->assertNotEquals($row, $this->student->id);
        $_GET = ['id' => $this->student->id, 'part' => 'emerg', 'item' => $row];

        $html = $this->run_page('studentpart.php');

        $pattern = '/name="id" type="hidden" value="(\d+)"|type="hidden" name="id" value="(\d+)"/';
        $this->assertSame(1, preg_match($pattern, $html, $m));
        $this->assertEquals($this->student->id, $m[1] ?: $m[2]);
    }

    /**
     * Posting that form saves the row of that student.
     */
    public function test_posting_a_row_updates_it(): void {
        global $DB;
        $row = records::save('local_studentrecords_educ', ['studentid' => $this->student->id, 'school' => 'Old High'], 'form');
        \local_studentrecords\form\educ::mock_submit(['id' => $this->student->id, 'part' => 'educ', 'item' => $row,
            'school' => 'New High', 'credential' => '', 'country' => '', 'notes' => '', 'active' => 1,
            'completed' => ['enabled' => 0]]);
        try {
            $this->run_page('studentpart.php');
            $this->fail('No redirect');
        } catch (\moodle_exception $e) {
            $this->assertSame('New High', $DB->get_field('local_studentrecords_educ', 'school', ['id' => $row]));
        }
        $this->assertEquals($this->student->id, $DB->get_field('local_studentrecords_educ', 'studentid', ['id' => $row]));
    }

    /**
     * The form to fix an imported item has no field called id, which would override the import id of the page.
     */
    public function test_the_fix_form_has_no_id_of_its_own(): void {
        $form = new \local_studentrecords\form\draft_fix(null, ['fields' => ['name' => 'Spring']]);
        $this->assertStringNotContainsString('name="id"', $form->render());
    }

    /**
     * Saving a fix on an imported item reaches the item.
     */
    public function test_import_fix_saves(): void {
        global $DB;
        $importid = drafts::store('terms.csv', importer::build(['Terms' => [['Term', 'Start date', 'End date'],
            ['Spring', '2030-01-14', '2030-05-03']]]));
        // An item id that differs from the import id, as on any real site.
        $DB->insert_record(drafts::TABLE, (object)['importid' => $importid, 'itemtype' => 'term', 'itemkey' => 'x',
            'data' => '{}']);
        $draft = $DB->get_record(drafts::TABLE, ['importid' => $importid, 'itemkey' => 'spring']);
        $_GET = ['id' => $importid, 'fix' => $draft->id, 'type' => 'term'];
        \local_studentrecords\form\draft_fix::mock_submit(['f_name' => 'Spring 2030', 'f_startdate' => '2030-01-14',
            'f_enddate' => '2030-05-03']);
        try {
            $this->run_page('import.php');
            $this->fail('No redirect');
        } catch (\moodle_exception $e) {
            $data = json_decode($DB->get_field(drafts::TABLE, 'data', ['id' => $draft->id]), true);
            $this->assertSame('Spring 2030', $data['name']);
        }
    }

    /**
     * Text from an imported file is shown as text on the review page.
     */
    public function test_import_review_escapes_the_file(): void {
        $bad = '<img src=x onerror=alert(1)>';
        $importid = drafts::store('terms.csv', importer::build(['Terms' => [['Term', 'Start date', 'End date'],
            [$bad, 'soon', '2030-05-03']]]));
        $_GET = ['id' => $importid, 'type' => 'term'];
        $html = $this->run_page('import.php');
        $this->assertStringContainsString('&lt;img src=x', $html);
        $this->assertStringNotContainsString($bad, $html);
    }

    /**
     * Only import staff reach the import page.
     */
    public function test_import_needs_its_capability(): void {
        $this->setUser($this->teacher);
        $_GET['id'] = 1;
        $this->expectException(\required_capability_exception::class);
        $this->run_page('import.php');
    }

    /**
     * No LMS staff with one course stay on the home page. The course would send them back, and round again.
     */
    public function test_no_lms_staff_with_one_course_are_not_sent_to_it(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');
        $DB->insert_record('local_studentrecords_faculty', (object)['userid' => $user->id, 'firstname' => 'A', 'lastname' => 'B',
            'nolms' => 1]);
        $this->setUser($user);
        $html = $this->run_page('index.php');
        $this->assertStringContainsString(get_string('homenothing', 'local_studentrecords'), $html);
    }

    /**
     * A person with one course and nothing in Student Records still goes straight to the course.
     */
    public function test_a_plain_user_with_one_course_is_sent_to_it(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');
        $this->setUser($user);
        $this->expectException(\moodle_exception::class);
        $this->run_page('index.php');
    }

    /**
     * A saved export that lists race and sex needs the view demographics capability, like the record does.
     */
    public function test_demographics_in_an_export_need_their_capability(): void {
        global $DB;
        $id = $DB->insert_record('local_studentrecords_export', (object)['name' => 'State', 'fields' => 'studentid,races']);
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Registrar', 'registrar', '');
        assign_capability('local/studentrecords:managestudents', CAP_ALLOW, $roleid, SYSCONTEXTID);
        role_assign($roleid, $user->id, SYSCONTEXTID);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);
        $_GET['run'] = $id;
        $this->expectException(\required_capability_exception::class);
        $this->run_page('outsidereports.php');
    }

    /**
     * Without that capability, the card of such an export says what is missing and has no Run button. Other exports still
     * run, and someone with the capability sees no note.
     */
    public function test_export_card_explains_a_missing_demographics_capability(): void {
        global $DB;
        $export = ['purpose' => 'state', 'population' => 'all'];
        $blocked = $DB->insert_record('local_studentrecords_export', (object)($export + ['name' => 'Race report',
            'fields' => 'studentid,races']));
        $open = $DB->insert_record('local_studentrecords_export', (object)($export + ['name' => 'Plain report',
            'fields' => 'studentid']));
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Registrar', 'registrar', '');
        assign_capability('local/studentrecords:managestudents', CAP_ALLOW, $roleid, SYSCONTEXTID);
        role_assign($roleid, $user->id, SYSCONTEXTID);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);
        $note = get_string('rep_needsdemographics', 'local_studentrecords', get_string(
            'studentrecords:viewdemographics',
            'local_studentrecords'
        ));

        $html = $this->run_page('outsidereports.php');

        $this->assertStringContainsString($note, $html);
        $this->assertStringNotContainsString('run=' . $blocked . '"', $html);
        $this->assertStringContainsString('run=' . $open . '"', $html);

        assign_capability('local/studentrecords:viewdemographics', CAP_ALLOW, $roleid, SYSCONTEXTID);
        accesslib_clear_all_caches_for_unit_testing();

        $html = $this->run_page('outsidereports.php');

        $this->assertStringNotContainsString($note, $html);
        $this->assertStringContainsString('run=' . $blocked . '"', $html);
    }

    /**
     * Every kind of record written to the audit log has a name on the log page.
     */
    public function test_log_page_names_every_kind_of_record(): void {
        $types = [];
        $root = dirname(__DIR__);
        $files = array_merge(glob($root . '/*.php'), glob($root . '/classes/local/*.php'));
        foreach ($files as $file) {
            preg_match_all("/audit::log\(\s*[^,]+,\s*'([a-z]+)'/", file_get_contents($file), $m);
            $types = array_merge($types, $m[1]);
        }
        $this->assertContains('student', $types);
        foreach (array_unique($types) as $type) {
            audit::log('view', $type);
        }
        $html = $this->run_page('log.php');
        $this->assertStringNotContainsString('[[obj_', $html);
    }

    /**
     * A name with an ampersand is escaped once on a report, and a decision message is escaped.
     */
    public function test_report_escapes_names_once(): void {
        global $DB;
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $program = $sr->create_program(['code' => 'A&B', 'name' => 'Arts & Sciences']);
        $term = $sr->create_term(['name' => 'Fall & Winter']);
        $offer = $sr->create_offering(['courseid' => $sr->create_course()->id, 'termid' => $term->id]);
        $DB->set_field(students::TABLE, 'programid', $program->id, ['id' => $this->student->id]);
        $sr->create_registration(['studentid' => $this->student->id, 'offerid' => $offer->id]);
        $html = $this->run_page('reports.php');
        $this->assertStringContainsString('A&amp;B - Arts &amp; Sciences', $html);
        $this->assertStringContainsString('Fall &amp; Winter', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * Run a page and return what it prints. The page must not end with die().
     *
     * @param string $file the page
     * @return string
     */
    private function run_page(string $file): string {
        global $CFG, $DB, $PAGE, $OUTPUT, $USER, $SITE, $COURSE, $SESSION;

        // The test data can set up the theme of the page, and on Moodle 4.5 require_login($course) then refuses to run.
        $PAGE = new \moodle_page();
        $OUTPUT = new \bootstrap_renderer();
        ob_start();
        try {
            require(__DIR__ . '/../' . $file);
        } finally {
            $output = ob_get_clean();
        }
        return $output;
    }
}
