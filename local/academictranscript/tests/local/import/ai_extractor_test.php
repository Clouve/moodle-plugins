<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_academictranscript\local\import;

use core_ai\aiactions\responses\response_generate_text;

#[\PHPUnit\Framework\Attributes\CoversClass(ai_extractor::class)]
/**
 * Tests for the AI extractor.
 *
 * The AI provider is never called. The tests that reach it put a stand-in for Moodle's AI manager in the service
 * container.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_academictranscript\local\import\ai_extractor
 */
final class ai_extractor_test extends \advanced_testcase {
    /**
     * Start each test as the admin user.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Take any stand-in for the AI manager out of the service container, so that it cannot reach another test.
     */
    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * The JSON may come wrapped in a Markdown code fence.
     */
    public function test_parse_response_reads_json_in_code_fence(): void {
        // Three backticks, written as hex escapes because Moodle's code style does not allow a backtick in a string.
        $fence = "\x60\x60\x60";
        $response = $fence . "json\n"
            . '{"grades":[{"letter":"A","min":93,"max":100,"points":4,"source":"A 93-100"}]}'
            . "\n" . $fence;

        $items = ai_extractor::parse_response($response);

        $this->assertSame([[
            'letter' => 'A',
            'minpct' => 93.0,
            'maxpct' => 100.0,
            'points' => 4.0,
            'ingpa' => 1,
            'earns' => 1,
            'description' => '',
            'source' => 'A 93-100',
        ]], $items['grade']);
    }

    /**
     * Words before and after the JSON are ignored, and a mark code is put in upper case.
     */
    public function test_parse_response_ignores_text_around_json(): void {
        $response = 'Here is what I found: {"marks":[{"code":"w","label":"Withdrawn"}]} I hope this helps.';

        $items = ai_extractor::parse_response($response);

        $this->assertCount(1, $items['mark']);
        $this->assertSame('W', $items['mark'][0]['code']);
        $this->assertSame('Withdrawn', $items['mark'][0]['label']);
        $this->assertSame(0, $items['mark'][0]['earns']);
    }

    /**
     * An item with a required value missing, and anything that is not an item, is dropped.
     */
    public function test_parse_response_drops_invalid_items(): void {
        $response = json_encode([
            'courses' => [['code' => 'BUS 101'], 'junk', ['code' => 'BUS 102', 'hours' => 3]],
            'programs' => [['name' => '']],
        ]);

        $items = ai_extractor::parse_response($response);

        $this->assertCount(1, $items['course']);
        $this->assertSame('BUS 102', $items['course'][0]['code']);
        $this->assertSame([], $items['program']);
    }

    /**
     * An answer without usable JSON gives an empty list for each of the four item types.
     */
    public function test_parse_response_returns_empty_lists_for_non_json(): void {
        $empty = ['grade' => [], 'mark' => [], 'program' => [], 'course' => []];

        foreach (['Sorry, I cannot read that document.', '', '{this is not JSON}'] as $response) {
            $this->assertSame($empty, ai_extractor::parse_response($response), $response);
        }
    }

    /**
     * Missing values get their defaults, and each item type is normalised in its own way.
     */
    public function test_clean_item_defaults(): void {
        $grade = ai_extractor::clean_item('grade', ['letter' => 'F', 'min' => 0, 'points' => 0]);
        // The maximum defaults to the whole number 100, so compare the value and not the type.
        $this->assertEquals(100, $grade['maxpct']);
        $this->assertSame(0, $grade['earns']);
        $this->assertSame(1, $grade['ingpa']);

        $mark = ai_extractor::clean_item('mark', ['code' => 'nc']);
        $this->assertSame('NC', $mark['code']);
        $this->assertSame('nc', $mark['label']);

        $program = ai_extractor::clean_item('program', ['name' => 'Nursing', 'unittype' => 'Clock hours']);
        $this->assertSame('clock', $program['unittype']);
        $this->assertNull($program['totalhours']);

        $course = ai_extractor::clean_item('course', ['code' => 'ABC 1', 'hours' => '2.5']);
        $this->assertSame(2.5, $course['hours']);
        $this->assertSame('credit', $course['unittype']);
    }

    /**
     * Long text is cut at paragraph breaks, and a paragraph that is longer than a chunk is cut in pieces.
     */
    public function test_chunks_split_at_paragraphs(): void {
        $first = str_repeat('a', 50);
        $second = str_repeat('b', 50);
        $third = str_repeat('c', 50);

        $chunks = ai_extractor::chunks($first . "\n\n" . $second . "\n\n" . $third, 120);

        $this->assertSame([$first . "\n\n" . $second, $third], $chunks);

        $chunks = ai_extractor::chunks(str_repeat('x', 300), 120);

        $this->assertSame([120, 120, 60], array_map('strlen', $chunks));
    }

