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
 * Programs and the course catalog. A program uses credit hours (degree programs) or clock hours
 * (non-degree programs); its courses carry hours of the same type.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class programs {
    /** @var string Credit hours. */
    const CREDIT = 'credit';

    /** @var string Clock hours. */
    const CLOCK = 'clock';

    /**
     * Active programs by name.
     *
     * @param bool $activeonly
     * @return \stdClass[]
     */
    public static function all(bool $activeonly = true): array {
        global $DB;
        return $DB->get_records('local_academictranscript_program', $activeonly ? ['active' => 1] : null, 'name');
    }

    /**
     * Program menu for forms.
     *
     * The names are HTML-safe (escaped), because a select shows a label as it is. Pages that print a name from this
     * menu print it as it is, without escaping it again.
     *
     * @param bool $withnone
     * @return array id => name
     */
    public static function menu(bool $withnone = true): array {
        $menu = $withnone ? [0 => get_string('none')] : [];
        foreach (self::all() as $p) {
            $menu[$p->id] = format_string($p->name);
        }
        return $menu;
    }

    /**
     * Unit type menu.
     *
     * @return array
     */
    public static function unittype_menu(): array {
        return [self::CREDIT => get_string('unit_credit', 'local_academictranscript'),
            self::CLOCK => get_string('unit_clock', 'local_academictranscript')];
    }

    /**
     * Normalise a unit type value ("clock", "Clock hours", "credit"...).
     *
     * @param string|null $value
     * @return string credit or clock
     */
    public static function clean_unittype(?string $value): string {
        return (stripos((string)$value, 'clock') !== false) ? self::CLOCK : self::CREDIT;
    }

    /**
     * Label for a unit type, e.g. "Credit Hours" or "Clock Hours" (settings can change them).
     *
     * @param string $unittype
     * @return string
     */
    public static function unit_label(string $unittype): string {
        if ($unittype === self::CLOCK) {
            return transcript::setting('clocklabel', get_string('clocklabel_default', 'local_academictranscript'));
        }
        return transcript::setting('creditlabel', get_string('creditlabel_default', 'local_academictranscript'));
    }

    /**
     * Short column heading for a unit type ("Cr" or "Hrs").
     *
     * @param string $unittype
     * @return string
     */
    public static function unit_short(string $unittype): string {
        return get_string($unittype === self::CLOCK ? 'col_clock' : 'col_cr', 'local_academictranscript');
    }

    /**
     * Add or update a program (matched by id, else by name).
     *
     * @param array|\stdClass $data name, credential, unittype, totalhours, active
     * @return int id
     */
    public static function save($data): int {
        global $DB, $USER;
        $data = (array)$data;
        $name = \core_text::substr(trim((string)$data['name']), 0, 255);
        $record = (object)['name' => $name,
            'credential' => \core_text::substr(trim((string)($data['credential'] ?? '')), 0, 255),
            'unittype' => self::clean_unittype($data['unittype'] ?? self::CREDIT),
            'totalhours' => ($data['totalhours'] ?? '') === '' ? null : (float)$data['totalhours'],
            'active' => isset($data['active']) && !$data['active'] ? 0 : 1,
            'usermodified' => $USER->id ?? 0, 'timemodified' => time()];
        $existing = !empty($data['id']) ? $DB->get_record('local_academictranscript_program', ['id' => $data['id']]) :
            $DB->get_record('local_academictranscript_program', ['name' => $name]);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_academictranscript_program', $record);
            return (int)$existing->id;
        }
        return (int)$DB->insert_record('local_academictranscript_program', $record);
    }

    /**
     * Add or update a catalog course (matched by code).
     *
     * @param array|\stdClass $data code, title, hours, unittype, programid or program (name), active
     * @return int id
     */
    public static function save_course($data): int {
        global $DB, $USER;
        $data = (array)$data;
        $code = \core_text::substr(trim((string)$data['code']), 0, 50);
        $programid = !empty($data['programid']) ? (int)$data['programid'] : null;
        if (!$programid && !empty($data['program'])) {
            $programid = (int)$DB->get_field('local_academictranscript_program', 'id', ['name' => trim($data['program'])]) ?: null;
        }
        $record = (object)['code' => $code, 'title' => \core_text::substr(trim((string)$data['title']), 0, 255),
            'hours' => (float)$data['hours'], 'unittype' => self::clean_unittype($data['unittype'] ?? self::CREDIT),
            'programid' => $programid, 'active' => isset($data['active']) && !$data['active'] ? 0 : 1,
            'usermodified' => $USER->id ?? 0, 'timemodified' => time()];
        if ($existing = $DB->get_record('local_academictranscript_catalog', ['code' => $code])) {
            $record->id = $existing->id;
            $DB->update_record('local_academictranscript_catalog', $record);
            return (int)$existing->id;
        }
        return (int)$DB->insert_record('local_academictranscript_catalog', $record);
    }

    /**
     * Catalog entries by upper-case code.
     *
     * @return \stdClass[] CODE => entry
     */
    public static function catalog_by_code(): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('local_academictranscript_catalog', ['active' => 1]) as $c) {
            $out[\core_text::strtoupper($c->code)] = $c;
        }
        return $out;
    }
}
