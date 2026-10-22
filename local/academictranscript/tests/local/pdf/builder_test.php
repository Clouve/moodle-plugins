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

namespace local_academictranscript\local\pdf;

use local_academictranscript\local\import\document_text;
use local_academictranscript\local\scale;
use local_academictranscript\local\transcript;

#[\PHPUnit\Framework\Attributes\CoversClass(builder::class)]
/**
 * Tests for the PDF builder.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_academictranscript\local\pdf\builder
 */
final class builder_test extends \advanced_testcase {
    /**
     * Start each test with an empty request cache.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        scale::reset_cache();
    }

    /**
     * A student without courses gets an Unofficial PDF: the Academic Record page, then the Transcript Key page.
     */
    public function test_render_unofficial_pdf_for_student_without_courses(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz']);

        $pdf = builder::render(transcript::build($user->id), null);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $document = $this->parse($pdf);
        $text = $document->getText();
        $this->assertCount(2, $document->getPages());
        $this->assertStringContainsString(fullname($user), $text);
        $this->assertStringContainsString(get_string('nocourses', 'local_academictranscript'), $text);
    }

    /**
     * The download name carries the student's name, the copy type and today's date.
     */
    public function test_filename_marks_copy_type(): void {
        $data = ['fullname' => 'Ana María Ruiz'];

        $this->assertSame('transcript-Ana-María-Ruiz-official-' . date('Ymd') . '.pdf', builder::filename($data, true));
        $this->assertSame('transcript-Ana-María-Ruiz-unofficial-' . date('Ymd') . '.pdf', builder::filename($data, false));
    }

