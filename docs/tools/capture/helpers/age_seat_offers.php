<?php

/**
 * Moves the seat offers of a test site three days back, as if the days had passed. A capture script runs it with site.sh php.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

define('CLI_SCRIPT', true);
require('/work/moodle/config.php');

$DB->execute("UPDATE {local_studentrecords_wait} SET timeoffered = timeoffered - ? WHERE status = 'offered'", [3 * DAYSECS]);
