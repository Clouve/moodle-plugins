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

namespace local_academictranscript\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider.
 *
 * The data subject is the student, and what the plugin keeps about a student is stored against the student's user
 * context: the student details, hand-entered codes, transfer credit, posted grades, the change requests on them, issued
 * transcripts, and the log entries about the student. A row that names a user only as the staff member who acted (who
 * changed it, posted it, issued it, asked for it or decided it, or who did what a log entry records about a student) is
 * part of the student's record. The metadata declares those fields, but the rows are not exported or deleted for that
 * staff member. A draft belongs to the user who uploaded the document.
 *
 * A log entry about no student is different. A failed verification, written while the visitor was logged in, holds the
 * id and the IP address of that user and nothing about a student, so it belongs to that user: it gives the user a
 * context, it is in the export, and a deletion removes it. One written by a visitor who was not logged in names no
 * user, so no request reaches it.
 *
 * The school's settings (the grading scale, marks, programs, catalog and terms) are nobody's data. Each row names the
 * admin who last changed it; the metadata declares that, and nothing is exported or deleted for that admin.
 *
 * The export of a user holds the log entries that belong to the user. The IP address belongs to whoever did the thing,
 * so it is left out of every entry that the user did not do (a staff member who viewed a student's transcript, or a
 * visitor who verified one without logging in). The ids of those users stay, as they do in core's own exports.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * @var string[] Tables where the userid column is the user that the row belongs to: the student, or the user who
     *      uploaded the document for a draft. The change requests and the log have their own rules.
     */
    const TABLES = ['local_academictranscript_student', 'local_academictranscript_code', 'local_academictranscript_issue',
        'local_academictranscript_transfer', 'local_academictranscript_posted', 'local_academictranscript_draft'];

    /** @var string[] The tables of the school's settings. They are declared in the metadata but never exported or deleted. */
    const SETTINGS_TABLES = ['local_academictranscript_grade', 'local_academictranscript_mark',
        'local_academictranscript_program', 'local_academictranscript_catalog', 'local_academictranscript_term'];

    /**
     * Metadata.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_academictranscript_student', [
            'userid' => 'privacy:metadata:userid',
            'studentid' => 'privacy:metadata:studentid',
            'dob' => 'privacy:metadata:dob',
            'programid' => 'privacy:metadata:programid',
            'admitterm' => 'privacy:metadata:admitterm',
            'status' => 'privacy:metadata:studentstatus',
            'qualification' => 'privacy:metadata:qualification',
            'awarddate' => 'privacy:metadata:awarddate',
            'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:student');
        $collection->add_database_table('local_academictranscript_code', [
            'userid' => 'privacy:metadata:userid',
            'courseid' => 'privacy:metadata:courseid',
            'code' => 'privacy:metadata:code',
            'note' => 'privacy:metadata:note',
            'reason' => 'privacy:metadata:codereason',
            'termid' => 'privacy:metadata:termid',
            'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:codetable');
        $collection->add_database_table('local_academictranscript_transfer', [
            'userid' => 'privacy:metadata:userid',
            'institution' => 'privacy:metadata:institution',
            'coursecode' => 'privacy:metadata:coursecode',
            'title' => 'privacy:metadata:coursetitle',
            'hours' => 'privacy:metadata:hours',
            'dateaccepted' => 'privacy:metadata:dateaccepted',
            'note' => 'privacy:metadata:note',
            'active' => 'privacy:metadata:active',
            'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:transfer');
        $collection->add_database_table('local_academictranscript_posted', [
            'userid' => 'privacy:metadata:userid',
            'courseid' => 'privacy:metadata:courseid',
            'termid' => 'privacy:metadata:termid',
            'coursecode' => 'privacy:metadata:coursecode',
            'coursename' => 'privacy:metadata:coursetitle',
            'hours' => 'privacy:metadata:hours',
            'percent' => 'privacy:metadata:percent',
            'grade' => 'privacy:metadata:grade',
            'note' => 'privacy:metadata:note',
            'postedby' => 'privacy:metadata:postedby',
            'timeposted' => 'privacy:metadata:timeposted',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:posted');
        $collection->add_database_table('local_academictranscript_change', [
            'postedid' => 'privacy:metadata:postedid',
            'oldgrade' => 'privacy:metadata:oldgrade',
            'newgrade' => 'privacy:metadata:newgrade',
            'oldnote' => 'privacy:metadata:oldnote',
            'newnote' => 'privacy:metadata:newnote',
            'reason' => 'privacy:metadata:changereason',
            'status' => 'privacy:metadata:changestatus',
            'requestedby' => 'privacy:metadata:requestedby',
            'timerequested' => 'privacy:metadata:timerequested',
            'decidedby' => 'privacy:metadata:decidedby',
            'timedecided' => 'privacy:metadata:timedecided',
            'decisionnote' => 'privacy:metadata:decisionnote',
        ], 'privacy:metadata:change');
        $collection->add_database_table('local_academictranscript_issue', [
            'userid' => 'privacy:metadata:userid',
            'verifycode' => 'privacy:metadata:verifycode',
            'issuedby' => 'privacy:metadata:issuedby',
            'snapshot' => 'privacy:metadata:snapshot',
            'recipient' => 'privacy:metadata:recipient',
            'transcriptno' => 'privacy:metadata:transcriptno',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:issue');
        $collection->add_database_table('local_academictranscript_log', [
            'action' => 'privacy:metadata:action',
            'userid' => 'privacy:metadata:loguser',
            'studentid' => 'privacy:metadata:logstudent',
            'issueid' => 'privacy:metadata:issueid',
            'ip' => 'privacy:metadata:ip',
            'info' => 'privacy:metadata:info',
            'timecreated' => 'privacy:metadata:logtime',
        ], 'privacy:metadata:log');
        $collection->add_database_table('local_academictranscript_draft', [
            'userid' => 'privacy:metadata:draftuser',
            'sourcename' => 'privacy:metadata:draftsource',
            'excerpt' => 'privacy:metadata:draftexcerpt',
            'decidedby' => 'privacy:metadata:draftdecidedby',
            'timecreated' => 'privacy:metadata:drafttime',
            'timedecided' => 'privacy:metadata:drafttimedecided',
        ], 'privacy:metadata:draft');
        // The school's settings hold no student data. They only name the admin who last changed a row.
        foreach (self::SETTINGS_TABLES as $table) {
            $collection->add_database_table($table, [
                'usermodified' => 'privacy:metadata:adminmodified',
                'timemodified' => 'privacy:metadata:timemodified',
            ], 'privacy:metadata:settings');
        }
        return $collection;
    }

    /**
     * Contexts with data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::has_data($userid)) {
            $contextlist->add_user_context($userid);
        }
        return $contextlist;
    }

    /**
     * Users in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof \context_user && self::has_data($context->instanceid)) {
            $userlist->add_user($context->instanceid);
        }
    }

    /**
     * Export.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!($context instanceof \context_user) || $context->instanceid != $userid) {
                continue;
            }
            $data = [];
            foreach (self::TABLES as $table) {
                $data[$table] = array_values($DB->get_records($table, ['userid' => $userid], 'id'));
            }
            $postedids = self::posted_ids($userid);
            $data['local_academictranscript_change'] = $postedids ?
                array_values($DB->get_records_list('local_academictranscript_change', 'postedid', $postedids, 'id')) : [];
            [$logselect, $logparams] = self::log_select($userid);
            $logrows = $DB->get_records_select('local_academictranscript_log', $logselect, $logparams, 'id');
            foreach ($logrows as $row) {
                // The IP address belongs to whoever did it. The user only gets it for what the user did.
                if ((int)$row->userid !== (int)$userid) {
                    $row->ip = '';
                }
            }
            $data['local_academictranscript_log'] = array_values($logrows);
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_academictranscript')],
                (object)$data
            );
        }
    }

    /**
     * Delete all data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context instanceof \context_user) {
            self::delete_user($context->instanceid);
        }
    }

    /**
     * Delete data for a user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && $context->instanceid == $contextlist->get_user()->id) {
                self::delete_user($context->instanceid);
            }
        }
    }

    /**
     * Delete data for users.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof \context_user && in_array($context->instanceid, $userlist->get_userids())) {
            self::delete_user($context->instanceid);
        }
    }

    /**
     * Whether the plugin keeps data for a user: a row that belongs to the user, or a log entry that belongs to the user
     * (see log_select()). A log entry that only names the user as the one who acted on a student does not count.
     *
     * @param int $userid
     * @return bool
     */
    protected static function has_data(int $userid): bool {
        global $DB;
        foreach (self::TABLES as $table) {
            if ($DB->record_exists($table, ['userid' => $userid])) {
                return true;
            }
        }
        [$select, $params] = self::log_select($userid);
        return $DB->record_exists_select('local_academictranscript_log', $select, $params);
    }

    /**
     * The condition that picks the log entries that belong to a user: the entries about the user as the student, and the
     * entries about no student that the user made while logged in (a failed verification), which hold the user's own id
     * and IP address and nothing about a student.
     *
     * @param int $userid
     * @return array The SQL condition, then its parameters
     */
    protected static function log_select(int $userid): array {
        return ['(studentid = :student OR (studentid = 0 AND userid = :author))', ['student' => $userid, 'author' => $userid]];
    }

    /**
     * The ids of the grades posted for a student.
     *
     * @param int $userid
     * @return int[]
     */
    protected static function posted_ids(int $userid): array {
        global $DB;
        return $DB->get_fieldset_select('local_academictranscript_posted', 'id', 'userid = ?', [$userid]);
    }

    /**
     * Delete everything stored about one student, the drafts the user uploaded, and the log entries that belong to the
     * user (see log_select()).
     *
     * @param int $userid
     */
    protected static function delete_user(int $userid): void {
        global $DB;
        $postedids = self::posted_ids($userid);
        if ($postedids) {
            $DB->delete_records_list('local_academictranscript_change', 'postedid', $postedids);
        }
        foreach (self::TABLES as $table) {
            $DB->delete_records($table, ['userid' => $userid]);
        }
        [$select, $params] = self::log_select($userid);
        $DB->delete_records_select('local_academictranscript_log', $select, $params);
    }
}
