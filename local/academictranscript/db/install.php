<?php

/**
 * Install: create the course fields, load the standard grading scale and marks, and mark the setup as not done.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

/**
 * Install hook. Safe to run again: it only adds what is missing and never changes data that is there. The one exception
 * is a course field that an uninstall kept: it gets its required, unique and locked settings back.
 *
 * @return bool
 */
function xmldb_local_academictranscript_install(): bool {
    \local_academictranscript\local\coursefields::ensure();
    \local_academictranscript\local\scale::load_defaults();
    if (get_config('local_academictranscript', 'setupcomplete') === false) {
        set_config('setupcomplete', 0, 'local_academictranscript');
    }
    return true;
}
