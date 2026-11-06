<?php

namespace local_studentrecords\local;

/**
 * Enrollment verification letters: the facts are taken from the record when the letter is issued and kept with it
 * (letter number, recipient, who, when), so a reprint shows what was released. PDF with the school's logo, details
 * and signer (setup wizard: school, registrar name and title).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class letters {
    /** @var string Table. */
    const TABLE = 'local_studentrecords_letter';

    /**
     * The term a letter is about: the one running today, else the next one the student is registered in, else the
     * last one.
     *
     * @param array $terms rows with id, startdate, enddate (registered terms of the student)
     * @param int $now
     * @return int|null term id
     */
    public static function pick_term(array $terms, int $now): ?int {
        $next = null;
        $last = null;
        foreach ($terms as $t) {
            $t = (array)$t;
            if ((int)$t['startdate'] <= $now && (int)$t['enddate'] >= $now) {
                return (int)$t['id'];
            }
            if ((int)$t['startdate'] > $now && ($next === null || (int)$t['startdate'] < $next[1])) {
                $next = [(int)$t['id'], (int)$t['startdate']];
            }
            if ((int)$t['enddate'] < $now && ($last === null || (int)$t['enddate'] > $last[1])) {
                $last = [(int)$t['id'], (int)$t['enddate']];
            }
        }
        return $next[0] ?? $last[0] ?? null;
    }

    /**
     * Facts for a letter, from the record now.
     *
     * @param \stdClass $student
     * @return array
     */
    public static function facts(\stdClass $student): array {
        global $DB;
        $p = 'local_studentrecords';
        $history = registration::history($student);
        $terms = [];
        foreach ($history['terms'] as $t) {
            $terms[] = $DB->get_record('local_studentrecords_term', ['id' => $t['termid']], 'id, name, startdate, enddate');
        }
        $termid = self::pick_term($terms, time());
        $term = null;
        $hours = 0.0;
        foreach ($history['terms'] as $t) {
            if ((int)$t['termid'] === $termid) {
                $term = $DB->get_record('local_studentrecords_term', ['id' => $termid]);
                foreach ($t['rows'] as $r) {
                    $hours += in_array($r->status, registration::ACTIVE, true) ? (float)$r->hours : 0;
                }
            }
        }
        $program = $student->programid ? $DB->get_record('local_studentrecords_program', ['id' => $student->programid]) : null;
        $admit = $student->admittermid ? $DB->get_field('local_studentrecords_term', 'name', ['id' => $student->admittermid]) : '';
        return [
            'name' => trim($student->legalfirstname . ' ' . ($student->legalmiddlename ? $student->legalmiddlename . ' ' : '') .
                $student->legallastname),
            'studentid' => (string)$student->studentid,
            'dob' => $student->dob ? catalog::show_date((int)$student->dob) : '',
            'program' => $program ? $program->code . ' - ' . reports::plain($program->name) : '',
            'credential' => $program ? (string)$program->credential : '',
            'status' => get_string('status_' . $student->status, $p),
            'load' => $student->loadtype ? get_string('load_' . $student->loadtype, $p) : '',
            'admitterm' => reports::plain((string)$admit),
            'termid' => $termid,
            'term' => $term ? reports::plain($term->name) : '',
            'termdates' => $term ? catalog::show_date((int)$term->startdate) . ' - ' . catalog::show_date((int)$term->enddate) : '',
            'hours' => $hours,
            'unit' => $program ? get_string('unit_' . $program->unittype, $p) : '',
            'award' => (string)$student->awardname,
            'awarddate' => $student->awarddate ? catalog::show_date((int)$student->awarddate) : '',
        ];
    }

    /**
     * Issue a letter: number, recipient and facts are kept; the audit log notes it.
     *
     * @param \stdClass $student
     * @param string $recipient to whom it is addressed (empty = to whom it may concern)
     * @return int letter id
     */
    public static function issue(\stdClass $student, string $recipient): int {
        global $DB, $USER;
        $facts = self::facts($student);
        $year = userdate(time(), '%Y', catalog::timezone(), false);
        $seq = $DB->count_records_select(self::TABLE, $DB->sql_like('letterno', '?'), ['L' . $year . '-%']) + 1;
        do {
            $no = 'L' . $year . '-' . str_pad((string)$seq++, 5, '0', STR_PAD_LEFT);
        } while ($DB->record_exists(self::TABLE, ['letterno' => $no]));
        $id = (int)$DB->insert_record(self::TABLE, (object)['studentid' => $student->id, 'letterno' => $no,
            'recipient' => \core_text::substr(trim($recipient), 0, 255), 'termid' => $facts['termid'],
            'content' => json_encode($facts), 'issuedby' => $USER->id, 'timeissued' => time()]);
        audit::log('export', 'letter', $id, (int)$student->userid, $no . ($recipient !== '' ? ' to ' . trim($recipient) : ''));
        return $id;
    }

    /**
     * Letters issued for a student, newest first.
     *
     * @param int $studentid
     * @return array
     */
    public static function issued(int $studentid): array {
        global $DB;
        return array_values($DB->get_records(self::TABLE, ['studentid' => $studentid], 'timeissued DESC, id DESC'));
    }

    /**
     * The letter as a PDF (string), from the facts kept when it was issued.
     *
     * @param \stdClass $letter
     * @return string PDF content
     */
    public static function pdf(\stdClass $letter): string {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');
        $p = 'local_studentrecords';
        $f = json_decode($letter->content, true);
        $pdf = new \pdf('P', 'mm', 'LETTER');
        $pdf->SetCreator(get_string('pluginname', $p));
        $pdf->SetTitle(get_string('enrollmentletter', $p) . ' ' . $letter->letterno);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(22, 20, 22);
        $pdf->AddPage();
        $logo = self::logo_path();
        if ($logo) {
            $pdf->Image($logo, 22, 16, 0, 18);
            $pdf->SetY(38);
        }
        $esc = fn($v) => s((string)$v);
        $school = catalog::setting('schoolname');
        $head = '<h2>' . $esc($school) . '</h2><div>' . nl2br($esc(catalog::setting('address'))) . '</div><div>' .
            $esc(trim(catalog::setting('phone') . '  ' . catalog::setting('email') . '  ' . catalog::setting('website'))) .
            '</div>';
        $pdf->writeHTML($head, true, false, true, false, '');
        $pdf->Ln(6);
        $date = userdate($letter->timeissued, get_string('strftimedate', 'langconfig'), catalog::timezone());
        $body = '<p>' . $esc($date) . '<br>' . get_string('letterno', $p) . ': ' . $esc($letter->letterno) . '</p>';
        $body .= '<p>' . ($letter->recipient !== '' ? $esc($letter->recipient) : get_string('letter_towhom', $p)) . '</p>';
        $body .= '<h3>' . get_string('enrollmentletter', $p) . '</h3>';
        $body .= '<p>' . get_string('letter_intro', $p, (object)['school' => $esc($school), 'name' => $esc($f['name'])]) . '</p>';
        $rows = [
            get_string('studentid', $p) => $f['studentid'], get_string('dob', $p) => $f['dob'],
            get_string('program', $p) => $f['program'], get_string('prog_credential', $p) => $f['credential'],
            get_string('admitterm', $p) => $f['admitterm'], get_string('status', $p) => $f['status'],
            get_string('loadtype', $p) => $f['load'],
        ];
        if ($f['term'] !== '') {
            $rows[get_string('term', $p)] = $f['term'] . ' (' . $f['termdates'] . ')';
            $rows[get_string('letter_hours', $p)] = format_float($f['hours'], -1) . ' ' . $f['unit'];
        }
        if ($f['award'] !== '') {
            $rows[get_string('award', $p)] = $f['award'] . ($f['awarddate'] !== '' ? ', ' . $f['awarddate'] : '');
        }
        $body .= '<table cellpadding="3">';
        foreach ($rows as $k => $v) {
            if ((string)$v !== '') {
                $body .= '<tr><td width="40%"><b>' . $esc($k) . '</b></td><td width="60%">' . $esc($v) . '</td></tr>';
            }
        }
        $body .= '</table><p>' . get_string('letter_closing', $p) . '</p><p><br><br>______________________________<br>' .
            $esc(catalog::setting('registrarname')) . '<br>' . $esc(catalog::setting('registrartitle') ?: get_string(
                'letter_registrar',
                $p
            )) . '<br>' . $esc($school) . '</p>';
        $pdf->writeHTML($body, true, false, true, false, '');
        return $pdf->Output($letter->letterno . '.pdf', 'S');
    }

    /**
     * The school logo as a temporary file path, or null.
     *
     * @return string|null
     */
    public static function logo_path(): ?string {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_studentrecords',
            'logo',
            0,
            'filename',
            false
        );
        $file = reset($files);
        if (!$file) {
            return null;
        }
        $path = make_request_directory() . '/' . clean_filename($file->get_filename());
        $file->copy_content_to($path);
        return $path;
    }
}
