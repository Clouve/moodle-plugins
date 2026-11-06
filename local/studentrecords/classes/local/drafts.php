<?php

namespace local_studentrecords\local;

/**
 * Imported items waiting for review, and applying approved items to the records.
 *
 * Nothing goes live until an admin approves it. Items already equal to what is saved are marked "same" so a
 * repeated import shows only what changed. Applying only adds or updates records, never deletes.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class drafts {
    /** @var string Table. */
    const TABLE = 'local_studentrecords_draft';

    /** @var string[] Date fields of a term. */
    const TERMDATES = ['startdate', 'enddate', 'adddropstart', 'adddropend', 'withdrawby', 'examstart', 'examend'];

    /**
     * Store an import: the file record and its draft items.
     *
     * @param string $filename
     * @param array $result output of importer::build()
     * @return int import id
     */
    public static function store(string $filename, array $result): int {
        global $DB, $USER;
        $transaction = $DB->start_delegated_transaction();
        $importid = (int)$DB->insert_record('local_studentrecords_import', (object)[
            'filename' => \core_text::substr($filename, 0, 255),
            'summary' => json_encode(['sheets' => $result['sheets'], 'gaps' => $result['gaps']]),
            'userid' => $USER->id, 'timecreated' => time()]);
        foreach ($result['items'] as $i => [$type, $key, $data, $source, $problems]) {
            $existing = self::existing($type, $data);
            $same = $existing && self::matches($type, $data, $existing);
            $DB->insert_record(self::TABLE, (object)['importid' => $importid, 'itemtype' => $type,
                'itemkey' => \core_text::substr($key, 0, 255), 'data' => json_encode($data),
                'source' => \core_text::substr($source, 0, 255), 'problems' => json_encode($problems),
                'state' => $same ? 'same' : 'pending', 'sortorder' => $i]);
        }
        $transaction->allow_commit();
        audit::log('import', 'import', $importid, null, $filename);
        return $importid;
    }

    /**
     * Counts per type and state for an import.
     *
     * @param int $importid
     * @return array type => state => count
     */
    public static function counts(int $importid): array {
        global $DB;
        $out = [];
        $rs = $DB->get_recordset_sql("SELECT itemtype, state, COUNT(1) AS n FROM {" . self::TABLE . "}
                                       WHERE importid = ? GROUP BY itemtype, state", [$importid]);
        foreach ($rs as $r) {
            $out[$r->itemtype][$r->state] = (int)$r->n;
        }
        $rs->close();
        return $out;
    }

    /**
     * Whether an item has a problem that must be fixed before it can be approved.
     *
     * @param \stdClass $draft
     * @return bool
     */
    public static function has_error(\stdClass $draft): bool {
        foreach ((array)json_decode((string)$draft->problems, true) as $p) {
            if (($p[0] ?? '') === 'error') {
                return true;
            }
        }
        return false;
    }

    /**
     * A list of problems as HTML. The values in a problem are text from the imported file, so each is escaped here.
     *
     * @param array $problems list of [severity, language string key, value]
     * @return string HTML
     */
    public static function problems_html(array $problems): string {
        $out = [];
        foreach ($problems as [$severity, $key, $a]) {
            $a = is_array($a) ? (object)array_map('s', $a) : ($a === null ? null : s($a));
            $text = get_string_manager()->string_exists($key, 'local_studentrecords') ?
                get_string($key, 'local_studentrecords', $a) : s($key);
            $out[] = \html_writer::span($text, $severity === 'error' ? 'text-danger' : ($severity === 'info' ? 'text-muted' : ''));
        }
        return implode('<br>', $out);
    }

    /**
     * Approve items: apply them in dependency order. Items that cannot be applied stay pending with a reason.
     *
     * @param int[] $ids draft ids
     * @return array [applied count, list of messages]
     */
    public static function approve(array $ids): array {
        global $DB, $USER;
        $drafts = $DB->get_records_list(self::TABLE, 'id', $ids);
        $order = array_flip(importer::TYPES);
        uasort($drafts, fn($a, $b) => [$order[$a->itemtype] ?? 99, $a->sortorder] <=> [$order[$b->itemtype] ?? 99, $b->sortorder]);
        $applied = 0;
        $messages = [];
        $courses = [];
        foreach ($drafts as $draft) {
            if ($draft->state !== 'pending') {
                continue;
            }
            if (self::has_error($draft)) {
                $messages[] = get_string('notappliederror', 'local_studentrecords', s($draft->itemkey));
                continue;
            }
            try {
                $data = json_decode($draft->data, true);
                self::apply($draft->itemtype, $data);
                if ($draft->itemtype === 'course') {
                    $courses[] = $data;
                }
                $DB->update_record(self::TABLE, (object)['id' => $draft->id, 'state' => 'approved',
                    'decidedby' => $USER->id, 'timedecided' => time()]);
                $applied++;
            } catch (\moodle_exception $e) {
                $messages[] = s($draft->itemkey) . ': ' . $e->getMessage();
            }
        }
        // Prerequisites last, so a course can require one approved later in the same batch.
        foreach ($courses as $data) {
            self::link_prereqs($data);
        }
        if ($applied) {
            audit::log('approve', 'import', (int)reset($drafts)->importid, null, $applied . ' items');
        }
        return [$applied, $messages];
    }

    /**
     * Reject items (kept, marked rejected).
     *
     * @param int[] $ids
     * @return int count
     */
    public static function reject(array $ids): int {
        global $DB, $USER;
        $n = 0;
        foreach ($DB->get_records_list(self::TABLE, 'id', $ids) as $draft) {
            if ($draft->state === 'pending') {
                $DB->update_record(self::TABLE, (object)['id' => $draft->id, 'state' => 'rejected',
                    'decidedby' => $USER->id, 'timedecided' => time()]);
                $n++;
            }
        }
        if ($n) {
            audit::log('reject', 'import', null, null, $n . ' items');
        }
        return $n;
    }

    /**
     * Save a fixed version of a pending item; the admin's fix clears the problems that were found.
     *
     * @param int $id
     * @param array $data
     */
    public static function fix(int $id, array $data): void {
        global $DB;
        $draft = $DB->get_record(self::TABLE, ['id' => $id, 'state' => 'pending'], '*', MUST_EXIST);
        $old = json_decode($draft->data, true);
        if ($draft->itemtype === 'school') {
            // The value can be fixed. Which setting it goes to cannot: that came from the importer's own list.
            unset($data['field']);
        }
        $new = array_merge($old, array_intersect_key($data, $old));
        $DB->update_record(self::TABLE, (object)['id' => $id, 'data' => json_encode($new),
            'problems' => json_encode([['warning', 'problem_fixedbyadmin', null]])]);
    }

    /**
     * The saved record an item would update, if any.
     *
     * @param string $type
     * @param array $d
     * @return \stdClass|null
     */
    public static function existing(string $type, array $d): ?\stdClass {
        global $DB;
        $r = null;
        switch ($type) {
            case 'school':
                $v = get_config('local_studentrecords', $d['field']);
                return $v === false ? null : (object)['value' => $v];
            case 'level':
                $id = catalog::level_id($d['name']);
                $r = $id ? $DB->get_record('local_studentrecords_level', ['id' => $id]) : null;
                break;
            case 'grade':
                $r = $DB->get_record('local_studentrecords_grade', ['letter' => $d['letter']]);
                break;
            case 'term':
                $r = $DB->get_record('local_studentrecords_term', ['name' => $d['name']]);
                break;
            case 'course':
                $r = $DB->get_record('local_studentrecords_course', ['code' => $d['code']]);
                break;
            case 'program':
                $r = $DB->get_record('local_studentrecords_program', ['code' => $d['code']]);
                break;
            case 'progcrs':
                $pid = catalog::program_id($d['programcode']);
                $cid = catalog::course_id($d['coursecode']);
                $r = ($pid && $cid) ? $DB->get_record(
                    'local_studentrecords_progcrs',
                    ['programid' => $pid, 'courseid' => $cid, 'groupname' => $d['groupname']]
                ) : null;
                break;
            case 'minpass':
                $r = self::find_minpass($d);
                break;
            case 'policy':
                $levelid = $d['levelname'] !== '' ? catalog::level_id($d['levelname']) : null;
                $r = $DB->get_record_select('local_studentrecords_policy', 'rulekey = ? AND ' .
                    ($levelid ? 'levelid = ?' : 'levelid IS NULL'), array_filter([$d['rulekey'], $levelid]));
                break;
            case 'faculty':
                $r = $DB->get_record('local_studentrecords_faculty', ['firstname' => $d['firstname'],
                    'lastname' => $d['lastname'], 'stafftype' => $d['stafftype']], '*', IGNORE_MULTIPLE);
                break;
            case 'reqdoc':
                $rec = self::to_record('reqdoc', $d);
                $where = ['name = ?'];
                $params = [$rec['name']];
                foreach (['levelid', 'credentialtype', 'programid'] as $f) {
                    $where[] = $rec[$f] ? "$f = ?" : "$f IS NULL";
                    if ($rec[$f]) {
                        $params[] = $rec[$f];
                    }
                }
                $r = $DB->get_record_select('local_studentrecords_reqdoc', implode(' AND ', $where), $params, '*', IGNORE_MULTIPLE);
                break;
            case 'facqual':
                $fid = self::faculty_id($d);
                $cid = catalog::course_id($d['coursecode']);
                $r = ($fid && $cid) ? $DB->get_record('local_studentrecords_facqual', ['facultyid' => $fid, 'courseid' => $cid]) :
                    null;
                break;
        }
        return $r ?: null;
    }

    /**
     * Faculty record named by a qualification item (first and last name), if saved.
     *
     * @param array $d
     * @return int|null
     */
    protected static function faculty_id(array $d): ?int {
        global $DB;
        $id = $DB->get_field('local_studentrecords_faculty', 'id', ['firstname' => $d['firstname'],
            'lastname' => $d['lastname']], IGNORE_MULTIPLE);
        return $id ? (int)$id : null;
    }

    /**
     * Whether an item equals the saved record.
     *
     * @param string $type
     * @param array $d
     * @param \stdClass $r
     * @return bool
     */
    protected static function matches(string $type, array $d, \stdClass $r): bool {
        $record = self::to_record($type, $d, $r);
        // Term dates are whole days: the same day counts as unchanged, whatever time of day it was saved at (for
        // example noon in an earlier school time zone).
        $dates = $type === 'term' ? self::TERMDATES : [];
        foreach ($record as $field => $value) {
            if (!property_exists($r, $field)) {
                continue;
            }
            if (in_array($field, $dates, true)) {
                $saved = catalog::time_to_date($r->$field ? (int)$r->$field : null);
                if ($saved !== catalog::time_to_date($value ? (int)$value : null)) {
                    return false;
                }
            } else if (!records::same($r->$field, $value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Record fields for an item (references resolved to ids).
     *
     * @param string $type
     * @param array $d
     * @param \stdClass|null $existing the saved record the item would update
     * @return array
     */
    protected static function to_record(string $type, array $d, ?\stdClass $existing = null): array {
        switch ($type) {
            case 'school':
                return ['value' => $d['value']];
            case 'level':
                return ['name' => $d['name'], 'description' => $d['description']];
            case 'grade':
                $fields = ['letter', 'minpct', 'maxpct', 'points', 'ingpa', 'earns', 'description'];
                return array_intersect_key($d, array_flip($fields));
            case 'term':
                $out = ['name' => $d['name'], 'notes' => $d['notes'], 'active' => 1];
                foreach (self::TERMDATES as $f) {
                    $out[$f] = catalog::date_to_time($d[$f] ?? null);
                }
                $out['startdate'] = (int)$out['startdate'];
                $out['enddate'] = (int)$out['enddate'];
                $out['withdrawcalc'] = empty($d['withdrawcalc']) ? 0 : 1;
                if ($out['withdrawcalc']) {
                    // Calculated with the school's rule as saved (a rule in the same file is applied first).
                    $out['withdrawby'] = catalog::withdraw_time($out['startdate'], $out['enddate']);
                    if ($existing && !empty($existing->withdrawby) && empty($existing->withdrawcalc)) {
                        // Keep a date staff picked.
                        unset($out['withdrawby'], $out['withdrawcalc']);
                    }
                }
                return $out;
            case 'course':
                return ['code' => $d['code'], 'title' => $d['title'], 'hours' => $d['hours'], 'unittype' => $d['unittype'],
                    'levelid' => $d['levelname'] !== '' ? catalog::level_id($d['levelname']) : null,
                    'prereqtext' => $d['prereqtext']];
            case 'program':
                return ['code' => $d['code'], 'name' => $d['name'], 'school' => $d['school'],
                    'levelid' => $d['levelname'] !== '' ? catalog::level_id($d['levelname']) : null,
                    'credential' => $d['credential'], 'credentialtype' => $d['credentialtype'], 'isdegree' => $d['isdegree'],
                    'cipcode' => $d['cipcode'], 'delivery' => $d['delivery'], 'duration' => $d['duration'],
                    'maxtime' => $d['maxtime'], 'unittype' => $d['unittype'], 'totalhours' => $d['totalhours'],
                    'trackhours' => $d['trackhours'], 'mingpa' => $d['mingpa'], 'graderules' => $d['graderules']] +
                    // Mode and location only when the file states them, so a re-import does not clear staff entries.
                    array_filter(['deliverymode' => $d['deliverymode'] ?? '', 'location' => $d['location'] ?? ''], 'strlen');
            case 'progcrs':
                return ['programid' => catalog::program_id($d['programcode']), 'courseid' => catalog::course_id($d['coursecode']),
                    'groupname' => $d['groupname'], 'requirement' => $d['requirement'], 'sortorder' => (int)$d['sortorder']];
            case 'minpass':
                return ['levelid' => $d['levelname'] !== '' ? catalog::level_id($d['levelname']) : null,
                    'programid' => $d['programcode'] !== '' ? catalog::program_id($d['programcode']) : null,
                    'groupname' => $d['groupname'],
                    'courseid' => $d['coursecode'] !== '' ? catalog::course_id($d['coursecode']) : null,
                    'minletter' => $d['minletter'], 'minpct' => $d['minpct'], 'note' => $d['note']];
            case 'policy':
                return ['rulekey' => $d['rulekey'], 'title' => $d['title'],
                    'levelid' => $d['levelname'] !== '' ? catalog::level_id($d['levelname']) : null, 'value' => $d['value']];
            case 'faculty':
                return array_intersect_key($d, array_flip(['stafftype', 'honorific', 'firstname', 'lastname', 'jobtitle',
                    'department', 'email']));
            case 'reqdoc':
                return ['name' => $d['name'], 'description' => $d['description'], 'required' => (int)$d['required'],
                    'levelid' => $d['levelname'] !== '' ? catalog::level_id($d['levelname']) : null,
                    'credentialtype' => $d['credentialtype'] !== '' ? $d['credentialtype'] : null,
                    'programid' => $d['programcode'] !== '' ? catalog::program_id($d['programcode']) : null, 'active' => 1];
            case 'facqual':
                return ['facultyid' => self::faculty_id($d), 'courseid' => catalog::course_id($d['coursecode']), 'active' => 1,
                    'timeremoved' => null, 'removedby' => null];
        }
        return [];
    }

    /**
     * Saved pass rule with the same scope as an item.
     *
     * @param array $d
     * @return \stdClass|null
     */
    protected static function find_minpass(array $d): ?\stdClass {
        global $DB;
        $rec = self::to_record('minpass', $d);
        $where = [];
        $params = [];
        foreach (['levelid', 'programid', 'courseid'] as $f) {
            $where[] = $rec[$f] ? "$f = ?" : "$f IS NULL";
            if ($rec[$f]) {
                $params[] = $rec[$f];
            }
        }
        $where[] = $DB->sql_compare_text('groupname') . ' = ' . $DB->sql_compare_text('?');
        $params[] = (string)$rec['groupname'];
        $r = $DB->get_record_select('local_studentrecords_minpass', implode(' AND ', $where), $params, '*', IGNORE_MULTIPLE);
        return $r ?: null;
    }

    /**
     * Link a course to its prerequisite courses that are saved (only adds links).
     *
     * @param array $d course item
     */
    public static function link_prereqs(array $d): void {
        global $DB;
        $id = catalog::course_id($d['code']);
        if (!$id) {
            return;
        }
        foreach ($d['prereqcodes'] ?? [] as $code) {
            $reqid = catalog::course_id($code);
            if ($reqid && !$DB->record_exists('local_studentrecords_prereq', ['courseid' => $id, 'reqcourseid' => $reqid])) {
                records::save('local_studentrecords_prereq', ['courseid' => $id, 'reqcourseid' => $reqid], 'import');
            }
        }
    }

    /**
     * Apply one approved item.
     *
     * @param string $type
     * @param array $d
     * @throws \moodle_exception when a referenced program, course or level is not saved yet
     */
    public static function apply(string $type, array $d): void {
        global $DB;
        $existing = self::existing($type, $d);
        $record = self::to_record($type, $d, $existing);
        if ($existing && isset($existing->id)) {
            $record['id'] = $existing->id;
        }
        switch ($type) {
            case 'school':
                records::set_setting($d['field'], (string)$d['value'], 'import');
                return;
            case 'progcrs':
                if (!$record['programid'] || !$record['courseid']) {
                    throw new \moodle_exception(
                        'err_needparent',
                        'local_studentrecords',
                        '',
                        null,
                        $d['programcode'] . ' / ' . $d['coursecode']
                    );
                }
                break;
            case 'minpass':
                if (
                    ($d['levelname'] !== '' && !$record['levelid']) || ($d['programcode'] !== '' && !$record['programid']) ||
                        ($d['coursecode'] !== '' && !$record['courseid'])
                ) {
                    throw new \moodle_exception('err_needparent', 'local_studentrecords');
                }
                break;
            case 'facqual':
                if (!$record['facultyid'] || !$record['courseid']) {
                    throw new \moodle_exception(
                        'err_needparent',
                        'local_studentrecords',
                        '',
                        null,
                        $d['facultyname'] . ' / ' . $d['coursecode']
                    );
                }
                break;
            case 'level':
                if (empty($record['id'])) {
                    $record['sortorder'] = (int)$DB->count_records('local_studentrecords_level') + 1;
                }
                break;
            case 'reqdoc':
                if ($d['levelname'] !== '' && !$record['levelid']) {
                    throw new \moodle_exception('err_needparent', 'local_studentrecords', '', null, $d['levelname']);
                }
                if (empty($record['id'])) {
                    $record['sortorder'] = (int)$DB->count_records('local_studentrecords_reqdoc') + 1;
                }
                break;
            case 'grade':
                if (empty($record['id'])) {
                    $record['sortorder'] = (int)$DB->count_records('local_studentrecords_grade') + 1;
                }
                break;
            case 'policy':
                if (empty($record['id'])) {
                    $record['sortorder'] = (int)$DB->count_records('local_studentrecords_policy') + 1;
                }
                break;
        }
        $table = 'local_studentrecords_' . $type;
        $id = records::save($table, $record, 'import');
        if ($type === 'faculty') {
            foreach ($d['credentials'] ?? [] as $title) {
                if (
                    !$DB->record_exists_select('local_studentrecords_faccred', 'facultyid = ? AND ' .
                        $DB->sql_compare_text('title') . ' = ' . $DB->sql_compare_text('?'), [$id, $title])
                ) {
                    records::save('local_studentrecords_faccred', ['facultyid' => $id, 'credtype' => 'degree',
                        'title' => \core_text::substr($title, 0, 255)], 'import');
                }
            }
        }
    }
}
