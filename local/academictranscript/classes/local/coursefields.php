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

use core_course\customfield\course_handler;

/**
 * The "Transcript" course custom fields: Course code (required), Hours, Hour type and Term.
 *
 * Field short names never change: the values saved on courses are stored against them.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coursefields {
    /** @var string Course code field shortname. */
    const CODE = 'transcript_coursecode';

    /** @var string Hours field shortname (credit or clock hours). */
    const HOURS = 'transcript_creditunits';

    /** @var string Hour type field shortname (select: 1 = credit hours, 2 = clock hours). */
    const HOURTYPE = 'transcript_hourtype';

    /** @var string Term field shortname (select; options are the term list, see terms::sync_field()). */
    const TERM = 'transcript_term';

    /**
     * Create the category and the fields if they are missing.
     *
     * A field that is already there, because an uninstall kept it, gets the settings it needs again (the code is required,
     * unique and locked, the other fields are locked). Its other settings stay. Never changes course values.
     */
    public static function ensure(): void {
        $handler = course_handler::create();
        $categoryname = get_string('coursefieldcategory', 'local_academictranscript');
        $category = null;
        $existing = [];
        foreach ($handler->get_categories_with_fields() as $cat) {
            foreach ($cat->get_fields() as $field) {
                $existing[$field->get('shortname')] = $field;
                if (in_array($field->get('shortname'), [self::CODE, self::HOURS, self::TERM])) {
                    $category = $cat;
                }
            }
            if (!$category && $cat->get('name') === $categoryname) {
                $category = $cat;
            }
        }
        if (!$category) {
            $category = \core_customfield\category_controller::create($handler->create_category($categoryname));
        }
        $common = ['locked' => 1, 'visibility' => course_handler::VISIBLETOALL];
        // What a field kept by an uninstall gets back: the same values that a new field starts with.
        $wanted = [
            self::CODE => ['required' => 1, 'uniquevalues' => 1, 'locked' => 1],
            self::HOURS => ['locked' => 1],
            self::HOURTYPE => ['locked' => 1],
            self::TERM => ['locked' => 1],
        ];
        $fields = [
            self::CODE => ['text', 'cf_code', 'cf_code_desc', ['required' => 1, 'uniquevalues' => 1, 'defaultvalue' => '',
                'displaysize' => 20, 'maxlength' => 50, 'ispassword' => 0, 'link' => '', 'linktarget' => '']],
            self::HOURS => ['number', 'cf_hours', 'cf_hours_desc', ['required' => 0, 'uniquevalues' => 0, 'defaultvalue' => '',
                'minimumvalue' => 0, 'maximumvalue' => 5000, 'decimalplaces' => 1, 'display' => '{value}',
                'displaywhenzero' => '0', 'fieldtype' => '']],
            self::HOURTYPE => ['select', 'cf_hourtype', 'cf_hourtype_desc', ['required' => 0, 'uniquevalues' => 0,
                'options' => get_string('unit_credit', 'local_academictranscript') . "\n" .
                    get_string('unit_clock', 'local_academictranscript'), 'defaultvalue' => '']],
            self::TERM => ['select', 'cf_term', 'cf_term_desc', ['required' => 0, 'uniquevalues' => 0,
                'options' => terms::options_text(), 'defaultvalue' => '']],
        ];
        foreach ($fields as $shortname => [$type, $namekey, $desckey, $config]) {
            if (isset($existing[$shortname])) {
                self::apply_settings($existing[$shortname], $wanted[$shortname]);
                continue;
            }
            $field = \core_customfield\field_controller::create(0, (object)['type' => $type], $category);
            $handler->save_field_configuration($field, (object)[
                'name' => get_string($namekey, 'local_academictranscript'),
                'shortname' => $shortname,
                'description' => '<p>' . get_string($desckey, 'local_academictranscript') . '</p>',
                'descriptionformat' => FORMAT_HTML,
                'configdata' => $config + $common,
            ]);
        }
    }

    /**
     * Let go of the fields when the plugin is uninstalled.
     *
     * The Course code, Hours and Hour type fields stay, with the values saved on courses, but they are no longer
     * required, unique or locked, so courses stay editable without the plugin. The Term field is deleted with its values:
     * they only point into the term list of the plugin, and a later install numbers the terms from 1 again.
     */
    public static function release(): void {
        foreach ([self::CODE, self::HOURS, self::HOURTYPE] as $shortname) {
            $field = self::field($shortname);
            if ($field) {
                self::apply_settings($field, ['required' => 0, 'uniquevalues' => 0, 'locked' => 0]);
            }
        }
        // Delete through the handler: it forgets the field, so term_field() no longer returns it.
        $term = self::term_field();
        if ($term) {
            course_handler::create()->delete_field_configuration($term);
        }
    }

    /**
     * Set settings on a field that exists, and save the field only when a setting differs.
     *
     * The other settings of the field stay as they are, and so do the values saved on courses.
     *
     * @param \core_customfield\field_controller $field
     * @param int[] $settings setting name => wanted value (0 or 1)
     */
    protected static function apply_settings(\core_customfield\field_controller $field, array $settings): void {
        $config = $field->get('configdata');
        $changes = [];
        foreach ($settings as $name => $value) {
            // The field form saves yes and no as text ("1"), this class as numbers: compare them as numbers.
            if ((int)($config[$name] ?? 0) !== $value) {
                $changes[$name] = $value;
            }
        }
        if ($changes) {
            course_handler::create()->save_field_configuration($field, (object)['configdata' => array_merge($config, $changes)]);
        }
    }

    /**
     * A field controller by shortname, or null.
     *
     * @param string $shortname
     * @return \core_customfield\field_controller|null
     */
    public static function field(string $shortname): ?\core_customfield\field_controller {
        foreach (course_handler::create()->get_fields() as $field) {
            if ($field->get('shortname') === $shortname) {
                return $field;
            }
        }
        return null;
    }

    /**
     * The Term field controller, or null.
     *
     * @return \core_customfield\field_controller|null
     */
    public static function term_field(): ?\core_customfield\field_controller {
        return self::field(self::TERM);
    }

    /**
     * Course code, hours, hour type and term option for courses.
     *
     * @param int[] $courseids
     * @return array courseid => ['code' => string, 'hours' => float|null, 'unittype' => string ('' if not set),
     *               'termindex' => int]
     */
    public static function get(array $courseids): array {
        $result = [];
        foreach ($courseids as $id) {
            $result[$id] = ['code' => '', 'hours' => null, 'unittype' => '', 'termindex' => 0];
        }
        if (!$courseids) {
            return $result;
        }
        $handler = course_handler::create();
        foreach ($handler->get_instances_data($courseids, true) as $courseid => $datas) {
            foreach ($datas as $data) {
                $short = $data->get_field()->get('shortname');
                $value = $data->get('id') ? $data->get_value() : null;
                if ($short === self::CODE) {
                    $result[$courseid]['code'] = trim((string)$value);
                } else if ($short === self::HOURS && $value !== null && $value !== '') {
                    $result[$courseid]['hours'] = (float)$value;
                } else if ($short === self::HOURTYPE && $value) {
                    $result[$courseid]['unittype'] = ((int)$value === 2) ? programs::CLOCK : programs::CREDIT;
                } else if ($short === self::TERM && $value) {
                    $result[$courseid]['termindex'] = (int)$value;
                }
            }
        }
        return $result;
    }

    /**
     * Course values with the catalog filled in where the course has no hours or hour type.
     *
     * @param int[] $courseids
     * @return array as get()
     */
    public static function get_resolved(array $courseids): array {
        $values = self::get($courseids);
        $catalog = null;
        foreach ($values as $id => $v) {
            if ($v['code'] !== '' && ($v['hours'] === null || $v['unittype'] === '')) {
                $catalog = $catalog ?? programs::catalog_by_code();
                $entry = $catalog[\core_text::strtoupper($v['code'])] ?? null;
                if ($entry) {
                    if ($v['hours'] === null) {
                        $values[$id]['hours'] = (float)$entry->hours;
                    }
                    if ($v['unittype'] === '') {
                        $values[$id]['unittype'] = $entry->unittype;
                    }
                }
            }
        }
        return $values;
    }

    /**
     * Format hours (3.0 -> "3", 1.5 -> "1.5").
     *
     * @param float|null $hours
     * @return string
     */
    public static function format_hours(?float $hours): string {
        if ($hours === null) {
            return '';
        }
        return rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.');
    }
}
