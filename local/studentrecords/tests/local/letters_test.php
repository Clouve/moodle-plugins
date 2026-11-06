<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(letters::class)]
/**
 * Tests for enrollment letters.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\letters
 */
final class letters_test extends \advanced_testcase {
    /**
     * Start each test as an admin on a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The term of a letter: the one running today, else the next, else the last.
     */
    public function test_pick_term(): void {
        $terms = [['id' => 1, 'startdate' => 100, 'enddate' => 200], ['id' => 2, 'startdate' => 300, 'enddate' => 400]];
        $this->assertSame(2, letters::pick_term($terms, 350));
        $this->assertSame(2, letters::pick_term($terms, 250));
        $this->assertSame(2, letters::pick_term($terms, 500));
        $this->assertNull(letters::pick_term([], 500));
    }

    /**
     * The facts are plain text, because the letter escapes them when it prints them.
     */
    public function test_facts_are_plain_text(): void {
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $term = $sr->create_term(['name' => 'Fall & Winter']);
        $program = $sr->create_program(['name' => 'Arts & Sciences']);
        $user = $this->getDataGenerator()->create_user();
        $student = $sr->create_student(['userid' => $user->id, 'studentid' => 'S1', 'programid' => $program->id]);
        $offer = $sr->create_offering(['courseid' => $sr->create_course()->id, 'termid' => $term->id]);
        $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);

        $facts = letters::facts(students::get((int)$student->id));

        $this->assertStringEndsWith('Arts & Sciences', $facts['program']);
        $this->assertSame('Fall & Winter', $facts['term']);
    }

    /**
     * An issued letter keeps its facts, and each letter gets its own number.
     */
    public function test_issue(): void {
        global $DB;
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $user = $this->getDataGenerator()->create_user();
        $student = $sr->create_student(['userid' => $user->id, 'studentid' => 'S1']);

        $first = letters::issue($student, 'First Bank');
        $second = letters::issue($student, '');

        $one = $DB->get_record(letters::TABLE, ['id' => $first]);
        $this->assertNotSame($one->letterno, $DB->get_field(letters::TABLE, 'letterno', ['id' => $second]));
        $this->assertSame('First Bank', $one->recipient);
        $this->assertNotEmpty(json_decode($one->content, true)['name']);
        $this->assertCount(2, letters::issued((int)$student->id));
        $this->assertStringStartsWith('%PDF', letters::pdf($one));
    }
}
