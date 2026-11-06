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

/**
 * Student Records theme - callbacks.
 *
 * @package    theme_studentrecords
 * @copyright  2016 Frédéric Massart - FMCorz.net
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Main SCSS: Boost's default preset, then this theme's own styles.
 *
 * @param theme_config $theme
 * @return string
 */
function theme_studentrecords_get_main_scss_content($theme) {
    global $CFG;
    $scss = file_get_contents($CFG->dirroot . '/theme/boost/scss/preset/default.scss');
    $scss .= "\n" . file_get_contents(__DIR__ . '/scss/studentrecords.scss');
    return $scss;
}

/**
 * SCSS variables set before everything else: the school's accent colour.
 *
 * @param theme_config $theme
 * @return string
 */
function theme_studentrecords_get_pre_scss($theme) {
    $accent = \theme_studentrecords\local\brand::accent();
    return '$primary: ' . $accent . ";\n" . '$sr-accent: ' . $accent . ";\n";
}

/**
 * Serve the school logo set in the theme settings.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function theme_studentrecords_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel == CONTEXT_SYSTEM && $filearea === 'logo') {
        $theme = theme_config::load('studentrecords');
        if (!array_key_exists('cacheability', $options)) {
            $options['cacheability'] = 'public';
        }
        return $theme->setting_file_serve('logo', $args, $forcedownload, $options);
    }
    send_file_not_found();
}
