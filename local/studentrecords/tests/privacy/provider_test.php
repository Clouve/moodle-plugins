<?php

namespace local_studentrecords\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_studentrecords\local\audit;
use local_studentrecords\local\faculty;
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
        audit::log('view', 'student', (int)$data['student']->id, (int)$data['student']->userid);
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
