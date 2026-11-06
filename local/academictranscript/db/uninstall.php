<?php

/**
 * Uninstall: let go of the course fields and delete the signing key.
 *
 * Moodle drops the tables, settings and stored files of the plugin on its own.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

/**
 * Uninstall hook. Keeps the course codes, hours and hour types saved on courses, but frees their fields. Deletes the Term
 * field and the folder in moodledata that holds the self-signed signing key. Safe to run again.
 *
 * The folder is deleted in place, with fulldelete(). remove_dir() renames the folder to a temporary name first, and when
 * it cannot delete every file, the private key stays in a folder with a name that nobody can guess. fulldelete() leaves
 * what it could not delete in the folder, under its own name, where an admin can delete it by hand.
 *
 * @return bool whether the folder in moodledata is gone
 */
function xmldb_local_academictranscript_uninstall(): bool {
    global $CFG;

    require_once($CFG->libdir . '/filelib.php');

    \local_academictranscript\local\coursefields::release();
    return fulldelete($CFG->dataroot . '/local_academictranscript');
}
