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

namespace local_academictranscript\local;

/**
 * Admin-managed terms and the course "Term" field.
 *
 * The course field is a select whose option N is the term with optionindex N. New terms are always
 * appended (optionindex = max + 1) and renaming a term only changes its option text, so the values
 * already saved on courses keep pointing at the same term.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class terms {
    /**
     * All terms, oldest first.
     *
     * @return \stdClass[] id => term
     */
    public static function all(): array {
        global $DB;
        return $DB->get_records('local_academictranscript_term', null, 'startdate, id');
    }

    /**
     * Term select options for forms.
     *
     * The names are HTML-safe (escaped), because a select shows a label as it is. Pages that print a name from this
     * menu print it as it is, without escaping it again.
     *
     * @param bool $withnone
     * @return array id => name
     */
    public static function menu(bool $withnone = true): array {
        $menu = $withnone ? [0 => get_string('none')] : [];
        foreach (self::all() as $term) {
            $menu[$term->id] = format_string($term->name);
        }
        return $menu;
    }

    /**
     * Options text for the course field, in optionindex order.
     *
     * @return string
     */
    public static function options_text(): string {
        global $DB;
        $names = $DB->get_records_menu('local_academictranscript_term', null, 'optionindex', 'optionindex, name');
        $lines = [];
        $max = $names ? max(array_keys($names)) : 0;
        for ($i = 1; $i <= $max; $i++) {
            // A gap cannot normally happen; keep positions stable anyway.
            $lines[] = $names[$i] ?? get_string('termoptionunused', 'local_academictranscript', $i);
        }
        return implode("\n", $lines);
    }

    /**
     * Save a term (insert or update) and refresh the course field options.
     *
     * @param \stdClass $data name, startdate, enddate, optional id
     * @return int term id
     */
    public static function save(\stdClass $data): int {
        global $DB, $USER;
        $record = (object)[
            'name' => trim($data->name),
            'startdate' => (int)$data->startdate,
            'enddate' => (int)$data->enddate,
            'usermodified' => $USER->id,
            'timemodified' => time(),
        ];
        if (!empty($data->id)) {
            $record->id = (int)$data->id;
            $DB->update_record('local_academictranscript_term', $record);
        } else {
            $record->optionindex = (int)$DB->get_field_sql('SELECT MAX(optionindex) FROM {local_academictranscript_term}') + 1;
            $record->id = $DB->insert_record('local_academictranscript_term', $record);
        }
        self::sync_field();
        return $record->id;
    }

    /**
     * Write the term names into the course Term field options.
     */
    public static function sync_field(): void {
        $field = coursefields::term_field();
        if (!$field) {
            return;
        }
        $config = $field->get('configdata');
        $config['options'] = self::options_text();
        $handler = \core_course\customfield\course_handler::create();
        $handler->save_field_configuration($field, (object)['configdata' => $config]);
    }

    /**
     * Term id for a course field value (option index).
     *
     * @param int $optionindex
     * @return int 0 if none
     */
    public static function id_for_option(int $optionindex): int {
        global $DB;
        if ($optionindex <= 0) {
            return 0;
        }
        return (int)$DB->get_field('local_academictranscript_term', 'id', ['optionindex' => $optionindex]);
    }

    /**
     * The term a student is taking a course in: admin override first, then the course field.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $courseoptionindex value of the course Term field
     * @param \stdClass|null $coderow the student's row in local_academictranscript_code, if any
     * @return int term id, 0 if none
     */
    public static function effective(int $userid, int $courseid, int $courseoptionindex, ?\stdClass $coderow): int {
        if ($coderow && !empty($coderow->termid)) {
            return (int)$coderow->termid;
        }
        return self::id_for_option($courseoptionindex);
    }
}
