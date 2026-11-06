<?php

namespace local_studentrecords\local;

/**
 * Change history for people: plain field labels and readable values (program name, person's name, dates) instead
 * of stored field names and ids. Display only; the stored history is unchanged.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class history_view {
    /** @var array Field => language string of its label, where the string name differs from the field name. */
    const LABELS = [
        'programid' => 'program', 'admittermid' => 'admitterm', 'advisorid' => 'advisor', 'userid' => 'moodleaccount',
        'courseid' => 'hist_course', 'facultyid' => 'hist_faculty', 'reqcourseid' => 'hist_reqcourse', 'levelid' => 'level',
        'jobtitle' => 'fac_jobtitle', 'department' => 'fac_department', 'honorific' => 'hist_honorific',
        'firstname' => 'hist_firstname', 'lastname' => 'hist_lastname', 'email' => 'hist_email', 'name' => 'termname',
        'notes' => 'notes', 'title' => 'cred_title', 'subject' => 'cred_subject', 'institution' => 'cred_institution',
        'yearawarded' => 'cred_yearawarded', 'verified' => 'cred_verified', 'withdrawcalc' => 'withdrawcalc',
        'deliverymode' => 'prog_deliverymode', 'location' => 'prog_location', 'delivery' => 'prog_delivery',
        'timeremoved' => 'hist_timeremoved', 'removedby' => 'hist_removedby', 'amount' => 'amountusd',
        'reversedby' => 'reversed', '*' => 'hist_erased',
    ];

    /** @var string[] Fields holding a date. */
    const DATES = ['dob', 'awarddate', 'expires', 'startdate', 'enddate', 'adddropstart', 'adddropend', 'withdrawby',
        'examstart', 'examend', 'timeremoved', 'withdrawdate', 'lastattended', 'statedate', 'completed', 'decisiondate',
        'gradefreeze', 'timereleased', 'awarded', 'conferred'];

    /** @var string[] Fields holding yes (1) or no (0). */
    const YESNO = ['isf1', 'ferpaoptout', 'active', 'nolms', 'verified', 'isdegree', 'ingpa', 'withdrawcalc', 'accesspending',
        'refundable', 'qualified'];

    /** @var array Fields whose value is a language string: field => string prefix. */
    const CODED = ['status' => 'status_', 'loadtype' => 'load_', 'employment' => 'load_', 'stafftype' => 'type_',
        'unittype' => 'unit_', 'deliverymode' => 'delivery_', 'credtype' => 'credtype_', 'credentialtype' => 'cred_',
        'kind' => 'feekind_', 'basis' => 'feebasis_', 'scope' => 'scope_'];

    /** @var array Looked-up names for this request: kind => id => name. */
    protected static $names = [];

    /**
     * One table row: date, who, field, old value, new value, source.
     *
     * @param \stdClass $h history row
     * @return string[] HTML cells
     */
    public static function row(\stdClass $h): array {
        $p = 'local_studentrecords';
        $source = get_string_manager()->string_exists('source_' . $h->source, $p) ? get_string('source_' . $h->source, $p) :
            s($h->source);
        return [userdate($h->timecreated), s(self::user_name((int)$h->userid)), s(self::label($h->fieldname)),
            s(self::value($h->fieldname, $h->oldvalue)), s(self::value($h->fieldname, $h->newvalue)), $source];
    }

    /**
     * Plain label of a stored field.
     *
     * @param string $field
     * @return string
     */
    public static function label(string $field): string {
        $p = 'local_studentrecords';
        $string = self::LABELS[$field] ?? $field;
        foreach ([$string, 'set_' . $field] as $name) {
            if (get_string_manager()->string_exists($name, $p)) {
                return get_string($name, $p);
            }
        }
        return $field;
    }

    /**
     * Readable form of a stored value (text, not HTML).
     *
     * @param string $field
     * @param string|null $value
     * @return string
     */
    public static function value(string $field, ?string $value): string {
        $p = 'local_studentrecords';
        if ($value === null || $value === '') {
            return '';
        }
        if (in_array($field, self::DATES, true)) {
            return ctype_digit($value) ? catalog::show_date((int)$value) : $value;
        }
        if (in_array($field, self::YESNO, true)) {
            return $value ? get_string('yes') : get_string('no');
        }
        if (isset(self::CODED[$field])) {
            $name = self::CODED[$field] . $value;
            return get_string_manager()->string_exists($name, $p) ? get_string($name, $p) : $value;
        }
        if ($field === 'homecountry') {
            return get_string_manager()->get_list_of_countries()[$value] ?? $value;
        }
        if (!ctype_digit($value)) {
            return $value;
        }
        $id = (int)$value;
        switch ($field) {
            case 'userid':
            case 'removedby':
                return self::user_name($id);
            case 'programid':
                return self::lookup('program', $id, fn() => reports::program_names());
            case 'admittermid':
                return self::lookup('term', $id, fn() => array_map([reports::class, 'plain'], catalog::terms()));
            case 'advisorid':
            case 'facultyid':
                return self::lookup('faculty', $id, function () {
                    global $DB;
                    // All records, also inactive ones (a former advisor still has a name).
                    return array_map(
                        fn($f) => catalog::faculty_name($f),
                        $DB->get_records('local_studentrecords_faculty', null, '', 'id, honorific, firstname, lastname')
                    );
                });
            case 'levelid':
                return self::lookup('level', $id, fn() => array_map([reports::class, 'plain'], catalog::levels()));
            case 'courseid':
            case 'reqcourseid':
                return self::lookup('course', $id, function () {
                    global $DB;
                    return array_map(
                        fn($c) => $c->code . ' ' . reports::plain($c->title),
                        $DB->get_records('local_studentrecords_course', null, '', 'id, code, title')
                    );
                });
        }
        return $value;
    }

    /**
     * Full name of a Moodle user, '-' for none.
     *
     * @param int $userid
     * @return string
     */
    public static function user_name(int $userid): string {
        if (!$userid) {
            return '-';
        }
        if (!isset(self::$names['user'][$userid])) {
            $u = \core_user::get_user($userid);
            self::$names['user'][$userid] = $u ? fullname($u) : '#' . $userid;
        }
        return self::$names['user'][$userid];
    }

    /**
     * Name of a record by id from a menu loaded once per request; "#id" when it no longer exists.
     *
     * @param string $kind
     * @param int $id
     * @param callable $load returns id => name
     * @return string
     */
    protected static function lookup(string $kind, int $id, callable $load): string {
        if (!isset(self::$names[$kind])) {
            self::$names[$kind] = $load();
        }
        return isset(self::$names[$kind][$id]) ? (string)self::$names[$kind][$id] : '#' . $id;
    }
}
