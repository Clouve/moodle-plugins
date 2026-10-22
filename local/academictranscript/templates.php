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
 * Download a CSV template (grading scale, marks, programs or courses).
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_academictranscript\local\import\csv;

require_login(null, false);
require_capability('local/academictranscript:configure', context_system::instance());
$type = required_param('type', PARAM_ALPHA);
if (!isset(csv::TEMPLATES[$type])) {
    throw new moodle_exception('invaliddata', 'error');
}
send_file(csv::template($type), 'academictranscript-' . $type . '-template.csv', 0, 0, true, true, 'text/csv');
