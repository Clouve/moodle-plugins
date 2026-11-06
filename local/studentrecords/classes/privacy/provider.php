<?php

namespace local_studentrecords\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_studentrecords\local\faculty;
use local_studentrecords\local\students;

/**
 * Privacy provider.
 *
 * The data subject is the student. What the plugin keeps about a student hangs from the student record, and all of it is
 * reported in the student's user context, although the documents sit at system level. A faculty or staff record with a
 * linked account is reported the same way for that person. A faculty record without an account, and a draft item read
 * from an imported file, name no user, so no request reaches them. The metadata declares them.
 *
 * A row that names a user only as the staff member who acted (who changed it, decided it, placed it or issued it) is part
 * of the student's record. The metadata declares those fields. The export of a staff member lists what they did as record
 * ids and dates, without the student's data, and a deletion request for a staff member leaves those rows alone: a school
 * must be able to say who changed an official record. Only the IP address in the staff member's log entries is removed.
 *
 * The school's settings, catalog and sections are nobody's data. Each row names the staff member who last changed it; the
 * metadata declares that, and nothing is exported or deleted for that staff member.
 *
 * A deletion request removes what a school does not have to keep:
 * - On the student record: preferred name, email, phone, addresses, home country, visa details, residency, citizenship,
 *   first generation status, high school and staff notes. The same values are blanked in the change history.
 * - Emergency contacts and demographics: blanked. The notes on the application checklist and on prior education: blanked.
 * - Student documents: deleted.
 * - Self-registration cart, waitlist places and the message log: deleted.
 * - On a faculty record: email, phone, office hours and notes, also in the change history.
 *
 * It keeps the rest, because a school must:
 * - The permanent academic record, which schools keep for good: legal name, student ID, date of birth, program, terms, status
 *   and award, status history, admission decisions, prior education, registrations and grades, grade changes, transfer
 *   credit, substitutions, academic standing, attendance, holds, and graduation applications.
 * - FERPA records, which must stay with the education record: notice acknowledgments, release authorizations, and letters
 *   issued with what they said and to whom.
 * - The student account, which schools keep as financial and tax records: account lines, online payment requests, refunds
 *   and payment plans. The payment itself is in Moodle's payment system, which handles its own export and deletion.
 * - Financial aid awards and aid year details, which federal rules require a school to keep.
 * - Staff names, job details, credentials and qualifications, as the school's staffing record for its accreditor.
 * - The change history and audit log, without the values and IP addresses named above: they show who did what to an
 *   official record.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string[] The tables of the school's settings, catalog and sections: they only name who last changed a row. */
    const SETTINGS_TABLES = ['level', 'grade', 'minpass', 'policy', 'term', 'course', 'prereq', 'program', 'progcrs', 'reqdoc',
        'offer', 'saprule', 'fee', 'plan', 'refrule', 'meeting', 'export'];

    /**
     * Data stored.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_studentrecords_student', [
            'userid' => 'privacy:metadata:student:userid', 'studentid' => 'privacy:metadata:student:studentid',
            'legalfirstname' => 'privacy:metadata:student:legalname', 'legallastname' => 'privacy:metadata:student:legalname',
            'preferredname' => 'privacy:metadata:student:preferredname', 'dob' => 'privacy:metadata:student:dob',
            'email' => 'privacy:metadata:student:contact', 'phone' => 'privacy:metadata:student:contact',
            'usaddress' => 'privacy:metadata:student:address', 'homeaddress' => 'privacy:metadata:student:address',
            'legalmiddlename' => 'privacy:metadata:student:legalname', 'homecountry' => 'privacy:metadata:student:address',
            'programid' => 'privacy:metadata:student:academic', 'status' => 'privacy:metadata:student:academic',
            'admittermid' => 'privacy:metadata:student:academic', 'catalogyear' => 'privacy:metadata:student:academic',
            'loadtype' => 'privacy:metadata:student:academic', 'advisorid' => 'privacy:metadata:student:academic',
            'awardname' => 'privacy:metadata:student:academic', 'awarddate' => 'privacy:metadata:student:academic',
            'lastattended' => 'privacy:metadata:student:academic', 'withdrawdate' => 'privacy:metadata:student:academic',
            'ferpaoptout' => 'privacy:metadata:student:ferpaoptout', 'isf1' => 'privacy:metadata:student:f1',
            'visatype' => 'privacy:metadata:student:f1', 'sevisid' => 'privacy:metadata:student:f1',
            'residency' => 'privacy:metadata:student:reporting', 'citizenship' => 'privacy:metadata:student:reporting',
            'firstgen' => 'privacy:metadata:student:reporting', 'highschool' => 'privacy:metadata:student:reporting',
            'hsyear' => 'privacy:metadata:student:reporting', 'notes' => 'privacy:metadata:student:notes',
            'usermodified' => 'privacy:metadata:usermodified', 'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:student');
        $collection->add_database_table('local_studentrecords_stathis', [
            'oldstatus' => 'privacy:metadata:stathis:status', 'newstatus' => 'privacy:metadata:stathis:status',
            'effective' => 'privacy:metadata:stathis:effective',
            'note' => 'privacy:metadata:stathis:note', 'userid' => 'privacy:metadata:stathis:userid',
        ], 'privacy:metadata:stathis');
        $collection->add_database_table('local_studentrecords_appdoc', [
            'state' => 'privacy:metadata:appdoc:state', 'statedate' => 'privacy:metadata:appdoc:state',
            'note' => 'privacy:metadata:appdoc:note', 'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:appdoc');
        $collection->add_database_table('local_studentrecords_admdec', [
            'decision' => 'privacy:metadata:admdec:decision', 'decisiondate' => 'privacy:metadata:admdec:decision',
            'reason' => 'privacy:metadata:admdec:reason',
            'conditions' => 'privacy:metadata:admdec:reason', 'decidedby' => 'privacy:metadata:admdec:decidedby',
        ], 'privacy:metadata:admdec');
        $collection->add_database_table('local_studentrecords_educ', [
            'school' => 'privacy:metadata:educ:school', 'credential' => 'privacy:metadata:educ:school',
            'completed' => 'privacy:metadata:educ:school', 'country' => 'privacy:metadata:educ:school',
            'notes' => 'privacy:metadata:educ:school', 'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:educ');
        $collection->add_database_table('local_studentrecords_emerg', [
            'name' => 'privacy:metadata:emerg:contact', 'relationship' => 'privacy:metadata:emerg:contact',
            'phone' => 'privacy:metadata:emerg:contact', 'email' => 'privacy:metadata:emerg:contact',
            'notes' => 'privacy:metadata:emerg:contact', 'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:emerg');
        $collection->add_database_table('local_studentrecords_demog', [
            'sex' => 'privacy:metadata:demog:values', 'hispanic' => 'privacy:metadata:demog:values',
            'races' => 'privacy:metadata:demog:values', 'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:demog');
        $collection->add_database_table('local_studentrecords_reg', [
            'offerid' => 'privacy:metadata:reg:offer', 'status' => 'privacy:metadata:reg:status',
            'grade' => 'privacy:metadata:reg:grade', 'gradepct' => 'privacy:metadata:reg:grade',
            'note' => 'privacy:metadata:reg:note', 'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:reg');
        $collection->add_database_table('local_studentrecords_hold', [
            'holdtype' => 'privacy:metadata:hold:hold', 'reason' => 'privacy:metadata:hold:hold',
            'releasenote' => 'privacy:metadata:hold:hold', 'placedby' => 'privacy:metadata:hold:by',
            'releasedby' => 'privacy:metadata:hold:by',
        ], 'privacy:metadata:hold');
        $collection->add_database_table('local_studentrecords_stand', [
            'standing' => 'privacy:metadata:stand:standing', 'gpa' => 'privacy:metadata:stand:standing',
            'note' => 'privacy:metadata:stand:standing', 'computedby' => 'privacy:metadata:stand:by',
            'confirmedby' => 'privacy:metadata:stand:by',
        ], 'privacy:metadata:stand');
        $collection->add_database_table('local_studentrecords_xfer', [
            'institution' => 'privacy:metadata:xfer:credit', 'extcourse' => 'privacy:metadata:xfer:credit',
            'credits' => 'privacy:metadata:xfer:credit', 'grade' => 'privacy:metadata:xfer:credit',
            'note' => 'privacy:metadata:xfer:credit', 'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:xfer');
        $collection->add_database_table('local_studentrecords_grchg', [
            'oldgrade' => 'privacy:metadata:grchg:grades', 'newgrade' => 'privacy:metadata:grchg:grades',
            'reason' => 'privacy:metadata:grchg:reason', 'requestedby' => 'privacy:metadata:grchg:by',
            'decidedby' => 'privacy:metadata:grchg:by', 'decisionnote' => 'privacy:metadata:grchg:reason',
        ], 'privacy:metadata:grchg');
        $collection->add_database_table('local_studentrecords_gradapp', [
            'state' => 'privacy:metadata:gradapp:state', 'auditsnapshot' => 'privacy:metadata:gradapp:audit',
            'credential' => 'privacy:metadata:gradapp:award', 'honors' => 'privacy:metadata:gradapp:award',
            'conferred' => 'privacy:metadata:gradapp:award', 'diplomaname' => 'privacy:metadata:gradapp:award',
            'note' => 'privacy:metadata:gradapp:state', 'reviewnote' => 'privacy:metadata:gradapp:state',
            'appliedby' => 'privacy:metadata:gradapp:by', 'reviewedby' => 'privacy:metadata:gradapp:by',
            'conferredby' => 'privacy:metadata:gradapp:by', 'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:gradapp');
        $collection->add_database_table('local_studentrecords_letter', [
            'letterno' => 'privacy:metadata:letter:letter', 'recipient' => 'privacy:metadata:letter:letter',
            'content' => 'privacy:metadata:letter:content', 'issuedby' => 'privacy:metadata:letter:by',
        ], 'privacy:metadata:letter');
        $collection->add_database_table('local_studentrecords_ferpa', [
            'acadyear' => 'privacy:metadata:ferpa:ack', 'acknowledged' => 'privacy:metadata:ferpa:ack',
            'recordedby' => 'privacy:metadata:ferpa:by',
        ], 'privacy:metadata:ferpa');
        $collection->add_database_table('local_studentrecords_relauth', [
            'recipient' => 'privacy:metadata:relauth:who', 'relationship' => 'privacy:metadata:relauth:who',
            'scope' => 'privacy:metadata:relauth:what', 'fromdate' => 'privacy:metadata:relauth:what',
            'todate' => 'privacy:metadata:relauth:what', 'details' => 'privacy:metadata:relauth:what',
            'createdby' => 'privacy:metadata:relauth:by', 'revokedby' => 'privacy:metadata:relauth:by',
            'usermodified' => 'privacy:metadata:usermodified', 'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:relauth');
        $collection->add_database_table('local_studentrecords_txn', [
            'studentid' => 'privacy:metadata:txn:line', 'txntype' => 'privacy:metadata:txn:line',
            'amount' => 'privacy:metadata:txn:line', 'description' => 'privacy:metadata:txn:line',
            'method' => 'privacy:metadata:txn:payment', 'receipt' => 'privacy:metadata:txn:payment',
            'reference' => 'privacy:metadata:txn:payment', 'note' => 'privacy:metadata:txn:note',
            'userid' => 'privacy:metadata:txn:userid',
        ], 'privacy:metadata:txn');
        $collection->add_database_table('local_studentrecords_payreq', [
            'studentid' => 'privacy:metadata:payreq:what', 'userid' => 'privacy:metadata:payreq:what',
            'amount' => 'privacy:metadata:payreq:what', 'status' => 'privacy:metadata:payreq:what',
            'paymentid' => 'privacy:metadata:payreq:payment',
        ], 'privacy:metadata:payreq');
        $collection->add_database_table('local_studentrecords_aid', [
            'studentid' => 'privacy:metadata:aid:what', 'awardyear' => 'privacy:metadata:aid:what',
            'fund' => 'privacy:metadata:aid:what', 'amount' => 'privacy:metadata:aid:what', 'status' => 'privacy:metadata:aid:what',
            'note' => 'privacy:metadata:aid:what', 'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:aid');
        $collection->add_database_table('local_studentrecords_attend', [
            'studentid' => 'privacy:metadata:attend:what', 'status' => 'privacy:metadata:attend:what',
            'hours' => 'privacy:metadata:attend:what', 'note' => 'privacy:metadata:attend:what',
            'usermodified' => 'privacy:metadata:attend:by', 'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:attend');
        $collection->add_database_table('local_studentrecords_wait', [
            'studentid' => 'privacy:metadata:wait:what', 'offerid' => 'privacy:metadata:wait:what',
            'programid' => 'privacy:metadata:wait:what', 'status' => 'privacy:metadata:wait:what',
            'timecreated' => 'privacy:metadata:wait:what', 'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:wait');
        $collection->add_database_table('local_studentrecords_aidyear', [
            'studentid' => 'privacy:metadata:aidyear:what', 'awardyear' => 'privacy:metadata:aidyear:what',
            'coa' => 'privacy:metadata:aidyear:what', 'sai' => 'privacy:metadata:aidyear:what',
            'dependency' => 'privacy:metadata:aidyear:what', 'gradelevel' => 'privacy:metadata:aidyear:what',
            'verification' => 'privacy:metadata:aidyear:what', 'counseling' => 'privacy:metadata:aidyear:what',
            'note' => 'privacy:metadata:aidyear:what', 'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:aidyear');
        $collection->add_database_table('local_studentrecords_subst', [
            'studentid' => 'privacy:metadata:subst:what', 'reqcourseid' => 'privacy:metadata:subst:what',
            'subcourseid' => 'privacy:metadata:subst:what', 'note' => 'privacy:metadata:subst:what',
            'approvedby' => 'privacy:metadata:subst:what',
        ], 'privacy:metadata:subst');
        $collection->add_database_table('local_studentrecords_cart', [
            'studentid' => 'privacy:metadata:cart:what', 'offerid' => 'privacy:metadata:cart:what',
            'timecreated' => 'privacy:metadata:cart:what',
        ], 'privacy:metadata:cart');
        $collection->add_database_table('local_studentrecords_msglog', [
            'msgkey' => 'privacy:metadata:msglog:what', 'userid' => 'privacy:metadata:msglog:what',
            'subject' => 'privacy:metadata:msglog:what', 'status' => 'privacy:metadata:msglog:what',
        ], 'privacy:metadata:msglog');
        $collection->add_database_table('local_studentrecords_refund', [
            'studentid' => 'privacy:metadata:refund:what', 'reason' => 'privacy:metadata:refund:what',
            'amount' => 'privacy:metadata:refund:what', 'status' => 'privacy:metadata:refund:what',
            'details' => 'privacy:metadata:refund:what', 'userid' => 'privacy:metadata:refund:by',
            'decidedby' => 'privacy:metadata:refund:by', 'decisionnote' => 'privacy:metadata:refund:by',
        ], 'privacy:metadata:refund');
        $collection->add_database_table('local_studentrecords_stuplan', [
            'studentid' => 'privacy:metadata:stuplan:what', 'name' => 'privacy:metadata:stuplan:what',
            'total' => 'privacy:metadata:stuplan:what', 'status' => 'privacy:metadata:stuplan:what',
            'note' => 'privacy:metadata:stuplan:note', 'approvedby' => 'privacy:metadata:stuplan:by',
            'cancelledby' => 'privacy:metadata:stuplan:by', 'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:stuplan');
        $collection->add_database_table('local_studentrecords_inst', [
            'duedate' => 'privacy:metadata:inst:what', 'amount' => 'privacy:metadata:inst:what',
            'paid' => 'privacy:metadata:inst:what', 'status' => 'privacy:metadata:inst:what',
        ], 'privacy:metadata:inst');
        $collection->add_database_table('local_studentrecords_faculty', [
            'userid' => 'privacy:metadata:faculty:userid', 'firstname' => 'privacy:metadata:faculty:name',
            'lastname' => 'privacy:metadata:faculty:name', 'email' => 'privacy:metadata:faculty:contact',
            'phone' => 'privacy:metadata:faculty:contact', 'officehours' => 'privacy:metadata:faculty:contact',
            'jobtitle' => 'privacy:metadata:faculty:job', 'department' => 'privacy:metadata:faculty:job',
            'employment' => 'privacy:metadata:faculty:job', 'honorific' => 'privacy:metadata:faculty:name',
            'nolms' => 'privacy:metadata:faculty:job', 'notes' => 'privacy:metadata:faculty:notes',
            'usermodified' => 'privacy:metadata:usermodified', 'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:faculty');
        $collection->add_database_table('local_studentrecords_faccred', [
            'title' => 'privacy:metadata:faccred:title', 'institution' => 'privacy:metadata:faccred:title',
            'subject' => 'privacy:metadata:faccred:title', 'yearawarded' => 'privacy:metadata:faccred:title',
            'expires' => 'privacy:metadata:faccred:expires', 'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:faccred');
        $collection->add_database_table('local_studentrecords_facqual', [
            'removedby' => 'privacy:metadata:facqual:removedby', 'timeremoved' => 'privacy:metadata:facqual:timeremoved',
            'usermodified' => 'privacy:metadata:usermodified', 'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:facqual');
        $collection->add_database_table('local_studentrecords_history', [
            'relateduserid' => 'privacy:metadata:history:relateduserid', 'userid' => 'privacy:metadata:history:userid',
            'oldvalue' => 'privacy:metadata:history:values', 'newvalue' => 'privacy:metadata:history:values',
            'timecreated' => 'privacy:metadata:history:timecreated',
        ], 'privacy:metadata:history');
        $collection->add_database_table('local_studentrecords_log', [
            'relateduserid' => 'privacy:metadata:log:relateduserid', 'userid' => 'privacy:metadata:log:userid',
            'action' => 'privacy:metadata:log:action', 'info' => 'privacy:metadata:log:action',
            'ip' => 'privacy:metadata:log:ip',
            'timecreated' => 'privacy:metadata:log:timecreated',
        ], 'privacy:metadata:log');
        $collection->add_database_table('local_studentrecords_import', [
            'userid' => 'privacy:metadata:import:userid', 'filename' => 'privacy:metadata:import:filename',
        ], 'privacy:metadata:import');
        $collection->add_database_table('local_studentrecords_draft', [
            'data' => 'privacy:metadata:draft:data', 'source' => 'privacy:metadata:draft:data',
            'decidedby' => 'privacy:metadata:draft:decidedby', 'timedecided' => 'privacy:metadata:draft:decidedby',
        ], 'privacy:metadata:draft');
        foreach (self::SETTINGS_TABLES as $name) {
            $collection->add_database_table('local_studentrecords_' . $name, [
                'usermodified' => 'privacy:metadata:usermodified', 'timemodified' => 'privacy:metadata:timemodified',
            ], 'privacy:metadata:settings');
        }
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:files');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:messages');
        $collection->add_subsystem_link('core_payment', [], 'privacy:metadata:payment');
        return $collection;
    }

    /**
     * Whether a user has data in this plugin: as a student, as a staff member with a record, or for what they did.
     *
     * @param int $userid
     * @return bool
     */
    protected static function has_data(int $userid): bool {
        global $DB;
        return $DB->record_exists('local_studentrecords_student', ['userid' => $userid]) ||
            $DB->record_exists('local_studentrecords_faculty', ['userid' => $userid]) ||
            $DB->record_exists('local_studentrecords_stathis', ['userid' => $userid]) ||
            $DB->record_exists('local_studentrecords_admdec', ['decidedby' => $userid]) ||
            $DB->record_exists_select('local_studentrecords_hold', 'placedby = ? OR releasedby = ?', [$userid, $userid]) ||
            $DB->record_exists_select('local_studentrecords_grchg', 'requestedby = ? OR decidedby = ?', [$userid, $userid]) ||
            $DB->record_exists_select('local_studentrecords_history', 'userid = ? OR relateduserid = ?', [$userid, $userid]) ||
            $DB->record_exists_select('local_studentrecords_log', 'userid = ? OR relateduserid = ?', [$userid, $userid]) ||
            $DB->record_exists('local_studentrecords_import', ['userid' => $userid]) ||
            $DB->record_exists('local_studentrecords_draft', ['decidedby' => $userid]) ||
            $DB->record_exists('local_studentrecords_txn', ['userid' => $userid]) ||
            $DB->record_exists('local_studentrecords_msglog', ['userid' => $userid]);
    }

    /**
     * Contexts with data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        if (self::has_data($userid)) {
            $list->add_user_context($userid);
        }
        return $list;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_user && self::has_data((int)$context->instanceid)) {
            $userlist->add_user((int)$context->instanceid);
        }
    }

    /**
     * Export a user's data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_user || (int)$context->instanceid !== $userid) {
                continue;
            }
            $base = [get_string('pluginname', 'local_studentrecords')];
            $student = students::get_by_user($userid);
            if ($student) {
                writer::with_context($context)->export_data(
                    array_merge($base, [get_string('studentrecord', 'local_studentrecords')]),
                    (object)array_diff_key((array)$student, ['id' => 1, 'usermodified' => 1])
                );
                // Documents are stored in the system context; export each one into the student's context.
                $files = get_file_storage()->get_area_files(
                    \context_system::instance()->id,
                    'local_studentrecords',
                    'studentdocs',
                    (int)$student->id,
                    'filename',
                    false
                );
                foreach ($files as $file) {
                    writer::with_context($context)->export_file(
                        array_merge($base, [get_string('documents', 'local_studentrecords')]),
                        $file
                    );
                }
                // Admissions parts of the record.
                $parts = ['reg' => 'academichistory', 'xfer' => 'transfercredit', 'stand' => 'academicstanding',
                    'gradapp' => 'graduation', 'letter' => 'lettersissued', 'txn' => 'studentaccount', 'payreq' => 'onlinepayments',
                    'refund' => 'refunds', 'attend' => 'attendance',
                    'aid' => 'financialaid', 'wait' => 'waitlist', 'cart' => 'nav_registration',
                    'subst' => 'privacy:substitutions', 'aidyear' => 'privacy:metadata:aidyear',
                    'ferpa' => 'ferpaannual', 'relauth' => 'ferpareleases',
                    'hold' => 'holds', 'stathis' => 'statushistory', 'appdoc' => 'appchecklist',
                    'admdec' => 'decisions',
                    'educ' => 'prioreducation', 'emerg' => 'emergencycontacts', 'demog' => 'demographics'];
                $studentplans = \local_studentrecords\local\plans::for_student((int)$student->id);
                if ($studentplans) {
                    writer::with_context($context)->export_data(
                        array_merge($base, [get_string('paymentplans', 'local_studentrecords')]),
                        (object)['entries' => array_values(array_map(fn($pl) => array_merge(
                            array_diff_key((array)$pl, ['usermodified' => 1, 'studentid' => 1, 'insts' => 1]),
                            ['payments' => array_values(array_map(fn($i) => (array)$i, $pl->insts))]
                        ), $studentplans))]
                    );
                }
                $changes = $DB->get_records_sql("SELECT g.* FROM {local_studentrecords_grchg} g
                    JOIN {local_studentrecords_reg} r ON r.id = g.regid WHERE r.studentid = ?", [$student->id]);
                if ($changes) {
                    writer::with_context($context)->export_data(
                        array_merge($base, [get_string('gradechanges', 'local_studentrecords')]),
                        (object)['entries' => array_values(array_map(fn($r) => (array)$r, $changes))]
                    );
                }
                foreach ($parts as $table => $string) {
                    $rows = $DB->get_records('local_studentrecords_' . $table, ['studentid' => $student->id], 'id');
                    if ($rows) {
                        writer::with_context($context)->export_data(
                            array_merge($base, [get_string($string, 'local_studentrecords')]),
                            (object)['entries' => array_values(array_map(
                                fn($r) => array_diff_key((array)$r, ['usermodified' => 1, 'studentid' => 1]),
                                $rows
                            ))]
                        );
                    }
                }
            }
            foreach ($DB->get_records('local_studentrecords_faculty', ['userid' => $userid]) as $f) {
                $credentials = faculty::credentials((int)$f->id);
                $f->credentials = array_values(array_map(
                    fn($c) => array_diff_key((array)$c, ['usermodified' => 1]),
                    $credentials
                ));
                $f->qualifications = array_values(array_map(
                    fn($q) => array_diff_key((array)$q, ['usermodified' => 1]),
                    $DB->get_records('local_studentrecords_facqual', ['facultyid' => $f->id], 'id')
                ));
                $path = array_merge($base, [get_string('facultyrecord', 'local_studentrecords'), $f->id]);
                writer::with_context($context)->export_data($path, (object)array_diff_key((array)$f, ['usermodified' => 1]));
                foreach ($credentials as $c) {
                    $files = get_file_storage()->get_area_files(
                        \context_system::instance()->id,
                        'local_studentrecords',
                        'credential',
                        (int)$c->id,
                        'filename',
                        false
                    );
                    foreach ($files as $file) {
                        writer::with_context($context)->export_file($path, $file);
                    }
                }
            }
            // The message log: messages to the person, as a student or as a staff member.
            $messages = $DB->get_records_select(
                'local_studentrecords_msglog',
                'userid = ? OR studentid = ?',
                [$userid, $student ? (int)$student->id : 0],
                'id'
            );
            if ($messages) {
                writer::with_context($context)->export_data(
                    array_merge($base, [get_string('msglog', 'local_studentrecords')]),
                    (object)['entries' => array_values(array_map(
                        fn($r) => array_diff_key((array)$r, ['studentid' => 1]),
                        $messages
                    ))]
                );
            }
            $about = $DB->get_records('local_studentrecords_history', ['relateduserid' => $userid], 'timecreated');
            $made = $DB->get_records('local_studentrecords_history', ['userid' => $userid], 'timecreated');
            writer::with_context($context)->export_data(
                array_merge($base, [get_string('changehistory', 'local_studentrecords')]),
                (object)['about_you' => array_values($about), 'made_by_you' => array_values(array_map(
                    fn($h) =>
                    (object)['tablename' => $h->tablename, 'recordid' => $h->recordid, 'fieldname' => $h->fieldname,
                    'timecreated' => \core_privacy\local\request\transform::datetime($h->timecreated)],
                    $made
                ))]
            );
            // Admissions actions the person made as staff (decisions recorded, statuses changed).
            $decided = $DB->get_records(
                'local_studentrecords_admdec',
                ['decidedby' => $userid],
                'timecreated',
                'id, decision, timecreated'
            );
            $statuses = $DB->get_records(
                'local_studentrecords_stathis',
                ['userid' => $userid],
                'timecreated',
                'id, newstatus, timecreated'
            );
            $staffacts = ['holds_placed' => $DB->get_fieldset_select('local_studentrecords_hold', 'id', 'placedby = ?', [$userid]),
                'holds_released' => $DB->get_fieldset_select('local_studentrecords_hold', 'id', 'releasedby = ?', [$userid]),
                'grade_changes_requested' => $DB->get_fieldset_select(
                    'local_studentrecords_grchg',
                    'id',
                    'requestedby = ?',
                    [$userid]
                ),
                'grade_changes_decided' => $DB->get_fieldset_select(
                    'local_studentrecords_grchg',
                    'id',
                    'decidedby = ?',
                    [$userid]
                ),
                'account_lines_added' => $DB->get_fieldset_select('local_studentrecords_txn', 'id', 'userid = ?', [$userid]),
                'files_imported' => $DB->get_fieldset_select('local_studentrecords_import', 'filename', 'userid = ?', [$userid]),
                'import_items_decided' => $DB->get_fieldset_select('local_studentrecords_draft', 'id', 'decidedby = ?', [$userid])];
            if (array_filter($staffacts)) {
                writer::with_context($context)->export_data(
                    array_merge($base, [get_string('staffactions', 'local_studentrecords')]),
                    (object)$staffacts
                );
            }
            if ($decided || $statuses) {
                $when = fn($r) => (object)['id' => $r->id, 'value' => $r->decision ?? $r->newstatus,
                    'time' => \core_privacy\local\request\transform::datetime($r->timecreated)];
                writer::with_context($context)->export_data(
                    array_merge($base, [get_string('admissionsactions', 'local_studentrecords')]),
                    (object)['decisions' => array_values(array_map($when, $decided)),
                        'status_changes' => array_values(array_map($when, $statuses))]
                );
            }
            $log = $DB->get_records_select(
                'local_studentrecords_log',
                'userid = ? OR relateduserid = ?',
                [$userid, $userid],
                'timecreated'
            );
            writer::with_context($context)->export_data(
                array_merge($base, [get_string('auditlog', 'local_studentrecords')]),
                (object)['entries' => array_values(array_map(
                    fn($l) => (object)['action' => $l->action,
                    'record' => $l->objecttype . ($l->objectid ? ' #' . $l->objectid : ''),
                    'by_you' => (int)$l->userid === $userid, 'about_you' => (int)$l->relateduserid === $userid,
                    'ip' => (int)$l->userid === $userid ? $l->ip : '',
                    'time' => \core_privacy\local\request\transform::datetime($l->timecreated)],
                    $log
                ))]
            );
        }
        \local_studentrecords\local\audit::log('privacyexport', 'student', null, $userid);
    }

    /**
     * Delete data for everyone in a context (a user context: that user).
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context instanceof \context_user) {
            self::erase((int)$context->instanceid);
        }
    }

    /**
     * Delete a user's data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int)$context->instanceid === $userid) {
                self::erase($userid);
            }
        }
    }

    /**
     * Delete data for users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_user && in_array((int)$context->instanceid, $userlist->get_userids())) {
            self::erase((int)$context->instanceid);
        }
    }

    /**
     * Remove a person's optional personal data; keep the permanent record (see class comment). All of it happens or
     * none of it, so a failed request can run again.
     *
     * @param int $userid
     */
    protected static function erase(int $userid): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        students::erase_personal($userid);
        faculty::erase_personal($userid);
        $DB->delete_records('local_studentrecords_msglog', ['userid' => $userid]);
        $DB->set_field('local_studentrecords_log', 'ip', null, ['userid' => $userid]);
        $transaction->allow_commit();
    }
}
