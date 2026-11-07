<?php

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
 * @license    https://opensource.org/license/mit MIT
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
     * An Official PDF has the QR code to the verify page next to the signature, with its caption. An Unofficial copy has
     * neither, because it can't be verified.
     */
    public function test_only_official_pdf_has_the_qr_code(): void {
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $caption = get_string('qr_scan', 'local_academictranscript');

        $issue = transcript::issue($user->id, 'Valley College');
        $official = builder::render(json_decode($issue->snapshot, true), $issue);
        $unofficial = builder::render(transcript::build($user->id), null);

        $this->assertStringContainsString($caption, $this->parse($official)->getText());
        $this->assertStringNotContainsString($caption, $this->parse($unofficial)->getText());

        // TCPDF draws a QR code as hundreds of small filled squares.
        $squares = fn(string $pdf) => substr_count($this->parse($pdf)->getPages()[0]->get('Contents')->getContent(), ' re');
        $this->assertGreaterThan($squares($unofficial) + 200, $squares($official));
    }

    /**
     * An older issued copy still draws: its rows get their hours from "credits" and it needs no unit fields of its own.
     */
    public function test_an_older_issued_copy_still_draws(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $course->id,
            'termid' => $generator->create_term()->id, 'coursecode' => 'BIO 101', 'coursename' => 'Cell Biology', 'hours' => 3,
            'grade' => 'A']);
        $issue = transcript::issue($user->id, 'Valley College');
        $data = json_decode($issue->snapshot, true);
        unset($data['unittype'], $data['unitlabel'], $data['unitshort'], $data['credential']);
        $data['version'] = 2;
        foreach ($data['terms'][0]['rows'] as $i => $row) {
            $data['terms'][0]['rows'][$i]['credits'] = $row['hours'];
            unset($data['terms'][0]['rows'][$i]['hours']);
        }

        $pdf = builder::render(transcript::stored(json_encode($data)), $issue);

        $text = $this->parse($pdf)->getText();
        $this->assertStringContainsString('BIO 101', $text);
        $this->assertStringContainsString('Cell Biology', $text);
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
     * Hours print as they are: 3.58 is not rounded to 3.6.
     */
    public function test_hours_keep_their_decimals(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $user = $this->getDataGenerator()->create_user();
        $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $this->getDataGenerator()->create_course()->id,
            'termid' => $generator->create_term()->id, 'grade' => 'A', 'hours' => 3.58]);

        $text = $this->parse(builder::render(transcript::build($user->id), null))->getText();

        $this->assertStringContainsString('3.58', $text);
    }

    /**
     * An Unofficial PDF draws the key of the scale the transcript was counted on, and an issued copy draws the key it stored.
     */
    public function test_the_key_comes_from_the_transcript(): void {
        global $DB;
        if (!class_exists('\\local_studentrecords\\local\\registration')) {
            $this->markTestSkipped('Student Records is not installed.');
        }
        set_config('source', 'studentrecords', 'local_academictranscript');
        $this->setAdminUser();
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $user = $this->getDataGenerator()->create_user();
        $sr->create_student(['userid' => $user->id, 'studentid' => 'S-1']);
        $sr->create_scale();
        $DB->set_field('local_studentrecords_grade', 'description', 'Excellent work', ['letter' => 'A']);

        $data = transcript::build($user->id);
        $this->assertStringContainsString('Excellent work', $this->parse(builder::render($data, null))->getText());

        $data['key'] = [['grade' => 'Q', 'points' => '9.0', 'percent' => '', 'description' => 'Kept from the issue',
            'rowspan' => 1, 'ismark' => false, 'ingpa' => true]];
        $text = $this->parse(builder::render($data, null))->getText();
        $this->assertStringContainsString('Kept from the issue', $text);
        $this->assertStringNotContainsString('Excellent work', $text);
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
     * The pages are drawn with the renamed fonts. When an HTML style names a font that TCPDF doesn't know, TCPDF goes on with
     * the current font and says nothing, so this looks at the fonts each page really uses.
     */
    public function test_pages_are_drawn_with_the_renamed_fonts(): void {
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $issue = transcript::issue($user->id, 'Valley College');

        $pdf = builder::render(json_decode($issue->snapshot, true), $issue);

        preg_match_all('~/BaseFont /[A-Z]{6}\+(\S+) /Name /F(\d+) ~', $pdf, $matches);
        $names = array_combine($matches[2], $matches[1]);
        $used = [];
        foreach ($this->parse($pdf)->getPages() as $page) {
            preg_match_all('~/F(\d+) [\d.]+ Tf~', $page->get('Contents')->getContent(), $calls);
            foreach ($calls[1] as $number) {
                $used[$names[$number]] = true;
            }
        }
        $fonts = ['ClouveSans-Bold', 'ClouveSans-Italic', 'ClouveSans-Regular', 'ClouveSans-SemiBold', 'ClouveSerif-Bold'];
        $this->assertSame([], array_diff($fonts, array_keys($used)));
    }

    /**
     * Every font family that the builder names in HTML is registered. For a family it doesn't know, TCPDF goes on with the
     * current font and says nothing, so a typo in one of these styles would change the look of a transcript without an error.
     */
    public function test_html_styles_name_registered_font_families(): void {
        $source = file_get_contents((new \ReflectionClass(builder::class))->getFileName());
        preg_match_all('/font-family:(\w+)/', $source, $matches);
        $doc = new document('P', 'mm', 'LETTER', true, 'UTF-8');
        $doc->add_fonts();
        $doc->SetFont('helvetica', '', 9);

        $this->assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $family) {
            $this->assertSame($family, $doc->getFontFamilyName($family));
        }
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
