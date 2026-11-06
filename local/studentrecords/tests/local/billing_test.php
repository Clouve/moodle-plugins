<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(billing::class)]
/**
 * Tests for billing: amounts, which price applies, charge amounts, running balance, and account lines in the database.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\billing
 */
final class billing_test extends \advanced_testcase {
    /** @var \local_studentrecords_generator */
    private $generator;

    /**
     * Billing on, with the admin working.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $this->generator->set_settings(['billing' => 1]);
    }

    /**
     * An example price list: bachelor and graduate tuition per credit, ESL per term, fees.
     *
     * @return array
     */
    protected function fees(): array {
        $f = fn($id, $kind, $basis, $amount, $scope, $scopeid, $active = 1) => (object)['id' => $id, 'kind' => $kind,
            'basis' => $basis, 'amount' => $amount, 'scope' => $scope, 'scopeid' => $scopeid, 'active' => $active];
        return [
            $f(1, 'tuition', 'credithour', '300.00', 'program', 1),
            $f(2, 'tuition', 'credithour', '500.00', 'all', null),
            $f(3, 'tuition', 'term', '1500.00', 'program', 23),
            $f(4, 'fee', 'term', '150.00', 'all', null),
            $f(5, 'fee', 'manual', '10.00', 'all', null),
            $f(6, 'fee', 'once', '50.00', 'program', 23),
            $f(7, 'tuition', 'credithour', '450.00', 'level', 2),
            $f(8, 'tuition', 'credithour', '999.00', 'program', 6, 0),
            $f(9, 'fee', 'late', '25.00', 'all', null),
        ];
    }

    /**
     * Money shown in US dollars; amounts typed by staff.
     */
    public function test_money(): void {
        $this->assertSame('$1,500.00', billing::money(1500));
        $this->assertSame('-$25.50', billing::money(-25.5));
        $this->assertSame('$0.00', billing::money(-0.001));
        $this->assertSame(1500.0, billing::parse_amount('$1,500'));
        $this->assertSame(4.69, billing::parse_amount('4.69'));
        $this->assertNull(billing::parse_amount('4.695'));
        $this->assertNull(billing::parse_amount('-5'));
        $this->assertNull(billing::parse_amount('ten'));
        $this->assertNull(billing::parse_amount(''));
    }

    /**
     * A comma is a thousands comma or the amount is refused: 1,50 must not become 150.00.
     *
     * @dataProvider parse_amount_provider
     * @param string $text
     * @param float|null $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('parse_amount_provider')]
    public function test_parse_amount(string $text, ?float $expected): void {
        $this->assertSame($expected, billing::parse_amount($text));
    }

    /**
     * Data for test_parse_amount.
     *
     * @return array
     */
    public static function parse_amount_provider(): array {
        return [
            'plain' => ['1500', 1500.0],
            'cents' => ['0.07', 0.07],
            'thousands' => ['1,500.50', 1500.5],
            'millions' => ['$12,345,678.90', 12345678.9],
            'dollar and space' => ['$ 25', 25.0],
            'spaces around' => ['  25.5 ', 25.5],
            'largest' => ['9,999,999,999.99', 9999999999.99],
            'decimal comma' => ['1,50', null],
            'comma in the wrong place' => ['15,00', null],
            'commas everywhere' => ['1,2,3', null],
            'two dollars' => ['$$5', null],
            'dollar after' => ['5$', null],
            'more than the table holds' => ['10000000000', null],
            'exponent' => ['1e5', null],
        ];
    }

    /**
     * An amount times hours or a percent is exact and a half cent goes up, on every PHP version. On PHP 8.4 round() gives
     * 3.07 for 2.05 x 1.5, and PHP 8.3 gives 3.08.
     *
     * @dataProvider times_provider
     * @param float $amount
     * @param float $factor
     * @param int $per
     * @param float $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('times_provider')]
    public function test_times(float $amount, float $factor, int $per, float $expected): void {
        $this->assertSame($expected, billing::times($amount, $factor, $per));
    }

    /**
     * Data for test_times.
     *
     * @return array
     */
    public static function times_provider(): array {
        return [
            'float just under the half' => [2.05, 1.5, 1, 3.08],
            'half cent' => [1.15, 0.5, 1, 0.58],
            'small' => [0.35, 0.5, 1, 0.18],
            'large' => [1012.45, 0.5, 1, 506.23],
            'hours' => [4.69, 320, 1, 1500.8],
            'whole' => [300, 3, 1, 900.0],
            'half of a price' => [1.15, 50, 100, 0.58],
            'odd percent' => [99.99, 33.33, 100, 33.33],
            'percent with decimals' => [1234.5, 12.5, 100, 154.31],
            'one cent' => [0.01, 50, 100, 0.01],
            'nothing' => [100, 0, 100, 0.0],
            'all' => [100, 100, 100, 100.0],
        ];
    }

