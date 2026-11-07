<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(admissions::class)]
/**
 * Tests for the admissions rules that need no database: student ID format, withdrawal dates, checklist scope and
 * missing documents, IPEDS category.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\admissions
 */
final class admissions_test extends \advanced_testcase {
    /**
     * Start each test with a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Student IDs: RVC-2026-0001 style, the next number after the ones in use in the same format.
     */
    public function test_student_ids(): void {
        $this->assertSame('RVC-2026-0001', admissions::format_id('RVC', '2026', 1, 4, '-'));
        $this->assertSame('RVC20260042', admissions::format_id('RVC', '2026', 42, 4, ''));
        $this->assertSame('S-12345', admissions::format_id('S', '', 12345, 4, '-'));
        $this->assertSame('007', admissions::format_id('', '', 7, 3, '-'));
        $existing = ['RVC-2026-0001', 'RVC-2026-0009', 'rvc-2026-0003', 'RVC-2025-0050', 'TEST-0004', 'RVC-2026-ABC'];
        $this->assertSame(10, admissions::next_seq($existing, 'RVC', '2026', '-'));
        $this->assertSame(51, admissions::next_seq($existing, 'RVC', '2025', '-'));
        $this->assertSame(1, admissions::next_seq($existing, 'RVC', '2027', '-'));
        $this->assertSame(5, admissions::next_seq($existing, 'TEST', '', '-'));
    }

    /**
     * A withdrawal needs both dates; attendance cannot end after the withdrawal.
     */
    public function test_status_problems(): void {
        $this->assertSame([], admissions::status_problems('active', null, null));
        $this->assertSame(
            ['withdrawdate' => 'err_withdrawdate', 'lastattended' => 'err_lastattended'],
            admissions::status_problems('withdrawn', null, null)
        );
        $this->assertSame([], admissions::status_problems('withdrawn', 2000, 1000));
        $this->assertSame(['lastattended' => 'err_attendedafter'], admissions::status_problems('withdrawn', 1000, 2000));
    }

    /**
     * Checklist scope: every scope a document names must match the program; required documents not received or
     * waived are missing; optional ones never are.
     */
    public function test_checklist(): void {
        $ma = ['id' => 6, 'levelid' => 2, 'credentialtype' => 'master'];
        $gc = ['id' => 2, 'levelid' => 2, 'credentialtype' => 'certificate'];
        $esl = ['id' => 23, 'levelid' => 4, 'credentialtype' => 'certificate'];
        $this->assertTrue(admissions::applies(['programid' => null, 'levelid' => null, 'credentialtype' => null], $ma));
        $this->assertTrue(admissions::applies(['levelid' => 2, 'credentialtype' => 'certificate'], $gc));
        $this->assertFalse(admissions::applies(['levelid' => 2, 'credentialtype' => 'certificate'], $esl));
        $this->assertFalse(admissions::applies(['levelid' => 2, 'credentialtype' => 'certificate'], $ma));
        $this->assertTrue(admissions::applies(['programid' => 23], $esl));
        $this->assertFalse(admissions::applies(['programid' => 23], null));
        $this->assertTrue(admissions::applies([], null));
        $docs = [(object)['id' => 1, 'required' => 1], (object)['id' => 2, 'required' => 1], (object)['id' => 3, 'required' => 0],
            (object)['id' => 4, 'required' => 1]];
        $missing = admissions::missing($docs, [1 => 'received', 2 => 'missing', 4 => 'waived']);
        $this->assertSame([2], array_map(fn($d) => $d->id, $missing));
        $this->assertCount(3, admissions::missing($docs, []));
    }

    /**
     * IPEDS category: nonresident first, then Hispanic, then one race, two or more, else unknown.
     */
    public function test_ipeds_category(): void {
        $this->assertSame('nonresident', admissions::ipeds_category('yes', 'white', true));
        $this->assertSame('hispanic', admissions::ipeds_category('yes', 'white,black', false));
        $this->assertSame('asian', admissions::ipeds_category('no', 'asian', false));
        $this->assertSame('twoormore', admissions::ipeds_category('notreported', 'asian,white', false));
        $this->assertSame('unknown', admissions::ipeds_category(null, 'notreported', false));
        $this->assertSame('unknown', admissions::ipeds_category(null, null, false));
    }

    /**
     * An applicant who is signed in with the rights of a manager, and the data of a decision.
     *
     * @return array student, decision
     */
    private function applicant_who_is_staff(): array {
        global $DB;
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $student = $gen->create_user_student(['status' => 'applicant']);
        $role = (int)$DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($role, (int)$student->userid, \context_system::instance()->id);
        $this->setUser((int)$student->userid);
        return ['student' => $student, 'decision' => ['decision' => 'admitted', 'decisiondate' => time(), 'reason' => '',
            'conditions' => '']];
    }

    /**
     * Nobody records an admission decision on their own record: it writes the decision and moves the status.
     */
    public function test_nobody_decides_their_own_admission(): void {
        global $DB;
        $s = $this->applicant_who_is_staff();

        try {
            admissions::record_decision($s['student'], $s['decision']);
            $this->fail('A person admitted themselves.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_ownrecord', $e->errorcode);
        }

        $this->assertSame(0, $DB->count_records('local_studentrecords_admdec'));
        $this->assertSame('applicant', students::get((int)$s['student']->id)->status);
    }

    /**
     * The status of one's own record doesn't move by any other way either.
     */
    public function test_nobody_sets_their_own_status(): void {
        $s = $this->applicant_who_is_staff();

        try {
            admissions::set_status((int)$s['student']->id, 'graduated', time());
            $this->fail('A person set their own status.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_ownrecord', $e->errorcode);
        }

        $this->assertSame('applicant', students::get((int)$s['student']->id)->status);
    }
}
