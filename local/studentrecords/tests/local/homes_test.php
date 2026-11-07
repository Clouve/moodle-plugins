<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(homes::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(catalog::class)]
/**
 * Tests for the home pages: the teacher's list of students at risk.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\homes
 * @covers     \local_studentrecords\local\catalog
 */
final class homes_test extends \advanced_testcase {
    /** @var string The line about grades in the intro of the list, up to the percent. */
    private const GRADELINE = 'a course grade under ';

    /** @var \local_studentrecords_generator */
    private $gen;

    /**
     * Start with a clean site and no menu of an earlier test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        shell::reset();
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
    }

    /**
     * Forget the menu of this test.
     */
    protected function tearDown(): void {
        shell::reset();
        parent::tearDown();
    }

    /**
     * A student registered in a section linked to a Moodle course, signed in as the teacher of that course.
     *
     * @param float $percent the student's course total
     * @return array offer, regid
     */
    private function student_with_a_grade(float $percent): array {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $user = $gen->create_user(['firstname' => 'Lena', 'lastname' => 'Marsh']);
        $teacher = $gen->create_user();
        $gen->enrol_user($user->id, $course->id, 'student');
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $offer = $this->gen->create_offering(['courseid' => $this->gen->create_course()->id,
            'termid' => $this->gen->create_term()->id, 'moodlecourseid' => $course->id]);
        $student = $this->gen->create_student(['userid' => $user->id, 'studentid' => 'S-1']);
        $regid = $this->gen->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        $item = $gen->create_grade_item(['courseid' => $course->id, 'itemtype' => 'manual', 'itemname' => 'Final',
            'grademin' => 0, 'grademax' => 100]);
        \grade_item::fetch(['id' => $item->id])->update_final_grade((int)$user->id, $percent, 'local_studentrecords');
        grade_regrade_final_grades($course->id);
        $this->setUser($teacher);
        return ['offer' => $offer, 'regid' => $regid];
    }

    /**
     * A course grade under the school's percent lists the student. Until the school sets one, that is 70%.
     *
     * @dataProvider grade_provider
     * @param string|null $setting the school's setting
     * @param float $percent the student's course total
     * @param bool $listed whether the student is listed
     * @param string $line how the intro of the list words the percent, when anyone is listed
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('grade_provider')]
    public function test_a_low_grade_follows_the_setting(?string $setting, float $percent, bool $listed, string $line): void {
        if ($setting !== null) {
            $this->gen->set_settings(['atriskgrade' => $setting]);
        }
        $this->student_with_a_grade($percent);

        $html = homes::atrisk();

        $this->assertSame($listed, str_contains($html, 'Marsh'));
        if ($listed) {
            $this->assertStringContainsString(self::GRADELINE . $line . '.', $html);
        } else {
            $this->assertStringContainsString(get_string('risk_none', 'local_studentrecords'), $html);
        }
    }

    /**
     * Data for test_a_low_grade_follows_the_setting.
     *
     * @return array
     */
    public static function grade_provider(): array {
        return [
            'never set, under 70' => [null, 69.9, true, '70%'],
            'never set, at 70' => [null, 70, false, ''],
            'left empty, under 70' => ['', 69.9, true, '70%'],
            'left empty, at 70' => ['', 70, false, ''],
            'set higher, listed' => ['80', 75, true, '80%'],
            'set higher, at the line' => ['80', 80, false, ''],
            'set lower, under the old 70' => ['60', 65, false, ''],
            'set lower, under the new line' => ['60', 59, true, '60%'],
            'part of a percent' => ['72.5', 72, true, '72.5%'],
            'full marks are the line' => ['100', 99, true, '100%'],
            'zero lists nobody by grade' => ['0', 0, false, ''],
            'a value out of range counts as not set' => ['101', 69, true, '70%'],
            'a value that is no number counts as not set' => ['high', 69, true, '70%'],
        ];
    }

    /**
     * The reason for a listing is true: a grade under the line never shows rounded up to the line.
     *
     * @dataProvider shown_grade_provider
     * @param string|null $setting the school's setting
     * @param float $percent the student's course total
     * @param string $shown what the list shows
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('shown_grade_provider')]
    public function test_a_low_grade_shows_under_the_line(?string $setting, float $percent, string $shown): void {
        if ($setting !== null) {
            $this->gen->set_settings(['atriskgrade' => $setting]);
        }
        $this->student_with_a_grade($percent);

        $html = homes::atrisk();

        $this->assertStringContainsString('course grade ' . $shown, $html);
    }

    /**
     * Data for test_a_low_grade_shows_under_the_line.
     *
     * @return array
     */
    public static function shown_grade_provider(): array {
        return [
            'a whole percent is short' => [null, 65, '65%'],
            'rounds as before when that stays under' => [null, 65.4, '65%'],
            'just under the default line' => [null, 69.6, '69.6%'],
            'just under full marks' => ['100', 99.6, '99.6%'],
            'closer still' => ['100', 99.96, '99.96%'],
        ];
    }

    /**
     * With 0 the list leaves grades out, so its intro says nothing about them, and students under the attendance minimum
     * still show.
     */
    public function test_zero_leaves_grades_out_of_the_list(): void {
        $this->gen->set_settings(['atriskgrade' => '0', 'minattendance' => '75']);
        $s = $this->student_with_a_grade(10);
        $meeting = $this->gen->create_meeting(['offerid' => $s['offer']->id, 'date' => catalog::time_to_date(time())]);
        attendance::save_marks($meeting, [$s['regid'] => ['status' => 'absent']]);

        $html = homes::atrisk();

        $this->assertStringContainsString('Marsh', $html);
        $this->assertStringContainsString('under or near the 75% minimum.', $html);
        $this->assertStringNotContainsString(self::GRADELINE, $html);
    }
}
