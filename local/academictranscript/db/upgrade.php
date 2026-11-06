<?php

/**
 * Upgrade steps.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

/**
 * Upgrade hook.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_academictranscript_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026111600) {
        // Official transcript requests from students.
        if (!$dbman->table_exists('local_academictranscript_request')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_academictranscript_request');
        }
        upgrade_plugin_savepoint(true, 2026111600, 'local', 'academictranscript');
    }

    if ($oldversion < 2026111606) {
        // Which registration in Student Records got a posted grade.
        $table = new xmldb_table('local_academictranscript_posted');
        $field = new xmldb_field('srregid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026111606, 'local', 'academictranscript');
    }

    if ($oldversion < 2026111607) {
        // Builds from before the first public release have two more columns on the student table, and nothing reads them.
        // A column goes only when it holds nothing the other columns don't. A program name with no linked program, or an
        // admission date, keeps its column.
        $table = new xmldb_table('local_academictranscript_student');
        $field = new xmldb_field('program');
        if ($dbman->field_exists($table, $field)) {
            $named = $DB->sql_isnotempty('local_academictranscript_student', 'program', true, false);
            if (!$DB->record_exists_select('local_academictranscript_student', "(programid IS NULL OR programid = 0) AND $named")) {
                $dbman->drop_field($table, $field);
            }
        }
        $field = new xmldb_field('admissiondate');
        if ($dbman->field_exists($table, $field)) {
            $dated = 'admissiondate IS NOT NULL AND admissiondate <> 0';
            if (!$DB->record_exists_select('local_academictranscript_student', $dated)) {
                $dbman->drop_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026111607, 'local', 'academictranscript');
    }

    return true;
}