    /**
     * An Official PDF made from an issued snapshot is digitally signed, and its Academic Record shows the transcript
     * number and the verification code. A student copy is not signed.
     */
    public function test_render_official_pdf_is_signed(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall 2025']);
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz']);
        $course = $generator->create_course_with_fields(['fullname' => 'BUS 101 - Introduction to Business',
            'code' => 'BUS 101', 'hours' => 3, 'hourtype' => 'credit', 'termid' => $term->id]);
        $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $course->id, 'termid' => $term->id,
            'coursecode' => 'BUS 101', 'coursename' => 'Introduction to Business', 'grade' => 'A']);
        $this->setAdminUser();
        $issue = transcript::issue($user->id, 'Valley College Registrar');
        $issue = $DB->get_record('local_academictranscript_issue', ['id' => $issue->id], '*', MUST_EXIST);

        $pdf = builder::render(json_decode($issue->snapshot, true), $issue);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/ByteRange', $pdf);
        $text = $this->parse($pdf)->getText();
        $this->assertStringContainsString($issue->transcriptno, $text);
        $this->assertStringContainsString(transcript::format_code($issue->verifycode), $text);
        $this->assertStringContainsString('Valley College Registrar', $text);

        $unofficial = builder::render(transcript::build($user->id), null);
        $this->assertStringNotContainsString('/ByteRange', $unofficial);
    }

    /**
     * Letters with accents are printed, and the parser reads them back (the fonts of the plugin have them).
     */
    public function test_render_handles_accented_names(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Zoë', 'lastname' => 'Çelik']);

        $pdf = builder::render(transcript::build($user->id), null);

        $text = $this->parse($pdf)->getText();
        $this->assertStringContainsString('Zoë Çelik', $text);
    }

    /**
     * Names with an ampersand are printed as they were typed: "Sales & Marketing", never "Sales &amp; Marketing". That
     * holds for the title of a course, the program in the student box, and a description in the Transcript Key.
     */
    public function test_pdf_prints_names_with_ampersand(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall 2026']);
        $program = $generator->create_program(['name' => 'Arts & Sciences']);
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz']);
        $generator->create_student_record(['userid' => $user->id, 'programid' => $program->id]);
        $course = $generator->create_course_with_fields(['fullname' => 'BUS 101 - Sales & Marketing', 'code' => 'BUS 101',
            'hours' => 3, 'hourtype' => 'credit', 'termid' => $term->id]);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        scale::replace_grades([
            ['letter' => 'G', 'minpct' => 50, 'maxpct' => 100, 'points' => 4.0, 'ingpa' => 1, 'earns' => 1,
                'description' => 'Good & solid'],
            ['letter' => 'U', 'minpct' => 0, 'maxpct' => 49, 'points' => 0.0, 'ingpa' => 1, 'earns' => 0,
                'description' => 'Not yet'],
        ]);

        $pdf = builder::render(transcript::build($user->id), null);

        $text = $this->parse($pdf)->getText();
        $this->assertStringContainsString('Sales & Marketing', $text);
        $this->assertStringContainsString('Arts & Sciences', $text);
        $this->assertStringContainsString('Good & solid', $text);
        $this->assertStringNotContainsString('&amp;', $text);
    }

    /**
     * The Transcript Key page, the last page, names the student with the Student ID.
     */
    public function test_key_page_shows_student_id(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz']);
        $generator->create_student_record(['userid' => $user->id, 'studentid' => 'S-1001']);

        $pdf = builder::render(transcript::build($user->id), null);

        $pages = $this->parse($pdf)->getPages();
        $text = end($pages)->getText();
        $this->assertStringContainsString(get_string('transcriptkey', 'local_academictranscript'), $text);
        $this->assertStringContainsString('ID S-1001', $text);
    }

    /**
     * With a Student ID, the Transcript Key page shows the name, a middle dot and the ID on one line.
     */
    public function test_key_page_shows_name_and_student_id_on_one_line(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz']);
        $generator->create_student_record(['userid' => $user->id, 'studentid' => 'S-1001']);

        $pdf = builder::render(transcript::build($user->id), null);

        $pages = $this->parse($pdf)->getPages();
        $this->assertMatchesRegularExpression('/^Ana Ruiz · ID S-1001$/m', end($pages)->getText());
    }

    /**
     * Without a Student ID, the Transcript Key page names the student alone, with no ID after the name.
     */
    public function test_key_page_shows_name_alone_without_student_id(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz']);

        $pdf = builder::render(transcript::build($user->id), null);

        $pages = $this->parse($pdf)->getPages();
        $text = end($pages)->getText();
        $this->assertStringContainsString(get_string('transcriptkey', 'local_academictranscript'), $text);
        $this->assertMatchesRegularExpression('/^Ana Ruiz$/m', $text);
    }

    /**
     * Without a School name setting, the Academic Record and the Transcript Key use the full name of the site. The Key
     * page does not start a line with a dot and the registrar's office, and its contact box has the name where
     * {school} stands in the text.
     */
    public function test_pdf_uses_the_site_name_without_a_school_name(): void {
        set_config('schoolname', '', 'local_academictranscript');
        $this->set_site_name('Test Academy');
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz']);

        $pdf = builder::render(transcript::build($user->id), null);

        $pages = $this->parse($pdf)->getPages();
        $this->assertStringContainsString('Test Academy', $pages[0]->getText());
        $keytext = end($pages)->getText();
        $this->assertMatchesRegularExpression('/^Test Academy · Office of the Registrar$/m', $keytext);
        $this->assertStringContainsString('Office of the Registrar, Test Academy, [Street Address]', $keytext);
        foreach ($pages as $page) {
            $this->assertDoesNotMatchRegularExpression('/^\s*· Office of the Registrar/m', $page->getText());
        }
    }

    /**
     * The School name setting is printed instead of the name of the site.
     */
    public function test_pdf_prints_the_school_name_setting(): void {
        set_config('schoolname', 'Riverside College', 'local_academictranscript');
        $this->set_site_name('Test Academy');
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz']);

        $pdf = builder::render(transcript::build($user->id), null);

        $pages = $this->parse($pdf)->getPages();
        $this->assertStringContainsString('Riverside College', $pages[0]->getText());
        $this->assertMatchesRegularExpression('/^Riverside College · Office of the Registrar$/m', end($pages)->getText());
        $this->assertStringNotContainsString('Test Academy', $this->parse($pdf)->getText());
    }

    /**
     * A record that needs a second page starts it with a line that names the student and the Student ID.
     */
    public function test_continued_line_names_the_student_and_the_student_id(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz']);
        $generator->create_student_record(['userid' => $user->id, 'studentid' => 'S-1001']);
        $this->post_many_grades($user);

        $pdf = builder::render(transcript::build($user->id), null);

        $pages = $this->parse($pdf)->getPages();
        $this->assertGreaterThanOrEqual(3, count($pages), 'two pages of record, then the Transcript Key');
        $this->assertMatchesRegularExpression('/^Academic record of Ana Ruiz · ID S-1001 \(continued\)$/m', $pages[1]->getText());
    }

    /**
     * Without a Student ID, the line that starts a later page of the record names the student alone, with no empty
     * "ID" after a dot.
     */
    public function test_continued_line_has_no_id_for_a_student_without_one(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz']);
        $this->post_many_grades($user);

        $pdf = builder::render(transcript::build($user->id), null);

        $pages = $this->parse($pdf)->getPages();
        $this->assertGreaterThanOrEqual(3, count($pages), 'two pages of record, then the Transcript Key');
        $this->assertMatchesRegularExpression('/^Academic record of Ana Ruiz \(continued\)$/m', $pages[1]->getText());
        $this->assertDoesNotMatchRegularExpression('/· ID\s+\(/', $this->parse($pdf)->getText());
    }

    /**
     * Post a grade to a student for each of ten courses in each of ten terms, which is more than a page of record holds.
     *
     * @param \stdClass $user The student
     */
    private function post_many_grades(\stdClass $user): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $terms = [];
        for ($i = 1; $i <= 10; $i++) {
            $terms[] = $generator->create_term(['name' => 'Term ' . $i]);
        }
        for ($i = 1; $i <= 10; $i++) {
            $course = $this->getDataGenerator()->create_course();
            foreach ($terms as $term) {
                $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $course->id, 'termid' => $term->id]);
            }
        }
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
     * Read a PDF with the parser that comes with the plugin.
     *
     * @param string $pdf
     * @return \Smalot\PdfParser\Document
     */
    private function parse(string $pdf): \Smalot\PdfParser\Document {
        document_text::load_pdfparser();
        return (new \Smalot\PdfParser\Parser())->parseContent($pdf);
    }
}
