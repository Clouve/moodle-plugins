<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(drafts::class)]
/**
 * Tests for storing, showing, fixing and approving imported items.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\drafts
 */
final class drafts_test extends \advanced_testcase {
    /**
     * Start each test as an admin on a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Store the sheets of a file as drafts.
     *
     * @param array $sheets
     * @return int import id
     */
    protected function store(array $sheets): int {
        return drafts::store('catalog.xlsx', importer::build($sheets));
    }

    /**
     * Text from the file is escaped in a list of problems. get_string() does not do it.
     */
    public function test_problems_html_escapes_the_file(): void {
        $html = drafts::problems_html([
            ['error', 'problem_baddate', '<img src=x onerror=alert(1)>'],
            ['warning', 'problem_totalmismatch', ['code' => '<b>X</b>', 'stated' => 12.0, 'outline' => 9.0]],
            ['info', 'gap_noterms', null],
            ['warning', '<script>', null],
        ]);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
        $this->assertStringNotContainsString('<b>X', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('text-danger', $html);
    }

    /**
     * An item the database refuses stays pending with a message, and the items after it are still saved.
     */
    public function test_approve_goes_on_after_a_failed_item(): void {
        global $DB;
        $this->preventResetByRollback();
        $importid = $this->store(['Terms' => [
            ['Term', 'Start date', 'End date'],
            [str_repeat('Long term name ', 10), '2030-01-14', '2030-05-03'],
            ['Spring 2030', '2030-01-14', '2030-05-03'],
        ]]);
        $ids = array_keys($DB->get_records(drafts::TABLE, ['importid' => $importid, 'itemtype' => 'term']));

        [$applied, $messages] = drafts::approve($ids);

        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame(1, $applied);
        $this->assertCount(1, $messages);
        $this->assertTrue($DB->record_exists('local_studentrecords_term', ['name' => 'Spring 2030']));
        $this->assertSame(1, $DB->count_records(drafts::TABLE, ['importid' => $importid, 'itemtype' => 'term',
            'state' => 'pending']));
    }

    /**
     * An item with an error is not applied until it is fixed. The fix clears the error.
     */
    public function test_error_blocks_until_fixed(): void {
        global $DB;
        $importid = $this->store(['Terms' => [
            ['Term', 'Start date', 'End date'],
            ['Spring 2030', 'not a date', '2030-05-03'],
        ]]);
        $draft = $DB->get_record(drafts::TABLE, ['importid' => $importid, 'itemtype' => 'term']);
        $this->assertTrue(drafts::has_error($draft));
        [$applied] = drafts::approve([$draft->id]);
        $this->assertSame(0, $applied);

        drafts::fix((int)$draft->id, ['startdate' => '2030-01-14']);
        [$applied] = drafts::approve([$draft->id]);
        $this->assertSame(1, $applied);
        $this->assertTrue($DB->record_exists('local_studentrecords_term', ['name' => 'Spring 2030']));
    }

    /**
     * A fix can change the value of a school setting, but not which setting it writes.
     */
    public function test_fix_cannot_change_which_setting_is_written(): void {
        global $DB;
        $importid = $this->store(['School' => [['Field', 'Value'], ['School name', 'Riverside College']]]);
        $draft = $DB->get_record(drafts::TABLE, ['importid' => $importid, 'itemtype' => 'school']);

        drafts::fix((int)$draft->id, ['field' => 'billing', 'value' => 'Hill College']);
        drafts::approve([$draft->id]);

        $this->assertSame('Hill College', get_config('local_studentrecords', 'schoolname'));
        $this->assertFalse(get_config('local_studentrecords', 'billing'));
    }

    /**
     * A repeated import shows items already saved as "same".
     */
    public function test_repeated_import_is_same(): void {
        global $DB;
        $sheets = ['Courses' => [['Course code', 'Title', 'Credits', 'Unit type'], ['ART101', 'Drawing', '3', 'Credit']]];
        $first = $this->store($sheets);
        drafts::approve(array_keys($DB->get_records(drafts::TABLE, ['importid' => $first, 'itemtype' => 'course'])));

        $second = $this->store($sheets);

        $this->assertSame(['course' => ['same' => 1]], array_intersect_key(drafts::counts($second), ['course' => 1]));
    }

    /**
     * Text from a CSV file in the Windows encoding goes through to the database.
     */
    public function test_store_a_windows_csv(): void {
        global $DB;
        $importid = $this->store(['Courses' => sheet_reader::read_csv("Course code,Title,Credits\nART101,Caf\xE9,3\n")]);
        $draft = $DB->get_record(drafts::TABLE, ['importid' => $importid, 'itemtype' => 'course']);
        $this->assertSame("Caf\u{e9}", json_decode($draft->data, true)['title']);
    }

    /**
     * Rejecting only touches pending items, and they stay on record.
     */
    public function test_reject(): void {
        global $DB;
        $importid = $this->store(['Terms' => [['Term', 'Start date', 'End date'], ['Spring 2030', '2030-01-14', '2030-05-03']]]);
        $draft = $DB->get_record(drafts::TABLE, ['importid' => $importid, 'itemtype' => 'term']);
        $this->assertSame(1, drafts::reject([$draft->id]));
        $this->assertSame(0, drafts::reject([$draft->id]));
        $this->assertSame('rejected', $DB->get_field(drafts::TABLE, 'state', ['id' => $draft->id]));
    }
}
