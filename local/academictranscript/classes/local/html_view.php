<?php

namespace local_academictranscript\local;

/**
 * HTML for the transcript pages.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class html_view {
    /**
     * Whole transcript: student details, transfer credit, terms, credit summary.
     *
     * @param array $data
     * @param bool $showdob
     * @return string
     */
    public static function transcript(array $data, bool $showdob = true): string {
        return self::student_table($data, $showdob) . self::courses($data) . self::summary($data);
    }

    /**
     * Student details.
     *
     * @param array $data
     * @param bool $showdob
     * @return string
     */
    public static function student_table(array $data, bool $showdob = true): string {
        $rows = [
            'studentname' => $data['fullname'],
            'studentid' => $data['studentid'],
            'dob' => transcript::date($data['dob']),
            'admitterm' => $data['admitterm'],
            'program' => $data['program'],
            'status' => $data['status'],
            'calendar' => $data['unitlabel'],
            'qualification' => $data['qualification'],
            'awarddate' => transcript::date($data['awarddate']),
            'lastentry' => transcript::date($data['lastentry']),
        ];
        if (!$showdob) {
            unset($rows['dob']);
        }
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm w-auto';
        foreach ($rows as $key => $value) {
            $table->data[] = [\html_writer::tag('strong', get_string($key, 'local_academictranscript')), s($value)];
        }
        return \html_writer::table($table);
    }

    /**
     * Transfer credit and term tables.
     *
     * @param array $data
     * @return string
     */
    public static function courses(array $data): string {
        if (!$data['terms'] && !$data['transfer']) {
            return \html_writer::div(get_string('nocourses', 'local_academictranscript'), 'alert alert-info');
        }
        $out = '';
        foreach ($data['transfer'] as $group) {
            $out .= self::block(
                get_string('transfercredit', 'local_academictranscript') . ': ' . $group['institution'],
                '',
                $group['rows'],
                $data['unitshort'],
                get_string('transferaccepted', 'local_academictranscript') . ' · ' .
                get_string('earnedx', 'local_academictranscript', scale::number($group['earned']))
            );
        }
        foreach ($data['terms'] as $term) {
            $range = $term['startdate'] ? transcript::shortdate($term['startdate']) . ' - ' .
                transcript::shortdate($term['enddate']) : '';
            $out .= self::block(
                $term['name'],
                $range,
                $term['rows'],
                $data['unitshort'],
                get_string('termtotals', 'local_academictranscript', ['earned' => scale::number($term['earned']),
                    'hours' => scale::number($term['gpahours']), 'qp' => number_format($term['qp'], 2)]) . ' · ' .
                get_string('termgpa', 'local_academictranscript', $term['gpa'] === '' ? '-' : $term['gpa'])
            );
        }
        return $out;
    }

    /**
     * One block (term or transfer institution).
     *
     * @param string $title
     * @param string $range
     * @param array $rows
     * @param string $unitshort column heading for hours
     * @param string $totals
     * @return string
     */
    protected static function block(string $title, string $range, array $rows, string $unitshort, string $totals): string {
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = [];
        foreach (['col_course', 'col_title', 'unit', 'col_grd', 'col_qp'] as $key) {
            $table->head[] = $key === 'unit' ? s($unitshort) : get_string($key, 'local_academictranscript');
        }
        foreach ($rows as $row) {
            $title2 = s($row['title']);
            foreach ($row['notes'] as $note) {
                $title2 .= \html_writer::div(s($note), 'small fst-italic text-muted');
            }
            $table->data[] = [s($row['code']), $title2, scale::number($row['hours']), s($row['grade']),
                $row['qp'] === null ? '-' : number_format($row['qp'], 2)];
        }
        return \html_writer::tag(
            'h5',
            s($title) . ($range !== '' ? ' <small class="text-muted">' . s($range) . '</small>' : ''),
            ['class' => 'mt-3']
        ) . \html_writer::table($table) . \html_writer::div(s($totals), 'small text-muted mb-2');
    }

    /**
     * Credit summary.
     *
     * @param array $data
     * @return string
     */
    public static function summary(array $data): string {
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm w-auto';
        $label = get_string($data['unittype'] === programs::CLOCK ? 'clocksummary' : 'creditsummary', 'local_academictranscript');
        $table->head = [$label, get_string('earned', 'local_academictranscript'),
            get_string('gpahours', 'local_academictranscript'), get_string('qualitypoints', 'local_academictranscript'),
            get_string('gpa', 'local_academictranscript')];
        foreach (['institutional', 'transfer', 'overall'] as $key) {
            $v = $data['summary'][$key];
            $table->data[] = [get_string('summary_' . $key, 'local_academictranscript'), scale::number($v['earned']),
                scale::number($v['gpahours']), number_format($v['qp'], 2),
                $key === 'transfer' || $v['gpa'] === '' ? '-' : $v['gpa']];
        }
        return \html_writer::tag('h5', $label, ['class' => 'mt-3'])
            . \html_writer::table($table);
    }

    /**
     * Grading scale and marks (the transcript key tables).
     *
     * @param array|null $rows rows of the key, or null for this plugin's scale
     * @return string
     */
    public static function scale_table(?array $rows = null): string {
        $html = '<table class="generaltable table-sm"><thead><tr>';
        foreach (['col_grade', 'col_pointsperunit', 'col_percentage', 'col_description'] as $key) {
            $html .= '<th>' . get_string($key, 'local_academictranscript') . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        $rows = $rows ?? scale::scale_rows();
        foreach ($rows as $row) {
            if ($row['ismark']) {
                continue;
            }
            $html .= '<tr><td>' . s($row['grade']) . '</td><td>' . s($row['points']) . '</td><td>' . s($row['percent']) . '</td>';
            if ($row['rowspan']) {
                $html .= '<td rowspan="' . $row['rowspan'] . '">' . s($row['description']) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table><table class="generaltable table-sm"><tbody>';
        foreach ($rows as $row) {
            if ($row['ismark']) {
                $html .= '<tr><td><strong>' . s($row['grade']) . '</strong></td><td>' . s($row['description']) . '</td></tr>';
            }
        }
        return $html . '</tbody></table>';
    }
}
