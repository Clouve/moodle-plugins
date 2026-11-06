<?php

/**
 * Test data generator: school settings, terms, programs, catalog courses, students, course offerings, registrations,
 * prices and class meetings, made through the plugin's own code. For automatic tests only.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class local_studentrecords_generator extends component_generator_base {
    /** @var int Count of made records, for unique codes. */
    protected $count = 0;

    /**
     * Reset between tests.
     */
    public function reset() {
        $this->count = 0;
    }

    /**
     * Set school settings (name => value), for example schoolname, billing, fedaid, msgtestmode.
     *
     * @param array $settings
     */
    public function set_settings(array $settings): void {
        foreach ($settings as $name => $value) {
            set_config($name, (string)$value, 'local_studentrecords');
        }
    }

    /**
     * A grading scale: A to F, and the marks P (earns the hours) and I. Rows are [letter, lowest %, highest %, points].
     *
     * @param array|null $rows other rows
     */
    public function create_scale(?array $rows = null): void {
        global $DB;
        $rows = $rows ?? [['A', 90, 100, 4], ['B', 80, 89.99, 3], ['C', 70, 79.99, 2], ['F', 0, 69.99, 0]];
        foreach ($rows as $i => [$letter, $min, $max, $points]) {
            $DB->insert_record('local_studentrecords_grade', (object)['letter' => $letter, 'minpct' => $min, 'maxpct' => $max,
                'points' => $points, 'ingpa' => 1, 'earns' => 0, 'sortorder' => $i + 1, 'timemodified' => time()]);
        }
        foreach (['P' => 1, 'I' => 0] as $letter => $earns) {
            $DB->insert_record('local_studentrecords_grade', (object)['letter' => $letter, 'ingpa' => 0, 'earns' => $earns,
                'sortorder' => count($rows) + 1 + $earns, 'timemodified' => time()]);
        }
    }

    /**
     * A term.
     *
     * @param array $record name, startdate, enddate, adddropend, withdrawby (Y-m-d)
     * @return stdClass
     */
    public function create_term(array $record = []): stdClass {
        global $DB;
        $this->count++;
        $t = fn($ymd) => $ymd ? \local_studentrecords\local\catalog::date_to_time($ymd) : null;
        $id = $DB->insert_record('local_studentrecords_term', (object)['name' => $record['name'] ?? 'Term ' . $this->count,
            'startdate' => $t($record['startdate'] ?? '2030-01-14'), 'enddate' => $t($record['enddate'] ?? '2030-05-03'),
            'adddropend' => $t($record['adddropend'] ?? '2030-01-21'), 'withdrawby' => $t($record['withdrawby'] ?? '2030-03-20'),
            'timemodified' => time()]);
        return $DB->get_record('local_studentrecords_term', ['id' => $id]);
    }

    /**
     * A level and a program.
     *
     * @param array $record code, name, level, unittype (credit or clock)
     * @return stdClass program
     */
    public function create_program(array $record = []): stdClass {
        global $DB;
        $this->count++;
        $level = $record['level'] ?? 'Undergraduate';
        $levelid = $DB->get_field('local_studentrecords_level', 'id', ['name' => $level]) ?:
            $DB->insert_record('local_studentrecords_level', (object)['name' => $level, 'sortorder' => $this->count]);
        $id = $DB->insert_record('local_studentrecords_program', (object)['code' => $record['code'] ?? 'PRG' . $this->count,
            'name' => $record['name'] ?? 'Program ' . $this->count, 'levelid' => $levelid, 'credential' => 'Test',
            'credentialtype' => 'bachelor', 'isdegree' => 1, 'unittype' => $record['unittype'] ?? 'credit', 'totalhours' => 120,
            'active' => 1, 'timemodified' => time()]);
        return $DB->get_record('local_studentrecords_program', ['id' => $id]);
    }

    /**
     * A catalog course.
     *
     * @param array $record code, title, hours, unittype
     * @return stdClass
     */
    public function create_course(array $record = []): stdClass {
        global $DB;
        $this->count++;
        $id = $DB->insert_record('local_studentrecords_course', (object)['code' => $record['code'] ?? 'TST' . (100 + $this->count),
            'title' => $record['title'] ?? 'Test course ' . $this->count, 'hours' => $record['hours'] ?? 3,
            'unittype' => $record['unittype'] ?? 'credit', 'timemodified' => time()]);
        return $DB->get_record('local_studentrecords_course', ['id' => $id]);
    }

    /**
     * A student record for a Moodle user.
     *
     * @param array $record userid, studentid, programid, status, loadtype
     * @return stdClass
     */
    public function create_student(array $record): stdClass {
        $user = core_user::get_user((int)$record['userid'], '*', MUST_EXIST);
        $id = \local_studentrecords\local\students::save(['userid' => $user->id, 'studentid' => $record['studentid'],
            'legalfirstname' => $user->firstname, 'legallastname' => $user->lastname, 'email' => $user->email,
            'programid' => $record['programid'] ?? null, 'status' => $record['status'] ?? 'active',
            'loadtype' => $record['loadtype'] ?? 'fulltime']);
        return \local_studentrecords\local\students::get($id);
    }

    /**
     * A course offering of a catalog course in a term, optionally linked to a Moodle course.
     *
     * @param array $record courseid, termid, section, moodlecourseid, capacity
     * @return stdClass
     */
    public function create_offering(array $record): stdClass {
        global $DB;
        $id = $DB->insert_record(\local_studentrecords\local\registration::OFFER, (object)['courseid' => $record['courseid'],
            'termid' => $record['termid'], 'section' => $record['section'] ?? '01',
            'moodlecourseid' => $record['moodlecourseid'] ?? null, 'capacity' => $record['capacity'] ?? 30, 'status' => 'open',
            'timemodified' => time()]);
        return \local_studentrecords\local\registration::offer((int)$id);
    }

    /**
     * A registration (staff override of date rules, so tests do not depend on today's date).
     *
     * @param array $record studentid, offerid
     * @return int registration id
     */
    public function create_registration(array $record): int {
        return \local_studentrecords\local\registration::add(
            \local_studentrecords\local\students::get((int)$record['studentid']),
            (int)$record['offerid'],
            true,
            'test data'
        );
    }

    /**
     * A price list item.
     *
     * @param array $record name, kind, basis, amount, scope, scopeid, refundable, qualified
     * @return int id
     */
    public function create_fee(array $record): int {
        return \local_studentrecords\local\billing::save_fee($record + ['kind' => 'fee', 'basis' => 'term', 'scope' => 'all',
            'scopeid' => null, 'refundable' => 1, 'qualified' => 1]);
    }

    /**
     * A Moodle user with a student record.
     *
     * @param array $record user fields, and studentid, programid, status, loadtype
     * @return stdClass the student record
     */
    public function create_user_student(array $record = []): stdClass {
        $this->count++;
        $studentfields = array_flip(['studentid', 'programid', 'status', 'loadtype']);
        $user = $this->datagenerator->create_user(array_diff_key($record, $studentfields));
        return $this->create_student(array_intersect_key($record, $studentfields) + ['userid' => $user->id,
            'studentid' => 'S' . $this->count]);
    }

    /**
     * A charge on a student account.
     *
     * @param array $record studentid, amount, and any other field of an account line
     * @return int account line id
     */
    public function create_charge(array $record): int {
        return \local_studentrecords\local\billing::add_line($record + ['txntype' => 'charge', 'description' => 'Test charge']);
    }

    /**
     * A class meeting.
     *
     * @param array $record offerid, date (Y-m-d), hours
     * @return int|null id
     */
    public function create_meeting(array $record): ?int {
        return \local_studentrecords\local\attendance::add_meeting(
            (int)$record['offerid'],
            $record['date'],
            (float)($record['hours'] ?? 3)
        );
    }
}
