<?php

namespace local_studentrecords\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_studentrecords\local\faculty;
use local_studentrecords\local\payments;
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
 * - The details of the audit log entries about the person, which can name someone a record was released to: blanked.
 * - Moodle's rows of the person's online payments, with what the payment provider keeps about them: deleted.
 *
 * It keeps the rest, because a school must:
 * - The permanent academic record, which schools keep for good: legal name, student ID, date of birth, program, terms, status
 *   and award, status history, admission decisions, prior education, registrations and grades, grade changes, transfer
 *   credit, substitutions, academic standing, attendance, holds, and graduation applications.
 * - FERPA records, which must stay with the education record: notice acknowledgments, release authorizations, and letters
 *   issued with what they said and to whom.
 * - The student account, which schools keep as financial and tax records: account lines, online payment requests, refunds
 *   and payment plans.
 * - Financial aid awards and aid year details, which federal rules require a school to keep.
 * - Staff names, job details, credentials and qualifications, as the school's staffing record for its accreditor.
 * - The change history and audit log, without the values, details and IP addresses named above: they show who did what to
 *   an official record.
 *
 * Moodle's payment system asks this provider about the plugin's payments through the same methods as a request for the plugin,
 * with core_payment as the component. A call from the payment system touches the payments and nothing else, so that a request
 * is not carried out twice. A call for the plugin covers the payments too, because the payment system often never gets here:
 * in every supported Moodle version, its call to enrol_fee stops with an error on a list that has no course context.
 * A deletion of a whole context doesn't say who asks, so it runs once for each.
 *
 * Some time after an account is deleted, Moodle removes its user context. The person's data here stays, so it is then reported
 * in the system context, and a request there erases that person by user id and nobody else. On a site with data registry
 * defaults, Moodle never approves the system context for a deletion: the request ends as deleted and this plugin is not called.
 * So a scheduled task reads the finished deletion requests and carries those out too.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class provider implements
    \core_payment\privacy\consumer_provider,
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string[] The tables of the school's settings, catalog and sections: they only name who last changed a row. */
    const SETTINGS_TABLES = ['level', 'grade', 'minpass', 'policy', 'term', 'course', 'prereq', 'program', 'progcrs', 'reqdoc',
        'offer', 'saprule', 'fee', 'plan', 'refrule', 'meeting', 'export'];

    /** @var string The component on the lists of a call from Moodle's payment system. */
    const PAYMENTSYSTEM = 'core_payment';

    /** @var array Tables that make a person someone with data here, and the fields that name the person. */
    const PEOPLE = [
        'local_studentrecords_student' => ['userid'],
        'local_studentrecords_faculty' => ['userid'],
        'local_studentrecords_stathis' => ['userid'],
        'local_studentrecords_admdec' => ['decidedby'],
        'local_studentrecords_hold' => ['placedby', 'releasedby'],
        'local_studentrecords_grchg' => ['requestedby', 'decidedby'],
        'local_studentrecords_history' => ['userid', 'relateduserid'],
        'local_studentrecords_log' => ['userid', 'relateduserid'],
        'local_studentrecords_import' => ['userid'],
        'local_studentrecords_draft' => ['decidedby'],
        'local_studentrecords_txn' => ['userid'],
        'local_studentrecords_msglog' => ['userid'],
    ];

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
        foreach (self::PEOPLE as $table => $fields) {
            foreach ($fields as $field) {
                if ($DB->record_exists($table, [$field => $userid])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Where a person's data is reported: their user context, or the system context once Moodle removed that with their account.
     *
     * @param int $userid
     * @return \context
     */
    protected static function context_of(int $userid): \context {
        return \context_user::instance($userid, IGNORE_MISSING) ?: \context_system::instance();
    }

    /**
     * Whether a context is the one a person's data is reported in.
     *
     * @param \context $context
     * @param int $userid
     * @return bool
     */
    protected static function is_context_of(\context $context, int $userid): bool {
        return (int)$context->id === (int)self::context_of($userid)->id;
    }

    /**
     * The people with data here whose account is deleted and whose user context is gone.
     *
     * @return int[] user ids
     */
    protected static function removed_accounts(): array {
        global $DB;
        $userids = [];
        foreach (self::PEOPLE as $table => $fields) {
            foreach ($fields as $field) {
                $userids = array_merge($userids, $DB->get_fieldset_sql("SELECT DISTINCT u.id
                                                                          FROM {" . $table . "} t
                                                                          JOIN {user} u ON u.id = t.$field AND u.deleted = 1
                                                                     LEFT JOIN {context} c ON c.instanceid = u.id
                                                                               AND c.contextlevel = ?
                                                                         WHERE c.id IS NULL", [CONTEXT_USER]));
            }
        }
        return array_values(array_unique(array_map('intval', $userids)));
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
            if (self::context_of($userid) instanceof \context_user) {
                $list->add_user_context($userid);
            } else {
                $list->add_system_context();
            }
        }
        return $list;
    }

    /**
     * The context of a payment: the user context of the student who started the request, like the rest of their data.
     *
     * @param string $paymentarea
     * @param int $itemid payment request id
     * @return int|null null when the request or the user context is gone: the payment system then uses the system context
     */
    public static function get_contextid_for_payment(string $paymentarea, int $itemid): ?int {
        global $DB;
        $id = $DB->get_field_sql("SELECT c.id FROM {" . payments::REQ . "} r
                                    JOIN {context} c ON c.instanceid = r.userid AND c.contextlevel = ?
                                   WHERE r.id = ?", [CONTEXT_USER, $itemid]);
        return $id ? (int)$id : null;
    }

    /**
     * The payments of this plugin in a context, as the end of a query on {payments} p: the same answer as
     * get_contextid_for_payment(), for many payments at once.
     *
     * @param \context $context
     * @return array sql, params
     */
    protected static function payments_in(\context $context): array {
        $params = ['contextuser' => CONTEXT_USER, 'component' => 'local_studentrecords'];
        $where = 'c.id IS NULL';
        if (!$context instanceof \context_system) {
            $where = 'c.id = :contextid';
            $params['contextid'] = $context->id;
        }
        return ["FROM {payments} p
            LEFT JOIN {" . payments::REQ . "} r ON r.id = p.itemid
            LEFT JOIN {context} c ON c.instanceid = r.userid AND c.contextlevel = :contextuser
                WHERE p.component = :component AND $where", $params];
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($userlist->get_component() === self::PAYMENTSYSTEM) {
            [$sql, $params] = self::payments_in($context);
            $userlist->add_from_sql('userid', "SELECT p.userid $sql", $params);
        } else if ($context instanceof \context_system) {
            $userlist->add_users(self::removed_accounts());
        } else if ($context instanceof \context_user && self::has_data((int)$context->instanceid)) {
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
        self::export_payments($contextlist);
        if ($contextlist->get_component() === self::PAYMENTSYSTEM) {
            return;
        }
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!self::is_context_of($context, $userid)) {
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
     * Export a user's payments, each in the context it is reported in.
     *
     * @param approved_contextlist $contextlist
     */
    protected static function export_payments(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            [$sql, $params] = self::payments_in($context);
            $reqids = $DB->get_fieldset_sql("SELECT DISTINCT p.itemid $sql AND p.userid = :payer", $params + ['payer' => $userid]);
            foreach ($reqids as $reqid) {
                \core_payment\privacy\provider::export_payment_data_for_user_in_context(
                    $context,
                    [get_string('pluginname', 'local_studentrecords')],
                    $userid,
                    'local_studentrecords',
                    payments::AREA,
                    (int)$reqid
                );
            }
        }
    }

    /**
     * Delete data for everyone in a context: a user context is that user, the system context the removed accounts.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context instanceof \context_user) {
            self::erase((int)$context->instanceid, $context);
        } else if ($context instanceof \context_system) {
            foreach (self::removed_accounts() as $userid) {
                self::erase($userid, $context);
            }
        }
        self::erase_payments($context);
    }

    /**
     * Delete a user's data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($contextlist->get_component() === self::PAYMENTSYSTEM) {
                self::erase_payments($context, [$userid]);
            } else if (self::is_context_of($context, $userid)) {
                self::erase($userid, $context);
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
        if ($userlist->get_component() === self::PAYMENTSYSTEM) {
            self::erase_payments($context, $userlist->get_userids());
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            if (self::is_context_of($context, (int)$userid)) {
                self::erase((int)$userid, $context);
            }
        }
    }

    /**
     * Carry out the finished deletion requests of removed accounts: a deletion a privacy officer approved, for a person with data
     * here whose account is deleted and whose user context is gone. Each person is erased by user id, payments included. A mark
     * in the audit log keeps a request from being carried out twice. Data registry defaults make no difference here.
     *
     * @return int people erased
     */
    public static function erase_removed_accounts(): int {
        global $DB;
        if (!class_exists(\tool_dataprivacy\api::class)) {
            return 0;
        }
        $sql = "SELECT r.id, r.userid
                  FROM {tool_dataprivacy_request} r
                  JOIN {user} u ON u.id = r.userid AND u.deleted = 1
             LEFT JOIN {context} c ON c.instanceid = r.userid AND c.contextlevel = :contextuser
             LEFT JOIN {local_studentrecords_log} l ON l.relateduserid = r.userid AND l.objectid = r.id
                       AND l.objecttype = 'datarequest' AND l.action = 'privacyerase'
                 WHERE r.type = :type AND r.status IN (:complete, :deleted) AND c.id IS NULL AND l.id IS NULL
              ORDER BY r.id";
        $api = \tool_dataprivacy\api::class;
        $requests = $DB->get_records_sql($sql, ['contextuser' => CONTEXT_USER, 'type' => $api::DATAREQUEST_TYPE_DELETE,
            'complete' => $api::DATAREQUEST_STATUS_COMPLETE, 'deleted' => $api::DATAREQUEST_STATUS_DELETED]);
        $system = \context_system::instance();
        $erased = [];
        foreach ($requests as $request) {
            $userid = (int)$request->userid;
            if (!self::has_data($userid)) {
                continue;
            }
            if (!isset($erased[$userid])) {
                self::erase($userid, $system);
                $erased[$userid] = true;
            }
            \local_studentrecords\local\audit::log('privacyerase', 'datarequest', (int)$request->id, $userid);
        }
        return count($erased);
    }

    /**
     * Remove a person's optional personal data; keep the permanent record (see class comment). All of it happens or
     * none of it, so a failed request can run again. Every deletion of a person comes through here.
     *
     * @param int $userid
     * @param \context $context the context the person's data is reported in
     */
    protected static function erase(int $userid, \context $context): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        students::erase_personal($userid);
        faculty::erase_personal($userid);
        $DB->delete_records('local_studentrecords_msglog', ['userid' => $userid]);
        $DB->set_field('local_studentrecords_log', 'ip', null, ['userid' => $userid]);
        $DB->set_field('local_studentrecords_log', 'info', null, ['relateduserid' => $userid]);
        self::erase_payments($context, [$userid]);
        $transaction->allow_commit();
    }

    /**
     * Delete the payments of this plugin in a context, with what the payment provider keeps about them.
     *
     * @param \context $context
     * @param int[]|null $userids only the payments of these users; null = everyone's
     */
    protected static function erase_payments(\context $context, ?array $userids = null): void {
        global $DB;
        [$sql, $params] = self::payments_in($context);
        if ($userids !== null) {
            [$in, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'payer', true, 0);
            $sql .= " AND p.userid $in";
            $params += $inparams;
        }
        \core_payment\privacy\provider::delete_data_for_payment_sql("SELECT p.id $sql", $params);
    }
}
