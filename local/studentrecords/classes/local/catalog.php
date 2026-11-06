<?php

namespace local_studentrecords\local;

/**
 * Lookups and menus for levels, programs, courses, terms and the grading scale, and the school settings.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class catalog {
    /** @var array School settings asked by the setup wizard: name => form element type, step. */
    const SCHOOL = [
        'schoolname' => ['text', 'school'], 'othernames' => ['text', 'school'], 'legalname' => ['text', 'school'],
        'address' => ['textarea', 'school'], 'campusaddress' => ['textarea', 'school'], 'state' => ['text', 'school'],
        'website' => ['text', 'school'], 'phone' => ['text', 'school'], 'email' => ['text', 'school'],
        'registrarname' => ['text', 'school'], 'registrartitle' => ['text', 'school'], 'officehours' => ['text', 'school'],
        'timezone' => ['timezone', 'school'], 'opeid' => ['text', 'school'],
        'stateagency' => ['textarea', 'approvals'], 'statelicense' => ['text', 'approvals'],
        'accreditor' => ['text', 'approvals'], 'fedaid' => ['yesno', 'approvals'], 'f1' => ['yesno', 'approvals'],
        'ferpanotice' => ['textarea', 'approvals'],
        'calendartype' => ['calendar', 'calendar'], 'yearstart' => ['text', 'calendar'], 'creditunit' => ['text', 'calendar'],
        'withdrawpct' => ['percent', 'calendar'], 'minattendance' => ['percent', 'calendar'],
        'ldamoodle' => ['yesno', 'calendar'], 'refundlda' => ['yesno', 'calendar'], 'fulltimehours' => ['hours', 'calendar'],
        'selfreg' => ['yesno', 'calendar'],
        'idauto' => ['yesno', 'admissions'], 'idprefix' => ['text', 'admissions'], 'idyear' => ['yesno', 'admissions'],
        'idpadding' => ['number', 'admissions'], 'idseparator' => ['text', 'admissions'],
        'transferingpa' => ['yesno', 'admissions'], 'honors' => ['textarea', 'admissions'],
    ];

    /** @var string[] Delivery modes of a program. */
    const DELIVERY = ['online', 'inperson', 'hybrid'];

    /** @var string[] Student statuses. */
    const STATUSES = ['inquiry', 'applicant', 'waitlisted', 'admitted', 'denied', 'deferred', 'enrolled', 'active',
        'graduated', 'withdrawn',
        'onleave'];

    /**
     * A plugin setting.
     *
     * @param string $name
     * @return string
     */
    public static function setting(string $name): string {
        $v = get_config('local_studentrecords', $name);
        return $v === false ? '' : (string)$v;
    }

    /**
     * Whether an optional module is switched on in the setup wizard.
     *
     * @param string $module fedaid or f1
     * @return bool
     */
    public static function module_on(string $module): bool {
        return self::setting($module) === '1';
    }

    /**
     * The school's time zone for dates such as term start (99 = server time zone).
     *
     * @return string|float
     */
    public static function timezone() {
        $tz = self::setting('timezone');
        return $tz === '' ? 99 : $tz;
    }

    /**
     * Timestamp of noon on a Y-m-d date in the school's time zone (noon, so the date stays the same when shown in
     * a nearby time zone).
     *
     * @param string|null $ymd
     * @return int|null
     */
    public static function date_to_time(?string $ymd): ?int {
        if (!$ymd || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
            return null;
        }
        return make_timestamp((int)$m[1], (int)$m[2], (int)$m[3], 12, 0, 0, self::timezone());
    }

    /**
     * Timestamp of the start of a Y-m-d date in the school's time zone.
     *
     * @param string $ymd
     * @return int
     */
    public static function day_start(string $ymd): int {
        [$year, $month, $day] = array_map('intval', explode('-', $ymd));
        return make_timestamp($year, $month, $day, 0, 0, 0, self::timezone());
    }

    /**
     * Y-m-d of a timestamp in the school's time zone.
     *
     * @param int|null $time
     * @return string
     */
    public static function time_to_date(?int $time): string {
        return $time ? userdate($time, '%Y-%m-%d', self::timezone(), false) : '';
    }

    /**
     * A date (no time) for display, in the school's time zone.
     *
     * @param int|null $time
     * @return string
     */
    public static function show_date(?int $time): string {
        return $time ? userdate($time, get_string('strftimedate', 'langconfig'), self::timezone()) : '';
    }

    /**
     * Share of the term after which students can no longer withdraw: the school's rule, else 60%.
     *
     * @return float
     */
    public static function withdraw_percent(): float {
        $v = self::setting('withdrawpct');
        return is_numeric($v) && $v > 0 && $v <= 100 ? (float)$v : dates::WITHDRAW_PERCENT;
    }

    /**
     * Calculated last day to withdraw of a term (noon in the school's time zone).
     *
     * @param int $startdate
     * @param int $enddate
     * @return int|null
     */
    public static function withdraw_time(int $startdate, int $enddate): ?int {
        if (!$startdate || !$enddate) {
            return null;
        }
        return self::date_to_time(dates::withdraw_ymd(
            self::time_to_date($startdate),
            self::time_to_date($enddate),
            self::withdraw_percent()
        ));
    }

    /**
     * Whether a course total is rounded to a whole percent before the grading scale is applied. Academic Transcript owns
     * this setting. Without that plugin nothing is rounded.
     *
     * @return bool
     */
    public static function round_percent(): bool {
        return \core_component::get_component_directory('local_academictranscript') !== null &&
            get_config('local_academictranscript', 'roundpercent') !== '0';
    }

    /**
     * How repeated courses count. With Academic Transcript installed, Student Records follows its Repeat rule setting, so
     * the record and the transcript leave out the same attempts. Without it, every attempt counts.
     *
     * @return string latest, highest or all
     */
    public static function repeat_rule(): string {
        if (\core_component::get_component_directory('local_academictranscript') === null) {
            return 'all';
        }
        $rule = (string)get_config('local_academictranscript', 'repeatrule');
        return in_array($rule, ['highest', 'all'], true) ? $rule : 'latest';
    }

    /**
     * Delivery modes menu.
     *
     * @return array
     */
    public static function delivery_modes(): array {
        $out = [];
        foreach (self::DELIVERY as $mode) {
            $out[$mode] = get_string('delivery_' . $mode, 'local_studentrecords');
        }
        return $out;
    }

    /**
     * Levels, id => name.
     *
     * @return array
     */
    public static function levels(): array {
        global $DB;
        return $DB->get_records_menu('local_studentrecords_level', null, 'sortorder, name', 'id, name');
    }

    /**
     * Level id by name (case-insensitive); null when unknown.
     *
     * @param string $name
     * @return int|null
     */
    public static function level_id(string $name): ?int {
        foreach (self::levels() as $id => $levelname) {
            if (strcasecmp($levelname, trim($name)) === 0) {
                return (int)$id;
            }
        }
        return null;
    }

    /**
     * Programs, id => "CODE - Name", escaped for HTML: print it as it is.
     *
     * @param bool $activeonly
     * @return array
     */
    public static function programs(bool $activeonly = true): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('local_studentrecords_program', $activeonly ? ['active' => 1] : null, 'code') as $p) {
            $out[$p->id] = s($p->code) . ' - ' . format_string($p->name);
        }
        return $out;
    }

    /**
     * Program id by code; null when unknown.
     *
     * @param string $code
     * @return int|null
     */
    public static function program_id(string $code): ?int {
        global $DB;
        $id = $DB->get_field('local_studentrecords_program', 'id', ['code' => trim($code)]);
        return $id ? (int)$id : null;
    }

    /**
     * Course id by code; null when unknown.
     *
     * @param string $code
     * @return int|null
     */
    public static function course_id(string $code): ?int {
        global $DB;
        $id = $DB->get_field('local_studentrecords_course', 'id', ['code' => grading::normalise_code($code)]);
        return $id ? (int)$id : null;
    }

    /**
     * Terms, id => name, by start date.
     *
     * @return array
     */
    public static function terms(): array {
        global $DB;
        return $DB->get_records_menu('local_studentrecords_term', null, 'startdate, name', 'id, name');
    }

    /**
     * The grading scale, best first.
     *
     * @return array rows
     */
    public static function scale(): array {
        global $DB;
        return array_values($DB->get_records('local_studentrecords_grade', null, 'sortorder, points DESC, minpct DESC'));
    }

    /**
     * Letters of the scale for a menu, best first.
     *
     * @return array letter => letter
     */
    public static function letters(): array {
        $letters = grading::ordered_letters(array_map(fn($r) => (array)$r, self::scale()));
        return array_combine($letters, $letters) ?: [];
    }

    /**
     * Marks of the scale: rows without grade points (for example I, P, NP, W, NC), in scale order.
     *
     * @return array letter => scale row
     */
    public static function marks(): array {
        $out = [];
        foreach (self::scale() as $row) {
            if ($row->points === null) {
                $out[$row->letter] = $row;
            }
        }
        return $out;
    }

    /**
     * Every final grade staff and teachers can give: the letters, best first, then the marks.
     *
     * @return array letter => letter
     */
    public static function grade_menu(): array {
        $marks = array_keys(self::marks());
        return self::letters() + (array_combine($marks, $marks) ?: []);
    }

    /**
     * Faculty records, id => name.
     *
     * @param string $type faculty, staff or '' for both
     * @return array
     */
    public static function faculty(string $type = 'faculty'): array {
        global $DB;
        $out = [];
        $params = ['active' => 1] + ($type ? ['stafftype' => $type] : []);
        foreach ($DB->get_records('local_studentrecords_faculty', $params, 'lastname, firstname') as $f) {
            $out[$f->id] = self::faculty_name($f);
        }
        return $out;
    }

    /**
     * Display name of a faculty record.
     *
     * @param \stdClass $f
     * @return string
     */
    public static function faculty_name(\stdClass $f): string {
        return trim(($f->honorific ? $f->honorific . ' ' : '') . $f->firstname . ' ' . $f->lastname);
    }

    /**
     * Credit or clock hours menu.
     *
     * @return array
     */
    public static function unittypes(): array {
        return ['credit' => get_string('unit_credit', 'local_studentrecords'),
            'clock' => get_string('unit_clock', 'local_studentrecords')];
    }
}
