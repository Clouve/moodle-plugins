<?php

/**
 * Behat data generator: "Given the following "local_studentrecords > students" exist:" and the like.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class behat_local_studentrecords_generator extends behat_generator_base {
    /**
     * Entities Behat can make.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'settings' => ['singular' => 'setting', 'datagenerator' => 'setting', 'required' => ['name', 'value']],
            'terms' => ['singular' => 'term', 'datagenerator' => 'term', 'required' => ['name']],
            'programs' => ['singular' => 'program', 'datagenerator' => 'program', 'required' => ['code']],
            'catalog courses' => ['singular' => 'catalog course', 'datagenerator' => 'course', 'required' => ['code']],
            'students' => ['singular' => 'student', 'datagenerator' => 'student', 'required' => ['user', 'studentid'],
                'switchids' => ['user' => 'userid', 'program' => 'programid']],
            'offerings' => ['singular' => 'offering', 'datagenerator' => 'offering', 'required' => ['catalogcourse', 'term'],
                'switchids' => ['catalogcourse' => 'courseid', 'term' => 'termid', 'moodlecourse' => 'moodlecourseid']],
            'registrations' => ['singular' => 'registration', 'datagenerator' => 'registration',
                'required' => ['student', 'offering'], 'switchids' => ['student' => 'studentid', 'offering' => 'offerid']],
            'fees' => ['singular' => 'fee', 'datagenerator' => 'fee', 'required' => ['name', 'amount']],
            'meetings' => ['singular' => 'meeting', 'datagenerator' => 'meeting', 'required' => ['offering', 'date'],
                'switchids' => ['offering' => 'offerid']],
        ];
    }

    /**
     * A setting from a Behat table row.
     *
     * @param array $data name, value
     */
    protected function process_setting(array $data): void {
        $this->componentdatagenerator->set_settings([$data['name'] => $data['value']]);
    }

    /**
     * Program id by code.
     *
     * @param string $code
     * @return int
     */
    protected function get_program_id(string $code): int {
        global $DB;
        return (int)$DB->get_field('local_studentrecords_program', 'id', ['code' => $code], MUST_EXIST);
    }

    /**
     * Catalog course id by code.
     *
     * @param string $code
     * @return int
     */
    protected function get_catalogcourse_id(string $code): int {
        global $DB;
        return (int)$DB->get_field('local_studentrecords_course', 'id', ['code' => $code], MUST_EXIST);
    }

    /**
     * Term id by name.
     *
     * @param string $name
     * @return int
     */
    protected function get_term_id(string $name): int {
        global $DB;
        return (int)$DB->get_field('local_studentrecords_term', 'id', ['name' => $name], MUST_EXIST);
    }

    /**
     * Moodle course id by short name.
     *
     * @param string $shortname
     * @return int
     */
    protected function get_moodlecourse_id(string $shortname): int {
        global $DB;
        return (int)$DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * Student record id by student ID.
     *
     * @param string $studentid
     * @return int
     */
    protected function get_student_id(string $studentid): int {
        global $DB;
        return (int)$DB->get_field('local_studentrecords_student', 'id', ['studentid' => $studentid], MUST_EXIST);
    }

    /**
     * Offering id by "COURSECODE TERM NAME" (for example "TST101 Fall 2030").
     *
     * @param string $key
     * @return int
     */
    protected function get_offering_id(string $key): int {
        global $DB;
        [$code, $term] = explode(' ', $key, 2);
        return (int)$DB->get_field_sql("SELECT o.id FROM {local_studentrecords_offer} o
            JOIN {local_studentrecords_course} c ON c.id = o.courseid JOIN {local_studentrecords_term} t ON t.id = o.termid
            WHERE c.code = ? AND t.name = ?", [$code, $term], MUST_EXIST);
    }
}