    /**
     * Whole numbers divide with a half going away from zero.
     */
    public function test_divide(): void {
        $this->assertSame(3, billing::divide(5, 2));
        $this->assertSame(-3, billing::divide(-5, 2));
        $this->assertSame(2, billing::divide(7, 3));
        $this->assertSame(0, billing::divide(0, 7));
    }

    /**
     * Tuition: program before level before all; inactive items and items not charged when registering ignored.
     */
    public function test_pick_tuition(): void {
        $fees = $this->fees();
        $this->assertSame(1, billing::pick_tuition($fees, 1, 1, 'credit')->id, 'BSBA: program price');
        $this->assertSame(7, billing::pick_tuition($fees, 6, 2, 'credit')->id, 'MBA: level price (program price not active)');
        $this->assertSame(2, billing::pick_tuition($fees, 99, 3, 'credit')->id, 'other program: price for all');
        $this->assertSame(3, billing::pick_tuition($fees, 23, 4, 'clock')->id, 'ESL: per term');
        $this->assertSame(2, billing::pick_tuition($fees, null, null, 'credit')->id, 'no program: price for all');
        $this->assertNull(billing::pick_tuition([$fees[4]], 1, 1, 'credit'));
        $this->assertNull(billing::pick_tuition($fees, 1, 1, 'clock'), 'BSBA student, clock hour course: no price per credit hour');
        $this->assertSame(3, billing::pick_tuition($fees, 23, 4, 'credit')->id, 'ESL per term: any course');
        $this->assertNull(billing::pick_tuition($fees, 1, 1, null), 'hours of the course not known: no hourly price');
    }

    /**
     * Prices per credit hour only on credit hour courses, per clock hour only on clock hour courses.
     */
    public function test_fits_unit(): void {
        $this->assertTrue(billing::fits_unit(['basis' => 'credithour'], 'credit'));
        $this->assertFalse(billing::fits_unit(['basis' => 'credithour'], 'clock'));
        $this->assertTrue(billing::fits_unit(['basis' => 'clockhour'], 'clock'));
        $this->assertFalse(billing::fits_unit(['basis' => 'clockhour'], 'credit'));
        $this->assertFalse(billing::fits_unit(['basis' => 'clockhour'], null));
        $this->assertTrue(billing::fits_unit(['basis' => 'term'], 'clock'));
        $this->assertTrue(billing::fits_unit(['basis' => 'course'], null));
    }

    /**
     * Fees charged by themselves: active fees for all or for the student's program; not staff-added or late fees.
     */
    public function test_auto_fees(): void {
        $ids = fn($list) => array_map(fn($f) => $f->id, $list);
        $this->assertSame([4], $ids(billing::auto_fees($this->fees(), 1, 1, 'credit')));
        $this->assertSame([4, 6], $ids(billing::auto_fees($this->fees(), 23, 4, 'clock')));
    }

    /**
     * Charge amounts: per hour times the course hours, rounded to cents; others the list price.
     */
    public function test_line_amount(): void {
        $fees = $this->fees();
        $this->assertSame(900.0, billing::line_amount($fees[0], 3));
        $this->assertSame(1500.0, billing::line_amount($fees[2], 320));
        $this->assertSame(1500.8, billing::line_amount(['basis' => 'clockhour', 'amount' => '4.69'], 320));
        $this->assertSame(150.0, billing::line_amount($fees[3], 3));
        $this->assertSame(3.08, billing::line_amount(['basis' => 'credithour', 'amount' => '2.05'], 1.5));
    }

    /**
     * Running balance in date order (same date: in the order added).
     */
    public function test_with_running(): void {
        $lines = billing::with_running([
            ['id' => 3, 'txndate' => 200, 'amount' => '-500.00'],
            ['id' => 1, 'txndate' => 100, 'amount' => '1500.00'],
            ['id' => 2, 'txndate' => 100, 'amount' => '150.00'],
            ['id' => 4, 'txndate' => 300, 'amount' => '-0.10'],
        ]);
        $this->assertSame([1, 2, 3, 4], array_map(fn($l) => $l->id, $lines));
        $this->assertSame([1500.0, 1650.0, 1150.0, 1149.9], array_map(fn($l) => $l->running, $lines));
    }

