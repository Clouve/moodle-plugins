<?php

namespace local_studentrecords\local;

/**
 * Registration: course offerings, add / drop / withdraw by the term dates with capacity and prerequisite checks,
 * Moodle enrolment (manual enrolment, student role; a drop or withdrawal suspends it, never removes it), final
 * grades (from the Moodle gradebook, read only, or entered by staff) and the academic history with GPA.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class registration {
    /** @var string Offerings table. */
    const OFFER = 'local_studentrecords_offer';

    /** @var string Registrations table. */
    const REG = 'local_studentrecords_reg';

    /** @var string[] Registration statuses. */
    const STATUSES = ['registered', 'dropped', 'withdrawn', 'completed'];

    /** @var string[] Statuses that hold a seat and count as attempted. */
    const ACTIVE = ['registered', 'completed'];

    /** @var string[] grade sources a Moodle gradebook refresh keeps (staff, approved grade change, teacher posting) */
    const KEPTSOURCES = ['staff', 'change', 'teacher'];

    /** @var string[] Offering statuses. */
    const OFFERSTATUSES = ['open', 'closed', 'cancelled'];

    /** @var string Mark shown for a withdrawal when the scale has no W. */
    const WITHDRAWN = 'W';

    /** @var int Seconds to wait for another request working on the same seats or grade. */
    const LOCKWAIT = 10;

    /** @var array Locks this request holds: resource => true. */
    protected static $locks = [];

    // Rules that need no database.

    /**
     * Whether an action is allowed on a day by the term dates. Add and drop: until the end of add/drop (without
     * add/drop dates: add until the term ends, drop until it starts). Withdraw: after add/drop until the last day to
     * withdraw.
     *
     * @param string $action add, drop or withdraw
     * @param string $today Y-m-d
     * @param array $term Y-m-d strings: startdate, enddate, adddropend, withdrawby ('' when not set)
     * @return string|null language string key of the problem, or null when allowed
     */
    public static function window_problem(string $action, string $today, array $term): ?string {
        $adddropend = $term['adddropend'] ?? '';
        if ($action === 'add') {
            $last = $adddropend !== '' ? $adddropend : ($term['enddate'] ?? '');
            return $last !== '' && $today > $last ? 'err_addclosed' : null;
        }
        if ($action === 'drop') {
            $last = $adddropend !== '' ? $adddropend : ($term['startdate'] ?? '');
            return $last !== '' && $today > $last ? 'err_dropclosed' : null;
        }
        if ($action === 'withdraw') {
            $first = $adddropend !== '' ? $adddropend : ($term['startdate'] ?? '');
            if ($first !== '' && $today <= $first) {
                return 'err_withdrawearly';
            }
            $last = $term['withdrawby'] ?? '';
            return $last !== '' && $today > $last ? 'err_withdrawclosed' : null;
        }
        return 'err_unknownaction';
    }

    /**
     * Whether grades are frozen on a day: after the term's grade freeze date.
     *
     * @param string $freezeymd Y-m-d or '' (no freeze)
     * @param string $today Y-m-d
     * @return bool
     */
    public static function is_frozen(string $freezeymd, string $today): bool {
        return $freezeymd !== '' && $today > $freezeymd;
    }

    /**
     * Whether the grades of an offering's term are frozen today.
     *
     * @param int $offerid
     * @return bool
     */
    public static function frozen(int $offerid): bool {
        global $DB;
        $freeze = $DB->get_field_sql("SELECT t.gradefreeze FROM {" . self::OFFER . "} o JOIN {local_studentrecords_term} t
                                        ON t.id = o.termid WHERE o.id = ?", [$offerid]);
        return self::is_frozen($freeze ? catalog::time_to_date((int)$freeze) : '', catalog::time_to_date(time()));
    }

    /**
     * Credits attempted and earned, quality points and GPA hours of a list of course results.
     *
     * The sums are whole numbers, hours in hundredths and quality points in ten-thousandths, as Academic Transcript's
     * calculator keeps them. So the arithmetic is exact, a GPA halfway between two hundredths rounds up, and every PHP
     * version gives the same result.
     *
     * @param array $results rows with hours, status, letter, points (null = not in GPA), passed (bool|null),
     *     repeatexcluded (a repeat left out by the repeat rule: attempted, but not earned and not in the GPA)
     * @return array attempted, earned, gpahours, qualitypoints, gpa (null when no GPA hours)
     */
    public static function totals(array $results): array {
        $attempted = 0;
        $earned = 0;
        $gpahours = 0;
        $qualitypoints = 0;
        foreach ($results as $r) {
            $r = (array)$r;
            if (!in_array($r['status'], ['completed', 'withdrawn', 'registered'], true)) {
                continue;
            }
            $hours = (int)round((float)$r['hours'] * 100);
            $attempted += $hours;
            if ($r['status'] !== 'completed' || ($r['letter'] ?? '') === '' || !empty($r['repeatexcluded'])) {
                continue;
            }
            if (($r['passed'] ?? null) !== false) {
                $earned += $hours;
            }
            if (($r['points'] ?? null) !== null) {
                $gpahours += $hours;
                $qualitypoints += (int)round((float)$r['points'] * 100) * $hours;
            }
        }
        return ['attempted' => $attempted / 100.0, 'earned' => $earned / 100.0, 'gpahours' => $gpahours / 100.0,
            'qualitypoints' => $qualitypoints / 10000.0,
            'gpa' => $gpahours > 0 ? grading::divide_rounded($qualitypoints, $gpahours) / 100.0 : null];
    }

    /**
     * Mark the attempts of repeated courses that the repeat rule leaves out. They stay on the record and count as attempted.
     * Academic Transcript's calculator decides, so the record and the transcript leave out the same attempts. What counts
     * depends on the attempts given, so a report on an earlier term marks that term's rows again.
     *
     * @param array $rows rows of history() with code, status, letter, hours, startdate, termid, timeregistered
     */
    public static function mark_repeats(array $rows): void {
        foreach ($rows as $r) {
            $r->repeatexcluded = false;
        }
        if (catalog::repeat_rule() === 'all') {
            return;
        }
        $terms = [];
        foreach ($rows as $r) {
            $terms[(int)$r->termid] = [(int)$r->startdate, (int)$r->termid];
        }
        asort($terms);
        $order = array_flip(array_keys($terms));
        $attempts = [];
        foreach ($rows as $key => $r) {
            if (in_array($r->status, ['completed', 'withdrawn'], true)) {
                $attempts[$key] = ['code' => (string)$r->code, 'grade' => (string)$r->letter, 'hours' => (float)$r->hours,
                    'group' => (string)$r->termid, 'order' => $order[(int)$r->termid], 'time' => (int)$r->timeregistered];
            }
        }
        $result = \local_academictranscript\local\scale::calculator()->compute($attempts);
        foreach ($result['attempts'] as $key => $attempt) {
            $rows[$key]->repeatexcluded = $attempt['excluded'];
        }
    }

    /**
     * Do some work while holding a lock, so that two requests can't both read a value and then write on it: the seats of
     * a section, or the grade of a registration. The lock is held until the work has committed. Asking again for a lock
     * this request holds just does the work.
     *
     * @param string $resource what is locked, for example offer12 or reg34
     * @param callable $work
     * @return mixed what the work returns
     * @throws \moodle_exception when another request keeps the lock for too long
     */
    public static function with_lock(string $resource, callable $work) {
        if (isset(self::$locks[$resource])) {
            return $work();
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_studentrecords')->get_lock($resource, self::LOCKWAIT);
        if (!$lock) {
            throw new \moodle_exception('err_busy', 'local_studentrecords');
        }
        self::$locks[$resource] = true;
        try {
            return $work();
        } finally {
            unset(self::$locks[$resource]);
            $lock->release();
        }
    }

    // Offerings.

    /**
     * An offering with its course and term.
     *
     * @param int $id
     * @return \stdClass offering + code, title, hours, unittype, termname
     */
    public static function offer(int $id): \stdClass {
        global $DB;
        return $DB->get_record_sql("SELECT o.*, c.code, c.title, c.hours, c.unittype, t.name AS termname
                                      FROM {" . self::OFFER . "} o
                                      JOIN {local_studentrecords_course} c ON c.id = o.courseid
                                      JOIN {local_studentrecords_term} t ON t.id = o.termid
                                     WHERE o.id = ?", [$id], MUST_EXIST);
    }

    /**
     * Offerings of a term (or all), with course, term and seats taken.
     *
     * @param int $termid 0 = all
     * @param bool $openonly
     * @return array
     */
    public static function offers(int $termid = 0, bool $openonly = false): array {
        global $DB;
        $where = [];
        $params = [];
        if ($termid) {
            $where[] = 'o.termid = ?';
            $params[] = $termid;
        }
        if ($openonly) {
            $where[] = "o.status = 'open'";
        }
        [$in, $inparams] = $DB->get_in_or_equal(self::ACTIVE);
        $sql = "SELECT o.*, c.code, c.title, c.hours, c.unittype, t.name AS termname, t.startdate,
                       (SELECT COUNT(1) FROM {" . self::REG . "} r WHERE r.offerid = o.id AND r.status $in) AS taken
                  FROM {" . self::OFFER . "} o
                  JOIN {local_studentrecords_course} c ON c.id = o.courseid
                  JOIN {local_studentrecords_term} t ON t.id = o.termid " .
            ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
              ORDER BY t.startdate, c.code, o.section";
        return $DB->get_records_sql($sql, array_merge($inparams, $params));
    }

    /**
     * The term that starts last before a term (for "Copy from last term").
     *
     * @param int $termid
     * @return \stdClass|null
     */
    public static function previous_term(int $termid): ?\stdClass {
        global $DB;
        $term = $DB->get_record('local_studentrecords_term', ['id' => $termid], '*', MUST_EXIST);
        $rows = $DB->get_records_select(
            'local_studentrecords_term',
            'startdate < ?',
            [$term->startdate],
            'startdate DESC',
            '*',
            0,
            1
        );
        return $rows ? reset($rows) : null;
    }

    /**
     * Sections of one term that the other term does not have yet (same course and section), not cancelled.
     *
     * @param int $fromterm
     * @param int $toterm
     * @return array
     */
    public static function sections_to_copy(int $fromterm, int $toterm): array {
        global $DB;
        $have = [];
        foreach ($DB->get_records(self::OFFER, ['termid' => $toterm], '', 'id, courseid, section') as $o) {
            $have[$o->courseid . '|' . $o->section] = true;
        }
        return array_filter($DB->get_records(self::OFFER, ['termid' => $fromterm], 'id'), fn($o) => $o->status !== 'cancelled' &&
            !isset($have[$o->courseid . '|' . $o->section]));
    }

    /**
     * Copy sections from one term to another: course, section, teacher, seats, class days, times and room. The Moodle
     * course is not copied (each term usually has its own); the new sections are open.
     *
     * @param int $fromterm
     * @param int $toterm
     * @return int sections copied
     */
    public static function copy_sections(int $fromterm, int $toterm): int {
        global $DB;
        $n = 0;
        $transaction = $DB->start_delegated_transaction();
        foreach (self::sections_to_copy($fromterm, $toterm) as $o) {
            $id = records::save(self::OFFER, ['termid' => $toterm, 'courseid' => $o->courseid, 'section' => $o->section,
                'facultyid' => $o->facultyid, 'capacity' => $o->capacity, 'moodlecourseid' => null, 'status' => 'open',
                'notes' => $o->notes, 'meetdays' => $o->meetdays, 'starttime' => $o->starttime, 'endtime' => $o->endtime,
                'room' => $o->room], 'copy');
            audit::log('create', 'offering', $id, null, 'copied from offering ' . $o->id);
            $n++;
        }
        $transaction->allow_commit();
        return $n;
    }

    /**
     * Seats taken in an offering.
     *
     * @param int $offerid
     * @return int
     */
    public static function taken(int $offerid): int {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal(self::ACTIVE);
        return $DB->count_records_select(self::REG, "offerid = ? AND status $in", array_merge([$offerid], $params));
    }

    // Checks.

    /**
     * The term dates of an offering as Y-m-d in the school's time zone.
     *
     * @param int $termid
     * @return array
     */
    public static function term_days(int $termid): array {
        global $DB;
        $t = $DB->get_record('local_studentrecords_term', ['id' => $termid], '*', MUST_EXIST);
        $out = [];
        foreach (['startdate', 'enddate', 'adddropend', 'withdrawby'] as $f) {
            $out[$f] = $t->$f ? catalog::time_to_date((int)$t->$f) : '';
        }
        return $out;
    }

    /**
     * Prerequisites of a catalog course the student has not passed: course codes.
     *
     * @param \stdClass $student
     * @param int $courseid
     * @return string[]
     */
    public static function missing_prereqs(\stdClass $student, int $courseid): array {
        global $DB;
        $missing = [];
        $sql = "SELECT p.reqcourseid, c.code FROM {local_studentrecords_prereq} p
                  JOIN {local_studentrecords_course} c ON c.id = p.reqcourseid
                 WHERE p.courseid = ?";
        foreach ($DB->get_records_sql($sql, [$courseid]) as $req) {
            if (!self::has_passed($student, (int)$req->reqcourseid)) {
                $missing[] = $req->code;
            }
        }
        return $missing;
    }

    /**
     * Whether the student passed a catalog course (a completed registration with a passing grade).
     *
     * @param \stdClass $student
     * @param int $courseid
     * @return bool
     */
    public static function has_passed(\stdClass $student, int $courseid): bool {
        global $DB;
        $sql = "SELECT r.grade FROM {" . self::REG . "} r JOIN {" . self::OFFER . "} o ON o.id = r.offerid
                 WHERE r.studentid = ? AND o.courseid = ? AND r.status = 'completed' AND r.grade IS NOT NULL";
        foreach ($DB->get_fieldset_sql($sql, [$student->id, $courseid]) as $letter) {
            if (self::result_passes($student, $courseid, (string)$letter) !== false) {
                return true;
            }
        }
        // Transfer credit for the equivalent course counts as passed.
        return $DB->record_exists('local_studentrecords_xfer', ['studentid' => $student->id, 'equivcourseid' => $courseid,
            'active' => 1]);
    }

    /**
     * Whether a letter passes a course for a student (the most specific pass rule of the program); null if unknown.
     *
     * @param \stdClass $student
     * @param int $courseid
     * @param string $letter
     * @return bool|null
     */
    public static function result_passes(\stdClass $student, int $courseid, string $letter): ?bool {
        global $DB;
        $marks = catalog::marks();
        if (isset($marks[$letter])) {
            return (bool)$marks[$letter]->earns;
        }
        $rules = array_values($DB->get_records('local_studentrecords_minpass'));
        $levelid = $student->programid ? (int)$DB->get_field(
            'local_studentrecords_program',
            'levelid',
            ['id' => $student->programid]
        ) : null;
        $groups = $student->programid ? $DB->get_fieldset_select(
            'local_studentrecords_progcrs',
            'groupname',
            'programid = ? AND courseid = ?',
            [$student->programid, $courseid]
        ) : [];
        $rule = grading::effective_rule(
            $rules,
            $levelid ?: null,
            $student->programid ? (int)$student->programid : null,
            $groups,
            $courseid
        );
        $passes = grading::passes(catalog::scale(), $letter, $rule);
        if ($passes === null) {
            // No rule: any letter with grade points above zero passes.
            foreach (catalog::scale() as $row) {
                if ($row->letter === $letter && $row->points !== null) {
                    return (float)$row->points > 0;
                }
            }
        }
        return $passes;
    }

    /**
     * Problems that stop a registration: closed or cancelled offering, already registered, no seat, missing
     * prerequisites, add period over.
     *
     * @param \stdClass $student
     * @param \stdClass $offer from offer()
     * @return array list of [language string key, a]
     */
    public static function add_problems(\stdClass $student, \stdClass $offer): array {
        global $DB;
        $out = [];
        $holds = academic::blocking_holds((int)$student->id);
        if ($holds) {
            $out[] = ['err_hold', implode(', ', array_map(
                fn($h) => get_string('holdtype_' . $h->holdtype, 'local_studentrecords'),
                $holds
            ))];
        }
        if ($offer->status !== 'open') {
            $out[] = ['err_offernotopen', get_string('offerstatus_' . $offer->status, 'local_studentrecords')];
        }
        $existing = $DB->get_record(self::REG, ['studentid' => $student->id, 'offerid' => $offer->id]);
        if ($existing && in_array($existing->status, self::ACTIVE, true)) {
            $out[] = ['err_alreadyregistered', null];
        }
        // Seats held for students offered one from the waitlist count as taken for everyone else.
        if (
            $offer->capacity !== null &&
                self::taken((int)$offer->id) + waitlist::held_seats((int)$offer->id, (int)$student->id) >= (int)$offer->capacity
        ) {
            $out[] = ['err_full', (int)$offer->capacity];
        }
        $missing = self::missing_prereqs($student, (int)$offer->courseid);
        if ($missing) {
            $out[] = ['err_prereqs', implode(', ', $missing)];
        }
        $window = self::window_problem('add', catalog::time_to_date(time()), self::term_days((int)$offer->termid));
        if ($window) {
            $out[] = [$window, null];
        }
        return $out;
    }

    // Actions.

    /**
     * Register a student in an offering and enrol them in its Moodle course. The seats are counted and taken under one
     * lock, so two students can't both take the last seat.
     *
     * @param \stdClass $student
     * @param int $offerid
     * @param bool $override staff override of the problems found (reason in $note)
     * @param string $note
     * @return int registration id
     * @throws \moodle_exception when there are problems and no override
     */
    public static function add(\stdClass $student, int $offerid, bool $override = false, string $note = ''): int {
        return self::with_lock('offer' . $offerid, fn() => self::add_seat($student, $offerid, $override, $note));
    }

    /**
     * Check the rules and take the seat. The caller holds the lock on the offering.
     *
     * @param \stdClass $student
     * @param int $offerid
     * @param bool $override
     * @param string $note
     * @return int registration id
     */
    protected static function add_seat(\stdClass $student, int $offerid, bool $override, string $note): int {
        global $DB;
        $offer = self::offer($offerid);
        $problems = self::add_problems($student, $offer);
        // A hold or a second registration cannot be overridden.
        $blocking = array_filter($problems, fn($p) => in_array($p[0], ['err_alreadyregistered', 'err_hold'], true));
        if ($blocking || ($problems && !$override)) {
            $first = $blocking ? reset($blocking) : $problems[0];
            throw new \moodle_exception($first[0], 'local_studentrecords', '', $first[1]);
        }
        if ($problems && trim($note) === '') {
            throw new \moodle_exception('err_overridenote', 'local_studentrecords');
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $existing = $DB->get_record(self::REG, ['studentid' => $student->id, 'offerid' => $offerid]);
            $record = ['studentid' => $student->id, 'offerid' => $offerid, 'status' => 'registered', 'timeregistered' => time(),
                'timeended' => null, 'grade' => null, 'gradepct' => null, 'gradesource' => null,
                'override' => $problems ? 1 : 0, 'accesspending' => 0, 'note' => \core_text::substr(trim($note), 0, 255)];
            if ($existing) {
                $record['id'] = $existing->id;
            }
            $id = records::save(self::REG, $record, 'form', (int)$student->userid);
            billing::charge_registration($student, $id);
            if ($offer->moodlecourseid && !payments::access_ok($student, (int)$offer->termid)) {
                // Billing: the Moodle course enrolment waits until the payment rule is met (or staff give access).
                records::save(self::REG, ['id' => $id, 'accesspending' => 1], 'billing', (int)$student->userid);
            } else if ($offer->moodlecourseid) {
                self::enrol((int)$offer->moodlecourseid, (int)$student->userid);
            }
            audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'registered ' . $offer->code . ' ' .
                $offer->section . ($problems ? ' (override)' : ''));
            waitlist::registered((int)$student->id, $offerid);
            $DB->delete_records(selfreg::CART, ['studentid' => $student->id, 'offerid' => $offerid]);
            messages::student('regadded', $student, ['course' => $offer->code, 'term' => $offer->termname]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The callers show the error and carry on, so the transaction must end here.
            $transaction->rollback($e);
        }
        return $id;
    }

    /**
     * Drop or withdraw: the registration is kept with its status and date; the Moodle enrolment is suspended
     * (never removed), unless another active registration of the student uses the same Moodle course.
     *
     * @param int $regid
     * @param string $action drop or withdraw
     * @param bool $override staff override of the term dates
     * @param string $note
     */
    public static function end(int $regid, string $action, bool $override = false, string $note = ''): void {
        global $DB;
        $offerid = (int)$DB->get_field(self::REG, 'offerid', ['id' => $regid], MUST_EXIST);
        self::with_lock('offer' . $offerid, fn() => self::end_seat($regid, $action, $override, $note));
    }

    /**
     * End a registration. The caller holds the lock on the offering.
     *
     * @param int $regid
     * @param string $action
     * @param bool $override
     * @param string $note
     */
    protected static function end_seat(int $regid, string $action, bool $override, string $note): void {
        global $DB;
        $reg = $DB->get_record(self::REG, ['id' => $regid], '*', MUST_EXIST);
        $student = students::get((int)$reg->studentid);
        $offer = self::offer((int)$reg->offerid);
        if ($reg->status !== 'registered') {
            throw new \moodle_exception('err_notregistered', 'local_studentrecords');
        }
        $window = self::window_problem($action, catalog::time_to_date(time()), self::term_days((int)$offer->termid));
        if ($window && !$override) {
            throw new \moodle_exception($window, 'local_studentrecords');
        }
        if ($window && trim($note) === '') {
            throw new \moodle_exception('err_overridenote', 'local_studentrecords');
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $status = $action === 'drop' ? 'dropped' : 'withdrawn';
            $reason = trim($note) !== '' ? \core_text::substr(trim($note), 0, 255) : $reg->note;
            $override = $window ? 1 : (int)$reg->override;
            records::save(self::REG, ['id' => $regid, 'status' => $status, 'timeended' => time(), 'override' => $override,
                'accesspending' => 0, 'note' => $reason], 'form', (int)$student->userid);
            if ($offer->moodlecourseid && !self::other_active((int)$student->id, (int)$offer->moodlecourseid, $regid)) {
                self::suspend((int)$offer->moodlecourseid, (int)$student->userid);
            }
            audit::log('update', 'student', (int)$student->id, (int)$student->userid, $action . ' ' . $offer->code . ' ' .
                $offer->section . ($window ? ' (override)' : ''));
            // Attendance: a withdrawal fills the student's last date of attendance when it is empty.
            if ($action === 'withdraw') {
                attendance::fill_last_attended($student);
            }
            // Billing: the refund is figured by the school's refund rules and waits for staff approval (on the last date
            // of attendance when the school says so and there is one).
            $eventtime = null;
            if (catalog::setting('refundlda') === '1') {
                $lda = attendance::for_reg($reg, $student->userid ? (int)$student->userid : null)['lda'];
                $eventtime = $lda !== '' ? catalog::date_to_time($lda) : null;
            }
            refunds::propose($regid, $action, $eventtime);
            messages::student($action === 'drop' ? 'regdropped' : 'regwithdrawn', $student, ['course' => $offer->code,
                'term' => $offer->termname]);
            waitlist::seat_opened((int)$offer->id);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The callers show the error and carry on, so the transaction must end here.
            $transaction->rollback($e);
        }
    }

    /**
     * Whether the student has another active registration in an offering linked to the same Moodle course.
     *
     * @param int $studentid
     * @param int $moodlecourseid
     * @param int $exceptregid
     * @return bool
     */
    protected static function other_active(int $studentid, int $moodlecourseid, int $exceptregid): bool {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal(self::ACTIVE);
        return $DB->record_exists_sql(
            "SELECT 1 FROM {" . self::REG . "} r JOIN {" . self::OFFER . "} o ON o.id = r.offerid
                                        WHERE r.studentid = ? AND o.moodlecourseid = ? AND r.id <> ? AND r.status $in",
            array_merge([$studentid, $moodlecourseid, $exceptregid], $params)
        );
    }

    /**
     * The working manual enrolment method of a Moodle course: an enrolment through a disabled method gives no access.
     *
     * @param int $courseid
     * @return \stdClass
     * @throws \moodle_exception when the course has no working manual enrolment
     */
    protected static function manual_instance(int $courseid): \stdClass {
        foreach (enrol_get_instances($courseid, true) as $instance) {
            if ($instance->enrol === 'manual') {
                return $instance;
            }
        }
        throw new \moodle_exception('err_nomanual', 'local_studentrecords');
    }

    /**
     * Enrol (or re-activate) a user in a Moodle course with the student role, by manual enrolment.
     *
     * @param int $courseid
     * @param int $userid
     */
    public static function enrol(int $courseid, int $userid): void {
        global $DB;
        $instance = self::manual_instance($courseid);
        $plugin = enrol_get_plugin('manual');
        $ue = $DB->get_record('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid]);
        if ($ue) {
            if ((int)$ue->status !== ENROL_USER_ACTIVE) {
                $plugin->update_user_enrol($instance, $userid, ENROL_USER_ACTIVE);
            }
            return;
        }
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']) ?: (int)$instance->roleid;
        $plugin->enrol_user($instance, $userid, $roleid);
    }

    /**
     * Suspend a user's manual enrolment in a Moodle course (course work and grades stay). A course without a manual
     * method has no enrolment of ours, and the method may be disabled by now, so neither stops a drop.
     *
     * @param int $courseid
     * @param int $userid
     */
    public static function suspend(int $courseid, int $userid): void {
        global $DB;
        foreach (enrol_get_instances($courseid, false) as $instance) {
            $active = ['enrolid' => $instance->id, 'userid' => $userid, 'status' => ENROL_USER_ACTIVE];
            if ($instance->enrol === 'manual' && $DB->record_exists('user_enrolments', $active)) {
                enrol_get_plugin('manual')->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED);
            }
        }
    }

    // Grades.

    /**
     * Final grade of a user in a Moodle course from the gradebook (read only): course total as a percentage, not rounded,
     * so the letter comes from the same number Academic Transcript sees.
     *
     * @param int $courseid
     * @param int $userid
     * @return float|null
     */
    public static function moodle_percent(int $courseid, int $userid): ?float {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $item = \grade_item::fetch_course_item($courseid);
        if (!$item) {
            return null;
        }
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
        if (!$grade || $grade->finalgrade === null) {
            return null;
        }
        $range = (float)$item->grademax - (float)$item->grademin;
        return $range > 0 ? ((float)$grade->finalgrade - (float)$item->grademin) / $range * 100 : null;
    }

    /**
     * Set a final grade (completes the registration).
     *
     * Staff enter or correct a grade until the term's grade freeze, and the change history keeps every value. The Moodle
     * gradebook and teachers never replace a grade staff set, and nothing but an approved grade change writes after the
     * grade freeze.
     *
     * @param int $regid
     * @param string $letter
     * @param float|null $pct
     * @param string $source moodle, staff, teacher or change (an approved grade change)
     * @param bool $viachange true for an approved grade change (allowed after the grade freeze)
     * @throws \moodle_exception when one of those rules stops the grade
     */
    public static function set_grade(int $regid, string $letter, ?float $pct, string $source, bool $viachange = false): void {
        if ($viachange !== ($source === 'change')) {
            throw new \coding_exception('Only an approved grade change uses the grade change source.');
        }
        self::with_lock('reg' . $regid, fn() => self::write_grade($regid, $letter, $pct, $source, $viachange));
    }

    /**
     * Check the rules and write a final grade. The caller holds the lock on the registration.
     *
     * @param int $regid
     * @param string $letter
     * @param float|null $pct
     * @param string $source
     * @param bool $viachange
     */
    protected static function write_grade(int $regid, string $letter, ?float $pct, string $source, bool $viachange): void {
        global $DB;
        $reg = $DB->get_record(self::REG, ['id' => $regid], '*', MUST_EXIST);
        if (!in_array($reg->status, self::ACTIVE, true)) {
            throw new \moodle_exception('err_gradenotactive', 'local_studentrecords');
        }
        if (!$viachange) {
            if (self::frozen((int)$reg->offerid)) {
                throw new \moodle_exception('err_frozen', 'local_studentrecords');
            }
            $kept = $source === 'moodle' ? self::KEPTSOURCES : ($source === 'teacher' ? ['staff', 'change'] : []);
            if (in_array($reg->gradesource, $kept, true)) {
                throw new \moodle_exception('err_gradekept', 'local_studentrecords');
            }
        }
        $student = students::get((int)$reg->studentid);
        // The column keeps four decimals. The letter was chosen from the exact percentage before this.
        records::save(self::REG, ['id' => $regid, 'status' => 'completed', 'grade' => $letter,
            'gradepct' => $pct === null ? null : round($pct, 4), 'gradesource' => $source], 'form', (int)$student->userid);
        if (records::$lastchanged) {
            audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'grade ' . $letter);
            $offer = self::offer((int)$reg->offerid);
            messages::student('gradeposted', $student, ['course' => $offer->code, 'term' => $offer->termname]);
        }
    }

    /**
     * Take final grades of an offering from its Moodle course (read only). Grades entered by staff, set by an approved
     * grade change or posted by the teacher are kept.
     *
     * @param int $offerid
     * @return array [set, kept (staff grades), no grade yet, frozen (1 when the term's grades are frozen)]
     */
    public static function grades_from_moodle(int $offerid): array {
        global $DB;
        $offer = self::offer($offerid);
        $counts = [0, 0, 0, 0];
        if (!$offer->moodlecourseid) {
            return $counts;
        }
        if (self::frozen($offerid)) {
            $counts[3] = 1;
            return $counts;
        }
        $scale = catalog::scale();
        $round = catalog::round_percent();
        [$in, $params] = $DB->get_in_or_equal(self::ACTIVE);
        foreach ($DB->get_records_select(self::REG, "offerid = ? AND status $in", array_merge([$offerid], $params)) as $reg) {
            if (in_array($reg->gradesource, self::KEPTSOURCES, true)) {
                $counts[1]++;
                continue;
            }
            $userid = (int)$DB->get_field(students::TABLE, 'userid', ['id' => $reg->studentid]);
            $pct = self::moodle_percent((int)$offer->moodlecourseid, $userid);
            $letter = $pct === null ? null : grading::letter_for_pct($scale, $pct, $round);
            if ($letter === null) {
                $counts[2]++;
                continue;
            }
            self::set_grade((int)$reg->id, $letter, $pct, 'moodle');
            $counts[0]++;
        }
        return $counts;
    }

    // Final grades posted by teachers (Academic Transcript "Post final grades").

    /**
     * Where a teacher's posted final grade for a Moodle course goes in Student Records. The registration is the
     * student's open (registered) one for an offering linked to the course, else the latest completed one.
     *
     * @param int $moodlecourseid
     * @param int $userid
     * @return array ['state' => norecord (no Student Records file, nothing to do) | noreg | several | kept | frozen | ok,
     *     'regid' => int, 'grade' => current letter or '', 'freeze' => Y-m-d or '']
     */
    public static function teacher_post_target(int $moodlecourseid, int $userid): array {
        global $DB;
        $out = ['state' => 'norecord', 'regid' => 0, 'grade' => '', 'freeze' => ''];
        $student = students::get_by_user($userid);
        if (!$student) {
            return $out;
        }
        [$in, $params] = $DB->get_in_or_equal(self::ACTIVE);
        $regs = $DB->get_records_sql("SELECT r.*, t.gradefreeze
                                        FROM {" . self::REG . "} r
                                        JOIN {" . self::OFFER . "} o ON o.id = r.offerid
                                        JOIN {local_studentrecords_term} t ON t.id = o.termid
                                       WHERE r.studentid = ? AND o.moodlecourseid = ? AND r.status $in
                                    ORDER BY t.startdate DESC, r.id DESC", array_merge([$student->id, $moodlecourseid], $params));
        $open = array_values(array_filter($regs, fn($r) => $r->status === 'registered'));
        if (count($open) > 1) {
            return ['state' => 'several'] + $out;
        }
        $reg = $open ? $open[0] : (reset($regs) ?: null);
        if (!$reg) {
            return ['state' => 'noreg'] + $out;
        }
        $out['regid'] = (int)$reg->id;
        $out['grade'] = (string)$reg->grade;
        $out['freeze'] = $reg->gradefreeze ? catalog::time_to_date((int)$reg->gradefreeze) : '';
        if (in_array($reg->gradesource, ['staff', 'change'], true)) {
            $out['state'] = 'kept';
        } else if ($reg->gradesource === 'teacher') {
            // Already posted. A course that runs again needs a new registration, or a finished attempt would be replaced.
            $out['state'] = 'posted';
        } else if (self::frozen((int)$reg->offerid)) {
            $out['state'] = 'frozen';
        } else {
            $out['state'] = 'ok';
        }
        return $out;
    }

    /**
     * Letter on the school's grading scale for a teacher's posting: from the course total percentage, else the
     * hand-entered letter or mark (I, P, NP, W, NC ...) when it is on the scale.
     *
     * @param float|null $pct
     * @param string $letter
     * @return string|null null when the grade is not on the scale
     */
    public static function teacher_letter(?float $pct, string $letter): ?string {
        if ($pct !== null) {
            return grading::letter_for_pct(catalog::scale(), $pct, catalog::round_percent());
        }
        return isset(catalog::grade_menu()[$letter]) ? $letter : null;
    }

    /**
     * Save a teacher's posted final grade on the registration (completes it). Never replaces a grade entered by staff
     * or set by an approved grade change, and never after the grade freeze.
     *
     * @param int $moodlecourseid
     * @param int $userid
     * @param float|null $pct course total percentage, or null for a hand-entered letter
     * @param string $letter hand-entered letter (used when $pct is null)
     * @return string the letter saved
     * @throws \moodle_exception when the grade cannot be saved
     */
    public static function teacher_post(int $moodlecourseid, int $userid, ?float $pct, string $letter): string {
        $target = self::teacher_post_target($moodlecourseid, $userid);
        if ($target['state'] !== 'ok') {
            throw new \moodle_exception('err_teacherpost_' . $target['state'], 'local_studentrecords', '', (object)$target);
        }
        $grade = self::teacher_letter($pct, $letter);
        if ($grade === null) {
            throw new \moodle_exception('err_teacherpost_notonscale', 'local_studentrecords');
        }
        self::set_grade($target['regid'], $grade, $pct, 'teacher');
        return $grade;
    }

    // Academic history.

    /**
     * Academic history of a student: terms (by start date) with their courses and totals, and cumulative totals.
     *
     * @param \stdClass $student
     * @return array ['terms' => list of [term, rows, totals], 'cumulative' => totals]
     */
    public static function history(\stdClass $student): array {
        global $DB;
        $points = [];
        foreach (catalog::scale() as $row) {
            $points[$row->letter] = $row->ingpa && $row->points !== null ? (float)$row->points : null;
        }
        $sql = "SELECT r.*, o.termid, o.section, o.courseid, c.code, c.title, c.hours, c.unittype, t.name AS termname,
                       t.startdate
                  FROM {" . self::REG . "} r
                  JOIN {" . self::OFFER . "} o ON o.id = r.offerid
                  JOIN {local_studentrecords_course} c ON c.id = o.courseid
                  JOIN {local_studentrecords_term} t ON t.id = o.termid
                 WHERE r.studentid = ?
              ORDER BY t.startdate, c.code, r.id";
        $byterm = [];
        $all = [];
        foreach ($DB->get_records_sql($sql, [$student->id]) as $r) {
            $r->letter = $r->status === 'withdrawn' ? self::WITHDRAWN : (string)$r->grade;
            $r->points = $r->status === 'completed' && $r->grade !== null ? ($points[$r->grade] ?? null) : null;
            $r->passed = $r->status === 'completed' && $r->grade !== null ?
                self::result_passes($student, (int)$r->courseid, (string)$r->grade) : null;
            $byterm[$r->termid]['term'] = $r->termname;
            $byterm[$r->termid]['rows'][] = $r;
            if ($r->status !== 'dropped') {
                $all[] = $r;
            }
        }
        self::mark_repeats($all);
        $terms = [];
        foreach ($byterm as $termid => $t) {
            $terms[] = ['termid' => $termid, 'term' => $t['term'], 'rows' => $t['rows'], 'totals' => self::totals($t['rows'])];
        }
        // Transfer credit: attempted and earned; in the GPA only when marked so and the grade is on the scale.
        $transfers = [];
        foreach ($DB->get_records('local_studentrecords_xfer', ['studentid' => $student->id, 'active' => 1], 'awarded, id') as $x) {
            $x->hours = (float)$x->credits;
            $x->status = 'completed';
            $x->letter = (string)$x->grade !== '' ? (string)$x->grade : 'TR';
            $x->points = $x->ingpa && $x->grade !== null ? ($points[$x->grade] ?? null) : null;
            $x->passed = true;
            $transfers[] = $x;
        }
        return ['terms' => $terms, 'transfers' => $transfers, 'cumulative' => self::totals(array_merge($all, $transfers)),
            'transfertotals' => self::totals($transfers)];
    }
}
