<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(reports::class)]
/**
 * Tests for the reports and for safe downloads.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\reports
 */
final class reports_test extends \advanced_testcase {
    /**
     * Start each test as an admin on a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A cell a spreadsheet program would run as a formula becomes text. Numbers, dates and plain text stay as they are.
     *
     * @dataProvider safe_cell_provider
     * @param mixed $value
     * @param mixed $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('safe_cell_provider')]
    public function test_safe_cell($value, $expected): void {
        $this->assertSame($expected, reports::safe_cell($value));
    }

    /**
     * Data for test_safe_cell.
     *
     * @return array
     */
    public static function safe_cell_provider(): array {
        return [
            'formula' => ['=HYPERLINK("http://example.com")', "'=HYPERLINK(\"http://example.com\")"],
            'command' => ['=cmd|\' /C calc\'!A0', "'=cmd|' /C calc'!A0"],
            'plus' => ['+1+1', "'+1+1"],
            'at' => ['@SUM(1)', "'@SUM(1)"],
            'minus' => ['-2+3', "'-2+3"],
            'tab' => ["\t=1", "'\t=1"],
            'negative number' => ['-12.50', '-12.50'],
            'number' => [12, 12],
            'float' => [1.5, 1.5],
            'date' => ['2030-01-14', '2030-01-14'],
            'text' => ['Ana Ruiz', 'Ana Ruiz'],
            'empty' => ['', ''],
            'null' => [null, null],
        ];
    }

    /**
     * Rates round half up to one decimal, on every PHP version, and nothing to count gives null.
     */
    public function test_rate(): void {
        $this->assertSame(6.3, reports::rate(1, 16));
        $this->assertSame(33.3, reports::rate(1, 3));
        $this->assertSame(100.0, reports::rate(4, 4));
        $this->assertNull(reports::rate(0, 0));
    }

    /**
     * Plain text is for downloads: no HTML entities.
     */
    public function test_plain(): void {
        $this->assertSame('Arts & Sciences', reports::plain('Arts & Sciences'));
    }

    /**
     * A program's name reaches a download as text.
     */
    public function test_program_names(): void {
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $program = $sr->create_program(['code' => 'A&B', 'name' => 'Arts & Sciences']);
        $this->assertSame('A&B - Arts & Sciences', reports::program_names()[$program->id]);
    }

    /**
     * Enrollment counts each student once per term and program and adds up the hours.
     */
    public function test_enrollment(): void {
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $term = $sr->create_term(['name' => 'Spring']);
        $program = $sr->create_program();
        $user = $this->getDataGenerator()->create_user();
        $student = $sr->create_student(['userid' => $user->id, 'studentid' => 'S1', 'programid' => $program->id]);
        foreach ([3, 4] as $hours) {
            $offer = $sr->create_offering(['courseid' => $sr->create_course(['hours' => $hours])->id, 'termid' => $term->id]);
            $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        }
        $rows = reports::enrollment();
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['headcount']);
        $this->assertSame(1, $rows[0]['fulltime']);
        $this->assertEquals(7.0, $rows[0]['hours']);
        $this->assertSame('Spring', $rows[0]['term']);
    }

    /**
     * The graduation report leaves out people still in the admissions pipeline.
     */
    public function test_graduation(): void {
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $program = $sr->create_program();
        foreach (['graduated' => 'S1', 'active' => 'S2', 'applicant' => 'S3'] as $status => $id) {
            $user = $this->getDataGenerator()->create_user();
            $sr->create_student(['userid' => $user->id, 'studentid' => $id, 'programid' => $program->id, 'status' => $status]);
        }
        $rows = reports::graduation();
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['students']);
        $this->assertSame(1, $rows[0]['graduated']);
        $this->assertSame(50.0, $rows[0]['rate']);
    }
}
