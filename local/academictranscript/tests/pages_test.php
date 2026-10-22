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

namespace local_academictranscript;

use local_academictranscript\local\posting;
use local_academictranscript\local\scale;

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
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
