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
 * Pure grade arithmetic: letter from percent, grade points, hours earned, GPA, repeat rules.
 *
 * No database or Moodle API calls, so it can be unit tested directly. Works the same for
 * credit hours and clock hours: quality points = hours x grade points; GPA = quality points / GPA hours.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calculator {
    /** @var string Only the latest attempt of a repeated course counts. */
    const REPEAT_LATEST = 'latest';
    /** @var string Only the attempt with the highest grade points counts. */
    const REPEAT_HIGHEST = 'highest';
    /** @var string Every attempt counts. */
    const REPEAT_ALL = 'all';

    /** @var array Grade rows: letter => ['min' => float, 'max' => float, 'points' => float, 'ingpa' => bool, 'earns' => bool]. */
    protected $grades = [];

    /** @var array Marks: code => ['earns' => bool]. */
    protected $marks = [];

    /** @var string Repeat rule. */
    protected $repeatrule;

    /** @var bool Round the percentage to a whole number before choosing the letter. */
    protected $round;

    /**
     * Constructor.
     *
     * @param array $grades list of ['letter', 'min', 'max', 'points', 'ingpa', 'earns'] (arrays or objects)
     * @param array $marks list of ['code', 'earns'] (arrays or objects)
     * @param string $repeatrule latest, highest or all
     * @param bool $round
     */
    public function __construct(array $grades, array $marks, string $repeatrule = self::REPEAT_LATEST, bool $round = true) {
        foreach ($grades as $g) {
            $g = (array)$g;
            $this->grades[(string)$g['letter']] = [
                'min' => (float)$g['min'],
                'max' => (float)$g['max'],
                'points' => (float)$g['points'],
                'ingpa' => !empty($g['ingpa']),
                'earns' => !empty($g['earns']),
            ];
        }
        // Highest minimum first, so the first match wins.
        uasort($this->grades, fn($a, $b) => $b['min'] <=> $a['min']);
        foreach ($marks as $m) {
            $m = (array)$m;
            $this->marks[(string)$m['code']] = ['earns' => !empty($m['earns'])];
        }
        $this->repeatrule = in_array($repeatrule, [self::REPEAT_LATEST, self::REPEAT_HIGHEST, self::REPEAT_ALL]) ?
            $repeatrule : self::REPEAT_LATEST;
        $this->round = $round;
    }

    /**
     * Letter for a percentage: the row with the highest minimum not above the percentage.
     *
     * With rounding on, the percentage is rounded to a whole number first, and a half rounds up. A float error in the
     * last digits of the percentage does not change that, so every PHP version gives the same letter for any course
     * whose total has a range below 99,900 points.
     *
     * @param float $percent
     * @return string|null null when no row matches
     */
    public function letter(float $percent): ?string {
        if ($this->round) {
            // The course total can arrive as 89.499999999999986 for 89.5, so drop that float error before rounding.
            // This holds for any course whose total has a range below 99,900 points.
            $percent = round(round($percent, 10));
        }
        foreach ($this->grades as $letter => $g) {
            if ($percent >= $g['min'] - 0.00001) {
                return $letter;
            }
        }
        return null;
    }

    /**
     * Whether a grade is a letter of the scale.
     *
     * @param string $grade
     * @return bool
     */
    public function is_letter(string $grade): bool {
        return isset($this->grades[$grade]);
    }

    /**
     * Grade points for a letter, null for marks and unknown grades.
     *
     * @param string $grade
     * @return float|null
     */
    public function points(string $grade): ?float {
        return isset($this->grades[$grade]) ? $this->grades[$grade]['points'] : null;
    }

    /**
     * Whether a grade counts in the GPA.
     *
     * @param string $grade
     * @return bool
     */
    public function in_gpa(string $grade): bool {
        return isset($this->grades[$grade]) && $this->grades[$grade]['ingpa'];
    }

    /**
     * Whether a grade earns the course hours.
     *
     * @param string $grade
     * @return bool
     */
    public function earns(string $grade): bool {
        if (isset($this->grades[$grade])) {
            return $this->grades[$grade]['earns'];
        }
        return isset($this->marks[$grade]) && $this->marks[$grade]['earns'];
    }

    /**
     * GPA as a string with 2 decimals, '' when there are no GPA hours.
     *
     * Quality points are used to the ten-thousandth (two decimals of grade points times two decimals of hours) and hours
     * to the hundredth, so hours below half a hundredth count as none. For hours with at most two decimals and quality
     * points with at most four, as the plugin stores them, the arithmetic is exact, a GPA halfway between two hundredths
     * rounds up, and every PHP version gives the same result.
     *
     * @param float $qp
     * @param float $hours
     * @return string
     */
    public static function gpa(float $qp, float $hours): string {
        return self::gpa_from_whole((int)round($qp * 10000), (int)round($hours * 100));
    }

    /**
     * Work out every attempt and the totals.
     *
     * Each attempt: ['code' => course code, 'group' => term key, 'order' => term order (int),
     * 'time' => int, 'hours' => float, 'grade' => string]. Attempts of the same code (case-insensitive)
     * are repeats. Attempts that a repeat rule leaves out keep their row but are 'excluded'.
     *
     * Grade points and hours are used to the hundredth, as the database keeps them, and the arithmetic is exact. The
     * quality points of an attempt, of a group and of the whole record are each rounded once to two decimals, and a value
     * halfway between two hundredths rounds up. The GPA of a group and of the whole record is worked out from the exact
     * quality points, so that rounding cannot change a GPA when the hours are fractions. Every PHP version gives the same
     * result.
     *
     * @param array $attempts
     * @return array ['attempts' => annotated attempts (same keys), 'groups' => group => totals, 'total' => totals]
     *               totals: ['earned' => float, 'gpahours' => float, 'qp' => float, 'gpa' => string]
     */
    public function compute(array $attempts): array {
        // Repeats.
        $excluded = [];
        if ($this->repeatrule !== self::REPEAT_ALL) {
            $bycode = [];
            foreach ($attempts as $i => $a) {
                $grade = (string)$a['grade'];
                if (trim((string)$a['code']) !== '' && ($this->is_letter($grade) || $this->earns($grade))) {
                    $bycode[strtoupper(trim((string)$a['code']))][] = $i;
                }
            }
            foreach ($bycode as $indexes) {
                if (count($indexes) < 2) {
                    continue;
                }
                $keep = null;
                foreach ($indexes as $i) {
                    if ($keep === null || $this->better($attempts[$i], $attempts[$keep])) {
                        $keep = $i;
                    }
                }
                foreach ($indexes as $i) {
                    if ($i !== $keep) {
                        $excluded[$i] = true;
                    }
                }
            }
        }

        // The sums are whole numbers: hours in hundredths and quality points in ten-thousandths, so they are exact.
        $groups = [];
        $total = ['earned' => 0, 'gpahours' => 0, 'qp' => 0];
        foreach ($attempts as $i => $a) {
            $grade = (string)$a['grade'];
            $hours = (int)round((float)$a['hours'] * 100);
            $points = $this->points($grade);
            $qp = $points === null ? null : (int)round($points * 100) * $hours;
            $isexcluded = !empty($excluded[$i]);
            $ingpa = $this->in_gpa($grade) && !$isexcluded;
            $earned = $this->earns($grade) && !$isexcluded;
            $attempts[$i]['points'] = $points;
            $attempts[$i]['qp'] = $qp === null ? null : self::divide_rounded($qp, 100) / 100.0;
            $attempts[$i]['ingpa'] = $ingpa;
            $attempts[$i]['earned'] = $earned;
            $attempts[$i]['excluded'] = $isexcluded;
            $key = (string)($a['group'] ?? '');
            if (!isset($groups[$key])) {
                $groups[$key] = ['earned' => 0, 'gpahours' => 0, 'qp' => 0];
            }
            if ($earned) {
                $groups[$key]['earned'] += $hours;
                $total['earned'] += $hours;
            }
            if ($ingpa) {
                $groups[$key]['gpahours'] += $hours;
                $groups[$key]['qp'] += $qp;
                $total['gpahours'] += $hours;
                $total['qp'] += $qp;
            }
        }
        foreach ($groups as $key => $sums) {
            $groups[$key] = self::totals($sums);
        }
        return ['attempts' => $attempts, 'groups' => $groups, 'total' => self::totals($total)];
    }

    /**
     * Whether attempt $a should count instead of attempt $b under the repeat rule.
     *
     * @param array $a
     * @param array $b
     * @return bool
     */
    protected function better(array $a, array $b): bool {
        $later = [(int)($a['order'] ?? 0), (int)($a['time'] ?? 0)] > [(int)($b['order'] ?? 0), (int)($b['time'] ?? 0)];
        if ($this->repeatrule === self::REPEAT_HIGHEST) {
            // Letters by grade points; a mark that earns hours (e.g. P) ranks below every letter.
            $pa = $this->points((string)$a['grade']) ?? -1.0;
            $pb = $this->points((string)$b['grade']) ?? -1.0;
            if ($pa != $pb) {
                return $pa > $pb;
            }
        }
        return $later;
    }

    /**
     * The totals of a group or of the whole record, from sums in whole numbers.
     *
     * The quality points are rounded once, from the exact sum, and the GPA divides the exact sum.
     *
     * @param array $sums ['earned' => hours earned in hundredths, 'gpahours' => GPA hours in hundredths,
     *                     'qp' => quality points in ten-thousandths]
     * @return array ['earned' => float, 'gpahours' => float, 'qp' => float, 'gpa' => string]
     */
    private static function totals(array $sums): array {
        return [
            'earned' => $sums['earned'] / 100.0,
            'gpahours' => $sums['gpahours'] / 100.0,
            'qp' => self::divide_rounded($sums['qp'], 100) / 100.0,
            'gpa' => self::gpa_from_whole($sums['qp'], $sums['gpahours']),
        ];
    }

    /**
     * GPA as a string with 2 decimals from whole numbers, '' when there are no GPA hours.
     *
     * Quality points over hours, with the quality points in ten-thousandths and the hours in hundredths, is the GPA in
     * hundredths.
     *
     * @param int $qp Quality points in ten-thousandths
     * @param int $hours GPA hours in hundredths
     * @return string
     */
    private static function gpa_from_whole(int $qp, int $hours): string {
        if ($hours <= 0) {
            return '';
        }
        $gpa = self::divide_rounded($qp, $hours);
        $hundredths = abs($gpa);
        return sprintf('%s%d.%02d', $gpa < 0 ? '-' : '', intdiv($hundredths, 100), $hundredths % 100);
    }

    /**
     * Whole-number division rounded half away from zero, as round() does.
     *
     * @param int $number
     * @param int $divisor Greater than 0
     * @return int
     */
    private static function divide_rounded(int $number, int $divisor): int {
        $quotient = intdiv(2 * abs($number) + $divisor, 2 * $divisor);
        return $number < 0 ? -$quotient : $quotient;
    }
}
