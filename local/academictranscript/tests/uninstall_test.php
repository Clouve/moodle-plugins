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

namespace local_academictranscript;

use core_course\customfield\course_handler;
use local_academictranscript\local\coursefields;
use local_academictranscript\local\pdf\signer;
use local_academictranscript\local\scale;

#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_local_academictranscript_uninstall')]
#[\PHPUnit\Framework\Attributes\CoversClass(coursefields::class)]
/**
 * Tests for the uninstall hook, and for the install hook that puts the course fields back afterwards.
 *
 * The PHPUnit site starts with the four course fields that the install hook makes (course code, hours, hour type and
 * term). A test makes its courses before it runs the uninstall hook, because the Term field is gone after that.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_local_academictranscript_uninstall
 * @covers     \local_academictranscript\local\coursefields
 */
final class uninstall_test extends \advanced_testcase {
    /** @var int[][] The required, unique and locked settings of each course field after an install. */
    private const INSTALLED = [
        coursefields::CODE => ['required' => 1, 'uniquevalues' => 1, 'locked' => 1],
        coursefields::HOURS => ['required' => 0, 'uniquevalues' => 0, 'locked' => 1],
        coursefields::HOURTYPE => ['required' => 0, 'uniquevalues' => 0, 'locked' => 1],
        coursefields::TERM => ['required' => 0, 'uniquevalues' => 0, 'locked' => 1],
    ];

    /** @var int[][] The same settings after an uninstall: the Term field is gone and the other three are free. */
    private const RELEASED = [
        coursefields::CODE => ['required' => 0, 'uniquevalues' => 0, 'locked' => 0],
        coursefields::HOURS => ['required' => 0, 'uniquevalues' => 0, 'locked' => 0],
        coursefields::HOURTYPE => ['required' => 0, 'uniquevalues' => 0, 'locked' => 0],
    ];

    /** @var string[] The fields that an uninstall keeps. */
    private const KEPT = [coursefields::CODE, coursefields::HOURS, coursefields::HOURTYPE];

    /**
     * Load the install and uninstall hooks and start each test with an empty request cache.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        scale::reset_cache();
        require_once(__DIR__ . '/../db/install.php');
        require_once(__DIR__ . '/../db/uninstall.php');
    }

    /**
     * The Course code, Hours and Hour type fields stay, with the values saved on courses, and are no longer required,
     * unique or locked.
     */
    public function test_uninstall_keeps_values_and_relaxes_fields(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $course = $generator->create_course_with_fields(['code' => 'BUS 101', 'hours' => 3, 'hourtype' => 'credit']);
        // The fields are strict first, so the check afterwards cannot pass only because they never were.
        $this->assertSame(self::INSTALLED, $this->settings());

        $this->assertTrue(xmldb_local_academictranscript_uninstall());

        $this->assertSame(self::RELEASED, $this->settings());
        $this->assertSame(
            ['code' => 'BUS 101', 'hours' => 3.0, 'unittype' => 'credit', 'termindex' => 0],
            coursefields::get([$course->id])[$course->id]
        );
    }

    /**
     * The other settings of the kept fields (size, decimals, options and so on) stay as they are.
     */
    public function test_uninstall_leaves_the_other_field_settings(): void {
        $before = $this->field_values(self::KEPT, 'configdata');
        $this->assertArrayHasKey('maxlength', $before[coursefields::CODE]);

        xmldb_local_academictranscript_uninstall();

        $free = ['required' => 0, 'uniquevalues' => 0, 'locked' => 0];
        $this->assertEquals(
            array_map(fn($config) => $free + $config, $before),
            $this->field_values(self::KEPT, 'configdata')
        );
    }

    /**
     * The Term field goes with the values saved in it, and so does the folder with the self-signed signing key.
     */
    public function test_uninstall_deletes_term_field_and_signing_key(): void {
        global $CFG, $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall 2026']);
        $generator->create_course_with_fields(['code' => 'BUS 101', 'termid' => $term->id]);
        $termfieldid = coursefields::term_field()->get('id');
        $this->assertTrue($DB->record_exists('customfield_data', ['fieldid' => $termfieldid]));
        $this->assertNotNull(signer::selfsigned());
        $folder = $CFG->dataroot . '/local_academictranscript';
        $this->assertDirectoryExists($folder . '/signing');

        xmldb_local_academictranscript_uninstall();

        $this->assertNull(coursefields::term_field());
        $this->assertFalse($DB->record_exists('customfield_field', ['id' => $termfieldid]));
        $this->assertFalse($DB->record_exists('customfield_data', ['fieldid' => $termfieldid]));
        $this->assertDirectoryDoesNotExist($folder);
    }

