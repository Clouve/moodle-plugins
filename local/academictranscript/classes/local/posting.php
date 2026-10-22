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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/gradelib.php');

/**
 * Posting final grades, locking the course total, and approved changes to posted grades.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class posting {
    /**
     * What would be posted for a student now: the hand-entered code, else the letter from the course total.
     *
     * @param int $courseid
     * @param int $userid
     * @return array ['grade' => string|null, 'percent' => float|null, 'note' => string, 'termid' => int, 'error' => string]
     */
    public static function preview(int $courseid, int $userid): array {
        global $DB;
        $fields = coursefields::get_resolved([$courseid])[$courseid];
        $coderow = $DB->get_record('local_academictranscript_code', ['userid' => $userid, 'courseid' => $courseid]);
        $termid = terms::effective($userid, $courseid, $fields['termindex'], $coderow ?: null);
        $result = ['grade' => null, 'percent' => null, 'note' => $coderow ? trim((string)$coderow->note) : '',
            'termid' => $termid, 'error' => '', 'posted' => null];
        if ($fields['code'] === '') {
            $result['error'] = get_string('missingcode', 'local_academictranscript');
        } else if ($fields['hours'] === null) {
            $result['error'] = get_string('missinghours', 'local_academictranscript');
        } else if (!$termid) {
            $result['error'] = get_string('missingterm', 'local_academictranscript');
        }
        if ($termid) {
            $result['posted'] = $DB->get_record(
                'local_academictranscript_posted',
                ['userid' => $userid, 'courseid' => $courseid, 'termid' => $termid]
            ) ?: null;
        }
        if ($coderow && isset(scale::teacher_marks()[(string)$coderow->code])) {
            $result['grade'] = $coderow->code;
        } else {
            $percent = self::course_percent($courseid, $userid);
            if ($percent !== null) {
                $result['percent'] = $percent;
                $result['grade'] = scale::letter($percent);
                if ($result['grade'] === null && !$result['error']) {
                    $result['error'] = get_string('noscalematch', 'local_academictranscript');
                }
            }
        }
        if (!$result['error'] && $result['grade'] === null) {
            $result['error'] = get_string('nogradetopost', 'local_academictranscript');
        }
        return $result;
    }

    /**
     * Course total as a percentage, or null.
     *
     * @param int $courseid
     * @param int $userid
     * @return float|null
     */
    public static function course_percent(int $courseid, int $userid): ?float {
        $item = \grade_item::fetch_course_item($courseid);
        if (!$item || $item->grademax <= $item->grademin) {
            return null;
        }
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
        if (!$grade || $grade->finalgrade === null) {
            return null;
        }
        return \grade_grade::standardise_score((float)$grade->finalgrade, (float)$item->grademin, (float)$item->grademax, 0, 100);
    }

    /**
     * Post the final grade for one student in one course (for the term the student is taking it in).
     * Locks the student's course total in the gradebook.
     *
     * @param int $courseid
     * @param int $userid
     * @return \stdClass posted record
     */
    public static function post(int $courseid, int $userid): \stdClass {
        global $DB, $USER;
        $preview = self::preview($courseid, $userid);
        if ($preview['error']) {
            throw new \moodle_exception('cannotpost', 'local_academictranscript', '', $preview['error']);
        }
        if ($preview['posted']) {
            throw new \moodle_exception('alreadyposted', 'local_academictranscript');
        }
        $course = get_course($courseid);
        $fields = coursefields::get_resolved([$courseid])[$courseid];
        $now = time();
        $record = (object)[
            'userid' => $userid,
            'courseid' => $courseid,
            'termid' => $preview['termid'],
            'coursecode' => $fields['code'],
            'coursename' => self::title($course, $fields['code']),
            'hours' => $fields['hours'],
            'unittype' => $fields['unittype'] !== '' ? $fields['unittype'] : programs::CREDIT,
            'percent' => $preview['percent'],
            'grade' => $preview['grade'],
            'note' => \core_text::substr($preview['note'], 0, 255),
            'postedby' => $USER->id,
            'timeposted' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_academictranscript_posted', $record);

        // The hand-entered code belongs to this attempt: clear it (row kept) so a later attempt starts clean.
        if ($coderow = $DB->get_record('local_academictranscript_code', ['userid' => $userid, 'courseid' => $courseid])) {
            $DB->update_record('local_academictranscript_code', (object)['id' => $coderow->id, 'code' => null, 'note' => '',
                'reason' => get_string('clearedonpost', 'local_academictranscript'), 'usermodified' => $USER->id,
                'timemodified' => $now]);
        }

        self::lock_course_total($courseid, $userid);
        logger::log('post', $userid, 0, "course $courseid term {$record->termid} grade {$record->grade}");
        return $record;
    }

    /**
     * Lock the student's course total in the gradebook (if there is one).
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    public static function lock_course_total(int $courseid, int $userid): bool {
        $item = \grade_item::fetch_course_item($courseid);
        if (!$item) {
            return false;
        }
        if ($item->needsupdate) {
            grade_regrade_final_grades($courseid);
            $item = \grade_item::fetch_course_item($courseid);
        }
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
        if (!$grade || $grade->is_locked()) {
            return false;
        }
        $grade->grade_item = $item;
        return (bool)$grade->set_locked(true);
    }

    /**
     * Course title as plain text, without a leading "CODE - ".
     *
     * The title is stored as it is and escaped where it is shown, so it is not HTML-escaped here: an ampersand stays "&".
     *
     * @param \stdClass $course
     * @param string $code
     * @return string
     */
    public static function title(\stdClass $course, string $code): string {
        $name = text::plain($course->fullname, \context_course::instance($course->id));
        if ($code !== '' && stripos($name, $code) === 0) {
            // Cut the separator by character, not by byte: a byte mask would also take the first bytes of a name that
            // starts with a curly quote (the bytes of a dash and of a curly quote begin the same). The separator is
            // white space, a hyphen, an en dash, an em dash or a colon, as many as there are in a row; \s under /u also
            // matches a non-breaking space and the other Unicode spaces.
            $rest = preg_replace('/^[\s\-\x{2013}\x{2014}:]+/u', '', substr($name, strlen($code)));
            if ($rest !== null && $rest !== '') {
                return $rest;
            }
        }
        return $name;
    }

    /**
     * Request a change to a posted grade (needs admin approval).
     *
     * @param int $postedid
     * @param string $newgrade
     * @param string $newnote
     * @param string $reason
     * @return int change id
     */
    public static function request_change(int $postedid, string $newgrade, string $newnote, string $reason): int {
        global $DB, $USER;
        $posted = $DB->get_record('local_academictranscript_posted', ['id' => $postedid], '*', MUST_EXIST);
        if (!isset(scale::grade_menu()[$newgrade])) {
            throw new \moodle_exception('invalidgrade', 'local_academictranscript');
        }
        if (trim($reason) === '') {
            throw new \moodle_exception('reasonmissing', 'local_academictranscript');
        }
        if ($DB->record_exists('local_academictranscript_change', ['postedid' => $postedid, 'status' => 'pending'])) {
            throw new \moodle_exception('changepending', 'local_academictranscript');
        }
        $id = $DB->insert_record('local_academictranscript_change', (object)[
            'postedid' => $postedid,
            'oldgrade' => $posted->grade,
            'newgrade' => $newgrade,
            'oldnote' => (string)$posted->note,
            'newnote' => \core_text::substr(trim($newnote), 0, 255),
            'reason' => \core_text::substr(trim($reason), 0, 255),
            'status' => 'pending',
            'requestedby' => $USER->id,
            'timerequested' => time(),
        ]);
        logger::log('change_request', $posted->userid, 0, "change $id posted $postedid {$posted->grade} -> $newgrade");
        return $id;
    }

    /**
     * Approve or reject a change request.
     *
     * @param int $changeid
     * @param bool $approve
     * @param string $decisionnote
     */
    public static function decide(int $changeid, bool $approve, string $decisionnote): void {
        global $DB, $USER;
        $change = $DB->get_record('local_academictranscript_change', ['id' => $changeid, 'status' => 'pending'], '*', MUST_EXIST);
        $posted = $DB->get_record('local_academictranscript_posted', ['id' => $change->postedid], '*', MUST_EXIST);
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        if ($approve) {
            $update = (object)['id' => $posted->id, 'grade' => $change->newgrade, 'note' => $change->newnote,
                'timemodified' => $now];
            if ($change->newgrade !== $posted->grade) {
                // A hand-decided letter or code no longer matches the stored course total percentage.
                $update->percent = null;
            }
            $DB->update_record('local_academictranscript_posted', $update);
        }
        $DB->update_record('local_academictranscript_change', (object)['id' => $change->id,
            'status' => $approve ? 'approved' : 'rejected', 'decidedby' => $USER->id, 'timedecided' => $now,
            'decisionnote' => \core_text::substr(trim($decisionnote), 0, 255)]);
        $transaction->allow_commit();
        logger::log(
            $approve ? 'change_approve' : 'change_reject',
            $posted->userid,
            0,
            "change {$change->id} posted {$posted->id} {$change->oldgrade} -> {$change->newgrade}"
        );
    }
}
