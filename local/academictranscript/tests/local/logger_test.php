<?php

namespace local_academictranscript\local;

#[\PHPUnit\Framework\Attributes\CoversClass(logger::class)]
/**
 * Tests for the activity log.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_academictranscript\local\logger
 */
final class logger_test extends \basic_testcase {
    /**
     * The activity log page prints a language string for each action, so every known action needs one.
     */
    public function test_every_action_has_a_language_string(): void {
        $manager = get_string_manager();

        foreach (logger::ACTIONS as $action) {
            $this->assertTrue($manager->string_exists('logaction_' . $action, 'local_academictranscript'), $action);
        }
    }
}
