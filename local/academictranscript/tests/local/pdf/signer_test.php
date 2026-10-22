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

#[\PHPUnit\Framework\Attributes\CoversClass(signer::class)]
/**
 * Tests for the digital signature of Official transcripts.
 *
 * The self-signed certificate is made once and kept in moodledata, so each test removes that folder first and gets a
 * new certificate.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_academictranscript\local\pdf\signer
 */
final class signer_test extends \advanced_testcase {
    /**
     * Load the PDF library and start each test without a self-signed certificate.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->libdir . '/pdflib.php');
        remove_dir($CFG->dataroot . '/local_academictranscript');
    }

    /**
     * With no school name in the settings, the self-signed certificate is issued to the site, under its full name.
     */
    public function test_selfsigned_certificate_uses_site_name_without_school_name(): void {
        set_config('schoolname', '', 'local_academictranscript');
        $this->set_site_name('Test Academy');

        $subject = $this->subject(signer::selfsigned());

        $this->assertSame('Test Academy - Office of the Registrar', $subject['CN']);
        $this->assertSame('Test Academy', $subject['O']);
    }

    /**
     * The school name in the settings wins over the name of the site.
     */
    public function test_selfsigned_certificate_uses_school_name(): void {
        set_config('schoolname', 'Riverside College', 'local_academictranscript');
        $this->set_site_name('Test Academy');

        $subject = $this->subject(signer::selfsigned());

        $this->assertSame('Riverside College - Office of the Registrar', $subject['CN']);
        $this->assertSame('Riverside College', $subject['O']);
    }

    /**
     * The name goes into the certificate as it is. A site name with an ampersand is not written as "&amp;".
     */
    public function test_selfsigned_certificate_does_not_escape_the_site_name(): void {
        set_config('schoolname', '', 'local_academictranscript');
        $this->set_site_name('Smith & Sons College');

        $subject = $this->subject(signer::selfsigned());

        $this->assertSame('Smith & Sons College - Office of the Registrar', $subject['CN']);
        $this->assertSame('Smith & Sons College', $subject['O']);
    }

    /**
     * A certificate name holds at most 64 characters. A long school name is cut, and the certificate is still made.
     */
    public function test_selfsigned_certificate_cuts_long_names_to_64_characters(): void {
        set_config('schoolname', str_repeat('A', 70), 'local_academictranscript');

        $subject = $this->subject(signer::selfsigned());

        $this->assertSame(str_repeat('A', 64), $subject['CN']);
        $this->assertSame(str_repeat('A', 64), $subject['O']);
    }

    /**
     * An uploaded certificate that cannot be read (here a file that is not a PKCS#12 file, with a password that
     * does not fit) is not used. The transcript is signed with the self-signed certificate, and developers are told.
     */
    public function test_bad_uploaded_certificate_falls_back_to_selfsigned(): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_academictranscript',
            'filearea' => 'signingcert',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'cert.p12',
        ], 'This is not a PKCS#12 file.');
        set_config('signingcertpassword', 'wrong', 'local_academictranscript');

        $credentials = signer::credentials();

        $this->assertSame('selfsigned', $credentials['source']);
        $this->assertDebuggingCalled();
    }

    /**
     * The signature names the registrar's office of the school and gives the reason for signing. Both are read back
     * from the signed PDF.
     */
    public function test_apply_signs_with_school_name_and_reason(): void {
        set_config('schoolname', 'Riverside College', 'local_academictranscript');

        $pdf = $this->signed_pdf();

        $this->assertSame('Riverside College - Office of the Registrar', $this->signature_entry($pdf, 'Name'));
        $this->assertSame('Official academic transcript', $this->signature_entry($pdf, 'Reason'));
    }

    /**
     * Without a school name the signature uses the full name of the site.
     */
    public function test_apply_signs_with_site_name_without_school_name(): void {
        set_config('schoolname', '', 'local_academictranscript');
        $this->set_site_name('Test Academy');

        $pdf = $this->signed_pdf();

        $this->assertSame('Test Academy - Office of the Registrar', $this->signature_entry($pdf, 'Name'));
    }

    /**
     * The words around the school name are language strings, so a translation or a site's own customisation changes
     * them in the certificate and in the signature.
     */
    public function test_certificate_and_signature_text_come_from_language_strings(): void {
        $this->customise_strings(['signername' => '{$a} Registrar', 'signaturereason' => 'Issued by the registrar']);
        set_config('schoolname', 'Riverside College', 'local_academictranscript');

        $pdf = $this->signed_pdf();

        $this->assertSame('Riverside College Registrar', $this->subject(signer::selfsigned())['CN']);
        $this->assertSame('Riverside College Registrar', $this->signature_entry($pdf, 'Name'));
        $this->assertSame('Issued by the registrar', $this->signature_entry($pdf, 'Reason'));
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
     * The subject of the certificate in some credentials: the common name (CN) and the organisation (O).
     *
     * @param array|null $credentials as returned by the signer
     * @return array
     */
    private function subject(?array $credentials): array {
        $this->assertNotNull($credentials);
        $certificate = openssl_x509_parse($credentials['cert']);
        $this->assertIsArray($certificate);
        return $certificate['subject'];
    }

    /**
     * A one-page PDF, signed by the signer.
     *
     * @return string
     */
    private function signed_pdf(): string {
        $document = new \pdf();
        $document->AddPage();
        $document->Write(0, 'Transcript');
        $this->assertTrue(signer::apply($document));
        return $document->Output('transcript.pdf', 'S');
    }

    /**
     * One entry of the signature in a signed PDF, such as Name or Reason.
     *
     * The PDF holds an entry as a text string in brackets: a byte order mark, then the text in UTF-16, big endian.
     * This reads plain text without brackets, which is all the tests use.
     *
     * @param string $pdf
     * @param string $key
     * @return string
     */
    private function signature_entry(string $pdf, string $key): string {
        $found = preg_match('~/' . $key . ' \(\xFE\xFF(.*?)\)~s', $pdf, $matches);
        $this->assertSame(1, $found, "The signature has no $key.");
        return mb_convert_encoding($matches[1], 'UTF-8', 'UTF-16BE');
    }

    /**
     * Replace language strings of the plugin, the way a site's own language customisation does.
     *
     * @param string[] $strings text by string name
     */
    private function customise_strings(array $strings): void {
        global $CFG;

        $folder = $CFG->langlocalroot . '/en_local';
        check_dir_exists($folder, true, true);
        $code = "<?php\n";
        foreach ($strings as $name => $text) {
            $code .= '$string[' . var_export($name, true) . '] = ' . var_export($text, true) . ";\n";
        }
        file_put_contents($folder . '/local_academictranscript.php', $code);
        get_string_manager()->reset_caches();
    }
}
