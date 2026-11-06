<?php

namespace local_studentrecords\local;

/**
 * Date rules that need no database: dates picked as day / month / year, and the calculated last day to withdraw.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class dates {
    /** @var float Share of the term after which students can no longer withdraw, when the catalog has no rule. */
    const WITHDRAW_PERCENT = 60.0;

    /**
     * A date picked as day, month and year menus that start blank.
     *
     * @param mixed $day
     * @param mixed $month
     * @param mixed $year
     * @return string|null|false Y-m-d, null when all three are blank, false when only some are picked or the date
     *     does not exist
     */
    public static function parts_to_ymd($day, $month, $year) {
        $parts = [(string)$day, (string)$month, (string)$year];
        $blank = array_filter($parts, fn($v) => trim($v) === '');
        if (count($blank) === 3) {
            return null;
        }
        if ($blank || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
            return false;
        }
        [$d, $m, $y] = array_map('intval', $parts);
        if (!checkdate($m, $d, $y)) {
            return false;
        }
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /**
     * Day, month and year of a Y-m-d date (blank parts for no date).
     *
     * @param string $ymd
     * @return array [day, month, year] as ints, or '' each
     */
    public static function ymd_to_parts(string $ymd): array {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
            return ['', '', ''];
        }
        return [(int)$m[3], (int)$m[2], (int)$m[1]];
    }

    /**
     * The last day to withdraw: the day by which the given share of the term has passed, counting calendar days
     * from the first day to the last day of the term (both included), rounded to a whole day.
     *
     * @param string $startymd first day of the term, Y-m-d
     * @param string $endymd last day of the term, Y-m-d
     * @param float $percent share of the term, for example 60
     * @return string|null Y-m-d, or null when the dates are missing or the term ends before it starts
     */
    public static function withdraw_ymd(string $startymd, string $endymd, float $percent = self::WITHDRAW_PERCENT): ?string {
        $utc = new \DateTimeZone('UTC');
        $start = \DateTime::createFromFormat('!Y-m-d', $startymd, $utc);
        $end = \DateTime::createFromFormat('!Y-m-d', $endymd, $utc);
        if (!$start || !$end || $end < $start || $percent <= 0 || $percent > 100) {
            return null;
        }
        $days = (int)$start->diff($end)->days + 1;
        $offset = max(0, (int)round($days * $percent / 100) - 1);
        return $start->modify('+' . $offset . ' days')->format('Y-m-d');
    }

    /**
     * A percentage stated in a rule such as "Students may withdraw until 60% of the term" (first number with %).
     *
     * @param string $text
     * @return float|null
     */
    public static function percent_in_text(string $text): ?float {
        if (preg_match('/(\d{1,3}(?:\.\d+)?)\s*(?:%|percent)/i', $text, $m)) {
            $v = (float)$m[1];
            return $v > 0 && $v <= 100 ? $v : null;
        }
        return null;
    }
}