    /**
     * Who a price applies to.
     */
    public function test_applies(): void {
        $fees = $this->fees();
        $this->assertTrue(billing::applies($fees[0], 1, 1));
        $this->assertFalse(billing::applies($fees[0], 6, 2));
        $this->assertFalse(billing::applies($fees[0], null, null));
        $this->assertTrue(billing::applies($fees[6], 6, 2));
        $this->assertFalse(billing::applies($fees[6], 6, null));
        $this->assertTrue(billing::applies($fees[1], null, null));
    }

    /**
     * A reversal is a new line with the opposite amount, once: the first line is marked, and neither line can be reversed
     * again. A reason is needed.
     */
    public function test_reverse(): void {
        global $DB;
        $this->preventResetByRollback();
        $student = $this->generator->create_user_student();
        $lineid = $this->generator->create_charge(['studentid' => $student->id, 'amount' => 100.25]);

        try {
            billing::reverse($lineid, ' ');
            $this->fail('A reversal needs a reason.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_reasonneeded', $e->errorcode);
        }

        $reversal = billing::reverse($lineid, 'Entered twice');

        $this->assertSame(0.0, billing::balance((int)$student->id));
        $this->assertSame($reversal, (int)$DB->get_field(billing::TXN, 'reversedby', ['id' => $lineid]));
        $this->assertSame($lineid, (int)$DB->get_field(billing::TXN, 'reverses', ['id' => $reversal]));
        foreach ([$lineid, $reversal] as $again) {
            try {
                billing::reverse($again, 'Again');
                $this->fail('A line is reversed once.');
            } catch (\moodle_exception $e) {
                $this->assertSame('err_cannotreverse', $e->errorcode);
            }
        }
        $this->assertSame(0.0, billing::balance((int)$student->id));
    }

    /**
     * A charge with a refund on it can't be reversed: the student would get the same money back twice. Once the refund
     * line is reversed, it can.
     */
    public function test_reverse_of_a_refunded_charge(): void {
        $this->preventResetByRollback();
        $student = $this->generator->create_user_student();
        $chargeid = $this->generator->create_charge(['studentid' => $student->id, 'amount' => 1000]);
        $refundid = billing::add_line(['studentid' => $student->id, 'txntype' => 'refund', 'amount' => -400,
            'chargeid' => $chargeid, 'description' => 'Refund']);

        try {
            billing::reverse($chargeid, 'Wrong student');
            $this->fail('A refunded charge can\'t be reversed.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_chargerefunded', $e->errorcode);
        }
        $this->assertSame(600.0, billing::balance((int)$student->id));

        billing::reverse($refundid, 'Refund was a mistake');
        billing::reverse($chargeid, 'Wrong student');
        $this->assertSame(0.0, billing::balance((int)$student->id));
    }

    /**
     * Only Finance staff and the student see an account.
     */
    public function test_can_view(): void {
        $student = $this->generator->create_user_student();
        $other = $this->generator->create_user_student();

        $this->assertTrue(billing::can_view($student), 'admin');
        $this->setUser($student->userid);
        $this->assertTrue(billing::can_view($student));
        $this->assertFalse(billing::can_view($other));
    }

    /**
     * Labels of the cost estimate are plain text, because the page escapes them.
     */
    public function test_estimate_labels_are_plain_text(): void {
        $program = $this->generator->create_program();
        $student = $this->generator->create_user_student(['programid' => $program->id]);
        $this->generator->create_fee(['name' => 'Books & labs', 'kind' => 'fee', 'basis' => 'course', 'amount' => 20]);
        $this->generator->create_fee(['name' => 'Tuition & fees', 'kind' => 'tuition', 'basis' => 'credithour',
            'amount' => 100]);
        $term = $this->generator->create_term();
        $offer = $this->generator->create_offering(['courseid' => $this->generator->create_course(['code' => 'A&B 101'])->id,
            'termid' => $term->id]);

        $estimate = billing::estimate($student, [$offer]);

        $labels = implode(' | ', array_column($estimate['lines'], 'label'));
        $this->assertStringContainsString('Tuition & fees', $labels);
        $this->assertStringContainsString('Books & labs, A&B 101', $labels);
        $this->assertSame(320.0, $estimate['total']);
    }

    /**
     * The statement is made for all lines and for one term.
     */
    public function test_statement_pdf(): void {
        $student = $this->generator->create_user_student();
        $term = $this->generator->create_term(['name' => 'Fall & winter']);
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 10, 'termid' => $term->id]);

        $this->assertStringStartsWith('%PDF', billing::statement_pdf($student));
        $this->assertStringStartsWith('%PDF', billing::statement_pdf($student, (int)$term->id));
    }
}
