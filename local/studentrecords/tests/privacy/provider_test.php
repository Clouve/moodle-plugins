<?php

namespace local_studentrecords\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist_collection;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_studentrecords\local\audit;
use local_studentrecords\local\faculty;
use local_studentrecords\local\ferpa;
use local_studentrecords\local\letters;
use local_studentrecords\local\payments;
use local_studentrecords\local\records;
use local_studentrecords\local\students;

#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
/**
 * Tests for the privacy provider.
 *
 * The data subject is the student. A row that names a user only as the staff member who acted is part of the student's
 * record: it is declared, the staff member's export lists it as an id, and a request from the staff member leaves it alone.
 * A deletion removes what a school does not have to keep and keeps the permanent record; the class comment of the
 * provider says which is which, and these tests hold it to that.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var string The component name. */
    private const COMPONENT = 'local_studentrecords';

    /** @var string[] Columns that end in "by" and hold no user id. */
    private const NOTUSERS = ['local_studentrecords_txn.reversedby', 'local_studentrecords_term.withdrawby'];

    /**
     * Start each test as an admin on a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Every table that names a person is declared, with every column that holds a user id. Every declared field is a real
     * column, and every string exists.
     */
    public function test_metadata_declares_every_user_column(): void {
        $metadata = $this->metadata();
        $schema = $this->schema();
        $missing = [];
        foreach ($schema as $table => $columns) {
            foreach ($columns as $column) {
                if ($this->holds_a_user($table, $column) && !isset($metadata[$table][$column])) {
                    $missing[] = $table . '.' . $column;
                }
            }
        }
        $this->assertSame([], $missing);

        foreach ($metadata as $table => $fields) {
            $this->assertArrayHasKey($table, $schema);
            foreach ($fields as $field => $string) {
                $this->assertContains($field, $schema[$table], $table);
                $this->assertTrue(get_string_manager()->string_exists($string, self::COMPONENT), $string);
            }
        }
    }

    /**
     * Every table with a row about a student or about a person's name is declared, and so is every column in the student
     * record. A column added later must be thought about here.
     */
    public function test_metadata_declares_every_table_about_a_person(): void {
        $metadata = $this->metadata();
        foreach ($this->schema() as $table => $columns) {
            if (in_array('studentid', $columns, true) || in_array('userid', $columns, true)) {
                $this->assertArrayHasKey($table, $metadata);
            }
        }
        $notpersonal = ['id', 'timecreated'];
        $this->assertSame(
            [],
            array_values(array_diff($this->schema()['local_studentrecords_student'], array_keys(
                $metadata['local_studentrecords_student']
            ), $notpersonal))
        );
    }

    /**
     * Files and online payments are declared as subsystems.
     */
    public function test_metadata_links_files_and_payments(): void {
        $items = provider::get_metadata(new collection(self::COMPONENT))->get_collection();
        $names = array_map(fn($item) => $item->get_name(), $items);
        $this->assertContains('core_files', $names);
        $this->assertContains('core_payment', $names);
        $this->assertTrue(get_string_manager()->string_exists('privacy:metadata:payment', self::COMPONENT));
    }

    /**
     * A student's data is in their own user context, and a user without data has none.
     */
    public function test_contexts_for_a_student(): void {
        $data = $this->make_student();
        $user = \core_user::get_user($data['student']->userid);
        $contexts = provider::get_contexts_for_userid((int)$user->id);
        $this->assertSame([\context_user::instance($user->id)->id], array_map('intval', $contexts->get_contextids()));

        $nobody = $this->getDataGenerator()->create_user();
        $this->assertCount(0, provider::get_contexts_for_userid((int)$nobody->id));
    }

    /**
     * A staff member's own actions give them a context of their own.
     */
    public function test_contexts_for_a_staff_member(): void {
        $staff = $this->getDataGenerator()->create_user();
        $this->setUser($staff);
        audit::log('view', 'student', 1, null);
        $this->assertCount(1, provider::get_contexts_for_userid((int)$staff->id));
    }

    /**
     * The user of a user context is listed.
     */
    public function test_users_in_context(): void {
        $data = $this->make_student();
        $context = \context_user::instance($data['student']->userid);
        $list = new userlist($context, self::COMPONENT);
        provider::get_users_in_context($list);
        $this->assertSame([(int)$data['student']->userid], array_map('intval', $list->get_userids()));

        $list = new userlist(\context_system::instance(), self::COMPONENT);
        provider::get_users_in_context($list);
        $this->assertSame([], $list->get_userids());
    }

    /**
     * The export has the record, each part of it, the documents and the change history.
     */
    public function test_export_for_a_student(): void {
        $data = $this->make_student();
        $student = $data['student'];
        $this->export_all_data_for_user((int)$student->userid, self::COMPONENT);

        $writer = writer::with_context(\context_user::instance($student->userid));
        $base = get_string('pluginname', self::COMPONENT);
        $path = fn(string $key) => [$base, get_string($key, self::COMPONENT)];
        $record = $writer->get_data($path('studentrecord'));
        $this->assertSame('Ana', $record->preferredname);
        $this->assertSame('N0012345678', $record->sevisid);
        foreach (
            ['academichistory', 'emergencycontacts', 'demographics', 'prioreducation', 'waitlist', 'nav_registration', 'msglog',
                'statushistory', 'changehistory', 'auditlog'] as $key
        ) {
            $this->assertNotEmpty((array)$writer->get_data($path($key)), $key);
        }
        $files = $writer->get_files($path('documents'));
        $this->assertArrayHasKey('transcript.pdf', $files);
    }

    /**
     * Exporting is noted in the audit log, about the person.
     */
    public function test_export_is_logged(): void {
        global $DB;
        $data = $this->make_student();
        $this->export_all_data_for_user((int)$data['student']->userid, self::COMPONENT);
        $this->assertTrue($DB->record_exists('local_studentrecords_log', ['action' => 'privacyexport',
            'relateduserid' => $data['student']->userid]));
    }

    /**
     * A staff member's export lists what they did as ids, and their faculty record with its credential copies.
     */
    public function test_export_for_a_staff_member(): void {
        global $DB;
        $staff = $this->getDataGenerator()->create_user();
        $id = faculty::save(['userid' => $staff->id, 'firstname' => 'Lee', 'lastname' => 'Park', 'stafftype' => 'faculty',
            'email' => 'lee@example.com']);
        $credid = faculty::save_credential(['facultyid' => $id, 'title' => 'PhD']);
        $this->store_file('credential', $credid, 'phd.pdf');
        $DB->insert_record('local_studentrecords_import', (object)['filename' => 'catalog.xlsx', 'userid' => $staff->id]);
        $DB->insert_record('local_studentrecords_msglog', (object)['msgkey' => 'staffalert', 'userid' => $staff->id,
            'status' => 'sent', 'timecreated' => time()]);

        $this->export_all_data_for_user((int)$staff->id, self::COMPONENT);

        $writer = writer::with_context(\context_user::instance($staff->id));
        $base = get_string('pluginname', self::COMPONENT);
        $path = [$base, get_string('facultyrecord', self::COMPONENT), $id];
        $this->assertSame('lee@example.com', $writer->get_data($path)->email);
        $this->assertSame('PhD', $writer->get_data($path)->credentials[0]['title']);
        $this->assertArrayHasKey('phd.pdf', $writer->get_files($path));
        $actions = $writer->get_data([$base, get_string('staffactions', self::COMPONENT)]);
        $this->assertSame(['catalog.xlsx'], $actions->files_imported);
        $messages = $writer->get_data([$base, get_string('msglog', self::COMPONENT)]);
        $this->assertSame('staffalert', $messages->entries[0]['msgkey']);
    }

    /**
     * An export leaves the IP address of an entry to the person who did it.
     */
    public function test_export_shows_only_the_users_own_ip_address(): void {
        $data = $this->make_student();
        $this->export_all_data_for_user((int)$data['student']->userid, self::COMPONENT);
        $writer = writer::with_context(\context_user::instance($data['student']->userid));
        $log = $writer->get_data([get_string('pluginname', self::COMPONENT), get_string('auditlog', self::COMPONENT)]);
        foreach ($log->entries as $entry) {
            $this->assertSame('', $entry->by_you ? '' : $entry->ip);
        }
    }

    /**
     * A deletion blanks the optional data, in the record and in its change history.
     */
    public function test_delete_blanks_optional_data(): void {
        global $DB;
        $data = $this->make_student();
        $student = $data['student'];

        provider::delete_data_for_user($this->approved((int)$student->userid));

        $after = students::get((int)$student->id);
        foreach (students::PERSONAL as $field) {
            $this->assertNull($after->$field, $field);
        }
        $this->assertFalse($DB->record_exists_select(
            'local_studentrecords_history',
            "tablename = ? AND recordid = ? AND fieldname = 'residency' AND newvalue IS NOT NULL",
            [students::TABLE, $student->id]
        ));
        $emerg = $DB->get_record('local_studentrecords_emerg', ['id' => $data['emerg']]);
        $this->assertNull($emerg->name);
        $this->assertSame(0, (int)$emerg->active);
        $this->assertNull($DB->get_field('local_studentrecords_demog', 'races', ['id' => $data['demog']]));
        $this->assertNull($DB->get_field('local_studentrecords_educ', 'notes', ['id' => $data['educ']]));
        $this->assertNull($DB->get_field('local_studentrecords_appdoc', 'note', ['id' => $data['appdoc']]));
        $this->assertFalse($DB->record_exists_select(
            'local_studentrecords_history',
            "tablename = 'local_studentrecords_emerg' AND newvalue = 'Maria Silva'"
        ));
    }

    /**
     * A deletion removes the documents, the cart, waitlist places, the message log and the person's IP address.
     */
    public function test_delete_removes_what_is_not_kept(): void {
        global $DB;
        $data = $this->make_student();
        $student = $data['student'];

        provider::delete_data_for_user($this->approved((int)$student->userid));

        $this->assertSame([], get_file_storage()->get_area_files(
            \context_system::instance()->id,
            self::COMPONENT,
            'studentdocs',
            $student->id,
            'filename',
            false
        ));
        foreach (['cart', 'wait', 'msglog'] as $table) {
            $this->assertFalse($DB->record_exists('local_studentrecords_' . $table, ['studentid' => $student->id]), $table);
        }
        $this->assertFalse($DB->record_exists_select(
            'local_studentrecords_log',
            'userid = ? AND ip IS NOT NULL',
            [$student->userid]
        ));
    }

    /**
     * A deletion keeps the permanent academic record, the FERPA records, the account and aid, and says it ran.
     */
    public function test_delete_keeps_the_permanent_record(): void {
        global $DB;
        $data = $this->make_student();
        $student = $data['student'];

        provider::delete_data_for_user($this->approved((int)$student->userid));

        $after = students::get((int)$student->id);
        $this->assertSame($student->legalfirstname, $after->legalfirstname);
        $this->assertSame('S100', $after->studentid);
        $this->assertEquals(make_timestamp(2001, 4, 12), $after->dob);
        $this->assertEquals($student->programid, $after->programid);
        $this->assertTrue($DB->record_exists('local_studentrecords_reg', ['id' => $data['reg']]));
        foreach (['stathis', 'educ', 'ferpa', 'relauth', 'letter', 'txn', 'aid'] as $table) {
            $this->assertTrue($DB->record_exists('local_studentrecords_' . $table, ['studentid' => $student->id]), $table);
        }
        $this->assertTrue($DB->record_exists('local_studentrecords_log', ['action' => 'privacyerase',
            'relateduserid' => $student->userid]));
    }

    /**
     * A deletion for one student leaves another alone, and a second run does no harm.
     */
    public function test_delete_touches_only_that_student(): void {
        $first = $this->make_student();
        $second = $this->make_student('S200');

        provider::delete_data_for_user($this->approved((int)$first['student']->userid));
        provider::delete_data_for_user($this->approved((int)$first['student']->userid));

        $this->assertSame('Ana', students::get((int)$second['student']->id)->preferredname);
        $this->assertCount(1, get_file_storage()->get_area_files(
            \context_system::instance()->id,
            self::COMPONENT,
            'studentdocs',
            $second['student']->id,
            'filename',
            false
        ));
    }

    /**
     * A staff member's request removes their contact details and IP address, not what they did to a student's record.
     */
    public function test_delete_for_a_staff_member_keeps_their_actions(): void {
        global $DB;
        $staff = $this->getDataGenerator()->create_user();
        $id = faculty::save(['userid' => $staff->id, 'firstname' => 'Lee', 'lastname' => 'Park', 'stafftype' => 'faculty',
            'email' => 'lee@example.com', 'phone' => '555', 'notes' => 'Note']);
        $this->setUser($staff);
        $data = $this->make_student();
        audit::log('view', 'student', (int)$data['student']->id, (int)$data['student']->userid, 'view as student');
        $DB->set_field('local_studentrecords_log', 'ip', '192.0.2.1', ['userid' => $staff->id]);
        $this->setAdminUser();

        provider::delete_data_for_user($this->approved((int)$staff->id));

        $person = faculty::get($id);
        $this->assertNull($person->email);
        $this->assertSame('Park', $person->lastname);
        $this->assertTrue($DB->record_exists('local_studentrecords_log', ['userid' => $staff->id, 'ip' => null]));
        $this->assertFalse($DB->record_exists_select(
            'local_studentrecords_log',
            'userid = ? AND ip IS NOT NULL',
            [$staff->id]
        ));
        $this->assertTrue($DB->record_exists('local_studentrecords_stathis', ['userid' => $staff->id]));
        $this->assertSame('Ana', students::get((int)$data['student']->id)->preferredname);
        $this->assertSame(1, $this->log_entries_with('view as student', (int)$data['student']->userid));
    }

    /**
     * A context of another user or another level deletes nothing.
     */
    public function test_delete_ignores_other_contexts(): void {
        $data = $this->make_student();
        $other = $this->getDataGenerator()->create_user();
        $user = \core_user::get_user($data['student']->userid);

        provider::delete_data_for_user(new approved_contextlist($user, self::COMPONENT, [
            \context_user::instance($other->id)->id, \context_system::instance()->id]));
        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertSame('Ana', students::get((int)$data['student']->id)->preferredname);
    }

    /**
     * Deleting by context and by a list of users does the same as a request by a user.
     */
    public function test_delete_by_context_and_by_list(): void {
        $first = $this->make_student();
        $second = $this->make_student('S200');
        $third = $this->make_student('S300');

        provider::delete_data_for_all_users_in_context(\context_user::instance($first['student']->userid));
        $context = \context_user::instance($second['student']->userid);
        provider::delete_data_for_users(new approved_userlist($context, self::COMPONENT, [(int)$second['student']->userid]));

        $this->assertNull(students::get((int)$first['student']->id)->preferredname);
        $this->assertNull(students::get((int)$second['student']->id)->preferredname);
        $this->assertSame('Ana', students::get((int)$third['student']->id)->preferredname);
    }

    /**
     * A deletion blanks the details of the log entries about the person, which can name someone a record was released to,
     * and keeps the entries, on each way into the deletion. The entries of other students keep their details.
     *
     * @dataProvider delete_path_provider
     * @param string $path
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('delete_path_provider')]
    public function test_delete_blanks_the_audit_details(string $path): void {
        global $DB;
        $data = $this->make_student();
        $other = $this->make_student('S200');
        $userid = (int)$data['student']->userid;
        foreach ([$data['student'], $other['student']] as $student) {
            ferpa::add_release($student, ['recipient' => 'Maria Silva', 'relationship' => 'Mother', 'scope' => ['grades'],
                'details' => '', 'fromdate' => time()]);
            letters::issue($student, 'Riverside Bank');
        }
        $entries = $DB->count_records('local_studentrecords_log', ['relateduserid' => $userid]);
        $this->assertSame(1, $this->log_entries_with('release authorization Maria Silva', $userid));
        $this->assertSame(1, $this->log_entries_with('to Riverside Bank', $userid));

        $this->delete_by($path, $userid);

        $this->assertSame(0, $this->log_entries_with('Maria Silva', $userid));
        $this->assertSame(0, $this->log_entries_with('Riverside Bank', $userid));
        $this->assertFalse($DB->record_exists_select(
            'local_studentrecords_log',
            "relateduserid = ? AND info IS NOT NULL AND info <> ''",
            [$userid]
        ));
        $this->assertGreaterThan($entries, $DB->count_records('local_studentrecords_log', ['relateduserid' => $userid]));
        $this->assertSame(1, $this->log_entries_with('Maria Silva', (int)$other['student']->userid));
    }

    /**
     * Data for tests of the ways into a deletion.
     *
     * @return array
     */
    public static function delete_path_provider(): array {
        return ['by user' => ['user'], 'by context' => ['context'], 'by list' => ['list']];
    }

    /**
     * A payment of the plugin is reported in the user context of the student who started the request, as the rest of the
     * student's data is. A request that is gone has no context of its own.
     */
    public function test_payment_is_reported_in_the_students_context(): void {
        global $DB;
        $student = $this->make_student()['student'];
        $paymentid = $this->make_payment($student);
        $reqid = (int)$DB->get_field('payments', 'itemid', ['id' => $paymentid]);
        $context = \context_user::instance($student->userid);

        $this->assertSame((int)$context->id, provider::get_contextid_for_payment(payments::AREA, $reqid));
        $this->assertNull(provider::get_contextid_for_payment(payments::AREA, $reqid + 1));
        $contexts = \core_payment\privacy\provider::get_contexts_for_userid((int)$student->userid)->get_contextids();
        $this->assertSame([(int)$context->id], array_map('intval', array_values($contexts)));
    }

    /**
     * The payment system lists who paid in a context: a student with a payment, and not one without a payment, who is still
     * listed for the plugin's own data.
     */
    public function test_payment_system_lists_who_paid(): void {
        $payer = $this->make_student()['student'];
        $this->make_payment($payer);
        $other = $this->make_student('S200')['student'];

        $listed = function (\stdClass $student, string $component): array {
            $list = new userlist(\context_user::instance($student->userid), $component);
            if ($component === 'core_payment') {
                \core_payment\privacy\provider::get_users_in_context($list);
            } else {
                provider::get_users_in_context($list);
            }
            return array_map('intval', array_values($list->get_userids()));
        };

        $this->assertSame([(int)$payer->userid], $listed($payer, 'core_payment'));
        $this->assertSame([], $listed($other, 'core_payment'));
        $this->assertSame([(int)$other->userid], $listed($other, self::COMPONENT));
    }

    /**
     * An export holds Moodle's rows of a student's payments, with what the payment provider keeps. Two payments of one request
     * (the student paid in two tabs) are both there. The call from the payment system exports the payments and nothing else.
     */
    public function test_export_holds_the_payments(): void {
        global $DB;
        $student = $this->make_student()['student'];
        $user = \core_user::get_user($student->userid);
        $context = \context_user::instance($user->id);
        $first = $this->make_payment($student);
        $second = $this->make_payment($student, (int)$DB->get_field('payments', 'itemid', ['id' => $first]));
        $path = fn(int $id) => [get_string('payments', 'payment'), get_string('pluginname', self::COMPONENT), 'payment-' . $id];
        $exports = fn() => $DB->count_records('local_studentrecords_log', ['action' => 'privacyexport']);

        \core_payment\privacy\provider::export_user_data(new approved_contextlist($user, 'core_payment', [$context->id]));

        $writer = writer::with_context($context);
        foreach ([$first, $second] as $id) {
            $this->assertStringContainsString('100.00', $writer->get_data($path($id))->amount);
            $this->assertSame('ORDER' . $id, $writer->get_data(array_merge($path($id), [
                get_string('gatewayname', 'paygw_paypal')]))->orderid);
        }
        $this->assertSame(0, $exports());
        $this->assertEmpty($writer->get_data([get_string('pluginname', self::COMPONENT),
            get_string('studentrecord', self::COMPONENT)]));

        writer::reset();
        provider::export_user_data($this->approved((int)$user->id));

        $writer = writer::with_context($context);
        $this->assertNotEmpty($writer->get_data($path($first)));
        $this->assertNotEmpty($writer->get_data([get_string('pluginname', self::COMPONENT),
            get_string('studentrecord', self::COMPONENT)]));
        $this->assertSame(1, $exports());
    }

    /**
     * A deletion removes Moodle's rows of the student's payments and what the payment provider keeps, on each way into it, and
     * nobody else's payments. The account keeps its lines and requests, because a school must keep those.
     *
     * @dataProvider delete_path_provider
     * @param string $path
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('delete_path_provider')]
    public function test_delete_removes_the_payments(string $path): void {
        global $DB;
        $student = $this->make_student()['student'];
        $classmate = $this->make_student('S200')['student'];
        $paymentid = $this->make_payment($student);
        $kept = $this->make_payment($classmate);
        $enrolfee = $this->make_payment($student, 0, 'enrol_fee');
        $sid = (int)$student->id;
        $lineid = $DB->insert_record('local_studentrecords_txn', (object)['studentid' => $sid, 'txntype' => 'payment',
            'amount' => -100, 'description' => 'Payment', 'paymentid' => $paymentid, 'receipt' => 'R2030-00001']);

        $this->delete_by($path, (int)$student->userid);

        $this->assertFalse($DB->record_exists('payments', ['id' => $paymentid]));
        $this->assertFalse($DB->record_exists('paygw_paypal', ['paymentid' => $paymentid]));
        $this->assertTrue($DB->record_exists('payments', ['id' => $kept]));
        $this->assertTrue($DB->record_exists('paygw_paypal', ['paymentid' => $kept]));
        $this->assertTrue($DB->record_exists('payments', ['id' => $enrolfee]));
        $this->assertTrue($DB->record_exists('local_studentrecords_txn', ['id' => $lineid, 'paymentid' => $paymentid]));
        $this->assertTrue($DB->record_exists(payments::REQ, ['studentid' => $sid, 'paymentid' => $paymentid]));
    }

    /**
     * The payment system asks through the same methods as a request for the plugin. Its calls remove the payments and leave
     * the rest of the record alone, so a request is not carried out twice.
     */
    public function test_payment_system_call_touches_only_the_payments(): void {
        global $DB;
        $first = $this->make_student()['student'];
        $second = $this->make_student('S200')['student'];
        $firstpayment = $this->make_payment($first);
        $secondpayment = $this->make_payment($second);
        $firstcontext = \context_user::instance($first->userid);
        $secondcontext = \context_user::instance($second->userid);

        provider::delete_data_for_user(new approved_contextlist(\core_user::get_user($first->userid), 'core_payment', [
            $firstcontext->id]));
        \core_payment\privacy\provider::delete_data_for_users(new approved_userlist($secondcontext, 'core_payment', [
            (int)$second->userid]));

        $this->assertFalse($DB->record_exists('payments', ['id' => $firstpayment]));
        $this->assertFalse($DB->record_exists('payments', ['id' => $secondpayment]));
        foreach ([$first, $second] as $student) {
            $this->assertSame('Ana', students::get((int)$student->id)->preferredname);
        }
        $this->assertFalse($DB->record_exists('local_studentrecords_log', ['action' => 'privacyerase']));
    }

    /**
     * A whole request through Moodle's privacy manager removes the payments and exports them. The payment system stops at
     * enrol_fee, which fails on a list with no course context, so the plugin's own deletion has to remove them.
     */
    public function test_request_through_the_privacy_manager(): void {
        global $DB;
        $student = $this->make_student()['student'];
        $paymentid = $this->make_payment($student);
        $manager = new \core_privacy\manager();
        $userid = (int)$student->userid;
        $user = \core_user::get_user($userid);
        $approved = new contextlist_collection($userid);
        foreach ($manager->get_contexts_for_userid($userid) as $list) {
            if (in_array($list->get_component(), ['core_payment', self::COMPONENT], true)) {
                $approved->add_contextlist(new approved_contextlist($user, $list->get_component(), $list->get_contextids()));
            }
        }
        $this->assertCount(2, $approved);

        $manager->delete_data_for_user($approved);
        $this->resetDebugging();

        $this->assertFalse($DB->record_exists('payments', ['id' => $paymentid]));
        $this->assertNull(students::get((int)$student->id)->preferredname);
        $this->assertSame(1, $DB->count_records('local_studentrecords_log', ['action' => 'privacyerase']));
    }

    /**
     * A payment whose request is gone, or whose payer no longer has a user context, is reported in the system context. It can
     * be exported and deleted there, and clearing the system context leaves every other payment alone.
     */
    public function test_payment_without_a_request_is_in_the_system_context(): void {
        global $DB;
        $student = $this->make_student()['student'];
        $user = \core_user::get_user($student->userid);
        $classmate = $this->make_student('S200')['student'];
        $system = \context_system::instance();
        $orphan = $this->make_payment($student);
        $DB->delete_records(payments::REQ, ['id' => $DB->get_field('payments', 'itemid', ['id' => $orphan])]);
        $kept = $this->make_payment($classmate);

        $list = new userlist($system, 'core_payment');
        provider::get_users_in_context($list);
        $this->assertSame([(int)$student->userid], array_map('intval', array_values($list->get_userids())));

        provider::export_user_data(new approved_contextlist($user, 'core_payment', [$system->id]));
        $this->assertNotEmpty(writer::with_context($system)->get_data([get_string('payments', 'payment'),
            get_string('pluginname', self::COMPONENT), 'payment-' . $orphan]));

        provider::delete_data_for_all_users_in_context($system);
        $this->assertFalse($DB->record_exists('payments', ['id' => $orphan]));
        $this->assertTrue($DB->record_exists('payments', ['id' => $kept]));
    }

    /**
     * Moodle's Delete user, then the cleanup that removes the user context of a deleted account.
     *
     * @param int $userid
     */
    private function remove_account(int $userid): void {
        delete_user(\core_user::get_user($userid));
        \core\context_helper::cleanup_instances();
    }

    /**
     * Once Moodle removed the user context of a deleted account, the person's data is reported in the system context, so that
     * a deletion request can reach it. Nobody else is reported there.
     */
    public function test_a_removed_account_is_reported_in_the_system_context(): void {
        $this->preventResetByRollback();
        $gone = $this->make_student()['student'];
        $here = $this->make_student('S200')['student'];
        $this->remove_account((int)$gone->userid);

        $contexts = fn(\stdClass $student) => array_map(
            'intval',
            array_values(provider::get_contexts_for_userid((int)$student->userid)->get_contextids())
        );
        $this->assertSame([(int)SYSCONTEXTID], $contexts($gone));
        $this->assertSame([(int)\context_user::instance($here->userid)->id], $contexts($here));

        $list = new userlist(\context_system::instance(), self::COMPONENT);
        provider::get_users_in_context($list);
        $this->assertSame([(int)$gone->userid], array_map('intval', array_values($list->get_userids())));
    }

    /**
     * A deletion for a removed account erases by user id, payments included. Everyone else keeps their data: another removed
     * account whose data and payments are in the same system context, and a person who still has an account.
     *
     * @dataProvider system_delete_path_provider
     * @param string $path
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('system_delete_path_provider')]
    public function test_delete_for_a_removed_account_touches_nobody_else(string $path): void {
        global $DB;
        $this->preventResetByRollback();
        $student = $this->make_student()['student'];
        $other = $this->make_student('S200')['student'];
        $here = $this->make_student('S300')['student'];
        $payments = [];
        foreach ([$student, $other, $here] as $person) {
            $payments[$person->id] = $this->make_payment($person);
            ferpa::add_release($person, ['recipient' => 'Maria Silva', 'relationship' => 'Mother', 'scope' => ['grades'],
                'details' => '', 'fromdate' => time()]);
        }
        $userid = (int)$student->userid;
        $this->remove_account($userid);
        $this->remove_account((int)$other->userid);
        $system = \context_system::instance();

        if ($path === 'user') {
            $user = \core_user::get_user($userid);
            provider::delete_data_for_user(new approved_contextlist($user, self::COMPONENT, [$system->id]));
        } else {
            provider::delete_data_for_users(new approved_userlist($system, self::COMPONENT, [$userid]));
        }

        $this->assertNull(students::get((int)$student->id)->preferredname);
        $this->assertSame(0, $this->log_entries_with('Maria Silva', $userid));
        $this->assertFalse($DB->record_exists('local_studentrecords_msglog', ['userid' => $userid]));
        $this->assertFalse($DB->record_exists('payments', ['id' => $payments[$student->id]]));
        $this->assertFalse($DB->record_exists('paygw_paypal', ['paymentid' => $payments[$student->id]]));
        foreach ([$other, $here] as $person) {
            $this->assertSame('Ana', students::get((int)$person->id)->preferredname);
            $this->assertSame(1, $this->log_entries_with('Maria Silva', (int)$person->userid));
            $this->assertTrue($DB->record_exists('payments', ['id' => $payments[$person->id]]));
            $this->assertTrue($DB->record_exists('paygw_paypal', ['paymentid' => $payments[$person->id]]));
        }
    }

    /**
     * Data for test_delete_for_a_removed_account_touches_nobody_else.
     *
     * @return array
     */
    public static function system_delete_path_provider(): array {
        return ['by user' => ['user'], 'by list' => ['list']];
    }

    /**
     * Someone who still has an account is not erased through the system context, even if their own name is on the request.
     */
    public function test_a_person_with_an_account_is_not_erased_through_the_system_context(): void {
        global $DB;
        $student = $this->make_student()['student'];
        $paymentid = $this->make_payment($student);
        $system = \context_system::instance();

        provider::delete_data_for_user(new approved_contextlist(\core_user::get_user($student->userid), self::COMPONENT, [
            $system->id]));
        provider::delete_data_for_users(new approved_userlist($system, self::COMPONENT, [(int)$student->userid]));

        $this->assertSame('Ana', students::get((int)$student->id)->preferredname);
        $this->assertTrue($DB->record_exists('payments', ['id' => $paymentid]));
    }

    /**
     * Clearing the system context erases the removed accounts, which are reported there, and nobody with an account.
     */
    public function test_clearing_the_system_context_erases_only_removed_accounts(): void {
        global $DB;
        $this->preventResetByRollback();
        $gone = $this->make_student()['student'];
        $here = $this->make_student('S200')['student'];
        $goneid = $this->make_payment($gone);
        $hereid = $this->make_payment($here);
        $this->remove_account((int)$gone->userid);

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertNull(students::get((int)$gone->id)->preferredname);
        $this->assertFalse($DB->record_exists('payments', ['id' => $goneid]));
        $this->assertSame('Ana', students::get((int)$here->id)->preferredname);
        $this->assertTrue($DB->record_exists('payments', ['id' => $hereid]));
    }

    /**
     * An export for a removed account still finds the record, in the system context.
     */
    public function test_export_for_a_removed_account(): void {
        $this->preventResetByRollback();
        $student = $this->make_student()['student'];
        $this->make_payment($student);
        $this->remove_account((int)$student->userid);
        $system = \context_system::instance();

        $user = \core_user::get_user($student->userid);
        provider::export_user_data(new approved_contextlist($user, self::COMPONENT, [$system->id]));

        $this->assertNotEmpty(writer::with_context($system)->get_data([get_string('pluginname', self::COMPONENT),
            get_string('studentrecord', self::COMPONENT)]));
    }

    /**
     * Set the data registry defaults, as a site does when it sets up its privacy purposes.
     */
    private function set_registry_defaults(): void {
        $purpose = \tool_dataprivacy\api::create_purpose((object)['name' => 'Student data', 'lawfulbases' => 'gdpr_art_6_1_a',
            'retentionperiod' => 'PT1H', 'protected' => 0]);
        $category = \tool_dataprivacy\api::create_category((object)['name' => 'Records']);
        foreach ([CONTEXT_SYSTEM, CONTEXT_USER] as $level) {
            \tool_dataprivacy\api::set_contextlevel((object)['contextlevel' => $level, 'purposeid' => $purpose->get('id'),
                'categoryid' => $category->get('id')]);
        }
    }

    /**
     * A deletion request for a user that a privacy officer approved, carried out by Moodle's own task.
     *
     * @param int $userid
     * @return int the request id
     */
    private function run_deletion_request(int $userid): int {
        global $DB;
        $api = \tool_dataprivacy\api::class;
        $DB->delete_records('tool_dataprivacy_request', ['userid' => $userid]);
        $request = $api::create_data_request(
            $userid,
            $api::DATAREQUEST_TYPE_DELETE,
            '',
            \tool_dataprivacy\data_request::DATAREQUEST_CREATION_MANUAL
        );
        $api::update_request_status($request->get('id'), $api::DATAREQUEST_STATUS_AWAITING_APPROVAL);
        $api::approve_data_request($request->get('id'));
        $DB->delete_records('task_adhoc', ['component' => 'tool_dataprivacy']);
        $sink = $this->redirectMessages();
        ob_start();
        $task = new \tool_dataprivacy\task\process_data_request_task();
        $task->set_custom_data(['requestid' => $request->get('id')]);
        try {
            $task->execute();
        } finally {
            ob_end_clean();
            $sink->close();
            $this->resetDebugging();
        }
        return (int)$request->get('id');
    }

    /**
     * A row of a finished deletion request, as Moodle leaves it.
     *
     * @param int $userid
     * @param array $fields other values
     * @return int the request id
     */
    private function finished_request(int $userid, array $fields = []): int {
        global $DB;
        $api = \tool_dataprivacy\api::class;
        return (int)$DB->insert_record('tool_dataprivacy_request', (object)($fields + ['userid' => $userid,
            'requestedby' => $userid, 'type' => $api::DATAREQUEST_TYPE_DELETE, 'status' => $api::DATAREQUEST_STATUS_DELETED,
            'timecreated' => time(), 'timemodified' => time()]));
    }

    /**
     * The proved failure, with Moodle's own tasks: Delete user, the context cleanup, then a deletion request that a privacy
     * officer approves. With the data registry defaults set, Moodle never approves the system context, so the request ends as
     * deleted with everything still here. The scheduled task finishes the job, with the defaults set or not.
     *
     * @dataProvider registry_provider
     * @param bool $defaults
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('registry_provider')]
    public function test_a_finished_request_for_a_removed_account_is_carried_out(bool $defaults): void {
        global $DB;
        $this->preventResetByRollback();
        if ($defaults) {
            $this->set_registry_defaults();
        }
        $this->assertSame($defaults, \tool_dataprivacy\data_registry::defaults_set());
        $student = $this->make_student()['student'];
        $paymentid = $this->make_payment($student);
        letters::issue($student, 'Riverside Bank');
        $userid = (int)$student->userid;
        $this->remove_account($userid);

        $requestid = $this->run_deletion_request($userid);

        $api = \tool_dataprivacy\api::class;
        $status = $DB->get_field('tool_dataprivacy_request', 'status', ['id' => $requestid]);
        $this->assertEquals($api::DATAREQUEST_STATUS_DELETED, $status);
        if ($defaults) {
            $this->assertSame('555-0100', students::get((int)$student->id)->phone);
            $this->assertTrue($DB->record_exists('payments', ['id' => $paymentid]));
        }

        (new \local_studentrecords\task\erase_removed_accounts())->execute();

        $this->assertNull(students::get((int)$student->id)->phone);
        $this->assertNull(students::get((int)$student->id)->preferredname);
        $this->assertSame(0, $this->log_entries_with('Riverside Bank', $userid));
        $this->assertFalse($DB->record_exists('local_studentrecords_msglog', ['userid' => $userid]));
        $this->assertFalse($DB->record_exists('payments', ['id' => $paymentid]));
        $this->assertFalse($DB->record_exists('paygw_paypal', ['paymentid' => $paymentid]));
        $this->expectOutputRegex('/deletion requests carried out: 1/');
    }

    /**
     * Data for test_a_finished_request_for_a_removed_account_is_carried_out.
     *
     * @return array
     */
    public static function registry_provider(): array {
        return ['data registry defaults set' => [true], 'no data registry defaults' => [false]];
    }

    /**
     * The task acts on a finished deletion request and on nothing else. A request that is not finished, or that is no deletion,
     * has no privacy officer's yes behind it. Nobody else is touched, such as another removed account in the same context.
     *
     * @dataProvider unfinished_request_provider
     * @param array $fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unfinished_request_provider')]
    public function test_only_a_finished_deletion_request_erases(array $fields): void {
        global $DB;
        $this->preventResetByRollback();
        $student = $this->make_student()['student'];
        $paymentid = $this->make_payment($student);
        $this->remove_account((int)$student->userid);
        $DB->delete_records('tool_dataprivacy_request');
        $this->finished_request((int)$student->userid, $fields);

        $this->assertSame(0, provider::erase_removed_accounts());

        $this->assertSame('Ana', students::get((int)$student->id)->preferredname);
        $this->assertTrue($DB->record_exists('payments', ['id' => $paymentid]));
    }

    /**
     * Data for test_only_a_finished_deletion_request_erases.
     *
     * @return array
     */
    public static function unfinished_request_provider(): array {
        $api = \tool_dataprivacy\api::class;
        return [
            'waiting for the officer' => [['status' => $api::DATAREQUEST_STATUS_AWAITING_APPROVAL]],
            'approved' => [['status' => $api::DATAREQUEST_STATUS_APPROVED]],
            'being processed' => [['status' => $api::DATAREQUEST_STATUS_PROCESSING]],
            'rejected' => [['status' => $api::DATAREQUEST_STATUS_REJECTED]],
            'cancelled' => [['status' => $api::DATAREQUEST_STATUS_CANCELLED]],
            'an export' => [['type' => $api::DATAREQUEST_TYPE_EXPORT, 'status' => $api::DATAREQUEST_STATUS_COMPLETE]],
        ];
    }

    /**
     * A person with an account is left alone, and so is a deleted account whose user context is still there: Moodle's own
     * request reaches that one.
     */
    public function test_the_task_waits_for_the_user_context_to_go(): void {
        global $DB;
        $this->preventResetByRollback();
        $here = $this->make_student()['student'];
        $going = $this->make_student('S200')['student'];
        delete_user(\core_user::get_user($going->userid));
        $DB->delete_records('tool_dataprivacy_request');
        $this->finished_request((int)$here->userid);
        $this->finished_request((int)$going->userid);

        $this->assertSame(0, provider::erase_removed_accounts());

        $this->assertSame('Ana', students::get((int)$here->id)->preferredname);
        $this->assertSame('Ana', students::get((int)$going->id)->preferredname);

        \core\context_helper::cleanup_instances();
        $this->assertSame(1, provider::erase_removed_accounts());
        $this->assertNull(students::get((int)$going->id)->preferredname);
        $this->assertSame('Ana', students::get((int)$here->id)->preferredname);
    }

    /**
     * The task touches only the person of the request, even when another removed account has data and payments in the same
     * system context, and it is safe to run again: a request is carried out once.
     */
    public function test_the_task_erases_the_person_of_the_request_once(): void {
        global $DB;
        $this->preventResetByRollback();
        $student = $this->make_student()['student'];
        $other = $this->make_student('S200')['student'];
        $paymentid = $this->make_payment($student);
        $otherid = $this->make_payment($other);
        $this->remove_account((int)$student->userid);
        $this->remove_account((int)$other->userid);
        $DB->delete_records('tool_dataprivacy_request');
        $requestid = $this->finished_request((int)$student->userid);

        $this->assertSame(1, provider::erase_removed_accounts());
        $erased = $DB->count_records('local_studentrecords_log', ['action' => 'privacyerase']);
        $changes = $DB->count_records('local_studentrecords_history', ['fieldname' => '*']);
        $this->assertSame(0, provider::erase_removed_accounts());

        $this->assertSame($erased, $DB->count_records('local_studentrecords_log', ['action' => 'privacyerase']));
        $this->assertSame($changes, $DB->count_records('local_studentrecords_history', ['fieldname' => '*']));
        $this->assertTrue($DB->record_exists('local_studentrecords_log', ['action' => 'privacyerase', 'objecttype' => 'datarequest',
            'objectid' => $requestid, 'relateduserid' => $student->userid]));
        $this->assertFalse($DB->record_exists('payments', ['id' => $paymentid]));
        $this->assertSame('Ana', students::get((int)$other->id)->preferredname);
        $this->assertTrue($DB->record_exists('payments', ['id' => $otherid]));
    }

    /**
     * A removed account with no data here leaves no trace of the task, and two requests of one person erase once.
     */
    public function test_the_task_needs_data_here_and_erases_a_person_once(): void {
        global $DB;
        $this->preventResetByRollback();
        $stranger = $this->getDataGenerator()->create_user();
        $student = $this->make_student()['student'];
        $this->remove_account((int)$stranger->id);
        $this->remove_account((int)$student->userid);
        $DB->delete_records('tool_dataprivacy_request');
        $this->finished_request((int)$stranger->id);
        $this->finished_request((int)$student->userid);
        $this->finished_request((int)$student->userid);

        $this->assertSame(1, provider::erase_removed_accounts());

        $this->assertFalse($DB->record_exists('local_studentrecords_log', ['relateduserid' => $stranger->id]));
        $marks = fn(string $type) => $DB->count_records('local_studentrecords_log', ['action' => 'privacyerase',
            'objecttype' => $type]);
        $this->assertSame(1, $marks('student'));
        $this->assertSame(2, $marks('datarequest'));
    }

    /**
     * The task runs on its own every hour, and the audit log can name what it did.
     */
    public function test_the_task_is_scheduled(): void {
        $tasks = \core\task\manager::load_default_scheduled_tasks_for_component(self::COMPONENT);

        $found = array_filter($tasks, fn($task) => $task instanceof \local_studentrecords\task\erase_removed_accounts);

        $this->assertCount(1, $found);
        $this->assertSame('*', reset($found)->get_hour());
        $this->assertNotEmpty(reset($found)->get_name());
        $this->assertTrue(get_string_manager()->string_exists('obj_datarequest', self::COMPONENT));
    }

    /**
     * A person whose user context is missing is not a removed account while the account is in use: the system context doesn't
     * list them, and a finished request doesn't erase them.
     */
    public function test_a_live_account_is_never_a_removed_account(): void {
        global $DB;
        $student = $this->make_student()['student'];
        $DB->delete_records('context', ['contextlevel' => CONTEXT_USER, 'instanceid' => $student->userid]);
        $this->finished_request((int)$student->userid);
        $list = new userlist(\context_system::instance(), self::COMPONENT);

        provider::get_users_in_context($list);

        $this->assertSame([], array_values($list->get_userids()));
        $this->assertSame(0, provider::erase_removed_accounts());
        $this->assertSame('Ana', students::get((int)$student->id)->preferredname);
    }

    /**
     * Run a deletion for a person.
     *
     * @param string $path user, context or list
     * @param int $userid
     */
    private function delete_by(string $path, int $userid): void {
        $context = \context_user::instance($userid);
        if ($path === 'user') {
            provider::delete_data_for_user($this->approved($userid));
        } else if ($path === 'context') {
            provider::delete_data_for_all_users_in_context($context);
        } else {
            provider::delete_data_for_users(new approved_userlist($context, self::COMPONENT, [$userid]));
        }
    }

    /**
     * How many log entries about a user have a text in their details.
     *
     * @param string $text
     * @param int $userid
     * @return int
     */
    private function log_entries_with(string $text, int $userid): int {
        global $DB;
        return $DB->count_records_select(
            'local_studentrecords_log',
            'relateduserid = ? AND ' . $DB->sql_like('info', '?'),
            [$userid, '%' . $DB->sql_like_escape($text) . '%']
        );
    }

    /**
     * A paid online payment of a student: the request, Moodle's row and the row of the payment provider.
     *
     * @param \stdClass $student
     * @param int $reqid a request of the student to pay again, 0 for a new one
     * @param string $component what the payment was for
     * @return int Moodle payment id
     */
    private function make_payment(\stdClass $student, int $reqid = 0, string $component = self::COMPONENT): int {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('core_payment');
        $accountid = (int)$generator->create_payment_account(['gateways' => 'paypal'])->get('id');
        if (!$reqid) {
            $reqid = (int)$DB->insert_record(payments::REQ, (object)['studentid' => $student->id, 'userid' => $student->userid,
                'amount' => 100, 'accountid' => $accountid, 'status' => 'paid', 'timecreated' => time()]);
        }
        $id = $generator->create_payment(['accountid' => $accountid, 'amount' => 100, 'userid' => $student->userid,
            'gateway' => 'paypal', 'component' => $component, 'paymentarea' => payments::AREA, 'itemid' => $reqid,
            'currency' => 'USD', 'timecreated' => time(), 'timemodified' => time()]);
        $DB->insert_record('paygw_paypal', (object)['paymentid' => $id, 'pp_orderid' => 'ORDER' . $id]);
        $DB->set_field(payments::REQ, 'paymentid', $id, ['id' => $reqid]);
        return $id;
    }

    /**
     * Whether a column holds the id of a person.
     *
     * @param string $table
     * @param string $column
     * @return bool
     */
    private function holds_a_user(string $table, string $column): bool {
        return !in_array($table . '.' . $column, self::NOTUSERS, true) &&
            preg_match('/^(userid|usermodified|relateduserid|[a-z]+by)$/', $column);
    }

    /**
     * Tables and their columns in install.xml.
     *
     * @return array table => column names
     */
    private function schema(): array {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/db/install.xml');
        $out = [];
        foreach ($xml->TABLES->TABLE as $table) {
            foreach ($table->FIELDS->FIELD as $field) {
                $out[(string)$table['NAME']][] = (string)$field['NAME'];
            }
        }
        return $out;
    }

    /**
     * What the provider declares.
     *
     * @return array table => field => string
     */
    private function metadata(): array {
        $out = [];
        foreach (provider::get_metadata(new collection(self::COMPONENT))->get_collection() as $item) {
            if ($item instanceof database_table) {
                $out[$item->get_name()] = $item->get_privacy_fields();
            }
        }
        return $out;
    }

    /**
     * Store a file of the plugin.
     *
     * @param string $area
     * @param int $itemid
     * @param string $name
     */
    private function store_file(string $area, int $itemid, string $name): void {
        get_file_storage()->create_file_from_string(['contextid' => \context_system::instance()->id,
            'component' => self::COMPONENT, 'filearea' => $area, 'itemid' => $itemid, 'filepath' => '/',
            'filename' => $name], 'file content');
    }

    /**
     * A student with something in every kind of table.
     *
     * @param string $studentid
     * @return array student, and the ids of rows: reg, emerg, demog, educ, appdoc
     */
    private function make_student(string $studentid = 'S100'): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $sr = $gen->get_plugin_generator(self::COMPONENT);
        $user = $gen->create_user();
        $program = $sr->create_program();
        $term = $sr->create_term();
        $offer = $sr->create_offering(['courseid' => $sr->create_course()->id, 'termid' => $term->id]);
        $student = $sr->create_student(['userid' => $user->id, 'studentid' => $studentid, 'programid' => $program->id]);
        students::save(['id' => $student->id, 'preferredname' => 'Ana', 'phone' => '555-0100', 'usaddress' => '1 Main St',
            'homeaddress' => '2 Home Rd', 'homecountry' => 'BR', 'visatype' => 'F-1', 'sevisid' => 'N0012345678',
            'residency' => 'international', 'citizenship' => 'other', 'firstgen' => 'yes', 'highschool' => 'Central High',
            'hsyear' => 2019, 'notes' => 'A private note', 'dob' => make_timestamp(2001, 4, 12)]);
        $student = students::get((int)$student->id);
        $sid = (int)$student->id;
        $userid = (int)$user->id;
        $reg = $sr->create_registration(['studentid' => $sid, 'offerid' => $offer->id]);
        $emerg = records::save('local_studentrecords_emerg', ['studentid' => $sid, 'name' => 'Maria Silva',
            'relationship' => 'Mother', 'phone' => '555-0101'], 'form', $userid);
        $demog = records::save('local_studentrecords_demog', ['studentid' => $sid, 'sex' => 'female', 'hispanic' => 'no',
            'races' => 'white'], 'form', $userid);
        $educ = records::save('local_studentrecords_educ', ['studentid' => $sid, 'school' => 'Central High',
            'notes' => 'Scholarship'], 'form', $userid);
        $docid = $DB->insert_record('local_studentrecords_reqdoc', (object)['name' => 'Transcript ' . $studentid]);
        $appdoc = records::save('local_studentrecords_appdoc', ['studentid' => $sid, 'reqdocid' => $docid,
            'state' => 'received', 'note' => 'From the mother'], 'form', $userid);
        $DB->insert_record('local_studentrecords_cart', (object)['studentid' => $sid, 'offerid' => $offer->id]);
        $DB->insert_record('local_studentrecords_wait', (object)['kind' => 'section', 'offerid' => $offer->id,
            'studentid' => $sid, 'status' => 'waiting']);
        $DB->insert_record('local_studentrecords_msglog', (object)['msgkey' => 'registered', 'userid' => $userid,
            'studentid' => $sid, 'status' => 'sent', 'timecreated' => time()]);
        $DB->insert_record('local_studentrecords_ferpa', (object)['studentid' => $sid, 'acadyear' => '2030-2031',
            'acknowledged' => time()]);
        $DB->insert_record('local_studentrecords_relauth', (object)['studentid' => $sid, 'recipient' => 'Maria Silva',
            'scope' => 'grades', 'fromdate' => time()]);
        $DB->insert_record('local_studentrecords_letter', (object)['studentid' => $sid, 'letterno' => 'L-' . $studentid]);
        $DB->insert_record('local_studentrecords_txn', (object)['studentid' => $sid, 'txntype' => 'charge', 'amount' => 100,
            'description' => 'Tuition']);
        $DB->insert_record('local_studentrecords_aid', (object)['studentid' => $sid, 'awardyear' => '2030-2031',
            'fund' => 'pell', 'amount' => 500]);
        $this->store_file('studentdocs', $sid, 'transcript.pdf');
        $this->setUser($user);
        audit::log('view', 'student', $sid, $userid);
        $this->setAdminUser();
        $DB->set_field('local_studentrecords_log', 'ip', '192.0.2.7', ['userid' => $userid]);
        return ['student' => $student, 'reg' => $reg, 'emerg' => $emerg, 'demog' => $demog, 'educ' => $educ, 'appdoc' => $appdoc];
    }

    /**
     * The approved context list of a user's own context.
     *
     * @param int $userid
     * @return approved_contextlist
     */
    private function approved(int $userid): approved_contextlist {
        return new approved_contextlist(\core_user::get_user($userid), self::COMPONENT, [\context_user::instance($userid)->id]);
    }
}
