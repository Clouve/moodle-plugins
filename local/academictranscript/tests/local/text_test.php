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

namespace local_academictranscript\local;

#[\PHPUnit\Framework\Attributes\CoversClass(text::class)]
/**
 * Tests for the plain text helper.
 *
 * The helper must give the same plain text whether the site setting "formatstringstriptags" is on (the default) or
 * off. With the setting off, Moodle's own format_string() writes "&" as "&amp;" and keeps the tags that are safe,
 * whatever its escape option says.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_academictranscript\local\text
 */
final class text_test extends \advanced_testcase {
    /**
     * Start each test with the site setting that Moodle starts with.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * With the default setting (tags are stripped), names come back as plain text: an ampersand stays "&", tags are
     * gone, and an entity that was typed in the name is decoded.
     *
     * @dataProvider plain_provider
     * @param string $text The name as it was typed
     * @param string $expected The plain text
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('plain_provider')]
    public function test_plain_gives_plain_text(string $text, string $expected): void {
        $this->assertTrue(\core\di::get(\core\formatting::class)->get_striptags());

        $this->assertSame($expected, text::plain($text, \context_system::instance()));
    }

    /**
     * With the setting off, Moodle runs its HTML cleaner instead of stripping tags, and the result is the same plain
     * text.
     *
     * @dataProvider plain_provider
     * @param string $text The name as it was typed
     * @param string $expected The plain text
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('plain_provider')]
    public function test_plain_gives_the_same_text_when_tags_are_not_stripped(string $text, string $expected): void {
        set_config('formatstringstriptags', 0);
        // The formatter keeps the strings it has done, whatever the setting says, so it starts again.
        \core\di::reset_container();
        $this->assertFalse(\core\di::get(\core\formatting::class)->get_striptags());

        $this->assertSame($expected, text::plain($text, \context_system::instance()));
    }

    /**
     * Data for the two tests above.
     *
     * @return array
     */
    public static function plain_provider(): array {
        return [
            'an ampersand' => ['Health & Safety', 'Health & Safety'],
            'tags and an ampersand' => ['A <b>bold</b> & co', 'A bold & co'],
            'an entity that was typed' => ['Tom &amp; Jerry', 'Tom & Jerry'],
            'quotes' => ['The "Big" O\'Neil course', 'The "Big" O\'Neil course'],
            'an accented letter' => ["Caf\u{00E9} & Bar", "Caf\u{00E9} & Bar"],
        ];
    }
}
