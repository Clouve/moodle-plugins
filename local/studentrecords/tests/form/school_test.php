<?php

namespace local_studentrecords\form;

#[\PHPUnit\Framework\Attributes\CoversClass(school::class)]
/**
 * Tests for the setup wizard's school form: the percent settings of the Calendar step.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\form\school
 */
final class school_test extends \advanced_testcase {
    /**
     * Start each test as an admin on a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The form of the Calendar step.
     *
     * @return school
     */
    private function calendar_form(): school {
        return new school(null, ['step' => 'calendar']);
    }

    /**
     * The at-risk percent is a percent from 0 to 100, or empty.
     *
     * @dataProvider atrisk_provider
     * @param string $value
     * @param bool $accepted
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('atrisk_provider')]
    public function test_the_at_risk_percent_goes_from_0_to_100(string $value, bool $accepted): void {
        $errors = $this->calendar_form()->validation(['atriskgrade' => $value], []);

        if ($accepted) {
            $this->assertArrayNotHasKey('atriskgrade', $errors);
        } else {
            $this->assertSame(get_string('err_percent0', 'local_studentrecords'), $errors['atriskgrade']);
        }
    }

    /**
     * Data for test_the_at_risk_percent_goes_from_0_to_100.
     *
     * @return array
     */
    public static function atrisk_provider(): array {
        return [
            'empty' => ['', true],
            'zero' => ['0', true],
            'the default' => ['70', true],
            'part of a percent' => ['72.5', true],
            'one hundred' => ['100', true],
            'too high' => ['100.5', false],
            'below zero' => ['-1', false],
            'not a number' => ['seventy', false],
        ];
    }

    /**
     * The minimum attendance still has to be more than 0: only the at-risk percent takes 0.
     */
    public function test_the_attendance_minimum_still_refuses_zero(): void {
        $errors = $this->calendar_form()->validation(['minattendance' => '0'], []);

        $this->assertSame(get_string('err_percent', 'local_studentrecords'), $errors['minattendance']);
    }

    /**
     * The field sits on the Calendar step, right after the attendance minimum.
     */
    public function test_the_at_risk_percent_follows_the_attendance_minimum(): void {
        $html = $this->calendar_form()->render();

        $this->assertStringContainsString('id="id_atriskgrade"', $html);
        $this->assertGreaterThan(strpos($html, 'id="id_minattendance"'), strpos($html, 'id="id_atriskgrade"'));
        $this->assertLessThan(strpos($html, 'id="id_ldamoodle"'), strpos($html, 'id="id_atriskgrade"'));
    }
}
