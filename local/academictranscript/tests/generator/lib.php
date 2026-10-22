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

/**
 * Test data generator for Academic Transcript.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_academictranscript\local\coursefields;
use local_academictranscript\local\programs;
use local_academictranscript\local\terms;

/**
 * Makes terms, programs, catalog courses, student details, courses with Transcript fields, transfer credit, posted
 * grades, hand-entered code rows and course totals for tests.
 *
 * Use it through $this->getDataGenerator()->get_plugin_generator('local_academictranscript'). Every create method
 * returns the new database row, except create_course_with_fields(), which returns the course. The methods that
 * insert a row themselves (student details, transfer credit, posted grades, code rows) take any column of the table
 * and refuse a column that the table does not have, so a typo cannot pass unnoticed.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_academictranscript_generator extends component_generator_base {
    /** @var int[] Option number that the Hour type field stores for each hour type. */
    protected const HOURTYPE_OPTIONS = [programs::CREDIT => 1, programs::CLOCK => 2];

    /** @var int Number of terms made, for the default names. */
    protected $termcount = 0;

    /** @var int Number of programs made, for the default names. */
    protected $programcount = 0;

    /** @var int Number of catalog courses made, for the default codes. */
    protected $catalogcount = 0;

    /** @var int Number of transfer credits made, for the default titles. */
    protected $transfercount = 0;

    /** @var int Number of posted grades made, for the default course codes. */
    protected $postedcount = 0;

    /** @var int[] Id of the manual grade item that holds the course total, by course id. */
    protected $totalitems = [];

    /**
     * Forget what was made. Called between tests.
     */
    public function reset(): void {
        parent::reset();
        $this->termcount = 0;
        $this->programcount = 0;
        $this->catalogcount = 0;
        $this->transfercount = 0;
        $this->postedcount = 0;
        $this->totalitems = [];
    }

    /**
     * Make a term, with its place in the options of the course Term field.
     *
     * Defaults: name "Term {n}", start on 5 January 2026, end 90 days after the start.
     *
     * @param array $record name, startdate, enddate
     * @return stdClass the term row, with its optionindex
     */
    public function create_term(array $record = []): stdClass {
        global $DB;

        $this->termcount++;
        $startdate = $record['startdate'] ?? make_timestamp(2026, 1, 5);
        $id = terms::save((object)[
            'name' => $record['name'] ?? 'Term ' . $this->termcount,
            'startdate' => $startdate,
            'enddate' => $record['enddate'] ?? $startdate + 90 * DAYSECS,
        ]);
        return $DB->get_record('local_academictranscript_term', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Make a program.
     *
     * Defaults: name "Program {n}", credit hours.
     *
     * @param array $record name, credential, unittype (credit or clock), totalhours, active
     * @return stdClass the program row
     */
    public function create_program(array $record = []): stdClass {
        global $DB;

        $this->programcount++;
        $record += ['name' => 'Program ' . $this->programcount, 'unittype' => programs::CREDIT];
        $id = programs::save($record);
        return $DB->get_record('local_academictranscript_program', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Make a catalog course.
     *
     * Defaults: code "CAT {n}", title "Catalog course {n}", 3 credit hours.
     *
     * @param array $record code, title, hours, unittype (credit or clock), programid or program (a name), active
     * @return stdClass the catalog row
     */
    public function create_catalog_course(array $record = []): stdClass {
        global $DB;

        $this->catalogcount++;
        $record += [
            'code' => 'CAT ' . $this->catalogcount,
            'title' => 'Catalog course ' . $this->catalogcount,
            'hours' => 3,
            'unittype' => programs::CREDIT,
        ];
        $id = programs::save_course($record);
        return $DB->get_record('local_academictranscript_catalog', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Make the transcript details of a student (the Student ID, program, status and so on).
     *
     * @param array $record userid (required) and any other column of local_academictranscript_student
     * @return stdClass the student row
     */
    public function create_student_record(array $record): stdClass {
        $this->require_values($record, ['userid']);
        $record += ['usermodified' => get_admin()->id, 'timemodified' => time()];
        return $this->insert_row('local_academictranscript_student', $record);
    }

    /**
     * Make a course and fill its Transcript fields.
     *
     * The keys code, hours, hourtype and termid fill the course fields, and every other key goes to create_course().
     * A field whose key is missing, null or empty is left unset (zero hours are kept). The course fields are locked,
     * and only an admin may save locked fields, so the course is made as the admin, whoever is logged in.
     *
     * @param array $record code, hours, hourtype ('credit', 'clock' or ''), termid (the id of a term, saved as its
     *      option index), and any key that create_course() takes
     * @return stdClass the course
     */
    public function create_course_with_fields(array $record = []): stdClass {
        global $DB;

        $values = [];
        if (($record['code'] ?? '') !== '') {
            $values[coursefields::CODE] = (string)$record['code'];
        }
        if (($record['hours'] ?? '') !== '') {
            $values[coursefields::HOURS] = (float)$record['hours'];
        }
        $hourtype = (string)($record['hourtype'] ?? '');
        if ($hourtype !== '') {
            if (!isset(self::HOURTYPE_OPTIONS[$hourtype])) {
                throw new coding_exception("The hourtype must be 'credit', 'clock' or '', not '$hourtype'.");
            }
            $values[coursefields::HOURTYPE] = self::HOURTYPE_OPTIONS[$hourtype];
        }
        if (!empty($record['termid'])) {
            $values[coursefields::TERM] = (int)$DB->get_field(
                'local_academictranscript_term',
                'optionindex',
                ['id' => $record['termid']],
                MUST_EXIST
            );
        }

        unset($record['code'], $record['hours'], $record['hourtype'], $record['termid']);
        foreach ($values as $shortname => $value) {
            $record['customfield_' . $shortname] = $value;
        }
        return $this->run_as_admin(fn() => $this->datagenerator->create_course($record));
    }

    /**
     * Make a transfer credit.
     *
     * Defaults: Valley College, title "Transfer course {n}", 3 hours, active.
     *
     * @param array $record userid (required) and any other column of local_academictranscript_transfer
     * @return stdClass the transfer row
     */
    public function create_transfer(array $record): stdClass {
        $this->require_values($record, ['userid']);
        $this->transfercount++;
        $record += [
            'institution' => 'Valley College',
            'title' => 'Transfer course ' . $this->transfercount,
            'hours' => 3,
            'active' => 1,
            'usermodified' => get_admin()->id,
            'timemodified' => time(),
        ];
        return $this->insert_row('local_academictranscript_transfer', $record);
    }

    /**
     * Make a posted final grade, without going through the posting code or the gradebook.
     *
     * Defaults: course code "CRS {n}", course name "Course {n}", 3 credit hours, grade A, posted by the admin just now.
     *
     * @param array $record userid, courseid and termid (required) and any other column of local_academictranscript_posted
     * @return stdClass the posted row
     */
    public function create_posted_grade(array $record): stdClass {
        $this->require_values($record, ['userid', 'courseid', 'termid']);
        $this->postedcount++;
        $now = time();
        $record += [
            'coursecode' => 'CRS ' . $this->postedcount,
            'coursename' => 'Course ' . $this->postedcount,
            'hours' => 3,
            'unittype' => programs::CREDIT,
            'grade' => 'A',
            'postedby' => get_admin()->id,
            'timeposted' => $now,
        ];
        $record += ['timemodified' => $record['timeposted']];
        return $this->insert_row('local_academictranscript_posted', $record);
    }

    /**
     * Make a hand-entered row for a student and a course: the code a teacher chose, a note and a term override.
     *
     * Defaults: no code, no note and no term, the reason "Entered by the teacher", changed by the admin just now.
     *
     * @param array $record userid and courseid (required) and any other column of local_academictranscript_code
     * @return stdClass the code row
     */
    public function create_code_row(array $record): stdClass {
        $this->require_values($record, ['userid', 'courseid']);
        $record += [
            'reason' => 'Entered by the teacher',
            'usermodified' => get_admin()->id,
            'timemodified' => time(),
        ];
        return $this->insert_row('local_academictranscript_code', $record);
    }

    /**
     * Give a student a course total, as a percentage.
     *
     * The course gets one manual grade item with a maximum of 100 (made on the first call), the student gets the
     * grade on it, and the gradebook is recalculated, so the course total of the student is $percent.
     *
     * @param int $courseid
     * @param int $userid
     * @param float $percent 0 to 100
     */
    public function set_course_total(int $courseid, int $userid, float $percent): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $item = isset($this->totalitems[$courseid]) ? grade_item::fetch(['id' => $this->totalitems[$courseid]]) : false;
        if (!$item) {
            $record = $this->datagenerator->create_grade_item([
                'courseid' => $courseid,
                'itemtype' => 'manual',
                'itemname' => 'Final score',
                'grademin' => 0,
                'grademax' => 100,
            ]);
            $this->totalitems[$courseid] = $record->id;
            $item = grade_item::fetch(['id' => $record->id]);
        }
        if (!$item->update_final_grade($userid, $percent, 'local_academictranscript')) {
            throw new coding_exception("The grade of user $userid in course $courseid could not be saved.");
        }
        if (grade_regrade_final_grades($courseid) !== true) {
            throw new coding_exception("The grades of course $courseid could not be recalculated.");
        }
    }

    /**
     * Refuse a record that lacks a value the generator cannot choose.
     *
     * @param array $record
     * @param string[] $names keys the record must have
     * @throws coding_exception
     */
    protected function require_values(array $record, array $names): void {
        foreach ($names as $name) {
            if (!isset($record[$name])) {
                throw new coding_exception("The $name value is required.");
            }
        }
    }

    /**
     * Insert a row and read it back. A column that the table does not have is refused.
     *
     * @param string $table
     * @param array $record
     * @return stdClass the row, as the database holds it
     * @throws coding_exception
     */
    protected function insert_row(string $table, array $record): stdClass {
        global $DB;

        $unknown = array_diff_key($record, $DB->get_columns($table));
        if ($unknown) {
            throw new coding_exception("The table $table has no column " . implode(', ', array_keys($unknown)) . '.');
        }
        $id = $DB->insert_record($table, (object)$record);
        return $DB->get_record($table, ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Run code as the site admin, then go back to the user who was logged in (or to nobody).
     *
     * @param callable $callback
     * @return mixed what the callback returns
     */
    protected function run_as_admin(callable $callback) {
        global $USER;

        if (is_siteadmin()) {
            return $callback();
        }
        $previous = $USER->id ? clone($USER) : null;
        $this->set_user(get_admin());
        try {
            return $callback();
        } finally {
            $this->set_user($previous);
        }
    }
}
