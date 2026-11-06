<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(shell::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(cards::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(homes::class)]
/**
 * Tests for the page shell rules: which menu item is current, Student Records paths, term weeks, home view choice.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\shell
 * @covers     \local_studentrecords\local\cards
 * @covers     \local_studentrecords\local\homes
 */
final class shell_test extends \basic_testcase {
    /**
     * The best match needs the same path and all of the item's own parameters; more matching parameters win.
     */
    public function test_best_match(): void {
        $items = [
            'a:home' => ['/local/studentrecords/index.php', ['view' => 'student']],
            'b:home' => ['/local/studentrecords/index.php', ['view' => 'staff']],
            'c:reports' => ['/local/studentrecords/billingreports.php', ['report' => 'owed']],
            'c:payments' => ['/local/studentrecords/billingreports.php', ['report' => 'payments']],
            'd:billing' => ['/local/studentrecords/billing.php', []],
        ];
        $this->assertSame('b:home', shell::best_match($items, '/local/studentrecords/index.php', ['view' => 'staff']));
        $this->assertNull(shell::best_match($items, '/local/studentrecords/index.php', []));
        $this->assertSame('c:payments', shell::best_match($items, '/local/studentrecords/billingreports.php', [
            'report' => 'payments', 'from' => '2026-01-01']));
        $this->assertSame('d:billing', shell::best_match($items, '/local/studentrecords/billing.php', ['q' => 'smith']));
        $this->assertNull(shell::best_match($items, '/local/studentrecords/refunds.php', []));
    }

    /**
     * Student Records and Academic Transcript pages get the menu; other pages do not.
     */
    public function test_is_sr_path(): void {
        $this->assertTrue(shell::is_sr_path('/local/studentrecords/index.php'));
        $this->assertTrue(shell::is_sr_path('/local/academictranscript/index.php'));
        $this->assertFalse(shell::is_sr_path('/my/index.php'));
        $this->assertFalse(shell::is_sr_path('/local/studentrecordsx/index.php'));
    }

    /**
     * Week of the term: 1-based, never past the last week, null outside the term.
     */
    public function test_term_week(): void {
        $start = 1767225600; // A Monday.
        $end = $start + 16 * 7 * DAYSECS - DAYSECS;
        $this->assertSame([1, 16], cards::term_week($start, $end, $start));
        $this->assertSame([10, 16], cards::term_week($start, $end, $start + 9 * 7 * DAYSECS + 3600));
        $this->assertSame([16, 16], cards::term_week($start, $end, $end));
        $this->assertNull(cards::term_week($start, $end, $start - 1));
        $this->assertNull(cards::term_week($start, $end, $end + 2 * DAYSECS));
    }

    /**
     * The home view asked for is used only if allowed; else the first allowed one.
     */
    public function test_pick(): void {
        $this->assertSame('finance', homes::pick('finance', ['staff', 'finance']));
        $this->assertSame('staff', homes::pick('aid', ['staff', 'finance']));
        $this->assertSame('staff', homes::pick('', ['staff']));
        $this->assertSame('', homes::pick('student', []));
    }
}
