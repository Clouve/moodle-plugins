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
     * Sign in as the admin and ask for a change to the posted grade of a student.
     *
     * @return int id of the change request, which is waiting
     */
    private function request_change(): int {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall 2026']);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $posted = $generator->create_posted_grade(['userid' => $student->id, 'courseid' => $course->id,
            'termid' => $term->id, 'grade' => 'B']);
        $this->setAdminUser();
        set_config('setupcomplete', 1, 'local_academictranscript');

        return posting::request_change($posted->id, 'A', '', 'The exam was marked again.');
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
