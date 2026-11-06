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

namespace theme_studentrecords\local;

/**
 * What the theme asks of the Student Records plugin. The theme works without that plugin, so a call gives the default
 * when the plugin is missing, is not installed yet or fails, and the page then looks like Boost.
 *
 * @package    theme_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class records {
    /** The plugin that feeds the theme. */
    public const COMPONENT = 'local_studentrecords';

    /**
     * Call a theme callback of Student Records for a user who is logged in, and not during an upgrade.
     *
     * @param string $callback The name without the component, for example theme_card.
     * @param array $args The arguments of the callback.
     * @param mixed $default What to give when there is no answer.
     * @param string $component The plugin to call.
     * @return mixed
     */
    public static function call(string $callback, array $args, mixed $default, string $component = self::COMPONENT): mixed {
        global $CFG;
        if (!empty($CFG->upgraderunning) || during_initial_install() || !isloggedin() || isguestuser()) {
            return $default;
        }
        // Moodle's component_callback() throws for a plugin that is not on disk. One on disk may not be installed yet.
        if (\core_component::get_component_directory($component) === null || !get_config($component, 'version')) {
            return $default;
        }
        try {
            return component_callback($component, $callback, $args, $default);
        } catch (\Throwable $e) {
            debugging($component . ' ' . $callback . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $default;
        }
    }
}
