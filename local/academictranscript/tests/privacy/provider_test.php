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
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_academictranscript\local\import\drafts;
use local_academictranscript\local\logger;
use local_academictranscript\local\posting;
use local_academictranscript\local\scale;
use local_academictranscript\local\transcript;

#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
/**
 * Tests for the privacy provider.
 *
 * The data subject is the student. A row that names a user only as the staff member who acted (who changed it, posted
 * it, issued it, asked for it or decided it, or who did what the log says about a student) belongs to the student's
 * record, so it is not exported or deleted for that staff member. A log entry about no student is different: a failed
 * verification made while logged in holds the user's own id and IP address and nothing about a student, so it belongs
 * to that user. A draft belongs to the user who uploaded the document. The school's settings (the grading scale, marks,
 * programs, catalog and terms) are nobody's data: they only name the admin who last changed a row, which the metadata
 * declares and the privacy tools leave alone. An export gives the IP address of a log entry only to the user who did it.
 *
 * The tests make their rows with the plugin's own code where it has some (requesting and deciding a change, issuing a
 * transcript, storing drafts, writing the log) and with the test data generator for the rest, as a staff member who is
 * logged in.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_academictranscript\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var string The component name. */
    private const COMPONENT = 'local_academictranscript';

    /** @var string The reason on the change request that make_student() files. */
    private const CHANGE_REASON = 'The final exam was marked again.';

    /** @var array[] For each table: the key of its description, then the fields that it declares. */
    private const METADATA = [
        'local_academictranscript_student' => ['privacy:metadata:student', [
            'userid', 'studentid', 'dob', 'programid', 'admitterm', 'status', 'qualification', 'awarddate', 'usermodified',
            'timemodified',
        ]],
        'local_academictranscript_code' => ['privacy:metadata:codetable', [
            'userid', 'courseid', 'code', 'note', 'reason', 'termid', 'usermodified', 'timemodified',
        ]],
        'local_academictranscript_transfer' => ['privacy:metadata:transfer', [
            'userid', 'institution', 'coursecode', 'title', 'hours', 'dateaccepted', 'note', 'active', 'usermodified',
            'timemodified',
        ]],
        'local_academictranscript_posted' => ['privacy:metadata:posted', [
            'userid', 'courseid', 'termid', 'coursecode', 'coursename', 'hours', 'percent', 'grade', 'note', 'postedby',
            'timeposted', 'timemodified',
        ]],
        'local_academictranscript_change' => ['privacy:metadata:change', [
            'postedid', 'oldgrade', 'newgrade', 'oldnote', 'newnote', 'reason', 'status', 'requestedby', 'timerequested',
            'decidedby', 'timedecided', 'decisionnote',
        ]],
        'local_academictranscript_issue' => ['privacy:metadata:issue', [
            'userid', 'verifycode', 'issuedby', 'snapshot', 'recipient', 'transcriptno', 'timecreated',
        ]],
        'local_academictranscript_log' => ['privacy:metadata:log', [
            'action', 'userid', 'studentid', 'issueid', 'ip', 'info', 'timecreated',
        ]],
        'local_academictranscript_draft' => ['privacy:metadata:draft', [
            'userid', 'sourcename', 'excerpt', 'decidedby', 'timecreated', 'timedecided',
        ]],
        'local_academictranscript_grade' => ['privacy:metadata:settings', ['usermodified', 'timemodified']],
        'local_academictranscript_mark' => ['privacy:metadata:settings', ['usermodified', 'timemodified']],
        'local_academictranscript_program' => ['privacy:metadata:settings', ['usermodified', 'timemodified']],
        'local_academictranscript_catalog' => ['privacy:metadata:settings', ['usermodified', 'timemodified']],
        'local_academictranscript_term' => ['privacy:metadata:settings', ['usermodified', 'timemodified']],
    ];

    /** @var string[] The tables of the school's settings. An export and a deletion never touch them. */
    private const SETTINGS_TABLES = [
        'local_academictranscript_grade', 'local_academictranscript_mark', 'local_academictranscript_program',
        'local_academictranscript_catalog', 'local_academictranscript_term',
    ];

    /**
     * @var string[][] For each table, the columns that the metadata leaves out because they hold no personal data: the
     *      row id, units and flags, the settings an uploaded document proposes, and everything in the school's settings
     *      except the admin who last changed a row and when.
     */
    private const NOT_PERSONAL = [
        'local_academictranscript_student' => ['id'],
        'local_academictranscript_code' => ['id'],
        'local_academictranscript_transfer' => ['id'],
        'local_academictranscript_posted' => ['id', 'unittype'],
        'local_academictranscript_change' => ['id'],
        'local_academictranscript_issue' => ['id'],
        'local_academictranscript_log' => ['id'],
        'local_academictranscript_draft' => ['id', 'itemtype', 'data', 'source', 'sourcefileid', 'quotefound', 'status'],
        'local_academictranscript_grade' => [
            'id', 'letter', 'minpct', 'maxpct', 'points', 'ingpa', 'earns', 'description', 'percentlabel', 'active',
        ],
        'local_academictranscript_mark' => [
            'id', 'code', 'label', 'description', 'earns', 'systemmark', 'sortorder', 'active',
        ],
        'local_academictranscript_program' => ['id', 'name', 'credential', 'unittype', 'totalhours', 'active'],
        'local_academictranscript_catalog' => ['id', 'code', 'title', 'hours', 'unittype', 'programid', 'active'],
        'local_academictranscript_term' => ['id', 'name', 'startdate', 'enddate', 'optionindex'],
    ];

    /** @var string[] For each table about a student (without the plugin prefix), the column that names who acted. */
    private const ACTOR_COLUMNS = [
        'student' => 'usermodified', 'code' => 'usermodified', 'transfer' => 'usermodified', 'posted' => 'postedby',
        'change' => 'requestedby', 'issue' => 'issuedby', 'log' => 'userid',
    ];

    /**
     * Start each test with an empty request cache.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        scale::reset_cache();
    }

    /**
     * The metadata has one table item for each of the thirteen tables, with the description key of the table. The
     * recipient of a transcript and the staff who acted on a record are declared, and so is the admin who last changed
     * a row of the school's settings, so the registry shows every kind of person who appears in it.
     */
    public function test_get_metadata_declares_every_table(): void {
        $collection = provider::get_metadata(new collection(self::COMPONENT));

        $this->assertCount(13, $collection->get_collection());
        $items = $this->metadata();
        $this->assertEqualsCanonicalizing(array_keys(self::METADATA), array_keys($items));
        foreach (self::METADATA as $table => [$summary]) {
            $this->assertSame($summary, $items[$table]->get_summary(), $table);
        }
        $people = [
            'issue' => ['issuedby', 'recipient'],
            'posted' => ['postedby'],
            'change' => ['requestedby', 'decidedby'],
            'log' => ['userid', 'studentid'],
            'draft' => ['userid', 'decidedby'],
            'grade' => ['usermodified'],
            'mark' => ['usermodified'],
            'program' => ['usermodified'],
            'catalog' => ['usermodified'],
            'term' => ['usermodified'],
        ];
        foreach ($people as $table => $fields) {
            $declared = $items['local_academictranscript_' . $table]->get_privacy_fields();
            foreach ($fields as $field) {
                $this->assertArrayHasKey($field, $declared, "$table $field");
            }
        }
    }

    /**
     * Each table declares the fields that hold personal data, and no other field.
     *
     * @dataProvider declared_fields_provider
     * @param string $table The table
     * @param string[] $fields The fields that the table declares
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('declared_fields_provider')]
    public function test_get_metadata_declares_the_fields_of_each_table(string $table, array $fields): void {
        $items = $this->metadata();

        $this->assertArrayHasKey($table, $items);
        $this->assertEqualsCanonicalizing($fields, array_keys($items[$table]->get_privacy_fields()));
    }

    /**
     * Data for test_get_metadata_declares_the_fields_of_each_table.
     *
     * @return array
     */
    public static function declared_fields_provider(): array {
        $sets = [];
        foreach (self::METADATA as $table => [, $fields]) {
            $sets[$table] = [$table, $fields];
        }
        return $sets;
    }

    /**
     * Every table in db/install.xml is in the metadata, and every column of it is declared or is listed as holding no
     * personal data, so a table or a column added later cannot be left out of the metadata unnoticed. A declared table
     * and a declared field are real.
     */
    public function test_every_table_and_column_is_declared_or_holds_no_personal_data(): void {
        $items = $this->metadata();
        $schema = $this->schema();

        $this->assertSame([], array_values(array_diff(array_keys($schema), array_keys($items))), 'Tables not in the metadata');
        $this->assertSame([], array_values(array_diff(array_keys($items), array_keys($schema))), 'Metadata for no table');
        foreach ($schema as $table => $columns) {
            $declared = array_keys($items[$table]->get_privacy_fields());

            $this->assertSame([], array_values(array_diff($declared, $columns)), "$table declares a column it does not have");
            $this->assertSame(
                [],
                array_values(array_diff($columns, $declared, self::NOT_PERSONAL[$table] ?? [])),
                "$table has a column that the metadata does not mention"
            );
        }
    }

    /**
     * The description of each table and of each field is a language string that exists.
     */
    public function test_every_metadata_string_exists(): void {
        $strings = get_string_manager();

        foreach ($this->metadata() as $table => $item) {
            foreach ([$item->get_summary(), ...array_values($item->get_privacy_fields())] as $key) {
                $this->assertTrue($strings->string_exists($key, self::COMPONENT), "$table uses the missing string $key");
            }
        }
    }

    /**
     * A student with a posted grade has the student's own user context, and a user with nothing stored has none.
     */
    public function test_contexts_for_student_with_data(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator(self::COMPONENT);
        $student = $this->getDataGenerator()->create_user();
        $nodata = $this->getDataGenerator()->create_user();
        $generator->create_posted_grade(['userid' => $student->id, 'courseid' => $this->getDataGenerator()->create_course()->id,
            'termid' => $generator->create_term()->id]);

        $this->assertEquals(
            [\context_user::instance($student->id)->id],
            provider::get_contexts_for_userid($student->id)->get_contextids()
        );
        $this->assertSame([], provider::get_contexts_for_userid($nodata->id)->get_contextids());
    }

    /**
     * A row in any of the tables that name the user, or a log entry about the user, gives the user a context.
     *
     * @dataProvider data_table_provider
     * @param string $table The table, without the plugin prefix
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('data_table_provider')]
    public function test_contexts_for_a_row_in_any_data_table(string $table): void {
        $user = $this->getDataGenerator()->create_user();
        $bystander = $this->getDataGenerator()->create_user();

        $this->store_row($table, $user);

        $this->assertEquals(
            [\context_user::instance($user->id)->id],
            provider::get_contexts_for_userid($user->id)->get_contextids()
        );
        $this->assertSame([], provider::get_contexts_for_userid($bystander->id)->get_contextids());
    }

    /**
     * Data for test_contexts_for_a_row_in_any_data_table.
     *
     * @return array
     */
    public static function data_table_provider(): array {
        return [
            'student details' => ['student'],
            'code and note entered by hand' => ['code'],
            'transfer credit' => ['transfer'],
            'posted grade' => ['posted'],
            'issued transcript' => ['issue'],
            'draft from an uploaded document' => ['draft'],
            'log entry about the student' => ['log'],
            'failed verification made while logged in' => ['failedverification'],
        ];
    }

    /**
     * A user who only appears in the log as the student has a context. The staff member who did the thing the log
     * entry records does not get one from it.
     */
    public function test_contexts_for_user_with_log_rows_only(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $viewer = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);
        logger::log('view', $user->id);
        $this->assertEquals(
            1,
            $DB->count_records('local_academictranscript_log', ['studentid' => $user->id, 'userid' => $viewer->id])
        );

        $this->assertEquals(
            [\context_user::instance($user->id)->id],
            provider::get_contexts_for_userid($user->id)->get_contextids()
        );
        $this->assertSame([], provider::get_contexts_for_userid($viewer->id)->get_contextids());
    }

    /**
     * A visitor who is not logged in leaves a failed verification with an IP address and no user. No user can ask for
     * it, so it gives nobody a context, and a failed verification made by one user gives no context to another.
     */
    public function test_contexts_ignore_failed_verifications_of_other_people(): void {
        $verifier = $this->getDataGenerator()->create_user();
        $bystander = $this->getDataGenerator()->create_user();
        $this->log_as(null, 'verify_fail', 0, '198.51.100.4');
        $this->assertSame([], provider::get_contexts_for_userid($verifier->id)->get_contextids());

        $this->log_as($verifier, 'verify_fail', 0, '203.0.113.9');

        $this->assertEquals(
            [\context_user::instance($verifier->id)->id],
            provider::get_contexts_for_userid($verifier->id)->get_contextids()
        );
        $this->assertSame([], provider::get_contexts_for_userid($bystander->id)->get_contextids());
    }

    /**
     * The user context of a user who only made a failed verification lists that user. The context of another user, who
     * has no data, lists nobody.
     */
    public function test_users_in_context_lists_a_user_with_a_failed_verification(): void {
        $verifier = $this->getDataGenerator()->create_user();
        $bystander = $this->getDataGenerator()->create_user();
        $this->log_as($verifier, 'verify_fail', 0, '203.0.113.9');

        $this->assertSame([(int)$verifier->id], $this->users_in(\context_user::instance($verifier->id)));
        $this->assertSame([], $this->users_in(\context_user::instance($bystander->id)));
    }

    /**
     * Staff who changed, posted, issued, asked for or decided the rows of a student, and who appear in the log, have no
     * context from that: the rows belong to the student.
     */
    public function test_contexts_ignore_users_who_only_acted(): void {
        $staff = $this->getDataGenerator()->create_user();
        [$student, $rows] = $this->make_student($staff, 'S-1001');
        posting::decide($rows['change'][0], true, 'Approved after the exam was marked again.');
        $this->assert_acted_by($staff, $rows, true);

        $this->assertSame([], provider::get_contexts_for_userid($staff->id)->get_contextids());
        $this->assertEquals(
            [\context_user::instance($student->id)->id],
            provider::get_contexts_for_userid($student->id)->get_contextids()
        );
    }

    /**
     * An admin who changed the school's settings (the grading scale, marks, programs, catalog or terms) has no data in
     * the privacy tools. The metadata declares the admin id, but there is no context for the admin, and a deletion
     * for the admin leaves every setting as it is.
     */
    public function test_settings_changed_by_a_user_are_not_that_users_data(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator(self::COMPONENT);
        $admin = $this->getDataGenerator()->create_user();
        $this->setUser($admin);
        scale::replace_grades([['letter' => 'S', 'minpct' => 50, 'maxpct' => 100, 'points' => 4, 'ingpa' => 1, 'earns' => 1]]);
        scale::save_marks([['code' => 'AUD', 'label' => 'Audit', 'earns' => 0]]);
        $generator->create_program();
        $generator->create_catalog_course();
        $generator->create_term();
        $changed = array_fill_keys(self::SETTINGS_TABLES, 1);
        $this->assertSame($changed, $this->settings_changed_by($admin));

        $this->assertSame([], provider::get_contexts_for_userid($admin->id)->get_contextids());
        provider::delete_data_for_user($this->approved($admin));
        provider::delete_data_for_all_users_in_context(\context_user::instance($admin->id));

        $this->assertSame($changed, $this->settings_changed_by($admin));
    }

    /**
     * The user context of a student lists the student. That holds for a student who has only log entries too. The
     * context of a user with no data, and any other kind of context, list nobody.
     */
    public function test_users_in_context(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator(self::COMPONENT);
        $student = $this->getDataGenerator()->create_user();
        $logonly = $this->getDataGenerator()->create_user();
        $nodata = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $generator->create_posted_grade(['userid' => $student->id, 'courseid' => $course->id,
            'termid' => $generator->create_term()->id]);
        logger::log('view', $logonly->id);

        $this->assertSame([(int)$student->id], $this->users_in(\context_user::instance($student->id)));
        $this->assertSame([(int)$logonly->id], $this->users_in(\context_user::instance($logonly->id)));
        $this->assertSame([], $this->users_in(\context_user::instance($nodata->id)));
        $this->assertSame([], $this->users_in(\context_system::instance()));
        $this->assertSame([], $this->users_in(\context_course::instance($course->id)));
    }

    /**
     * The export of a student holds the change requests on the student's posted grades, with the reason and the
     * decision, in the user context of the student.
     */
    public function test_export_includes_change_requests(): void {
        $staff = $this->getDataGenerator()->create_user();
        [$student, $rows] = $this->make_student($staff, 'S-1001');
        posting::decide($rows['change'][0], false, 'The gradebook agrees with the posted grade.');

        $this->export_all_data_for_user($student->id, self::COMPONENT);

        $data = (array)$this->exported($student);
        $this->assertArrayHasKey('local_academictranscript_change', $data);
        $changes = $data['local_academictranscript_change'];
        $this->assertCount(1, $changes);
        $this->assertSame(self::CHANGE_REASON, $changes[0]->reason);
        $this->assertSame('rejected', $changes[0]->status);
        $this->assertSame('The gradebook agrees with the posted grade.', $changes[0]->decisionnote);
        $this->assertSame('A', $changes[0]->oldgrade);
        $this->assertSame('B', $changes[0]->newgrade);
    }

    /**
     * The export holds the rows of each of the eight tables of student records that belong to the student, and no row
     * of another student. The school's settings are not in it.
     */
    public function test_export_includes_the_rows_of_every_table(): void {
        $staff = $this->getDataGenerator()->create_user();
        [$student, $rows] = $this->make_student($staff, 'S-1001');
        $this->make_student($staff, 'S-2002');

        $this->export_all_data_for_user($student->id, self::COMPONENT);

        $data = (array)$this->exported($student);
        $this->assertEqualsCanonicalizing($this->exported_tables(), array_keys($data));
        foreach ($rows as $table => $ids) {
            $this->assertSame($ids, $this->sorted_ids(array_column($data['local_academictranscript_' . $table], 'id')), $table);
        }
        $this->assertSame([], $data['local_academictranscript_draft']);
        $this->assertSame('S-1001', $data['local_academictranscript_student'][0]->studentid);
    }

    /**
     * The export of a student lists the log entries about the student with the id of whoever did each thing, and gives
     * the IP address only for what the student did. A visitor who verified the transcript without logging in, and a
     * staff member who viewed it, keep their addresses to themselves. The stored entries keep every address.
     */
    public function test_export_gives_the_ip_address_only_for_what_the_student_did(): void {
        global $DB;

        $student = $this->getDataGenerator()->create_user();
        $staff = $this->getDataGenerator()->create_user();
        $before = $_SERVER['REMOTE_ADDR'] ?? null;
        try {
            // A visitor who is not logged in verifies the transcript: the entry has user 0 and the visitor's address.
            $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
            $this->setUser();
            logger::log('verify_ok', $student->id);
            $_SERVER['REMOTE_ADDR'] = '198.51.100.20';
            $this->setUser($staff);
            logger::log('view', $student->id);
            $_SERVER['REMOTE_ADDR'] = '192.0.2.55';
            $this->setUser($student);
            logger::log('view', $student->id);
        } finally {
            if ($before === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $before;
            }
        }
        $addresses = ['203.0.113.7', '198.51.100.20', '192.0.2.55'];
        $this->assertSame($addresses, array_values($DB->get_records_menu('local_academictranscript_log', null, 'id', 'id, ip')));

        $this->export_all_data_for_user($student->id, self::COMPONENT);

        $rows = ((array)$this->exported($student))['local_academictranscript_log'];
        $this->assertSame(['verify_ok', 'view', 'view'], array_column($rows, 'action'));
        $this->assertEquals([0, $staff->id, $student->id], array_column($rows, 'userid'));
        $this->assertSame(['', '', '192.0.2.55'], array_column($rows, 'ip'));
        $this->assertSame($addresses, array_values($DB->get_records_menu('local_academictranscript_log', null, 'id', 'id, ip')));
    }

    /**
     * The export of a user holds the failed verifications that the user made while logged in, with the IP address,
     * because the user did them. It holds no failed verification of another user or of a visitor who was not logged in,
     * and no entry about a student that the user only viewed.
     */
    public function test_export_includes_the_users_own_failed_verifications_with_the_ip_address(): void {
        $user = $this->getDataGenerator()->create_user();
        $staff = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->log_as($staff, 'view', $user->id, '198.51.100.20');
        $mine = $this->log_as($user, 'verify_fail', 0, '203.0.113.9');
        $this->log_as($other, 'verify_fail', 0, '192.0.2.77');
        $this->log_as(null, 'verify_fail', 0, '192.0.2.88');
        $this->log_as($user, 'view', $other->id, '203.0.113.9');

        $this->export_all_data_for_user($user->id, self::COMPONENT);

        $rows = ((array)$this->exported($user))['local_academictranscript_log'];
        $this->assertSame(['view', 'verify_fail'], array_column($rows, 'action'));
        $this->assertEquals([$staff->id, $user->id], array_column($rows, 'userid'));
        $this->assertSame(['', '203.0.113.9'], array_column($rows, 'ip'));
        $this->assertEquals($mine, $rows[1]->id);
        $this->assertSame('FAKECODE', $rows[1]->info);
    }

    /**
     * A user with a failed verification and nothing else gets an export, with that entry in it and nothing in the
     * other tables.
     */
    public function test_export_for_a_user_with_only_a_failed_verification(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->log_as($user, 'verify_fail', 0, '203.0.113.9');

        $this->export_all_data_for_user($user->id, self::COMPONENT);

        $data = (array)$this->exported($user);
        $this->assertEqualsCanonicalizing($this->exported_tables(), array_keys($data));
        $this->assertSame(['203.0.113.9'], array_column($data['local_academictranscript_log'], 'ip'));
        foreach (array_diff($this->exported_tables(), ['local_academictranscript_log']) as $table) {
            $this->assertSame([], $data[$table], $table);
        }
    }

    /**
     * A user who uploaded documents gets the drafts in the export. The rows that the user only acted on are not in it.
     */
    public function test_export_for_the_uploader_of_drafts(): void {
        $staff = $this->getDataGenerator()->create_user();
        $this->make_student($staff, 'S-1001');
        $draftid = $this->upload_draft($staff);

        $this->export_all_data_for_user($staff->id, self::COMPONENT);

        $data = (array)$this->exported($staff);
        $this->assertEqualsCanonicalizing($this->exported_tables(), array_keys($data));
        $this->assertSame([$draftid], $this->sorted_ids(array_column($data['local_academictranscript_draft'], 'id')));
        $this->assertSame('policy.pdf', $data['local_academictranscript_draft'][0]->sourcename);
        foreach (array_diff($this->exported_tables(), ['local_academictranscript_draft']) as $table) {
            $this->assertSame([], $data[$table], $table);
        }
    }

    /**
     * A request for one user writes nothing for the context of another user or for any other kind of context.
     */
    public function test_export_ignores_contexts_of_other_users(): void {
        $staff = $this->getDataGenerator()->create_user();
        [$student] = $this->make_student($staff, 'S-1001');
        [$other] = $this->make_student($staff, 'S-2002');
        $contextids = [
            \context_user::instance($student->id)->id,
            \context_user::instance($other->id)->id,
            \context_system::instance()->id,
        ];

        provider::export_user_data(new approved_contextlist($student, self::COMPONENT, $contextids));

        $this->assertNotEmpty($this->exported($student));
        $this->assertEmpty($this->exported($other));
        $this->assertEmpty($this->exported_in(\context_system::instance()));
    }

    /**
     * Deleting a student removes the student's rows in every table: the student details, codes, transfer credit, posted
     * grades, change requests, issued transcripts, log entries about the student and drafts the student uploaded.
     * Another student's rows stay, and so do the rows where the deleted user only acted on someone else's record: a
     * transcript the user issued, a grade the user posted, a change the user asked for, and what the log says the user
     * did. A draft that another user uploaded stays.
     */
    public function test_delete_data_for_user_removes_only_that_student(): void {
        $staff = $this->getDataGenerator()->create_user();
        [$student, $studentrows] = $this->make_student($staff, 'S-1001');
        [$other, $otherrows] = $this->make_student($student, 'S-2002');
        $studentrows['draft'] = [$this->upload_draft($student)];
        $staffrows = ['draft' => [$this->upload_draft($staff)]];
        $this->assert_acted_by($student, $otherrows);
        $this->assertSame($otherrows, $this->remaining($otherrows));

        provider::delete_data_for_user($this->approved($student));

        $this->assertSame($this->nothing($studentrows), $this->remaining($studentrows));
        $this->assertSame($otherrows, $this->remaining($otherrows));
        $this->assertSame($staffrows, $this->remaining($staffrows));
        $this->assertEquals(
            [\context_user::instance($other->id)->id],
            provider::get_contexts_for_userid($other->id)->get_contextids()
        );
        $this->assertSame([], provider::get_contexts_for_userid($student->id)->get_contextids());
    }

    /**
     * Deleting a staff member removes the drafts that the staff member uploaded and nothing else: the rows of the
     * students that the staff member changed, posted, issued and decided stay.
     */
    public function test_delete_data_for_user_keeps_what_the_user_only_acted_on(): void {
        $staff = $this->getDataGenerator()->create_user();
        [, $studentrows] = $this->make_student($staff, 'S-1001');
        posting::decide($studentrows['change'][0], true, 'Approved after the exam was marked again.');
        $draftrows = ['draft' => [$this->upload_draft($staff)]];
        $this->assert_acted_by($staff, $studentrows, true);

        provider::delete_data_for_user($this->approved($staff));

        $this->assertSame($this->nothing($draftrows), $this->remaining($draftrows));
        $this->assertSame($studentrows, $this->remaining($studentrows));
    }

    /**
     * Deleting a user removes the failed verifications that the user made while logged in, which are about no student.
     * The failed verifications of another user and of a visitor who was not logged in stay, and so do the entries that
     * the user wrote about a student.
     */
    public function test_delete_data_for_user_removes_the_users_own_failed_verifications(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->log_as($user, 'verify_fail', 0, '203.0.113.9');
        $this->log_as($user, 'verify_fail', 0, '203.0.113.9');
        $otherfail = $this->log_as($other, 'verify_fail', 0, '192.0.2.77');
        $visitorfail = $this->log_as(null, 'verify_fail', 0, '192.0.2.88');
        $viewed = $this->log_as($user, 'view', $student->id, '203.0.113.9');
        $this->assertSame(2, $DB->count_records('local_academictranscript_log', ['studentid' => 0, 'userid' => $user->id]));

        provider::delete_data_for_user($this->approved($user));

        $this->assertSame(0, $DB->count_records('local_academictranscript_log', ['studentid' => 0, 'userid' => $user->id]));
        $this->assertSame(
            [$otherfail, $visitorfail, $viewed],
            $this->sorted_ids($DB->get_fieldset_select('local_academictranscript_log', 'id', '1 = 1'))
        );
        $this->assertSame([], provider::get_contexts_for_userid($user->id)->get_contextids());
        $this->assertEquals(
            [\context_user::instance($other->id)->id],
            provider::get_contexts_for_userid($other->id)->get_contextids()
        );
    }

    /**
     * Deleting a student removes the student's own failed verifications along with the rows about the student, in a
     * deletion for the users of a context too. Another user's failed verification stays.
     */
    public function test_delete_data_for_users_removes_the_students_own_failed_verifications(): void {
        global $DB;

        $staff = $this->getDataGenerator()->create_user();
        [$student, $studentrows] = $this->make_student($staff, 'S-1001');
        $other = $this->getDataGenerator()->create_user();
        $this->log_as($student, 'verify_fail', 0, '203.0.113.9');
        $otherfail = $this->log_as($other, 'verify_fail', 0, '192.0.2.77');

        provider::delete_data_for_users(new approved_userlist(
            \context_user::instance($student->id),
            self::COMPONENT,
            [$student->id]
        ));

        $this->assertSame($this->nothing($studentrows), $this->remaining($studentrows));
        $this->assertSame(0, $DB->count_records('local_academictranscript_log', ['studentid' => 0, 'userid' => $student->id]));
        $this->assertSame([$otherfail], $this->sorted_ids($DB->get_fieldset_select(
            'local_academictranscript_log',
            'id',
            'studentid = 0 AND userid <> 0'
        )));
    }

    /**
     * A request for one user deletes nothing in the context of another user.
     */
    public function test_delete_data_for_user_ignores_contexts_of_other_users(): void {
        $staff = $this->getDataGenerator()->create_user();
        [$student, $studentrows] = $this->make_student($staff, 'S-1001');
        [$other, $otherrows] = $this->make_student($staff, 'S-2002');
        $contextids = [\context_user::instance($other->id)->id, \context_system::instance()->id];

        provider::delete_data_for_user(new approved_contextlist($student, self::COMPONENT, $contextids));

        $this->assertSame($studentrows, $this->remaining($studentrows));
        $this->assertSame($otherrows, $this->remaining($otherrows));
    }

    /**
     * Deleting everything in the user context of a student removes that student's rows. Deleting in any other kind of
     * context, or in the user context of another student, leaves them.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        $staff = $this->getDataGenerator()->create_user();
        [$student, $studentrows] = $this->make_student($staff, 'S-1001');
        [, $otherrows] = $this->make_student($staff, 'S-2002');
        $studentrows['draft'] = [$this->upload_draft($student)];

        provider::delete_data_for_all_users_in_context(\context_system::instance());
        provider::delete_data_for_all_users_in_context(\context_user::instance($staff->id));
        $this->assertSame($studentrows, $this->remaining($studentrows));
        $this->assertSame($otherrows, $this->remaining($otherrows));

        provider::delete_data_for_all_users_in_context(\context_user::instance($student->id));

        $this->assertSame($this->nothing($studentrows), $this->remaining($studentrows));
        $this->assertSame($otherrows, $this->remaining($otherrows));
    }

    /**
     * Deleting for the users of a context removes the rows of the student whose user context it is, when the student is
     * in the list. A list without the student, and a context that is not a user context, delete nothing.
     */
    public function test_delete_data_for_users(): void {
        $staff = $this->getDataGenerator()->create_user();
        [$student, $studentrows] = $this->make_student($staff, 'S-1001');
        [$other, $otherrows] = $this->make_student($staff, 'S-2002');
        $studentrows['draft'] = [$this->upload_draft($student)];
        $studentcontext = \context_user::instance($student->id);

        provider::delete_data_for_users(new approved_userlist($studentcontext, self::COMPONENT, [$other->id, $staff->id]));
        provider::delete_data_for_users(new approved_userlist(\context_system::instance(), self::COMPONENT, [$student->id]));
        $this->assertSame($studentrows, $this->remaining($studentrows));
        $this->assertSame($otherrows, $this->remaining($otherrows));

        provider::delete_data_for_users(new approved_userlist($studentcontext, self::COMPONENT, [$student->id]));

        $this->assertSame($this->nothing($studentrows), $this->remaining($studentrows));
        $this->assertSame($otherrows, $this->remaining($otherrows));
    }

    /**
     * The tables of db/install.xml with the names of their columns.
     *
     * @return string[][] The column names by table name
     */
    private function schema(): array {
        global $DB;

        $DB->get_manager(); // Load the XMLDB classes.
        $file = new \xmldb_file(__DIR__ . '/../../db/install.xml');
        $this->assertTrue($file->loadXMLStructure());
        $tables = [];
        foreach ($file->getStructure()->getTables() as $table) {
            $tables[$table->getName()] = array_map(fn($field) => $field->getName(), $table->getFields());
        }
        return $tables;
    }

    /**
     * The tables that an export holds: the tables of student records, not the school's settings.
     *
     * @return string[]
     */
    private function exported_tables(): array {
        return array_values(array_diff(array_keys(self::METADATA), self::SETTINGS_TABLES));
    }

    /**
     * How many rows of each table of the school's settings name a user as the one who last changed them.
     *
     * @param \stdClass $user
     * @return int[] The number of rows by table name
     */
    private function settings_changed_by(\stdClass $user): array {
        global $DB;

        $counts = [];
        foreach (self::SETTINGS_TABLES as $table) {
            $counts[$table] = $DB->count_records($table, ['usermodified' => $user->id]);
        }
        return $counts;
    }

    /**
     * The table items of the metadata, by table name.
     *
     * @return database_table[]
     */
    private function metadata(): array {
        $items = [];
        foreach (provider::get_metadata(new collection(self::COMPONENT))->get_collection() as $item) {
            $this->assertInstanceOf(database_table::class, $item);
            $items[$item->get_name()] = $item;
        }
        return $items;
    }

    /**
     * Make a student with a row in each table that is about a student, through the plugin's own code, while a staff
     * member is logged in: the student details, a code with a note, transfer credit, a posted grade, a change request
     * for it, an issued transcript, and the log entries that the request and the issue write. The staff member is the
     * one who changed, posted, asked for and issued them. The staff member stays logged in afterwards.
     *
     * @param \stdClass $actor The user who acts
     * @param string $studentid The Student ID printed on the transcript
     * @return array The student, then the ids of the rows about the student by table name without the plugin prefix
     */
    private function make_student(\stdClass $actor, string $studentid): array {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator(self::COMPONENT);
        $this->setUser($actor);
        $user = $this->getDataGenerator()->create_user();
        $term = $generator->create_term();
        $program = $generator->create_program();
        $course = $this->getDataGenerator()->create_course();

        $rows = [];
        $rows['student'] = [$generator->create_student_record(['userid' => $user->id, 'studentid' => $studentid,
            'dob' => make_timestamp(2001, 4, 12), 'programid' => $program->id, 'admitterm' => $term->id, 'status' => 'active',
            'qualification' => 'Diploma in Business', 'awarddate' => make_timestamp(2026, 6, 1),
            'usermodified' => $actor->id])->id];
        $rows['code'] = [$generator->create_code_row(['userid' => $user->id, 'courseid' => $course->id, 'code' => 'W',
            'note' => 'Left in week 3', 'reason' => 'Withdrew after the last day to add or drop', 'termid' => $term->id,
            'usermodified' => $actor->id])->id];
        $rows['transfer'] = [$generator->create_transfer(['userid' => $user->id, 'institution' => 'Valley College',
            'coursecode' => 'ENG 101', 'title' => 'English Composition', 'dateaccepted' => make_timestamp(2025, 8, 20),
            'note' => 'Accepted in full', 'usermodified' => $actor->id])->id];
        $posted = $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $course->id, 'termid' => $term->id,
            'coursecode' => 'BUS 101', 'coursename' => 'Introduction to Business', 'grade' => 'A', 'percent' => 93.5,
            'note' => 'Honors section', 'postedby' => $actor->id]);
        $rows['posted'] = [$posted->id];
        $rows['change'] = [posting::request_change($posted->id, 'B', 'Regraded', self::CHANGE_REASON)];
        $rows['issue'] = [transcript::issue($user->id, 'Valley College Registrar')->id];
        $rows['log'] = $DB->get_fieldset_select('local_academictranscript_log', 'id', 'studentid = ?', [$user->id]);

        return [$user, array_map([$this, 'sorted_ids'], $rows)];
    }

    /**
     * Store a row in one table for a user, in the way that the plugin would.
     *
     * @param string $table The table, without the plugin prefix: student, code, transfer, posted, issue, draft or log, or
     *      failedverification for a log entry about no student that the user made while logged in
     * @param \stdClass $user The user the row is about (for a draft, the user who uploads the document)
     */
    private function store_row(string $table, \stdClass $user): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator(self::COMPONENT);
        $courseid = $this->getDataGenerator()->create_course()->id;
        switch ($table) {
            case 'student':
                $generator->create_student_record(['userid' => $user->id]);
                break;
            case 'code':
                $generator->create_code_row(['userid' => $user->id, 'courseid' => $courseid, 'code' => 'W']);
                break;
            case 'transfer':
                $generator->create_transfer(['userid' => $user->id]);
                break;
            case 'posted':
                $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $courseid,
                    'termid' => $generator->create_term()->id]);
                break;
            case 'issue':
                $this->setAdminUser();
                transcript::issue($user->id, 'Valley College Registrar');
                // Issuing also writes a log entry about the student, and that alone gives a context. Empty the log, so
                // that the issued transcript is the only row.
                $DB->delete_records('local_academictranscript_log');
                break;
            case 'draft':
                $this->upload_draft($user);
                break;
            case 'log':
                logger::log('view', $user->id);
                break;
            case 'failedverification':
                $this->log_as($user, 'verify_fail', 0, '203.0.113.9');
                break;
            default:
                $this->fail("Unknown table $table.");
        }
    }

    /**
     * Write a log entry as a user, or as a visitor who is not logged in, from an IP address. That user, or nobody, stays
     * logged in afterwards.
     *
     * @param \stdClass|null $user The user who is logged in, or null for a visitor who is not
     * @param string $action The action, such as view or verify_fail
     * @param int $studentid The student the entry is about, 0 for no student
     * @param string $ip The IP address of the request
     * @return int The id of the log entry
     */
    private function log_as(?\stdClass $user, string $action, int $studentid, string $ip): int {
        global $DB;

        $before = $_SERVER['REMOTE_ADDR'] ?? null;
        try {
            $_SERVER['REMOTE_ADDR'] = $ip;
            $this->setUser($user);
            logger::log($action, $studentid, 0, $action === 'verify_fail' ? 'FAKECODE' : '');
        } finally {
            if ($before === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $before;
            }
        }
        return (int)$DB->get_field_sql('SELECT MAX(id) FROM {local_academictranscript_log}');
    }

    /**
     * A user uploads a document, and a grade row that the document holds is stored as a draft.
     *
     * @param \stdClass $uploader The user who uploads the document, and is logged in afterwards
     * @return int The id of the draft
     */
    private function upload_draft(\stdClass $uploader): int {
        global $DB;

        $this->setUser($uploader);
        $item = ['letter' => 'S', 'minpct' => 50, 'maxpct' => 100, 'points' => 4.0, 'ingpa' => 1, 'earns' => 1,
            'description' => 'Satisfactory', 'source' => 'S 50 to 100'];
        drafts::store(['grade' => [$item]], 'ai', 'policy.pdf', 0, 'S 50 to 100 Satisfactory');

        $latest = $DB->get_records('local_academictranscript_draft', ['userid' => $uploader->id], 'id DESC', 'id', 0, 1);
        return (int)key($latest);
    }

    /**
     * Check that the same user is named as the one who acted in every row.
     *
     * @param \stdClass $actor The user who acted
     * @param array $rows The ids of the rows by table, as make_student() gives them
     * @param bool $decided Whether the change request was decided, so that it names the user who decided it as well
     */
    private function assert_acted_by(\stdClass $actor, array $rows, bool $decided = false): void {
        global $DB;

        $columns = self::ACTOR_COLUMNS;
        if ($decided) {
            $columns['change'] = ['requestedby', 'decidedby'];
        }
        foreach ($columns as $table => $columnlist) {
            foreach ((array)$columnlist as $column) {
                [$insql, $params] = $DB->get_in_or_equal($rows[$table], SQL_PARAMS_NAMED);
                $actors = $DB->get_fieldset_select('local_academictranscript_' . $table, $column, "id $insql", $params);
                $this->assertEquals([$actor->id], array_values(array_unique($actors)), "$table.$column");
            }
        }
    }

    /**
     * Which of the given rows are still in the database.
     *
     * @param array $rows The ids of the rows by table name without the plugin prefix
     * @return array The same, with only the ids of the rows that are still there
     */
    private function remaining(array $rows): array {
        global $DB;

        $found = [];
        foreach ($rows as $table => $ids) {
            [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $present = $DB->get_fieldset_select('local_academictranscript_' . $table, 'id', "id $insql", $params);
            $found[$table] = $this->sorted_ids($present);
        }
        return $found;
    }

    /**
     * The same tables as the given rows, with no ids.
     *
     * @param array $rows The ids of the rows by table name
     * @return array
     */
    private function nothing(array $rows): array {
        return array_map(fn($ids) => [], $rows);
    }

    /**
     * Whole numbers in order, so that ids from the database and from the code compare equal.
     *
     * @param array $ids
     * @return int[]
     */
    private function sorted_ids(array $ids): array {
        $ids = array_map('intval', $ids);
        sort($ids);
        return $ids;
    }

    /**
     * Ask for the data of one user to be deleted, as the privacy tools do: in the contexts that hold it.
     *
     * @param \stdClass $user
     * @return approved_contextlist
     */
    private function approved(\stdClass $user): approved_contextlist {
        return new approved_contextlist(
            $user,
            self::COMPONENT,
            provider::get_contexts_for_userid($user->id)->get_contextids()
        );
    }

    /**
     * The users that the provider lists in a context.
     *
     * @param \context $context
     * @return int[]
     */
    private function users_in(\context $context): array {
        $userlist = new userlist($context, self::COMPONENT);
        provider::get_users_in_context($userlist);
        return array_map('intval', $userlist->get_userids());
    }

    /**
     * What was exported to the user context of a user.
     *
     * @param \stdClass $user
     * @return \stdClass|array The exported object, or an empty array when nothing was exported
     */
    private function exported(\stdClass $user) {
        return $this->exported_in(\context_user::instance($user->id));
    }

    /**
     * What was exported to a context.
     *
     * @param \context $context
     * @return \stdClass|array The exported object, or an empty array when nothing was exported
     */
    private function exported_in(\context $context) {
        return writer::with_context($context)->get_data([get_string('pluginname', self::COMPONENT)]);
    }
}
