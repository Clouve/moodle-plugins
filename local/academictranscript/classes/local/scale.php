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
 * The school's grading scale and marks (stored in the database, editable by admins).
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scale {
    /** @var string Transfer credit mark (set by the plugin). */
    const TRANSFER = 'TR';

    /** @var string In progress mark (set by the plugin). */
    const INPROGRESS = 'IP';

    /** @var array|null Request cache of active grade rows. */
    protected static $grades = null;

    /** @var array|null Request cache of active marks. */
    protected static $marks = null;

    /**
     * Forget cached rows (after edits).
     */
    public static function reset_cache(): void {
        self::$grades = null;
        self::$marks = null;
    }

    /**
     * Active grade rows, highest minimum first.
     *
     * @return \stdClass[]
     */
    public static function grades(): array {
        global $DB;
        if (self::$grades === null) {
            self::$grades = $DB->get_records('local_academictranscript_grade', ['active' => 1], 'minpct DESC, id');
        }
        return self::$grades;
    }

    /**
     * Active marks in display order.
     *
     * @return \stdClass[] keyed by id
     */
    public static function marks(): array {
        global $DB;
        if (self::$marks === null) {
            self::$marks = $DB->get_records('local_academictranscript_mark', ['active' => 1], 'sortorder, id');
        }
        return self::$marks;
    }

    /**
     * Marks teachers may enter (all active marks except TR and IP).
     *
     * The labels are HTML-safe (escaped), because they only go into selects, which show a label as it is.
     *
     * @return array code => label
     */
    public static function teacher_marks(): array {
        $menu = [];
        foreach (self::marks() as $m) {
            if (!$m->systemmark) {
                $menu[$m->code] = format_string($m->label);
            }
        }
        return $menu;
    }

    /**
     * Calculator configured from the scale, marks and settings.
     *
     * @return calculator
     */
    public static function calculator(): calculator {
        $grades = [];
        foreach (self::grades() as $g) {
            $grades[] = ['letter' => $g->letter, 'min' => $g->minpct, 'max' => $g->maxpct, 'points' => $g->points,
                'ingpa' => $g->ingpa, 'earns' => $g->earns];
        }
        $marks = [];
        foreach (self::marks() as $m) {
            $marks[] = ['code' => $m->code, 'earns' => $m->earns];
        }
        $rule = (string)get_config('local_academictranscript', 'repeatrule');
        return new calculator(
            $grades,
            $marks,
            $rule === '' ? calculator::REPEAT_LATEST : $rule,
            get_config('local_academictranscript', 'roundpercent') !== '0'
        );
    }

    /**
     * Letter for a percentage (null if the scale has no matching row).
     *
     * @param float $percent
     * @return string|null
     */
    public static function letter(float $percent): ?string {
        return self::calculator()->letter($percent);
    }

    /**
     * Label of a mark, as plain text, for the footnotes of the transcript (the code itself when it is not a mark).
     *
     * Whatever prints the label escapes it. Menus use teacher_marks(), which is escaped already.
     *
     * @param string $code
     * @return string
     */
    public static function code_label(string $code): string {
        foreach (self::marks() as $m) {
            if ($m->code === $code) {
                return text::plain($m->label, \context_system::instance());
            }
        }
        return $code;
    }

    /**
     * Grades a posted grade may be changed to: letters, then teacher marks.
     *
     * The labels are HTML-safe (escaped), because they go into a select, which shows a label as it is.
     *
     * @return array grade => label
     */
    public static function grade_menu(): array {
        $menu = [];
        foreach (self::grades() as $g) {
            $menu[$g->letter] = s($g->letter . ' (' . self::percent_label($g) . ')');
        }
        foreach (self::teacher_marks() as $code => $label) {
            $menu[$code] = s($code) . ' - ' . $label;
        }
        return $menu;
    }

    /**
     * Percentage text of a grade row: its own label, "Below X%" for a row starting at 0, else "min-max%".
     *
     * @param \stdClass $g
     * @return string
     */
    public static function percent_label(\stdClass $g): string {
        if (trim((string)$g->percentlabel) !== '') {
            return $g->percentlabel;
        }
        $fmt = fn($n) => rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
        if ((float)$g->minpct <= 0) {
            return get_string('belowpercent', 'local_academictranscript', $fmt((float)$g->maxpct + 1));
        }
        return $fmt($g->minpct) . '-' . $fmt($g->maxpct) . '%';
    }

    /**
     * Rows of the transcript key: grades (description merged over consecutive equal rows) then marks.
     *
     * The descriptions are plain text. The rows are stored in issued transcripts, and whatever prints a row escapes it.
     *
     * @return array list of ['grade', 'points', 'percent', 'description', 'rowspan', 'ismark']
     */
    public static function scale_rows(): array {
        $context = \context_system::instance();
        $rows = [];
        $grades = array_values(self::grades());
        $n = count($grades);
        for ($i = 0; $i < $n; $i++) {
            $g = $grades[$i];
            $desc = (string)$g->description;
            $first = $i === 0 || (string)$grades[$i - 1]->description !== $desc;
            $span = 0;
            if ($first) {
                for ($j = $i; $j < $n && (string)$grades[$j]->description === $desc; $j++) {
                    $span++;
                }
            }
            $rows[] = ['grade' => $g->letter, 'points' => number_format((float)$g->points, 1),
                'percent' => self::percent_label($g), 'description' => $first ? text::plain($desc, $context) : null,
                'rowspan' => $span, 'ismark' => false, 'ingpa' => (bool)$g->ingpa];
        }
        foreach (self::marks() as $m) {
            $rows[] = ['grade' => $m->code, 'points' => get_string('notingpa', 'local_academictranscript'),
                'percent' => '', 'description' => text::plain((string)($m->description ?: $m->label), $context), 'rowspan' => 1,
                'ismark' => true, 'ingpa' => false];
        }
        return $rows;
    }

    /**
     * The ready-made standard 4.0 scale (a starting point; every value can be edited).
     *
     * @return array list of rows
     */
    public static function standard_preset(): array {
        $rows = [
            ['A', 93, 100, 4.0, 'a'], ['A-', 90, 92, 3.7, 'a'], ['B+', 87, 89, 3.3, 'b'], ['B', 83, 86, 3.0, 'b'],
            ['B-', 80, 82, 2.7, 'b'], ['C+', 77, 79, 2.3, 'c'], ['C', 73, 76, 2.0, 'c'], ['C-', 70, 72, 1.7, 'c'],
            ['D+', 67, 69, 1.3, 'd'], ['D', 63, 66, 1.0, 'd'], ['D-', 60, 62, 0.7, 'd'], ['F', 0, 59, 0.0, 'f'],
        ];
        $out = [];
        foreach ($rows as [$letter, $min, $max, $points, $band]) {
            $out[] = ['letter' => $letter, 'minpct' => $min, 'maxpct' => $max, 'points' => $points, 'ingpa' => 1,
                'earns' => $letter === 'F' ? 0 : 1,
                'description' => get_string('preset_' . $band, 'local_academictranscript'), 'percentlabel' => ''];
        }
        return $out;
    }

    /**
     * The standard marks (a starting point; every value can be edited).
     *
     * @return array list of rows
     */
    public static function standard_marks(): array {
        $out = [];
        foreach (['P' => 1, 'NP' => 0, 'I' => 0, 'NC' => 0, 'W' => 0, 'TR' => 1, 'IP' => 0] as $code => $earns) {
            $key = strtolower($code);
            $out[] = ['code' => $code, 'label' => get_string('mark_' . $key, 'local_academictranscript'),
                'description' => get_string('markdesc_' . $key, 'local_academictranscript'), 'earns' => $earns,
                'systemmark' => in_array($code, [self::TRANSFER, self::INPROGRESS]) ? 1 : 0];
        }
        return $out;
    }

    /**
     * Replace the active scale with new rows. Old rows are retired (kept, active = 0), never deleted.
     *
     * @param array $rows list of arrays with letter, minpct, maxpct, points, ingpa, earns, description, percentlabel
     */
    public static function replace_grades(array $rows): void {
        global $DB, $USER;
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $DB->set_field('local_academictranscript_grade', 'active', 0, ['active' => 1]);
        foreach ($rows as $r) {
            $r = (array)$r;
            $DB->insert_record('local_academictranscript_grade', (object)[
                'letter' => \core_text::substr(trim($r['letter']), 0, 10),
                'minpct' => (float)$r['minpct'], 'maxpct' => (float)$r['maxpct'], 'points' => (float)$r['points'],
                'ingpa' => empty($r['ingpa']) ? 0 : 1, 'earns' => empty($r['earns']) ? 0 : 1,
                'description' => \core_text::substr(trim((string)($r['description'] ?? '')), 0, 255),
                'percentlabel' => \core_text::substr(trim((string)($r['percentlabel'] ?? '')), 0, 50),
                'active' => 1, 'usermodified' => $USER->id ?? 0, 'timemodified' => $now,
            ]);
        }
        $transaction->allow_commit();
        self::reset_cache();
    }

    /**
     * Add or update marks by code (never deleted; set active = 0 to hide).
     *
     * @param array $rows list of arrays with code, label, description, earns, systemmark, active
     */
    public static function save_marks(array $rows): void {
        global $DB, $USER;
        $max = (int)$DB->get_field_sql('SELECT MAX(sortorder) FROM {local_academictranscript_mark}');
        foreach ($rows as $r) {
            $r = (array)$r;
            $code = \core_text::strtoupper(\core_text::substr(trim($r['code']), 0, 10));
            if ($code === '') {
                continue;
            }
            $record = (object)['code' => $code, 'label' => \core_text::substr(trim($r['label'] ?? $code), 0, 100),
                'description' => \core_text::substr(trim((string)($r['description'] ?? '')), 0, 255),
                'earns' => empty($r['earns']) ? 0 : 1, 'active' => isset($r['active']) && !$r['active'] ? 0 : 1,
                'usermodified' => $USER->id ?? 0, 'timemodified' => time()];
            if ($existing = $DB->get_record('local_academictranscript_mark', ['code' => $code])) {
                $record->id = $existing->id;
                // TR and IP stay system marks.
                $DB->update_record('local_academictranscript_mark', $record);
            } else {
                $record->systemmark = in_array($code, [self::TRANSFER, self::INPROGRESS]) ? 1 : (empty($r['systemmark']) ? 0 : 1);
                $record->sortorder = ++$max;
                $DB->insert_record('local_academictranscript_mark', $record);
            }
        }
        self::reset_cache();
    }

    /**
     * Load the standard scale and marks that are missing (used on install).
     *
     * The standard scale is loaded only while no grade row is active, so a scale the school has set up is never
     * replaced. Marks are added by code, as in add_missing_standard_marks().
     */
    public static function load_defaults(): void {
        global $DB;
        if (!$DB->record_exists('local_academictranscript_grade', ['active' => 1])) {
            self::replace_grades(self::standard_preset());
        }
        self::add_missing_standard_marks();
    }

    /**
     * Add the standard marks whose code is not in the marks table yet. Marks that exist (even retired or
     * edited ones) are left as they are.
     */
    public static function add_missing_standard_marks(): void {
        global $DB;
        $have = array_flip($DB->get_records_menu('local_academictranscript_mark', null, '', 'id, code'));
        self::save_marks(array_filter(self::standard_marks(), fn($m) => !isset($have[$m['code']])));
    }

    /**
     * Problems with a set of grade rows (overlaps or impossible values). Empty when fine.
     *
     * @param array $rows list of arrays (letter, minpct, maxpct, points)
     * @return string[]
     */
    public static function validate_rows(array $rows): array {
        $errors = [];
        $letters = [];
        foreach ($rows as $r) {
            $r = (array)$r;
            $letter = trim((string)$r['letter']);
            if ($letter === '') {
                $errors[] = get_string('err_letterempty', 'local_academictranscript');
                continue;
            }
            if (isset($letters[$letter])) {
                $errors[] = get_string('err_letterdup', 'local_academictranscript', $letter);
            }
            $letters[$letter] = true;
            if ((float)$r['minpct'] > (float)$r['maxpct'] || (float)$r['minpct'] < 0 || (float)$r['maxpct'] > 1000) {
                $errors[] = get_string('err_range', 'local_academictranscript', $letter);
            }
            if ((float)$r['points'] < 0 || (float)$r['points'] > 100) {
                $errors[] = get_string('err_points', 'local_academictranscript', $letter);
            }
        }
        usort($rows, fn($a, $b) => (float)((array)$a)['minpct'] <=> (float)((array)$b)['minpct']);
        for ($i = 1; $i < count($rows); $i++) {
            $prev = (array)$rows[$i - 1];
            $cur = (array)$rows[$i];
            if ((float)$cur['minpct'] <= (float)$prev['maxpct'] && (float)$cur['minpct'] != (float)$prev['minpct']) {
                $errors[] = get_string('err_overlap', 'local_academictranscript', $prev['letter'] . ' / ' . $cur['letter']);
            }
        }
        return $errors;
    }
}
