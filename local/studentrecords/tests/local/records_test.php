<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(records::class)]
/**
 * Tests for saving records with their change history.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\records
 */
final class records_test extends \advanced_testcase {
    /**
     * Start each test with a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A failed save leaves no open transaction, so a caller that goes on (the import does) can still save.
     */
    public function test_failed_save_leaves_no_open_transaction(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->setAdminUser();
        try {
            records::save('local_studentrecords_level', ['name' => str_repeat('x', 300)], 'import');
            $this->fail('No exception');
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(\dml_exception::class, $e);
        }
        $this->assertFalse($DB->is_transaction_started());

        records::save('local_studentrecords_level', ['name' => 'Undergraduate'], 'import');
        $this->assertTrue($DB->record_exists('local_studentrecords_level', ['name' => 'Undergraduate']));
    }

    /**
     * A new record and a change are in the history, and saving the same values again writes nothing.
     */
    public function test_history(): void {
        $this->setAdminUser();
        $id = records::save('local_studentrecords_level', ['name' => 'Graduate'], 'form');
        $this->assertTrue(records::$lastchanged);
        records::save('local_studentrecords_level', ['id' => $id, 'name' => 'Graduate'], 'form');
        $this->assertFalse(records::$lastchanged);
        records::save('local_studentrecords_level', ['id' => $id, 'name' => 'Masters'], 'form');
        $history = records::get_history('local_studentrecords_level', $id);
        $this->assertSame('Masters', $history[0]->newvalue);
        $this->assertSame('Graduate', $history[0]->oldvalue);
    }

    /**
     * Numbers compare as numbers. Text that only looks like a number stays text, so a change to it is saved.
     *
     * @dataProvider same_provider
     * @param mixed $a
     * @param mixed $b
     * @param bool $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('same_provider')]
    public function test_same($a, $b, bool $expected): void {
        $this->assertSame($expected, records::same($a, $b));
    }

    /**
     * Data for test_same.
     *
     * @return array
     */
    public static function same_provider(): array {
        return [
            'a stored decimal' => ['3.00', 3, true],
            'a stored decimal and float' => ['120.50', 120.5, true],
            'null is empty' => [null, '', true],
            'a real change' => ['3.00', '3.50', false],
            'leading zeros are part of an ID' => ['007', '7', false],
            'a long number is text' => ['12345678901234567890', '12345678901234567891', false],
            'not scientific notation' => ['1e3', '1000', false],
        ];
    }
}
