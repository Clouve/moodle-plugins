<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(standing::class)]
/**
 * Tests for academic standing (SAP), holds and the grade freeze: the rules, and a run on the database.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\standing
 */
final class standing_test extends \advanced_testcase {
    /**
     * A run proposes a standing from the record, staff confirm it, and a confirmed standing is kept and read as the
     * previous one for the next term.
     */
    public function test_run_and_confirm(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $gen->create_scale();
        $DB->insert_record(standing::RULES, (object)['mingpa' => 3.0, 'mincompletion' => 67, 'active' => 1,
            'timemodified' => time()]);
        $program = $gen->create_program();
        $student = $gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-1',
            'programid' => $program->id]);
        $first = $gen->create_term(['startdate' => '2030-01-14', 'enddate' => '2030-05-03']);
        $second = $gen->create_term(['startdate' => '2030-08-19', 'enddate' => '2030-12-13']);
        foreach ([$first, $second] as $term) {
            $offer = $gen->create_offering(['courseid' => $gen->create_course()->id, 'termid' => $term->id]);
            $regid = $gen->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
            registration::set_grade($regid, 'C', null, 'staff');
        }

        $this->assertSame([1, 0], standing::run((int)$first->id));
        $row = $DB->get_record(standing::TABLE, ['studentid' => $student->id, 'termid' => $first->id]);
        $this->assertSame('warning', $row->standing);
        $this->assertSame('proposed', $row->state);
        $this->assertEquals(2.0, $row->gpa);
        $this->assertEquals(100, $row->completion);
        $this->assertEquals(2.5, $row->timeframe);

        $this->assertSame(1, standing::confirm([$row->id]));
        $this->assertSame(0, standing::confirm([$row->id]));
        $this->assertSame([0, 1], standing::run((int)$first->id));

        standing::run((int)$second->id);
        $next = $DB->get_record(standing::TABLE, ['studentid' => $student->id, 'termid' => $second->id]);
        $this->assertSame('probation', $next->standing);
        $this->assertSame('warning', $next->previous);
        $this->assertCount(2, standing::history((int)$student->id));
    }

    /**
     * Completion and time used are exact on every PHP version: 2050 of 8000 hundredths is 25.625%, which PHP 8.4 rounds
     * down through round().
     */
    public function test_measures_are_exact(): void {
        $m = standing::measures(['attempted' => 80.0, 'earned' => 20.5, 'gpa' => 3.0], 120.0, 0, 0);
        $this->assertSame(25.63, $m['completion']);
        $this->assertSame(66.67, $m['timeframe']);
        $this->assertNull(standing::measures(['attempted' => 0.0, 'earned' => 0.0, 'gpa' => null], null, 0, 0)['completion']);
        $this->assertNull(standing::measures(['attempted' => 3.0, 'earned' => 3.0, 'gpa' => null], null, 0, 0)['timeframe']);
    }

    /**
     * Below standard moves one step from the previous standing; maximum time means dismissal; good otherwise.
     */
    public function test_evaluate(): void {
        $rule = ['mingpa' => 3.0, 'mincompletion' => 67, 'maxyears' => 3, 'maxtimeframe' => null];
        $ok = ['gpa' => 3.2, 'completion' => 100.0, 'years' => 1.0, 'timeframe' => 30.0];
        $this->assertSame(['good', []], standing::evaluate($ok, $rule, 'probation'));
        $low = ['gpa' => 2.8] + $ok;
        $this->assertSame(['warning', ['gpa']], standing::evaluate($low, $rule, null));
        $this->assertSame(['probation', ['gpa']], standing::evaluate($low, $rule, 'warning'));
        $this->assertSame(['dismissal', ['gpa']], standing::evaluate($low, $rule, 'probation'));
        $this->assertSame(['dismissal', ['gpa']], standing::evaluate($low, $rule, 'dismissal'));
        $slow = ['completion' => 50.0] + $ok;
        $this->assertSame(['warning', ['completion']], standing::evaluate($slow, $rule, 'good'));
        $old = ['years' => 3.5] + $ok;
        $this->assertSame(['dismissal', ['maxtime']], standing::evaluate($old, $rule, null));
        $this->assertSame(['good', []], standing::evaluate(['gpa' => null] + $ok, $rule, null));
        $this->assertSame(['good', []], standing::evaluate($low, null, null));
        $over = ['timeframe' => 151.0] + $ok;
        $this->assertSame(['dismissal', ['maxtime']], standing::evaluate($over, ['maxtimeframe' => 150], null));
        $this->assertSame(['good', []], standing::evaluate(['gpa' => 3.0] + $ok, $rule, null));
    }

    /**
     * Measures: completion, timeframe and years.
     */
    public function test_measures(): void {
        $m = standing::measures(['attempted' => 12.0, 'earned' => 9.0, 'gpa' => 2.5], 36.0, 0, 0);
        $this->assertEquals(75.0, $m['completion']);
        $this->assertEquals(33.33, $m['timeframe']);
        $this->assertEquals(0.0, $m['years']);
        $start = 1798977600;
        $end = $start + (int)(2 * 365.25 * 86400);
        $m = standing::measures(['attempted' => 0.0, 'earned' => 0.0, 'gpa' => null], null, $start, $end);
        $this->assertNull($m['completion']);
        $this->assertNull($m['timeframe']);
        $this->assertEquals(2.0, $m['years']);
    }

    /**
     * Rule values from the academic rules as an example catalog words them.
     */
    public function test_rule_from_policies(): void {
        $graduate = ['good_standing_gpa_cumulative' => '3.0', 'completion_rate' => '67% of attempted credits (MML, MEDL)',
            'maximum_time' => '3 years', 'transfer_credit_max' => '25%'];
        $this->assertEquals(
            ['mingpa' => 3.0, 'mincompletion' => 67.0, 'maxyears' => 3.0, 'maxtransfer' => 25.0],
            standing::rule_from_policies($graduate)
        );
        $esl = ['good_standing_gpa_cumulative' => '80% per level component', 'completion_rate' => 'Attendance 80% per term',
            'maximum_time' => 'Up to 6 terms (96 weeks)', 'transfer_credit_max' => 'n/a'];
        $this->assertEquals(
            ['mingpa' => null, 'mincompletion' => null, 'maxyears' => null, 'maxtransfer' => null],
            standing::rule_from_policies($esl)
        );
        $ug = ['maximum_time' => '6 years (+ up to 2-year extension)', 'transfer_credit_max' => '75% of program credits'];
        $this->assertEquals(6.0, standing::rule_from_policies($ug)['maxyears']);
        $this->assertEquals(75.0, standing::rule_from_policies($ug)['maxtransfer']);
    }

    /**
     * Holds in force and the grade freeze.
     */
    public function test_holds_and_freeze(): void {
        $now = 2000000;
        $this->assertTrue(academic::hold_active(['startdate' => 1000, 'enddate' => null, 'timereleased' => null], $now));
        $this->assertFalse(academic::hold_active(['startdate' => 1000, 'enddate' => null, 'timereleased' => 1500], $now));
        $this->assertFalse(academic::hold_active(['startdate' => 3000000, 'enddate' => null, 'timereleased' => null], $now));
        $this->assertFalse(academic::hold_active(['startdate' => 1000, 'enddate' => 1999999, 'timereleased' => null], $now));
        $this->assertTrue(academic::hold_active(['startdate' => 1000, 'enddate' => 2000000, 'timereleased' => null], $now));
        $this->assertFalse(registration::is_frozen('', '2027-06-01'));
        $this->assertFalse(registration::is_frozen('2027-05-15', '2027-05-15'));
        $this->assertTrue(registration::is_frozen('2027-05-15', '2027-05-16'));
    }

    /**
     * A course retaken later doesn't change the standing of the term before: at the end of that term the first attempt
     * counted, and at the end of the next term the retake does.
     */
    public function test_a_later_retake_leaves_an_earlier_term_as_it_was(): void {
        global $DB;
        if (\core_component::get_component_directory('local_academictranscript') === null) {
            $this->markTestSkipped('Academic Transcript is not installed.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $gen->create_scale();
        $DB->insert_record(standing::RULES, (object)['mingpa' => 3.0, 'active' => 1, 'timemodified' => time()]);
        $student = $gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-1',
            'programid' => $gen->create_program()->id]);
        $first = $gen->create_term(['startdate' => '2030-01-14', 'enddate' => '2030-05-03']);
        $second = $gen->create_term(['startdate' => '2030-08-19', 'enddate' => '2030-12-13']);
        $course = $gen->create_course();
        foreach ([[$first, 'C'], [$second, 'A']] as [$term, $grade]) {
            $offer = $gen->create_offering(['courseid' => $course->id, 'termid' => $term->id]);
            $regid = $gen->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
            registration::set_grade($regid, $grade, null, 'staff');
        }

        $this->assertEquals(2.0, standing::compute($student, $first)['gpa']);
        $this->assertEquals(4.0, standing::compute($student, $second)['gpa']);
    }
}
