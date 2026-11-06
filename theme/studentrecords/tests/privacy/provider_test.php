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

namespace theme_studentrecords\privacy;

use core_privacy\local\metadata\null_provider;

#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
/**
 * Tests for the privacy provider.
 *
 * @package    theme_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_studentrecords\privacy\provider
 */
final class provider_test extends \basic_testcase {
    /**
     * The theme says it stores nothing, with a reason that exists.
     */
    public function test_null_provider(): void {
        $this->assertTrue(is_subclass_of(provider::class, null_provider::class));
        $this->assertTrue(get_string_manager()->string_exists(provider::get_reason(), 'theme_studentrecords'));
    }
}