    /**
     * The prompt describes the JSON shape of the item types that were asked for, and holds the text.
     */
    public function test_prompt_includes_only_requested_shapes(): void {
        $prompt = ai_extractor::prompt('Grade A is 93 to 100.', ['grade']);

        $this->assertStringContainsString('"grades":[', $prompt);
        $this->assertStringContainsString('Grade A is 93 to 100.', $prompt);
        foreach (['"marks":[', '"programs":[', '"courses":['] as $shape) {
            $this->assertStringNotContainsString($shape, $prompt);
        }
    }

    /**
     * Without an AI provider that is set up and enabled, the AI path is not offered.
     */
    public function test_available_without_provider_is_false(): void {
        $this->assertFalse(ai_extractor::available());
    }

    /**
     * Each chunk of a long document is sent on its own, and the items of all the answers are put together.
     */
    public function test_extract_merges_items_from_each_chunk(): void {
        global $USER;

        $manager = $this->fake_manager([
            $this->text_response('{"marks":[{"code":"W","label":"Withdrawn"}]}'),
            // Item types that were not asked for are left out of the result.
            $this->text_response(
                '{"marks":[{"code":"I","label":"Incomplete"}],"grades":[{"letter":"A","min":93,"points":4}]}'
            ),
        ]);
        \core\di::set(\core_ai\manager::class, $manager);
        // Two paragraphs that cannot share a chunk of 12000 characters.
        $text = 'FIRST-CHUNK ' . str_repeat('a', 7000) . "\n\n" . 'SECOND-CHUNK ' . str_repeat('b', 7000);

        $items = ai_extractor::extract($text, ['mark'], $USER->id);

        $this->assertSame(['W', 'I'], array_column($items['mark'], 'code'));
        $this->assertSame([], $items['grade']);
        $this->assertCount(2, $manager->prompts);
        $this->assertStringContainsString('FIRST-CHUNK', $manager->prompts[0]);
        $this->assertStringNotContainsString('SECOND-CHUNK', $manager->prompts[0]);
        $this->assertStringContainsString('SECOND-CHUNK', $manager->prompts[1]);
        $this->assertStringNotContainsString('FIRST-CHUNK', $manager->prompts[1]);
    }

    /**
     * When the provider reports a failure, the extractor stops with an error that carries the provider's message.
     */
    public function test_extract_reports_provider_failure(): void {
        global $USER;

        \core\di::set(\core_ai\manager::class, $this->fake_manager([$this->failed_response('Provider down')]));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('err_ai', 'local_academictranscript', 'Provider down'));
        ai_extractor::extract('Grade A is 93 to 100.', ['grade'], $USER->id);
    }

    /**
     * A stand-in for Moodle's AI manager that gives the prepared responses one by one and keeps the prompts it got.
     *
     * @param \core_ai\aiactions\responses\response_base[] $responses What to answer, one for each request
     * @return object An object with a process_action() method and a public list of prompts
     */
    private function fake_manager(array $responses): object {
        return new class ($responses) {
            /** @var array The responses still to give. */
            private array $responses;

            /** @var string[] The prompt text of each request received. */
            public array $prompts = [];

            /**
             * Constructor.
             *
             * @param array $responses The responses to give, in order
             */
            public function __construct(array $responses) {
                $this->responses = $responses;
            }

            /**
             * Take the place of \core_ai\manager::process_action().
             *
             * @param \core_ai\aiactions\base $action The action to process
             * @return \core_ai\aiactions\responses\response_base The next prepared response
             */
            public function process_action(\core_ai\aiactions\base $action) {
                $this->prompts[] = $action->get_configuration('prompttext');
                return array_shift($this->responses);
            }
        };
    }

    /**
     * A successful "generate text" response.
     *
     * @param string $content The text the provider generated
     * @return response_generate_text
     */
    private function text_response(string $content): response_generate_text {
        $response = new response_generate_text(true);
        $response->set_response_data(['generatedcontent' => $content]);
        return $response;
    }

    /**
     * A failed "generate text" response.
     *
     * @param string $message The error message of the provider
     * @return response_generate_text
     */
    private function failed_response(string $message): response_generate_text {
        $arguments = ['success' => false, 'errorcode' => 500, 'errormessage' => $message];
        // Moodle 5.3 also needs a name for the error, and Moodle 4.5 has no such argument. Ask the constructor.
        $constructor = new \ReflectionMethod(response_generate_text::class, '__construct');
        if (in_array('error', array_map(fn($parameter) => $parameter->getName(), $constructor->getParameters()))) {
            $arguments['error'] = 'provider_down';
        }
        return new response_generate_text(...$arguments);
    }
}
