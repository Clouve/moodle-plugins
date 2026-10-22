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
 * Set Course code and/or Hours for listed courses. Dry run unless --execute is given.
 *
 * Example:
 *   php cli/set_course_fields.php --set="2|ACC 502|3" --set="3|ACC 503|" --execute
 *   (courseid|code|hours; an empty part leaves that value unchanged)
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_academictranscript\local\coursefields;

// Collect every --set (core cli_get_params keeps only the last one).
$sets = [];
$execute = false;
foreach (array_slice($_SERVER['argv'], 1) as $arg) {
    if (strpos($arg, '--set=') === 0) {
        $sets[] = substr($arg, 6);
    } else if ($arg === '--execute') {
        $execute = true;
    } else {
        cli_error("Unknown option: $arg\nUsage: --set=\"courseid|code|hours\" [--set=...] [--execute]");
    }
}
if (!$sets) {
    cli_error('Nothing to do. Usage: --set="courseid|code|hours" [--set=...] [--execute]');
}

\core\session\manager::set_user(get_admin());
$handler = \core_course\customfield\course_handler::create();

$plan = [];
foreach ($sets as $set) {
    $parts = array_map('trim', explode('|', $set));
    if (count($parts) !== 3 || !ctype_digit($parts[0])) {
        cli_error("Bad --set value: $set");
    }
    [$courseid, $code, $hours] = $parts;
    $course = $DB->get_record('course', ['id' => (int)$courseid], 'id, shortname', MUST_EXIST);
    if ($hours !== '' && !is_numeric($hours)) {
        cli_error("Credits must be a number: $set");
    }
    $plan[(int)$courseid] = ['course' => $course, 'code' => $code, 'hours' => $hours];
}

// Course codes must be unique across all courses.
$current = coursefields::get($DB->get_fieldset_select('course', 'id', 'id <> ?', [SITEID]));
foreach ($plan as $courseid => $p) {
    if ($p['code'] === '') {
        continue;
    }
    foreach ($current as $otherid => $f) {
        $othercode = isset($plan[$otherid]) && $plan[$otherid]['code'] !== '' ? $plan[$otherid]['code'] : $f['code'];
        if ($otherid != $courseid && strcasecmp($othercode, $p['code']) === 0) {
            cli_error("Course code '{$p['code']}' would duplicate course id $otherid");
        }
    }
}

foreach ($plan as $courseid => $p) {
    $f = $current[$courseid];
    mtrace(sprintf(
        "course %d %-16s code: '%s' -> '%s'   hours: '%s' -> '%s'",
        $courseid,
        $p['course']->shortname,
        $f['code'],
        $p['code'] === '' ? $f['code'] : $p['code'],
        coursefields::format_hours($f['hours']),
        $p['hours'] === '' ? coursefields::format_hours($f['hours']) : $p['hours']
    ));
    if (!$execute) {
        continue;
    }
    $data = (object)['id' => $courseid];
    if ($p['code'] !== '') {
        $data->{'customfield_' . coursefields::CODE} = $p['code'];
    }
    if ($p['hours'] !== '') {
        $data->{'customfield_' . coursefields::HOURS} = (float)$p['hours'];
    }
    $handler->instance_form_save($data);
}
mtrace($execute ? 'Saved.' : 'Dry run only - nothing saved. Add --execute to save.');
