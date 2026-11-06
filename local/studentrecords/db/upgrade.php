<?php

/**
 * Upgrade steps.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_studentrecords_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026102200) {
        // Staff who never use the LMS (registrar, finance ...): only adds a column, existing records keep LMS access.
        $table = new xmldb_table('local_studentrecords_faculty');
        $field = new xmldb_field('nolms', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'active');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026102200, 'local', 'studentrecords');
    }

    if ($oldversion < 2026102202) {
        // 0.2.0: calculated last day to withdraw, delivery mode and location, qualifications kept when removed.
        $fields = [
            'local_studentrecords_term' => [
                new xmldb_field('withdrawcalc', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'withdrawby'),
            ],
            'local_studentrecords_program' => [
                new xmldb_field('deliverymode', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'delivery'),
                new xmldb_field('location', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'deliverymode'),
            ],
            'local_studentrecords_facqual' => [
                new xmldb_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'courseid'),
                new xmldb_field('timeremoved', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'active'),
                new xmldb_field('removedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timeremoved'),
            ],
        ];
        foreach ($fields as $tablename => $list) {
            $table = new xmldb_table($tablename);
            foreach ($list as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        $table = new xmldb_table('local_studentrecords_facqual');
        $key = new xmldb_key('removedby', XMLDB_KEY_FOREIGN, ['removedby'], 'user', ['id']);
        $dbman->add_key($table, $key);

        // Terms without a last day to withdraw get the calculated one; programs get a delivery mode read from
        // their delivery text. Both are written to the change history (source "upgrade").
        $now = time();
        $history = function ($table, $id, $field, $old, $new) use ($DB, $now) {
            $DB->insert_record('local_studentrecords_history', (object)['tablename' => $table, 'recordid' => $id,
                'fieldname' => $field, 'oldvalue' => $old, 'newvalue' => (string)$new, 'relateduserid' => null,
                'userid' => 0, 'source' => 'upgrade', 'timecreated' => $now]);
        };
        foreach ($DB->get_records_select('local_studentrecords_term', 'withdrawby IS NULL') as $term) {
            $time = \local_studentrecords\local\catalog::withdraw_time((int)$term->startdate, (int)$term->enddate);
            if ($time) {
                $DB->update_record('local_studentrecords_term', (object)['id' => $term->id, 'withdrawby' => $time,
                    'withdrawcalc' => 1, 'timemodified' => $now]);
                $history('local_studentrecords_term', $term->id, 'withdrawby', null, $time);
                $history('local_studentrecords_term', $term->id, 'withdrawcalc', null, 1);
            }
        }
        foreach ($DB->get_records_select('local_studentrecords_program', 'deliverymode IS NULL') as $prog) {
            $mode = \local_studentrecords\local\importer::delivery_mode((string)$prog->delivery);
            if ($mode) {
                $DB->update_record('local_studentrecords_program', (object)['id' => $prog->id, 'deliverymode' => $mode,
                    'timemodified' => $now]);
                $history('local_studentrecords_program', $prog->id, 'deliverymode', null, $mode);
            }
        }
        upgrade_plugin_savepoint(true, 2026102202, 'local', 'studentrecords');
    }

    if ($oldversion < 2026102204) {
        // 0.3.0 Admissions: last date of attendance and withdrawal date on the student; new tables.
        $table = new xmldb_table('local_studentrecords_student');
        foreach (
            [new xmldb_field('lastattended', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'awarddate'),
            new xmldb_field('withdrawdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'lastattended')] as $field
        ) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        foreach (['stathis', 'reqdoc', 'appdoc', 'admdec', 'educ', 'emerg', 'demog'] as $name) {
            if (!$dbman->table_exists('local_studentrecords_' . $name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_studentrecords_' . $name);
            }
        }
        // Status history starts with each student's current status, dated when the record was created.
        $now = time();
        foreach ($DB->get_records('local_studentrecords_student', null, 'id', 'id, status, timecreated') as $s) {
            if (!$DB->record_exists('local_studentrecords_stathis', ['studentid' => $s->id])) {
                $DB->insert_record('local_studentrecords_stathis', (object)['studentid' => $s->id, 'oldstatus' => null,
                    'newstatus' => $s->status, 'effective' => (int)$s->timecreated ?: $now,
                    'note' => 'Status when the status history started (upgrade to 0.3.0)', 'userid' => 0, 'timecreated' => $now]);
            }
        }
        upgrade_plugin_savepoint(true, 2026102204, 'local', 'studentrecords');
    }

    if ($oldversion < 2026102205) {
        // 0.4.0 Registration: course offerings and registrations.
        foreach (['offer', 'reg'] as $name) {
            if (!$dbman->table_exists('local_studentrecords_' . $name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_studentrecords_' . $name);
            }
        }
        upgrade_plugin_savepoint(true, 2026102205, 'local', 'studentrecords');
    }

    if ($oldversion < 2026102206) {
        // 0.5.0: grade freeze date per term; holds, standing rules, standing, transfer credit, grade changes.
        $table = new xmldb_table('local_studentrecords_term');
        $field = new xmldb_field('gradefreeze', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'examend');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        foreach (['hold', 'saprule', 'stand', 'xfer', 'grchg'] as $name) {
            if (!$dbman->table_exists('local_studentrecords_' . $name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_studentrecords_' . $name);
            }
        }
        upgrade_plugin_savepoint(true, 2026102206, 'local', 'studentrecords');
    }

    if ($oldversion < 2026102207) {
        // 0.6.0: graduation applications.
        if (!$dbman->table_exists('local_studentrecords_gradapp')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_studentrecords_gradapp');
        }
        upgrade_plugin_savepoint(true, 2026102207, 'local', 'studentrecords');
    }

    if ($oldversion < 2026102208) {
        // 0.7.0: letters issued, FERPA acknowledgments, release authorizations.
        foreach (['letter', 'ferpa', 'relauth'] as $name) {
            if (!$dbman->table_exists('local_studentrecords_' . $name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_studentrecords_' . $name);
            }
        }
        upgrade_plugin_savepoint(true, 2026102208, 'local', 'studentrecords');
    }

    if ($oldversion < 2026110300) {
        // 0.8.0 Billing, part 1: price list and student account lines. Billing stays off until a school turns it on.
        foreach (['fee', 'txn'] as $name) {
            if (!$dbman->table_exists('local_studentrecords_' . $name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_studentrecords_' . $name);
            }
        }
        upgrade_plugin_savepoint(true, 2026110300, 'local', 'studentrecords');
    }

    if ($oldversion < 2026110400) {
        // 0.9.0 Billing, part 2: online payments, and course access that waits for payment. Existing enrolments unchanged.
        $table = new xmldb_table('local_studentrecords_reg');
        $field = new xmldb_field('accesspending', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'override');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        if (!$dbman->table_exists('local_studentrecords_payreq')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_studentrecords_payreq');
        }
        upgrade_plugin_savepoint(true, 2026110400, 'local', 'studentrecords');
    }

    if ($oldversion < 2026110401) {
        // 0.9.1: a price "per hour" becomes "per credit hour" (or "per clock hour" when it is for a clock hour program), so a
        // price per credit hour is never charged on clock hours. Each change is kept in the change history.
        $sql = "SELECT f.id, p.unittype FROM {local_studentrecords_fee} f
             LEFT JOIN {local_studentrecords_program} p ON f.scope = 'program' AND p.id = f.scopeid
                 WHERE f.basis = 'hour'";
        foreach ($DB->get_records_sql($sql) as $fee) {
            \local_studentrecords\local\records::save('local_studentrecords_fee', ['id' => $fee->id,
                'basis' => $fee->unittype === 'clock' ? 'clockhour' : 'credithour'], 'upgrade');
        }
        upgrade_plugin_savepoint(true, 2026110401, 'local', 'studentrecords');
    }

    if ($oldversion < 2026110500) {
        // 0.10.0 Billing, part 3: payment plans, reminders, late fees and holds.
        foreach (['plan', 'stuplan', 'inst'] as $name) {
            if (!$dbman->table_exists('local_studentrecords_' . $name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_studentrecords_' . $name);
            }
        }
        upgrade_plugin_savepoint(true, 2026110500, 'local', 'studentrecords');
    }

    if ($oldversion < 2026110600) {
        // 0.11.0 Billing, part 4: refund rules and refunds; a refund line records the charge it refunds.
        $table = new xmldb_table('local_studentrecords_txn');
        $field = new xmldb_field('chargeid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'refundid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        foreach (['refrule', 'refund'] as $name) {
            if (!$dbman->table_exists('local_studentrecords_' . $name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_studentrecords_' . $name);
            }
        }
        upgrade_plugin_savepoint(true, 2026110600, 'local', 'studentrecords');
    }

    if ($oldversion < 2026110800) {
        // 0.13.0 Automatic messages: message log.
        if (!$dbman->table_exists('local_studentrecords_msglog')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_studentrecords_msglog');
        }
        upgrade_plugin_savepoint(true, 2026110800, 'local', 'studentrecords');
    }

    if ($oldversion < 2026110900) {
        // 0.14.0 Attendance: class meetings and attendance marks.
        foreach (['meeting', 'attend'] as $name) {
            if (!$dbman->table_exists('local_studentrecords_' . $name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_studentrecords_' . $name);
            }
        }
        upgrade_plugin_savepoint(true, 2026110900, 'local', 'studentrecords');
    }

    if ($oldversion < 2026111000) {
        // 0.15.0 Outside reports: saved exports.
        if (!$dbman->table_exists('local_studentrecords_export')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_studentrecords_export');
        }
        upgrade_plugin_savepoint(true, 2026111000, 'local', 'studentrecords');
    }

    if ($oldversion < 2026111100) {
        // 0.16.0 Optional federal aid reporting module (off unless the school turns it on): aid awards; disbursement lines
        // record their award.
        $table = new xmldb_table('local_studentrecords_txn');
        $field = new xmldb_field('aidid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'chargeid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        if (!$dbman->table_exists('local_studentrecords_aid')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_studentrecords_aid');
        }
        upgrade_plugin_savepoint(true, 2026111100, 'local', 'studentrecords');
    }

    if ($oldversion < 2026111400) {
        // 1.2.0 Student screens: class days, times and room on each section; waitlists; self-registration cart.
        $table = new xmldb_table('local_studentrecords_offer');
        $fields = [
            new xmldb_field('meetdays', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'notes'),
            new xmldb_field('starttime', XMLDB_TYPE_CHAR, '5', null, null, null, null, 'meetdays'),
            new xmldb_field('endtime', XMLDB_TYPE_CHAR, '5', null, null, null, null, 'starttime'),
            new xmldb_field('room', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'endtime'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        foreach (['wait', 'cart'] as $name) {
            if (!$dbman->table_exists('local_studentrecords_' . $name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_studentrecords_' . $name);
            }
        }
        upgrade_plugin_savepoint(true, 2026111400, 'local', 'studentrecords');
    }

    if ($oldversion < 2026111500) {
        // 1.3.0: extra student fields for state reporting (residency, citizenship, first generation, high school); program
        // credits at the school, name on diploma and conditional approval, course substitutions.
        $table = new xmldb_table('local_studentrecords_student');
        $fields = [
            new xmldb_field('residency', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'withdrawdate'),
            new xmldb_field('citizenship', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'residency'),
            new xmldb_field('firstgen', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'citizenship'),
            new xmldb_field('highschool', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'firstgen'),
            new xmldb_field('hsyear', XMLDB_TYPE_INTEGER, '4', null, null, null, null, 'highschool'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $more = [
            'local_studentrecords_program' => [new xmldb_field(
                'residenthours',
                XMLDB_TYPE_NUMBER,
                '8, 2',
                null,
                null,
                null,
                null,
                'graderules'
            )],
            'local_studentrecords_gradapp' => [
                new xmldb_field('diplomaname', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'timeconferred'),
                new xmldb_field('conditional', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'diplomaname'),
            ],
        ];
        foreach ($more as $tablename => $tablefields) {
            $table = new xmldb_table($tablename);
            foreach ($tablefields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        if (!$dbman->table_exists('local_studentrecords_subst')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_studentrecords_subst');
        }
        upgrade_plugin_savepoint(true, 2026111500, 'local', 'studentrecords');
    }

    if ($oldversion < 2026111600) {
        // 1.4.0 Financial Aid screens: aid year details (cost of attendance, Student Aid Index, verification, counseling).
        if (!$dbman->table_exists('local_studentrecords_aidyear')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_studentrecords_aidyear');
        }
        upgrade_plugin_savepoint(true, 2026111600, 'local', 'studentrecords');
    }

    if ($oldversion < 2026111606) {
        // 1.4.6 Marks (I, P, NP, W, NC ...): whether a mark without grade points earns the course hours.
        $table = new xmldb_table('local_studentrecords_grade');
        $field = new xmldb_field('earns', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'ingpa');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026111606, 'local', 'studentrecords');
    }

    if ($oldversion < 2026111607) {
        // Indexes for the change history of a user (privacy requests), and for the account lines found by these fields.
        $indexes = [
            'local_studentrecords_history' => ['userid'],
            'local_studentrecords_txn' => ['reverses', 'chargeid', 'aidid'],
        ];
        foreach ($indexes as $tablename => $fields) {
            $table = new xmldb_table($tablename);
            foreach ($fields as $field) {
                $index = new xmldb_index($field, XMLDB_INDEX_NOTUNIQUE, [$field]);
                if (!$dbman->index_exists($table, $index)) {
                    $dbman->add_index($table, $index);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026111607, 'local', 'studentrecords');
    }

    return true;
}