    /**
     * When a file of the signing key cannot be deleted, the hook returns false and the folder stays where it was, with
     * what is left in it. An admin finds it there and deletes it by hand, as the README says. The hook must not rename the
     * folder first: a renamed copy gets a name that nobody can guess, and it would keep the private key.
     *
     * A user who can write to a read-only folder, such as root, cannot make a file that is not deletable, so the test is
     * skipped for that user.
     */
    public function test_uninstall_keeps_the_key_folder_in_place_when_it_cannot_delete_it(): void {
        global $CFG;

        $this->assertNotNull(signer::selfsigned());
        $folder = $CFG->dataroot . '/local_academictranscript';
        $signing = $folder . '/signing';
        $renamed = fn() => glob($CFG->dataroot . '/_temp_*');
        chmod($signing, 0555);
        try {
            if (is_writable($signing)) {
                $this->markTestSkipped('This user can write to a read-only folder, so a file cannot be made undeletable.');
            }
            $before = $renamed();

            // PHP warns about a file it cannot delete. The test expects that and keeps the warnings.
            $warnings = [];
            set_error_handler(function (int $errno, string $message) use (&$warnings): bool {
                $warnings[] = $message;
                return true;
            }, E_WARNING);
            try {
                $deleted = xmldb_local_academictranscript_uninstall();
            } finally {
                restore_error_handler();
            }

            $this->assertFalse($deleted);
            $this->assertNotEmpty($warnings);
            $this->assertFileExists($signing . '/selfsigned-key.pem');
            $this->assertSame($before, $renamed(), 'No renamed copy of the folder appears in the data folder.');
        } finally {
            if (is_dir($signing)) {
                chmod($signing, 0755);
            }
            fulldelete($folder);
        }
    }

    /**
     * The reason for freeing the fields: a user who may not change locked fields, such as a teacher, can edit the kept
     * fields of a course once the plugin is uninstalled.
     */
    public function test_uninstall_lets_teachers_edit_the_kept_fields(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $course = $generator->create_course_with_fields(['code' => 'BUS 101']);
        $handler = course_handler::create();
        // The uninstall needs no capability (a command line uninstall has no user), so the teacher can run it.
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        foreach (self::KEPT as $shortname) {
            $this->assertFalse($handler->can_edit(coursefields::field($shortname), $course->id), $shortname);
        }

        xmldb_local_academictranscript_uninstall();

        foreach (self::KEPT as $shortname) {
            $this->assertTrue($handler->can_edit(coursefields::field($shortname), $course->id), $shortname);
        }
    }

    /**
     * Uninstalling a second time, for example after the first run stopped early, changes nothing and does not fail. The
     * field form saves yes and no as text ("0"), the uninstall hook as numbers, and both mean the same.
     */
    public function test_uninstall_can_run_again(): void {
        xmldb_local_academictranscript_uninstall();
        $this->change_settings(coursefields::HOURS, ['locked' => '0']);
        $sink = $this->redirectEvents();

        $this->assertTrue(xmldb_local_academictranscript_uninstall());

        $this->assertSame([], $this->field_changes($sink));
        $this->assertSame(self::RELEASED, $this->settings());
        $sink->close();
    }

    /**
     * A site whose course fields an admin deleted by hand has nothing to relax, and the uninstall does not fail.
     */
    public function test_uninstall_without_the_course_fields(): void {
        course_handler::create()->delete_category(coursefields::field(coursefields::CODE)->get_category());
        $this->assertSame([], $this->settings());

        $this->assertTrue(xmldb_local_academictranscript_uninstall());
    }

    /**
     * Installing again puts required, unique and locked back on the kept fields and makes the Term field again. The values
     * on courses stay.
     */
    public function test_install_after_uninstall_locks_fields_again(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $course = $generator->create_course_with_fields(['code' => 'BUS 101', 'hours' => 3, 'hourtype' => 'clock']);
        xmldb_local_academictranscript_uninstall();
        $this->assertSame(self::RELEASED, $this->settings());

        $this->assertTrue(xmldb_local_academictranscript_install());

        $this->assertSame(self::INSTALLED, $this->settings());
        $this->assertNotNull(coursefields::term_field());
        $this->assertSame(
            ['code' => 'BUS 101', 'hours' => 3.0, 'unittype' => 'clock', 'termindex' => 0],
            coursefields::get([$course->id])[$course->id]
        );
    }

    /**
     * A reinstall finds the kept fields by their short names. It makes no second category and no second copy of a field,
     * the kept fields keep their ids (the values on courses belong to those ids), and the Term field comes back in the
     * same category.
     */
    public function test_install_after_uninstall_reuses_the_kept_fields(): void {
        global $DB;

        $ids = $this->field_values(self::KEPT, 'id');
        $categoryid = coursefields::field(coursefields::CODE)->get('categoryid');
        $categories = $DB->count_records('customfield_category');
        $fields = $DB->count_records('customfield_field');

        xmldb_local_academictranscript_uninstall();
        xmldb_local_academictranscript_install();

        $this->assertSame($ids, $this->field_values(self::KEPT, 'id'));
        $this->assertSame($categories, $DB->count_records('customfield_category'));
        $this->assertSame($fields, $DB->count_records('customfield_field'));
        $this->assertSame($categoryid, coursefields::term_field()->get('categoryid'));
    }

