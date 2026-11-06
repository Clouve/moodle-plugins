<?php

namespace local_studentrecords\local;

/**
 * Turns a school's spreadsheet (or CSV files) into draft items for review. No database access.
 *
 * Sheets are recognised by their column headers, not by their names, and columns are matched by common
 * header wordings, so a school can use its own layout. Anything unclear is kept as a draft with a problem
 * for the admin, never guessed silently. Sheets of fees and admission rules are listed, not read.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class importer {
    /** @var string[] Item types in the order they are applied (pass rules name levels, programs and courses). */
    const TYPES = ['school', 'level', 'grade', 'term', 'course', 'program', 'progcrs', 'minpass', 'policy', 'faculty',
        'facqual', 'reqdoc'];

    /** @var array Column header wordings per field. */
    const COLUMNS = [
        'term' => [
            'name' => ['term', 'term name', 'name', 'session'],
            'startdate' => ['start', 'start date', 'begins', 'first day', 'classes begin'],
            'enddate' => ['end', 'end date', 'ends', 'last day', 'classes end'],
            'adddropstart' => ['add drop start', 'drop add start', 'add drop begins'],
            'adddropend' => ['add drop end', 'drop add end', 'add drop deadline', 'last day to add', 'add drop ends'],
            'withdrawby' => ['withdraw', 'withdraw by', 'withdraw deadline', 'last day to withdraw', 'withdrawal deadline'],
            'exams' => ['final exams', 'exams', 'exam week', 'finals'],
            'notes' => ['holidays breaks in term', 'holidays', 'breaks', 'notes'],
        ],
        'grade' => [
            'letter' => ['letter', 'grade', 'letter grade'],
            'minpct' => ['min', 'min %', 'minimum', 'minimum %', 'from', 'from %', 'lowest'],
            'maxpct' => ['max', 'max %', 'maximum', 'maximum %', 'to', 'to %', 'highest'],
            'points' => ['quality points', 'grade points', 'points', 'gpa points'],
            'ingpa' => ['counts in gpa', 'in gpa', 'gpa'],
            'earns' => ['earns hours', 'earns credit', 'earns credits', 'earns'],
            'description' => ['description', 'meaning'],
        ],
        'course' => [
            'code' => ['course code', 'code', 'course number', 'course'],
            'title' => ['title', 'course title', 'name', 'course name'],
            'hours' => ['hours', 'credits', 'credit hours', 'clock hours', 'units'],
            'unittype' => ['hour type', 'unit type', 'units type', 'credit type'],
            'level' => ['level', 'academic level'],
            'prereq' => ['prerequisites', 'prerequisite', 'prereqs', 'pre requisites'],
        ],
        'program' => [
            'code' => ['program code', 'code', 'program id'],
            'name' => ['program name', 'name', 'program', 'title'],
            'school' => ['school', 'department', 'division', 'college'],
            'level' => ['level', 'credential level', 'degree level'],
            'credential' => ['credential', 'award', 'degree'],
            'totalhours' => ['total hours', 'total credits', 'total credit hours', 'total clock hours', 'hours', 'credits'],
            'unittype' => ['hour type', 'unit type', 'units'],
            'trackhours' => ['track credits choose one', 'track credits', 'track hours', 'concentration credits'],
            'mingpa' => ['min gpa', 'minimum gpa', 'gpa required', 'graduation gpa'],
            'graderules' => ['grade rules', 'grading rules', 'minimum grades'],
            'maxtime' => ['max time', 'maximum time', 'maximum time frame'],
            'delivery' => ['delivery', 'delivery mode', 'mode', 'modality'],
            'location' => ['location', 'campus', 'site', 'instructional site'],
            'cipcode' => ['cip', 'cip code'],
            'duration' => ['length', 'duration', 'program length'],
        ],
        'progcrs' => [
            'programcode' => ['program code', 'program'],
            'groupname' => ['group track', 'group', 'track', 'requirement group', 'category'],
            'coursecode' => ['course code', 'course', 'course number'],
            'title' => ['course title', 'title'],
            'hours' => ['hours', 'credits'],
            'requirement' => ['requirement', 'required', 'type'],
        ],
        'faculty' => [
            'name' => ['name', 'full name', 'staff name', 'faculty name'],
            'jobtitle' => ['role title', 'role', 'title', 'position', 'job title'],
            'stafftype' => ['type', 'staff type', 'category'],
            'credential' => ['highest credential first listed', 'highest credential', 'highest degree', 'degree', 'credentials'],
            'department' => ['department', 'school'],
            'email' => ['email', 'e mail'],
            'qualified' => ['qualified courses', 'courses qualified to teach', 'qualified to teach', 'courses taught',
                'teaching qualifications'],
        ],
        'reqdoc' => [
            'level' => ['level', 'program level', 'credential level', 'program'],
            'priordegree' => ['prior degree', 'previous degree', 'prior education', 'admission requirement'],
            'toefl' => ['toefl ibt', 'toefl'],
            'ielts' => ['ielts'],
            'duolingo' => ['duolingo'],
            'other' => ['other', 'documents', 'required documents', 'other requirements'],
        ],
        'facqual' => [
            'name' => ['faculty name', 'faculty', 'instructor', 'instructor name', 'name', 'full name'],
            'coursecode' => ['course code', 'course', 'course number', 'qualified course', 'qualified to teach'],
        ],
    ];

    /** @var array School setting => field label wordings (sheets with Field / Value columns). */
    const SCHOOLFIELDS = [
        'schoolname' => ['institution name', 'school name', 'name of school', 'college name', 'university name'],
        'legalname' => ['legal entity', 'legal name'],
        'othernames' => ['other names', 'also known as', 'dba', 'doing business as'],
        'address' => ['principal place of business', 'address', 'main address', 'legal address', 'mailing address'],
        'campusaddress' => ['instructional facility', 'campus', 'campus address', 'instructional site', 'site address'],
        'state' => ['state'],
        'website' => ['website', 'web site', 'url'],
        'phone' => ['phone', 'telephone', 'main phone'],
        'email' => ['email', 'e mail'],
        'registrarname' => ['registrar', 'registrar name'],
        'stateagency' => ['state regulator', 'state agency', 'regulator', 'licensed by', 'state approval'],
        'statelicense' => ['license number', 'license no', 'state license number'],
        'accreditor' => ['accreditation', 'accreditor', 'accrediting agency', 'accredited by'],
        'fedaid' => ['title iv federal aid', 'federal aid', 'title iv', 'federal financial aid'],
        'f1' => ['f 1', 'f 1 students', 'i 20', 'sevp', 'international students'],
        'calendartype' => ['calendar type', 'calendar', 'term type', 'academic calendar'],
        'creditunit' => ['credit unit', 'unit of credit'],
        'officehours' => ['office hours', 'hours of operation'],
        'timezone' => ['time zone', 'timezone'],
        'opeid' => ['opeid', 'ope id'],
    ];

    /** @var string[] Calendar types offered by the setup wizard. */
    const CALENDARS = ['semester', 'quarter', 'trimester', 'term', 'continuous', 'rolling'];

    /** @var string[] Credential types offered by the setup wizard. */
    const CREDENTIALS = ['certificate', 'diploma', 'associate', 'bachelor', 'master', 'doctorate', 'other'];

    /** @var array Drafts being built: type => key => item. */
    protected $items = [];

    /** @var array Import-wide notes: list of [severity, key, a]. */
    protected $gaps = [];

    /**
     * Read sheets into draft items.
     *
     * @param array $sheets sheet name => rows (first row = headers)
     * @return array ['items' => list of [type, key, data, source, problems], 'sheets' => name => [type, rows], 'gaps' => list]
     */
    public static function build(array $sheets): array {
        $self = new self();
        return $self->run($sheets);
    }

    /**
     * What kind of data a sheet holds, from its headers.
     *
     * @param array $headers raw header texts
     * @return string one of the item types, 'school', 'later' (fees, admission rules: listed, not read) or '' (not used)
     */
    public static function recognise(array $headers): string {
        $h = array_map([self::class, 'norm'], $headers);
        $has = fn(array $words) => (bool)array_intersect($words, $h);
        $first = $h[0] ?? '';
        $second = $h[1] ?? '';
        if (in_array($first, ['field', 'item', 'setting', 'question']) && in_array($second, ['value', 'answer'])) {
            return 'school';
        }
        if (in_array($first, ['rule', 'policy', 'academic rule'])) {
            return 'policy';
        }
        if ($has(['letter', 'letter grade']) && $has(['quality points', 'grade points', 'points', 'gpa points'])) {
            return 'grade';
        }
        if ($has(['program code', 'program']) && $has(['course code', 'course number'])) {
            return 'progcrs';
        }
        if ($has(['program code']) || ($has(['program name']) && $has(['credential', 'award']))) {
            return 'program';
        }
        if (
            $has(['faculty name', 'faculty', 'instructor', 'instructor name']) &&
                $has(['course code', 'course', 'course number', 'qualified course', 'qualified to teach'])
        ) {
            return 'facqual';
        }
        if ($has(['course code', 'course number']) && $has(['title', 'course title', 'course name'])) {
            return 'course';
        }
        $dated = $has(['start', 'start date', 'begins']) && $has(['end', 'end date', 'ends']);
        if ($dated && $has(['term', 'term name', 'session'])) {
            return 'term';
        }
        if (
            $has(['name', 'full name', 'staff name', 'faculty name']) &&
                $has(['role title', 'role', 'position', 'job title', 'type', 'staff type'])
        ) {
            return 'faculty';
        }
        if (
            $has(['level', 'program level', 'credential level']) &&
                $has(['prior degree', 'previous degree', 'toefl ibt', 'toefl', 'ielts', 'required documents'])
        ) {
            return 'reqdoc';
        }
        foreach ($h as $word) {
            if (preg_match('/\b(amount|fee|fees|tuition|refund|toefl|ielts|prior degree|duolingo)\b/', $word)) {
                return 'later';
            }
        }
        return '';
    }

    /**
     * Header text in one form: lower case words without punctuation.
     *
     * @param string $text
     * @return string
     */
    public static function norm(string $text): string {
        $text = preg_replace('/[^a-z0-9%]+/', ' ', strtolower($text));
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Run the import.
     *
     * @param array $sheets
     * @return array
     */
    protected function run(array $sheets): array {
        $kinds = [];
        $bytype = [];
        foreach ($sheets as $name => $rows) {
            $rows = array_values($rows);
            $kind = $rows ? self::recognise($rows[0]) : '';
            $kinds[$name] = ['type' => $kind, 'rows' => max(0, count($rows) - 1)];
            if ($kind !== '' && $kind !== 'later') {
                $bytype[$kind][] = [$name, $rows];
            }
        }
        // Order matters: courses before outlines, outlines before programs (levels and totals), levels last.
        foreach (['school', 'term', 'grade', 'course', 'progcrs', 'program', 'policy', 'faculty', 'facqual', 'reqdoc'] as $type) {
            foreach ($bytype[$type] ?? [] as [$name, $rows]) {
                $this->{'read_' . $type}($name, $rows);
            }
        }
        $this->finish();
        $out = [];
        foreach (self::TYPES as $type) {
            foreach ($this->items[$type] ?? [] as $key => $item) {
                $out[] = [$type, (string)$key, $item['data'], $item['source'], $item['problems']];
            }
        }
        return ['items' => $out, 'sheets' => $kinds, 'gaps' => $this->gaps];
    }

    /**
     * Add or replace a draft item.
     *
     * @param string $type
     * @param string $key
     * @param array $data
     * @param string $source
     * @param array $problems list of [severity, key, a]
     */
    protected function put(string $type, string $key, array $data, string $source, array $problems = []): void {
        $this->items[$type][$key] = ['data' => $data, 'source' => $source, 'problems' => $problems];
    }

    /**
     * Note a problem on an existing item.
     *
     * @param string $type
     * @param string $key
     * @param string $severity error (must be fixed before approving) or warning
     * @param string $problem language string key
     * @param mixed $a
     */
    protected function flag(string $type, string $key, string $severity, string $problem, $a = null): void {
        if (isset($this->items[$type][$key])) {
            $this->items[$type][$key]['problems'][] = [$severity, $problem, $a];
        }
    }

    /**
     * Column positions for the fields of a type.
     *
     * @param string $type
     * @param array $headers
     * @return array field => column index
     */
    protected static function columns(string $type, array $headers): array {
        $h = array_map([self::class, 'norm'], $headers);
        $found = [];
        $used = [];
        foreach (self::COLUMNS[$type] as $field => $words) {
            foreach ($words as $word) {
                $i = array_search(self::norm($word), $h, true);
                if ($i !== false && !isset($used[$i])) {
                    $found[$field] = $i;
                    $used[$i] = true;
                    break;
                }
            }
        }
        return $found;
    }

    /**
     * Value of a field in a row.
     *
     * @param array $row
     * @param array $cols
     * @param string $field
     * @return string
     */
    protected static function cell(array $row, array $cols, string $field): string {
        return isset($cols[$field]) ? trim((string)($row[$cols[$field]] ?? '')) : '';
    }

    /**
     * A number from text such as "1,920" or "3"; null if the text is not a plain number.
     *
     * @param string $text
     * @return float|null
     */
    public static function number(string $text): ?float {
        $t = str_replace([',', ' '], '', trim($text));
        return is_numeric($t) ? (float)$t : null;
    }

    /**
     * A date as Y-m-d from "2027-01-11", "1/11/2027" or an Excel date number; null if not a date.
     *
     * @param string $text
     * @return string|null
     */
    public static function date(string $text): ?string {
        $text = trim($text);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $text, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $text, $m) && checkdate((int)$m[1], (int)$m[2], (int)$m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);
        }
        if (preg_match('/^\d{5}(\.\d+)?$/', $text) && (float)$text > 20000 && (float)$text < 80000) {
            $d = new \DateTime('1899-12-30', new \DateTimeZone('UTC'));
            $d->modify('+' . (int)$text . ' days');
            return $d->format('Y-m-d');
        }
        return null;
    }

    /**
     * Credit or clock hours from text.
     *
     * @param string $text
     * @return string|null 'credit', 'clock' or null if not stated
     */
    public static function unittype(string $text): ?string {
        $t = strtolower($text);
        if (str_contains($t, 'clock')) {
            return 'clock';
        }
        if (str_contains($t, 'credit') || str_contains($t, 'semester') || str_contains($t, 'quarter') || str_contains($t, 'unit')) {
            return 'credit';
        }
        return null;
    }

    /**
     * Level name and description from a header such as "Graduate (Master/Certificate)".
     *
     * @param string $header
     * @return array [name, description]
     */
    public static function level_from_header(string $header): array {
        $desc = preg_match('/\(([^)]*)\)/', $header, $m) ? trim($m[1]) : '';
        $name = trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $header));
        return [$name, $desc];
    }

    /**
     * Note a level, keeping the first description seen.
     *
     * @param string $name
     * @param string $description
     * @param string $source
     */
    protected function add_level(string $name, string $description, string $source): void {
        if ($name === '') {
            return;
        }
        $key = self::level_key($name);
        if (!isset($this->items['level'][$key])) {
            $this->put('level', $key, ['name' => $name, 'description' => $description], $source);
        } else if ($description !== '' && $this->items['level'][$key]['data']['description'] === '') {
            $this->items['level'][$key]['data']['description'] = $description;
        }
    }

    /**
     * Key used to match level names.
     *
     * @param string $name
     * @return string
     */
    protected static function level_key(string $name): string {
        return self::norm($name);
    }

    /**
     * School details from a Field / Value sheet.
     *
     * @param string $sheet
     * @param array $rows
     */
    protected function read_school(string $sheet, array $rows): void {
        $wanted = [];
        foreach (self::SCHOOLFIELDS as $field => $words) {
            foreach ($words as $w) {
                $wanted[self::norm($w)] = $field;
            }
        }
        $unused = [];
        foreach (array_slice($rows, 1) as $i => $row) {
            $label = trim((string)($row[0] ?? ''));
            $value = trim((string)($row[1] ?? ''));
            if ($label === '' || $value === '') {
                continue;
            }
            $field = $wanted[self::norm($label)] ?? null;
            $source = $sheet . ' ' . ($i + 2);
            if (!$field) {
                $unused[] = $label;
                continue;
            }
            $problems = [];
            $data = ['field' => $field, 'value' => $value];
            if ($field === 'accreditor' && preg_match('/^(not|none|no\b|n\/a)/i', $value)) {
                $data['value'] = '';
                $problems[] = ['warning', 'problem_notaccredited', $value];
            } else if ($field === 'fedaid' || $field === 'f1') {
                $no = preg_match('/\b(not|no|none|does not|ineligible)\b/i', $value);
                $data['value'] = $no ? '0' : (preg_match('/\b(yes|eligible|participates|approved)\b/i', $value) ? '1' : '');
                if ($data['value'] === '') {
                    $problems[] = ['error', 'problem_yesno', $value];
                }
            } else if ($field === 'calendartype') {
                $data['value'] = '';
                // Named types first; plain "term" only when no other type is named ("Semester terms" is semester).
                foreach (array_merge(array_diff(self::CALENDARS, ['term']), ['term']) as $c) {
                    if (preg_match('/\b' . $c . 's?\b/i', $value)) {
                        $data['value'] = $c;
                        break;
                    }
                }
                if ($data['value'] === '') {
                    $problems[] = ['error', 'problem_calendar', $value];
                }
            }
            $this->put('school', $field, $data, $source, $problems);
            if (
                $field === 'address' && !isset($this->items['school']['state']) &&
                    preg_match('/,\s*([A-Z]{2})\s+\d{5}/', $value, $m)
            ) {
                $this->put(
                    'school',
                    'state',
                    ['field' => 'state', 'value' => $m[1]],
                    $source,
                    [['warning', 'problem_statefromaddress', $m[1]]]
                );
            }
        }
        if ($unused) {
            $this->gaps[] = ['info', 'gap_schoolunused', implode(', ', $unused)];
        }
    }

    /**
     * Terms.
     *
     * @param string $sheet
     * @param array $rows
     */
    protected function read_term(string $sheet, array $rows): void {
        $cols = self::columns('term', $rows[0]);
        foreach (array_slice($rows, 1) as $i => $row) {
            $name = self::cell($row, $cols, 'name');
            if ($name === '') {
                continue;
            }
            $problems = [];
            $data = ['name' => $name];
            foreach (['startdate', 'enddate', 'adddropstart', 'adddropend', 'withdrawby'] as $f) {
                $raw = self::cell($row, $cols, $f);
                $data[$f] = $raw === '' ? null : self::date($raw);
                if ($raw !== '' && $data[$f] === null) {
                    $problems[] = ['error', 'problem_baddate', $raw];
                }
            }
            $exams = self::cell($row, $cols, 'exams');
            $data['examstart'] = $data['examend'] = null;
            if ($exams !== '') {
                $parts = preg_split('/\s+(?:to|-|–|through)\s+/i', $exams);
                $data['examstart'] = self::date($parts[0]);
                $data['examend'] = self::date($parts[1] ?? $parts[0]);
                if (!$data['examstart']) {
                    $problems[] = ['warning', 'problem_baddate', $exams];
                }
            }
            $data['notes'] = self::cell($row, $cols, 'notes');
            if (!$data['startdate'] || !$data['enddate']) {
                $problems[] = ['error', 'problem_termdates', $name];
            } else if ($data['enddate'] < $data['startdate']) {
                $problems[] = ['error', 'problem_termorder', $name];
            }
            if (!$data['adddropstart'] && !$data['adddropend']) {
                $problems[] = ['warning', 'problem_noadddrop', $name];
            } else if (
                $data['adddropend'] && $data['startdate'] && ($data['adddropend'] < $data['startdate'] ||
                    ($data['enddate'] && $data['adddropend'] > $data['enddate']))
            ) {
                $problems[] = ['error', 'problem_adddropoutside', $name];
            }
            $this->put('term', self::norm($name), $data, $sheet . ' ' . ($i + 2), $problems);
        }
    }

    /**
     * Grading scale, and pass rules per level from Pass/Fail columns.
     *
     * @param string $sheet
     * @param array $rows
     */
    protected function read_grade(string $sheet, array $rows): void {
        $cols = self::columns('grade', $rows[0]);
        $known = array_flip($cols);
        $passcols = [];
        foreach ($rows[0] as $i => $header) {
            if (isset($known[$i]) || trim((string)$header) === '') {
                continue;
            }
            $values = array_filter(array_map(fn($r) => strtolower(trim((string)($r[$i] ?? ''))), array_slice($rows, 1)));
            $words = array_filter($values, fn($v) => str_starts_with($v, 'pass') || str_starts_with($v, 'fail'));
            if ($values && count($words) === count($values)) {
                $passcols[$i] = self::level_from_header((string)$header);
            }
        }
        $scale = [];
        $wordsbylevel = [];
        foreach (array_slice($rows, 1) as $i => $row) {
            $letter = self::cell($row, $cols, 'letter');
            if ($letter === '') {
                continue;
            }
            $data = ['letter' => $letter, 'minpct' => self::number(self::cell($row, $cols, 'minpct')),
                'maxpct' => self::number(self::cell($row, $cols, 'maxpct')),
                'points' => self::number(self::cell($row, $cols, 'points')),
                'ingpa' => preg_match('/^(no|n|false|0)$/i', self::cell($row, $cols, 'ingpa')) ? 0 : 1,
                'earns' => preg_match('/^(yes|y|true|1)$/i', self::cell($row, $cols, 'earns')) ? 1 : 0,
                'description' => self::cell($row, $cols, 'description')];
            $problems = [];
            if ($data['points'] === null && $data['ingpa']) {
                $problems[] = ['error', 'problem_nopoints', $letter];
            }
            if ($data['description'] === '' && $passcols) {
                $parts = [];
                foreach ($passcols as $col => [$level]) {
                    $parts[] = $level . ': ' . trim((string)($row[$col] ?? ''));
                }
                $data['description'] = implode('; ', $parts);
            }
            foreach ($passcols as $col => [$level]) {
                $wordsbylevel[$level][$letter] = (string)($row[$col] ?? '');
            }
            $scale[] = $data;
            $this->put('grade', $letter, $data, $sheet . ' ' . ($i + 2), $problems);
        }
        foreach (grading::check_scale($scale) as [$key, $a]) {
            $this->gaps[] = ['error', $key, $a];
        }
        $letters = grading::ordered_letters($scale);
        foreach ($passcols as [$level, $desc]) {
            $this->add_level($level, $desc, $sheet);
            [$lowest, $problem] = grading::lowest_pass_from_words($letters, $wordsbylevel[$level] ?? []);
            $key = 'level:' . self::level_key($level);
            if ($problem) {
                $this->gaps[] = ['error', $problem, $level];
                continue;
            }
            $this->put(
                'minpass',
                $key,
                ['levelname' => $level, 'programcode' => '', 'groupname' => '', 'coursecode' => '',
                'minletter' => $lowest, 'minpct' => null, 'note' => ''],
                $sheet,
                $lowest ? [] : [['error', 'problem_nopass', $level]]
            );
        }
        $marks = array_filter($scale, fn($r) => !$r['ingpa']);
        if (!$marks) {
            $this->gaps[] = ['warning', 'gap_nomarks', null];
        }
    }

    /**
     * Course catalog.
     *
     * @param string $sheet
     * @param array $rows
     */
    protected function read_course(string $sheet, array $rows): void {
        $cols = self::columns('course', $rows[0]);
        foreach (array_slice($rows, 1) as $i => $row) {
            $raw = self::cell($row, $cols, 'code');
            if ($raw === '') {
                continue;
            }
            $code = grading::normalise_code($raw);
            $source = $sheet . ' ' . ($i + 2);
            $prereq = self::cell($row, $cols, 'prereq');
            $data = ['code' => $code, 'title' => self::cell($row, $cols, 'title'),
                'hours' => self::number(self::cell($row, $cols, 'hours')),
                'unittype' => self::unittype(self::cell($row, $cols, 'unittype')) ?? 'credit',
                'levelname' => self::cell($row, $cols, 'level'), 'prereqtext' => $prereq, 'prereqcodes' => []];
            $problems = [];
            if ($data['title'] === '') {
                $problems[] = ['error', 'problem_notitle', $code];
            }
            if ($data['hours'] === null) {
                $problems[] = ['warning', 'problem_nohours', $code];
            }
            if (self::unittype(self::cell($row, $cols, 'unittype')) === null) {
                $problems[] = ['warning', 'problem_unitassumed', $code];
            }
            if ($prereq !== '') {
                preg_match_all('/\b([A-Z]{2,5}\s?\d{3,4}[A-Z]?)\b/', strtoupper($prereq), $m);
                $data['prereqcodes'] = array_values(array_unique(array_map([grading::class, 'normalise_code'], $m[1])));
                $rest = trim(preg_replace('/\b[A-Z]{2,5}\s?\d{3,4}[A-Z]?\b|[,;]|\band\b/i', ' ', $prereq));
                if ($rest !== '') {
                    $problems[] = ['warning', 'problem_prereqwords', $prereq];
                }
            }
            if ($data['levelname'] !== '') {
                $this->add_level($data['levelname'], '', $source);
            }
            if (isset($this->items['course'][$code])) {
                $old = $this->items['course'][$code]['data'];
                if (strcasecmp($old['title'], $data['title']) !== 0 || $old['hours'] !== $data['hours']) {
                    $this->flag('course', $code, 'error', 'problem_duplicatecode', $code);
                }
                continue;
            }
            $this->put('course', $code, $data, $source, $problems);
        }
    }

    /**
     * Program outlines (which courses each program uses).
     *
     * @param string $sheet
     * @param array $rows
     */
    protected function read_progcrs(string $sheet, array $rows): void {
        $cols = self::columns('progcrs', $rows[0]);
        $order = 0;
        foreach (array_slice($rows, 1) as $i => $row) {
            $program = trim(self::cell($row, $cols, 'programcode'));
            $raw = self::cell($row, $cols, 'coursecode');
            if ($program === '' || $raw === '') {
                continue;
            }
            $code = grading::normalise_code($raw);
            $group = self::cell($row, $cols, 'groupname');
            $req = strtolower(self::cell($row, $cols, 'requirement'));
            $requirement = str_contains($req, 'track') || str_contains($req, 'option') ? 'track' :
                (str_contains($req, 'elective') ? 'elective' : (($req === '' || str_contains($req, 'required') ||
                str_contains($req, 'core')) ? 'required' : ''));
            $source = $sheet . ' ' . ($i + 2);
            $problems = [];
            if ($requirement === '') {
                $requirement = 'required';
                $problems[] = ['warning', 'problem_requirement', self::cell($row, $cols, 'requirement')];
            }
            $hours = self::number(self::cell($row, $cols, 'hours'));
            if (!isset($this->items['course'][$code])) {
                // Not in the course catalog: offer it as a course too, so nothing is lost.
                $this->put(
                    'course',
                    $code,
                    ['code' => $code, 'title' => self::cell($row, $cols, 'title'), 'hours' => $hours,
                    'unittype' => 'credit', 'levelname' => '', 'prereqtext' => '', 'prereqcodes' => []],
                    $source,
                    [['warning', 'problem_onlyinoutline', $code]]
                );
            } else if (
                $hours !== null && $this->items['course'][$code]['data']['hours'] !== null &&
                    abs($hours - $this->items['course'][$code]['data']['hours']) > 0.001
            ) {
                $problems[] = ['error', 'problem_hoursdiffer', $code];
            }
            $key = $program . '|' . $code . '|' . $group;
            if (isset($this->items['progcrs'][$key])) {
                continue;
            }
            $this->put('progcrs', $key, ['programcode' => $program, 'coursecode' => $code, 'groupname' => $group,
                'requirement' => $requirement, 'hours' => $hours, 'sortorder' => ++$order], $source, $problems);
        }
    }

    /**
     * Programs.
     *
     * @param string $sheet
     * @param array $rows
     */
    protected function read_program(string $sheet, array $rows): void {
        $cols = self::columns('program', $rows[0]);
        $used = array_flip($cols);
        $ignored = [];
        foreach ($rows[0] as $i => $header) {
            if (!isset($used[$i]) && trim((string)$header) !== '') {
                $ignored[] = trim((string)$header);
            }
        }
        if ($ignored) {
            $this->gaps[] = ['info', 'gap_columnsnotused', $sheet . ': ' . implode(', ', $ignored)];
        }
        foreach (array_slice($rows, 1) as $i => $row) {
            $code = self::cell($row, $cols, 'code');
            $name = self::cell($row, $cols, 'name');
            if ($code === '' && $name === '') {
                continue;
            }
            $code = $code === '' ? strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $name)) : $code;
            $level = self::cell($row, $cols, 'level');
            $credential = self::cell($row, $cols, 'credential');
            $unitraw = self::cell($row, $cols, 'unittype');
            $problems = [];
            $data = ['code' => $code, 'name' => $name, 'school' => self::cell($row, $cols, 'school'),
                'credentiallevel' => $level, 'credential' => $credential,
                'credentialtype' => self::credential_type($level . ' ' . $credential),
                'isdegree' => preg_match('/non[- ]?degree|certificate|diploma/i', $level . ' ' . $credential) ? 0 : 1,
                'cipcode' => self::cell($row, $cols, 'cipcode'), 'delivery' => self::cell($row, $cols, 'delivery'),
                'deliverymode' => (string)self::delivery_mode(self::cell($row, $cols, 'delivery')),
                'location' => self::cell($row, $cols, 'location'),
                'duration' => self::cell($row, $cols, 'duration'), 'maxtime' => self::cell($row, $cols, 'maxtime'),
                'unittype' => self::unittype($unitraw) ?? 'credit',
                'totalhours' => self::number(self::cell($row, $cols, 'totalhours')),
                'trackhours' => self::number(self::cell($row, $cols, 'trackhours')),
                'mingpa' => self::number(self::cell($row, $cols, 'mingpa')),
                'graderules' => self::cell($row, $cols, 'graderules'), 'levelname' => ''];
            if (self::unittype($unitraw) === null) {
                $problems[] = ['warning', 'problem_unitassumed', $code];
            }
            if ($data['delivery'] !== '' && $data['deliverymode'] === '') {
                $problems[] = ['warning', 'problem_deliveryunknown', $data['delivery']];
            } else if ($data['deliverymode'] === 'hybrid' && $data['location'] === '') {
                $problems[] = ['warning', 'problem_nolocation', $code];
            }
            if ($data['isdegree'] && $data['unittype'] === 'clock') {
                $problems[] = ['warning', 'problem_degreeclock', $code];
            }
            if ($data['mingpa'] === null && self::cell($row, $cols, 'mingpa') !== '') {
                $problems[] = ['warning', 'problem_gpawords', self::cell($row, $cols, 'mingpa')];
            }
            if ($data['totalhours'] === null) {
                $problems[] = ['error', 'problem_nototal', $code];
            }
            $this->put('program', $code, $data, $sheet . ' ' . ($i + 2), $problems);
        }
    }

    /**
     * Academic rules, one column per level.
     *
     * @param string $sheet
     * @param array $rows
     */
    protected function read_policy(string $sheet, array $rows): void {
        $levels = [];
        foreach (array_slice($rows[0], 1, null, true) as $i => $header) {
            if (trim((string)$header) !== '') {
                $levels[$i] = self::level_from_header((string)$header);
                $this->add_level($levels[$i][0], $levels[$i][1], $sheet);
            }
        }
        foreach (array_slice($rows, 1) as $r => $row) {
            $title = trim((string)($row[0] ?? ''));
            if ($title === '') {
                continue;
            }
            $rulekey = str_replace(' ', '_', self::norm($title));
            $previous = null;
            foreach ($levels as $i => [$level]) {
                $value = trim((string)($row[$i] ?? ''));
                if (strcasecmp($value, 'same') === 0 && $previous !== null) {
                    $value = $previous;
                }
                if ($value === '') {
                    continue;
                }
                $previous = $value;
                $this->put('policy', $rulekey . '|' . self::level_key($level), ['rulekey' => $rulekey, 'title' => $title,
                    'levelname' => $level, 'value' => $value], $sheet . ' ' . ($r + 2));
                if (preg_match('/minimum passing|lowest passing|passing grade/i', $title)) {
                    $this->level_rule_from_policy($level, $value, $sheet . ' ' . ($r + 2));
                }
            }
        }
    }

    /**
     * A level's lowest passing grade stated in the academic rules, compared with the grading scale.
     *
     * @param string $level
     * @param string $value
     * @param string $source
     */
    protected function level_rule_from_policy(string $level, string $value, string $source): void {
        $key = 'level:' . self::level_key($level);
        $rule = grading::level_minimum_from_text($value);
        if (isset($this->items['minpass'][$key])) {
            $have = $this->items['minpass'][$key]['data'];
            if ($rule && ($rule['minletter'] !== $have['minletter'] || $rule['minpct'] !== $have['minpct'])) {
                $this->flag('minpass', $key, 'warning', 'problem_rulesdisagree', $value);
            }
            return;
        }
        if ($rule) {
            $this->put(
                'minpass',
                $key,
                ['levelname' => $level, 'programcode' => '', 'groupname' => '', 'coursecode' => '',
                'minletter' => $rule['minletter'], 'minpct' => $rule['minpct'], 'note' => $value],
                $source,
                [['warning', 'problem_rulefromwords', $value]]
            );
        }
    }

    /**
     * Staff and faculty (records only; no Moodle accounts are created).
     *
     * @param string $sheet
     * @param array $rows
     */
    protected function read_faculty(string $sheet, array $rows): void {
        $cols = self::columns('faculty', $rows[0]);
        foreach (array_slice($rows, 1) as $i => $row) {
            $full = self::cell($row, $cols, 'name');
            if ($full === '') {
                continue;
            }
            $problems = [];
            $name = $full;
            if (preg_match('/\(([^)]*)\)/', $name, $m)) {
                $problems[] = ['warning', 'problem_namebracket', $m[0]];
                $name = trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $name));
            }
            $honorific = '';
            if (preg_match('/^(dr|prof|professor|mr|mrs|ms|mx)\.?\s+/i', $name, $m)) {
                $honorific = rtrim($m[0]);
                $name = substr($name, strlen($m[0]));
            }
            $parts = preg_split('/\s+/', trim($name));
            $last = array_pop($parts);
            $first = implode(' ', $parts);
            if ($first === '') {
                $problems[] = ['error', 'problem_onename', $full];
            }
            $type = strtolower(self::cell($row, $cols, 'stafftype'));
            $data = ['stafftype' => str_contains($type, 'staff') && !str_contains($type, 'faculty') ? 'staff' : 'faculty',
                'honorific' => $honorific, 'firstname' => $first, 'lastname' => (string)$last,
                'jobtitle' => self::cell($row, $cols, 'jobtitle'), 'department' => self::cell($row, $cols, 'department'),
                'email' => self::cell($row, $cols, 'email'),
                'credentials' => array_values(array_filter([self::cell($row, $cols, 'credential')]))];
            $key = self::norm($last . ' ' . $first);
            if (isset($this->items['faculty'][$key])) {
                $problems[] = ['warning', 'problem_samename', $full];
                $key .= ' ' . ($i + 2);
            }
            $this->put('faculty', $key, $data, $sheet . ' ' . ($i + 2), $problems);
            foreach (self::course_codes(self::cell($row, $cols, 'qualified')) as $code) {
                $this->add_qualification($full, (string)$first, (string)$last, $code, $sheet . ' ' . ($i + 2));
            }
        }
    }

    /**
     * Admission requirements per level or credential (for example the catalog's admissions table) as application
     * checklist documents. Fees and rules that are not documents are listed as notes, not as documents.
     *
     * @param string $sheet
     * @param array $rows
     */
    protected function read_reqdoc(string $sheet, array $rows): void {
        $cols = self::columns('reqdoc', $rows[0]);
        $used = array_flip($cols);
        $ignored = [];
        foreach ($rows[0] as $i => $header) {
            if (!isset($used[$i]) && trim((string)$header) !== '') {
                $ignored[] = trim((string)$header);
            }
        }
        if ($ignored) {
            $this->gaps[] = ['info', 'gap_columnsnotused', $sheet . ': ' . implode(', ', $ignored)];
        }
        foreach (array_slice($rows, 1) as $i => $row) {
            $leveltext = self::cell($row, $cols, 'level');
            if ($leveltext === '') {
                continue;
            }
            $source = $sheet . ' ' . ($i + 2);
            [$levelname, $credtype] = $this->scope_from_words($leveltext);
            $docs = [];
            $prior = self::cell($row, $cols, 'priordegree');
            if ($prior !== '' && !preg_match('/^(none|none stated|n\/?a|-)$/i', $prior)) {
                $foreign = (bool)preg_match('/foreign/i', $prior);
                $docs[] = [$foreign ? $prior : get_string('import_prioreducation', 'local_studentrecords', $prior),
                    $foreign ? 0 : 1, ''];
            }
            $tests = [];
            foreach (['toefl' => 'TOEFL iBT', 'ielts' => 'IELTS', 'duolingo' => 'Duolingo'] as $f => $label) {
                $v = self::cell($row, $cols, $f);
                if (self::number($v) !== null) {
                    $tests[] = $label . ' ' . $v;
                }
            }
            if ($tests) {
                $docs[] = [get_string('import_englishtest', 'local_studentrecords'), 0,
                    get_string('import_englishtestnote', 'local_studentrecords', implode(', ', $tests))];
            }
            foreach (preg_split('/;|\.\s+/', self::cell($row, $cols, 'other')) as $piece) {
                $piece = trim($piece);
                if ($piece === '') {
                    continue;
                }
                $name = trim(preg_replace('/\s*\([^)]*\$[^)]*\)/', '', $piece));
                if (preg_match('/\bfees?\b/i', $name) || $name !== $piece) {
                    $this->gaps[] = ['info', 'gap_admissionfee', $leveltext . ': ' . $piece];
                }
                if (preg_match('/\bfees?\b/i', $name)) {
                    continue;
                }
                $doclike = '/statement|letter|transcript|resume|\bcv\b|photo|\bid\b|passport|diploma|certificate|score|' .
                    '\bgmat\b|\bgre\b|essay|portfolio|reference|recommendation|\bform\b|evaluation|assessment|' .
                    'interview|proof/i';
                if (!preg_match($doclike, $name)) {
                    $this->gaps[] = ['info', 'gap_admissionrule', $leveltext . ': ' . $piece];
                    continue;
                }
                $optional = (bool)preg_match('/\boptional\b/i', $name);
                $name = trim(preg_replace('/\s*\boptional\b\s*/i', ' ', $name));
                $docs[] = [ucfirst($name), $optional ? 0 : 1, ''];
            }
            foreach ($docs as [$name, $required, $description]) {
                $key = self::norm($leveltext . ' ' . $name);
                $this->put('reqdoc', $key, ['name' => mb_substr($name, 0, 255), 'description' => $description,
                    'required' => $required, 'levelname' => $levelname, 'credentialtype' => $credtype, 'programcode' => '',
                    'scopetext' => $leveltext], $source, $levelname === '' && $credtype === '' &&
                    !preg_match('/\ball\b/i', $leveltext) ? [['warning', 'problem_reqdocscope', $leveltext]] : []);
            }
        }
    }

    /**
     * Level and credential type named by words such as "Graduate Certificate", "Doctoral" or "All levels".
     *
     * @param string $text
     * @return array [level name or '', credential type or '']
     */
    protected function scope_from_words(string $text): array {
        if (preg_match('/\ball\b/i', $text)) {
            return ['', ''];
        }
        $level = '';
        foreach ($this->items['level'] ?? [] as $item) {
            if (preg_match('/\b' . preg_quote($item['data']['name'], '/') . '\b/i', $text)) {
                $level = $item['data']['name'];
                break;
            }
        }
        $type = self::credential_type($text);
        return [$level, $type === 'other' ? '' : $type];
    }

    /**
     * Courses faculty are qualified to teach, one row per person and course (or several codes in one cell).
     *
     * @param string $sheet
     * @param array $rows
     */
    protected function read_facqual(string $sheet, array $rows): void {
        $cols = self::columns('facqual', $rows[0]);
        foreach (array_slice($rows, 1) as $i => $row) {
            $full = self::cell($row, $cols, 'name');
            if ($full === '') {
                continue;
            }
            [, $first, $last] = self::split_name($full);
            foreach (self::course_codes(self::cell($row, $cols, 'coursecode')) as $code) {
                $this->add_qualification($full, $first, $last, $code, $sheet . ' ' . ($i + 2));
            }
        }
    }

    /**
     * Honorific, first and last name of a person's name as written ("Dr. Ana Ruiz (Dean)").
     *
     * @param string $full
     * @return array [honorific, first, last]
     */
    public static function split_name(string $full): array {
        $name = trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $full));
        $honorific = '';
        if (preg_match('/^(dr|prof|professor|mr|mrs|ms|mx)\.?\s+/i', $name, $m)) {
            $honorific = rtrim($m[0]);
            $name = substr($name, strlen($m[0]));
        }
        $parts = preg_split('/\s+/', trim($name));
        $last = (string)array_pop($parts);
        return [$honorific, implode(' ', $parts), $last];
    }

    /**
     * Course codes listed in a cell ("ACC2301, MGT 5301; FIN5310").
     *
     * @param string $text
     * @return string[]
     */
    public static function course_codes(string $text): array {
        $out = [];
        foreach (preg_split('/[,;\/\n]+/', $text) as $part) {
            $code = grading::normalise_code($part);
            if ($code !== '') {
                $out[$code] = $code;
            }
        }
        return array_values($out);
    }

    /**
     * One qualification draft (a person qualified to teach a course).
     *
     * @param string $full name as written
     * @param string $first
     * @param string $last
     * @param string $code course code
     * @param string $source
     */
    protected function add_qualification(string $full, string $first, string $last, string $code, string $source): void {
        $problems = $first === '' ? [['error', 'problem_onename', $full]] : [];
        $this->put('facqual', self::norm($last . ' ' . $first) . '|' . $code, ['facultyname' => $full, 'firstname' => $first,
            'lastname' => $last, 'coursecode' => $code], $source, $problems);
    }

    /**
     * Delivery mode from words such as "100% Online", "On campus" or "Online and in person (Riverside)".
     *
     * @param string $text
     * @return string|null online, inperson, hybrid or null if not stated
     */
    public static function delivery_mode(string $text): ?string {
        $t = strtolower($text);
        if ($t === '') {
            return null;
        }
        if (preg_match('/hybrid|blended/', $t)) {
            return 'hybrid';
        }
        $online = (bool)preg_match('/online|distance|remote|virtual/', $t);
        $inperson = (bool)preg_match(
            '/in[- ]?person|on[- ]?campus|on[- ]?site|on[- ]?ground|residential|face[- ]to[- ]face|campus/',
            $t
        );
        if ($online && $inperson) {
            return 'hybrid';
        }
        return $online ? 'online' : ($inperson ? 'inperson' : null);
    }

    /**
     * Cross-checks once everything is read: program levels and totals, rules in words, prerequisites, gaps.
     */
    protected function finish(): void {
        $courses = $this->items['course'] ?? [];
        $outline = [];
        foreach ($this->items['progcrs'] ?? [] as $key => $item) {
            $d = $item['data'];
            $outline[$d['programcode']][] = $d;
            if (!isset($this->items['program'][$d['programcode']])) {
                $this->flag('progcrs', $key, 'error', 'problem_unknownprogram', $d['programcode']);
            }
        }
        $letters = grading::ordered_letters(array_map(fn($i) => $i['data'], $this->items['grade'] ?? []));
        foreach ($this->items['program'] ?? [] as $code => $item) {
            $rows = $outline[$code] ?? [];
            // Level: the level most of the program's courses have.
            $count = [];
            foreach ($rows as $r) {
                $lvl = $courses[$r['coursecode']]['data']['levelname'] ?? '';
                if ($lvl !== '') {
                    $count[$lvl] = ($count[$lvl] ?? 0) + 1;
                }
            }
            arsort($count);
            $level = (string)array_key_first($count);
            if ($level === '') {
                $level = $this->level_by_words($item['data']['credentiallevel'] . ' ' . $item['data']['credential']);
            }
            $this->items['program'][$code]['data']['levelname'] = $level;
            if ($level === '') {
                $this->flag('program', $code, 'warning', 'problem_nolevel', $code);
            }
            if (!$rows) {
                $this->flag('program', $code, 'warning', 'problem_nooutline', $code);
            } else {
                $required = 0.0;
                foreach ($rows as $r) {
                    if ($r['requirement'] === 'required') {
                        $required += (float)($r['hours'] ?? $courses[$r['coursecode']]['data']['hours'] ?? 0);
                    }
                }
                $total = $required + (float)$item['data']['trackhours'];
                $stated = $item['data']['totalhours'];
                if ($stated !== null && abs($total - $stated) > 0.001) {
                    $this->flag(
                        'program',
                        $code,
                        'warning',
                        'problem_totalmismatch',
                        ['code' => $code, 'stated' => $stated, 'outline' => $total]
                    );
                }
                foreach ($rows as $r) {
                    $unit = $courses[$r['coursecode']]['data']['unittype'] ?? null;
                    if ($unit && $unit !== $item['data']['unittype']) {
                        $this->flag('program', $code, 'warning', 'problem_unitmix', $r['coursecode']);
                        break;
                    }
                }
            }
            // Grade rules written in words.
            $text = $item['data']['graderules'];
            if ($text !== '' && $letters) {
                $groups = array_values(array_unique(array_map(fn($r) => $r['groupname'], $rows)));
                $titles = [];
                foreach ($rows as $r) {
                    $titles[$r['coursecode']] = $courses[$r['coursecode']]['data']['title'] ?? '';
                }
                $parsed = grading::parse_program_rules($text, $letters, $groups, $titles);
                foreach ($parsed['rules'] as $rule) {
                    $key = 'program:' . $code . '|' . $rule['group'] . '|' . $rule['course'];
                    $this->put('minpass', $key, ['levelname' => '', 'programcode' => $code, 'groupname' => $rule['group'],
                        'coursecode' => $rule['course'], 'minletter' => $rule['minletter'], 'minpct' => $rule['minpct'],
                        'note' => $rule['text']], $item['source'], [['warning', 'problem_rulefromwords', $rule['text']]]);
                }
                foreach ($parsed['notes'] as $note) {
                    $this->flag('program', $code, 'warning', 'problem_rulenotconverted', $note);
                }
            }
        }
        // Prerequisites must be courses in the catalog.
        foreach ($courses as $code => $item) {
            foreach ($item['data']['prereqcodes'] as $req) {
                if (!isset($this->items['course'][$req])) {
                    $this->flag('course', $code, 'warning', 'problem_unknownprereq', $req);
                }
            }
        }
        // Every level needs a pass rule; every level named by a course or program is a level.
        foreach ($this->items['program'] ?? [] as $item) {
            $this->add_level($item['data']['levelname'], '', $item['source']);
        }
        foreach ($this->items['level'] ?? [] as $key => $item) {
            if (!isset($this->items['minpass']['level:' . $key])) {
                $this->gaps[] = ['warning', 'gap_nopassrule', $item['data']['name']];
            }
        }
        $this->finish_withdraw();
        foreach ($this->items['facqual'] ?? [] as $key => $item) {
            if ($courses && !isset($courses[$item['data']['coursecode']])) {
                $this->flag('facqual', $key, 'warning', 'problem_qualcourse', $item['data']['coursecode']);
            }
        }
        if (!isset($this->items['school']['schoolname'])) {
            $this->gaps[] = ['info', 'gap_noschool', null];
        }
        if (empty($this->items['term'])) {
            $this->gaps[] = ['info', 'gap_noterms', null];
        }
    }

    /**
     * Last day to withdraw for terms that have none: the catalog's rule (a percentage in an academic rule about
     * withdrawing), else 60% of the term. Marked as calculated so staff can change it.
     */
    protected function finish_withdraw(): void {
        $percent = null;
        $rule = '';
        foreach ($this->items['policy'] ?? [] as $item) {
            $d = $item['data'];
            if (preg_match('/withdraw/i', $d['title']) && ($found = dates::percent_in_text($d['value'])) !== null) {
                $percent = $found;
                $rule = $d['title'] . ': ' . $d['value'];
                break;
            }
        }
        if ($percent !== null && !isset($this->items['school']['withdrawpct'])) {
            $this->put(
                'school',
                'withdrawpct',
                ['field' => 'withdrawpct', 'value' => (string)$percent],
                $rule,
                [['warning', 'problem_rulefromwords', $rule]]
            );
        }
        foreach ($this->items['term'] ?? [] as $key => $item) {
            $d = $item['data'];
            $this->items['term'][$key]['data']['withdrawcalc'] = 0;
            if ($d['withdrawby'] || !$d['startdate'] || !$d['enddate']) {
                continue;
            }
            $ymd = dates::withdraw_ymd($d['startdate'], $d['enddate'], $percent ?? dates::WITHDRAW_PERCENT);
            $shown = rtrim(rtrim(sprintf('%.2f', $percent ?? dates::WITHDRAW_PERCENT), '0'), '.');
            if ($ymd === null) {
                continue;
            }
            $this->items['term'][$key]['data']['withdrawby'] = $ymd;
            $this->items['term'][$key]['data']['withdrawcalc'] = 1;
            $this->flag(
                'term',
                $key,
                'warning',
                $percent === null ? 'problem_withdrawcalc' : 'problem_withdrawrule',
                ['date' => $ymd, 'percent' => $shown, 'rule' => $rule]
            );
        }
    }

    /**
     * Level named in words such as "Bachelor" or "Master", matched to the levels found in the file.
     *
     * @param string $words
     * @return string level name or ''
     */
    protected function level_by_words(string $words): string {
        $w = strtolower($words);
        $want = str_contains($w, 'doctor') ? ['doctoral', 'doctorate', 'graduate'] :
            (preg_match('/master|graduate/', $w) ? ['graduate', 'postgraduate'] :
            (preg_match('/bachelor|associate|undergrad/', $w) ? ['undergraduate'] : []));
        foreach ($want as $name) {
            foreach ($this->items['level'] ?? [] as $item) {
                if (strcasecmp($item['data']['name'], $name) === 0) {
                    return $item['data']['name'];
                }
            }
        }
        return '';
    }

    /**
     * Credential type from words such as "Graduate Certificate" or "Master".
     *
     * @param string $words
     * @return string
     */
    public static function credential_type(string $words): string {
        $w = strtolower($words);
        foreach (
            ['doctor' => 'doctorate', 'master' => 'master', 'bachelor' => 'bachelor', 'associate' => 'associate',
                'certificate' => 'certificate', 'diploma' => 'diploma'] as $needle => $type
        ) {
            if (str_contains($w, $needle)) {
                return $type;
            }
        }
        foreach (
            ['mba' => 'master', 'ms' => 'master', 'msc' => 'master', 'med' => 'master', 'dba' => 'doctorate',
                'phd' => 'doctorate', 'edd' => 'doctorate', 'bs' => 'bachelor', 'ba' => 'bachelor'] as $abbr => $type
        ) {
            if (preg_match('/\b' . $abbr . '\b/', $w)) {
                return $type;
            }
        }
        return 'other';
    }
}
