<?php

/**
 * Download a CSV template (grading scale, marks, programs or courses).
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
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
