<?php

/**
 * First install.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

/**
 * The recovery of lost online payments starts now.
 *
 * @return bool
 */
function xmldb_local_studentrecords_install(): bool {
    set_config('recoverfrom', time(), 'local_studentrecords');
    return true;
}