    /**
     * Uninstalling and installing again leaves the other settings of the kept fields and the values saved on courses as
     * they were.
     */
    public function test_install_after_uninstall_changes_no_other_setting_or_value(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $generator->create_course_with_fields(['code' => 'BUS 101', 'hours' => 3, 'hourtype' => 'credit']);
        $generator->create_course_with_fields(['code' => 'ENG 101', 'hours' => 1.5, 'hourtype' => 'clock']);
        $configs = $this->field_values(self::KEPT, 'configdata');
        $fieldids = array_values($this->field_values(self::KEPT, 'id'));
        $values = $DB->get_records_list('customfield_data', 'fieldid', $fieldids, 'id');
        $this->assertCount(6, $values);

        xmldb_local_academictranscript_uninstall();
        xmldb_local_academictranscript_install();

        $this->assertEquals($configs, $this->field_values(self::KEPT, 'configdata'));
        $this->assertEquals($values, $DB->get_records_list('customfield_data', 'fieldid', $fieldids, 'id'));
    }

    /**
     * Only the settings that belong to the plugin are put back. A setting that an admin changed while the plugin was
     * uninstalled stays.
     */
    public function test_install_after_uninstall_keeps_changes_to_other_settings(): void {
        xmldb_local_academictranscript_uninstall();
        $this->change_settings(coursefields::HOURS, ['required' => 1, 'decimalplaces' => 2]);

        xmldb_local_academictranscript_install();

        $config = coursefields::field(coursefields::HOURS)->get('configdata');
        $this->assertEquals(1, $config['locked']);
        $this->assertEquals(1, $config['required']);
        $this->assertEquals(2, $config['decimalplaces']);
    }

    /**
     * The install hook saves a field only when one of the settings it owns differs, and then only that field. The field
     * form saves yes and no as text ("1"), the install hook as numbers, and both mean the same.
     */
    public function test_ensure_saves_only_the_fields_that_differ(): void {
        $this->change_settings(coursefields::HOURS, ['locked' => '1']);
        $sink = $this->redirectEvents();

        coursefields::ensure();
        $this->assertSame([], $this->field_changes($sink), 'Nothing differs, so nothing is saved.');

        $this->change_settings(coursefields::HOURTYPE, ['locked' => '0']);
        $this->change_settings(coursefields::TERM, ['locked' => 0]);
        $sink->clear();

        coursefields::ensure();

        $this->assertSame(
            ['updated ' . coursefields::HOURTYPE, 'updated ' . coursefields::TERM],
            $this->field_changes($sink)
        );
        $this->assertSame(self::INSTALLED, $this->settings());
        $sink->close();
    }

    /**
     * Change settings of a course field the way an admin does on the edit page of the field. The other settings stay.
     *
     * @param string $shortname
     * @param array $changes setting name => new value
     */
    private function change_settings(string $shortname, array $changes): void {
        $field = coursefields::field($shortname);
        $config = $changes + $field->get('configdata');
        course_handler::create()->save_field_configuration($field, (object)['configdata' => $config]);
    }

    /**
     * The required, unique and locked settings of each course field, read fresh from the handler. A field that does not
     * exist is left out.
     *
     * @return int[][] shortname => ['required' => 0 or 1, 'uniquevalues' => 0 or 1, 'locked' => 0 or 1]
     */
    private function settings(): array {
        $settings = [];
        foreach (array_keys(self::INSTALLED) as $shortname) {
            $field = coursefields::field($shortname);
            if ($field) {
                $settings[$shortname] = [
                    'required' => (int)$field->get_configdata_property('required'),
                    'uniquevalues' => (int)$field->get_configdata_property('uniquevalues'),
                    'locked' => (int)$field->get_configdata_property('locked'),
                ];
            }
        }
        return $settings;
    }

    /**
     * One property of each of the given course fields, read fresh from the handler. A field that does not exist is left
     * out.
     *
     * @param string[] $shortnames
     * @param string $property a property of the field, such as id, categoryid or configdata
     * @return array shortname => value
     */
    private function field_values(array $shortnames, string $property): array {
        $values = [];
        foreach ($shortnames as $shortname) {
            $field = coursefields::field($shortname);
            if ($field) {
                $values[$shortname] = $field->get($property);
            }
        }
        return $values;
    }

    /**
     * What happened to course fields, as one text per event: the action, a space and the short name of the field.
     *
     * @param \phpunit_event_sink $sink events caught since the sink was made or cleared
     * @return string[] for example "updated transcript_hourtype"
     */
    private function field_changes(\phpunit_event_sink $sink): array {
        $changes = [];
        foreach ($sink->get_events() as $event) {
            if ($event->component === 'core_customfield' && $event->objecttable === 'customfield_field') {
                $changes[] = $event->action . ' ' . $event->other['shortname'];
            }
        }
        return $changes;
    }
}
