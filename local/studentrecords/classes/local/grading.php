<?php

namespace local_studentrecords\local;

/**
 * Grading scale and pass rules (no database access; the callers pass the rows in).
 *
 * Nothing about a particular school is built in: the scale, the levels and the lowest passing grade for a
 * level, a program, a group of courses in a program or a single course all come from the school. The most
 * specific rule wins: course, then group, then program, then level.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class grading {
    /** @var string Letter grade pattern used when reading rules written in words. */
    const LETTER = '[A-F][+-]?';

    /**
     * Letters of the scale from best to worst (rows with grade points only).
     *
     * @param array $scale rows with letter, minpct, points
     * @return string[]
     */
    public static function ordered_letters(array $scale): array {
        $rows = array_values(array_filter(array_map(fn($r) => (array)$r, $scale), fn($r) =>
            isset($r['points']) && $r['points'] !== '' && $r['points'] !== null));
        usort($rows, fn($a, $b) => [(float)$b['points'], (float)($b['minpct'] ?? 0)] <=>
            [(float)$a['points'], (float)($a['minpct'] ?? 0)]);
        return array_map(fn($r) => (string)$r['letter'], $rows);
    }

    /**
     * Problems with a scale: duplicate letters, overlapping or missing percentage ranges, impossible values.
     *
     * @param array $scale rows with letter, minpct, maxpct, points
     * @return string[] language string keys with parameters, as [key, a]
     */
    public static function check_scale(array $scale): array {
        $problems = [];
        $seen = [];
        $ranges = [];
        foreach ($scale as $r) {
            $r = (array)$r;
            $letter = trim((string)$r['letter']);
            if (isset($seen[$letter])) {
                $problems[] = ['problem_duplicateletter', $letter];
            }
            $seen[$letter] = true;
            if (($r['minpct'] ?? '') === '' || ($r['minpct'] ?? null) === null) {
                continue;
            }
            $min = (float)$r['minpct'];
            $max = (float)$r['maxpct'];
            if ($min > $max || $min < 0 || $max > 100) {
                $problems[] = ['problem_badrange', $letter];
            }
            $ranges[] = [$min, $max, $letter];
        }
        usort($ranges, fn($a, $b) => $a[0] <=> $b[0]);
        for ($i = 1; $i < count($ranges); $i++) {
            [$min, , $letter] = $ranges[$i];
            [, $prevmax, $prevletter] = $ranges[$i - 1];
            if ($min <= $prevmax) {
                $problems[] = ['problem_overlap', $prevletter . ' / ' . $letter];
            } else if ($min - $prevmax > 1.0001) {
                $problems[] = ['problem_gap', $prevletter . ' / ' . $letter];
            }
        }
        return $problems;
    }

    /**
     * Lowest passing letter from a column of pass/fail words (for example "Pass (core needs C-)" and "Fail").
     *
     * @param string[] $letters best to worst
     * @param array $words letter => text
     * @return array [lowest passing letter or null, problem key or null]
     */
    public static function lowest_pass_from_words(array $letters, array $words): array {
        $lowest = null;
        $failed = false;
        foreach ($letters as $letter) {
            $text = strtolower(trim((string)($words[$letter] ?? '')));
            $pass = str_starts_with($text, 'pass') || in_array($text, ['yes', 'y', 'p']);
            $fail = str_starts_with($text, 'fail') || in_array($text, ['no', 'n', 'f']);
            if (!$pass && !$fail) {
                return [null, 'problem_passwords'];
            }
            if ($pass && $failed) {
                return [null, 'problem_passorder'];
            }
            if ($pass) {
                $lowest = $letter;
            } else {
                $failed = true;
            }
        }
        return [$lowest, null];
    }

    /**
     * A level-wide minimum written in words ("80% (B)", "C or better"). Null when the text does not state one.
     *
     * @param string $text
     * @return array|null ['minletter' => ?, 'minpct' => ?]
     */
    public static function level_minimum_from_text(string $text): ?array {
        $text = trim($text);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*%/', $text, $m)) {
            return ['minletter' => null, 'minpct' => (float)$m[1]];
        }
        if (preg_match('/\b(' . self::LETTER . ') or (?:better|higher)\b/', $text, $m)) {
            return ['minletter' => $m[1], 'minpct' => null];
        }
        return null;
    }

    /**
     * Turn a program's grade rules written in words into pass rules where the wording is clear.
     *
     * Each part (split at ";") becomes a rule for the program, a group of its courses or one course, or is
     * returned as a note for the admin to handle by hand. Advice ("Recommended B") is never made a rule.
     *
     * @param string $text for example "Core min C-; Gen Ed min D-; 2.0 GPA in business courses"
     * @param string[] $letters best to worst
     * @param string[] $groups group names used in the program outline
     * @param array $courses course code => title, for the program's courses
     * @return array ['rules' => list of [scope, group, course, minletter, minpct, text], 'notes' => string[]]
     */
    public static function parse_program_rules(string $text, array $letters, array $groups, array $courses): array {
        $rules = [];
        $notes = [];
        $l = self::LETTER;
        foreach (preg_split('/\s*;\s*/', trim($text)) as $part) {
            $part = trim($part, " .\t\n\r");
            if ($part === '') {
                continue;
            }
            $rule = null;
            if (preg_match('/^recommend/i', $part)) {
                $rule = null;
            } else if (preg_match("/^($l) or (?:better|higher) in (?:each|all|every) courses?$/i", $part, $m)) {
                $rule = ['program', '', '', strtoupper($m[1]), null];
            } else if (preg_match("/^($l) or (?:lower|below) fails$/i", $part, $m)) {
                $above = self::letter_above($letters, strtoupper($m[1]));
                $rule = $above ? ['program', '', '', $above, null] : null;
            } else if (preg_match("/^below ($l) (?:does not|doesn't) count/i", $part, $m)) {
                $rule = ['program', '', '', strtoupper($m[1]), null];
            } else if (preg_match('/^(.*?)\b([A-Z]{2,5}\s?\d{3,4}[A-Z]?)\s+needs\s+(\d+(?:\.\d+)?)\s*%/i', $part, $m)) {
                $code = self::normalise_code($m[2]);
                $rule = isset($courses[$code]) ? ['course', '', $code, null, (float)$m[3]] : null;
            } else if (preg_match("/^(.+?)\s+min(?:imum)?\s+($l)$/i", $part, $m)) {
                $group = self::match_group($m[1], $groups);
                $rule = $group !== null ? ['group', $group, '', strtoupper($m[2]), null] : null;
            } else if (preg_match("/^($l)(?: or (?:better|higher))? in (?:the )?(.+)$/i", $part, $m)) {
                $group = self::match_group($m[2], $groups);
                if ($group !== null) {
                    $rule = ['group', $group, '', strtoupper($m[1]), null];
                } else {
                    $matched = self::match_courses($m[2], $courses);
                    foreach ($matched as $code) {
                        $rules[] = ['scope' => 'course', 'group' => '', 'course' => $code, 'minletter' => strtoupper($m[1]),
                            'minpct' => null, 'text' => $part];
                    }
                    if ($matched) {
                        continue;
                    }
                }
            }
            if ($rule) {
                $rules[] = ['scope' => $rule[0], 'group' => $rule[1], 'course' => $rule[2], 'minletter' => $rule[3],
                    'minpct' => $rule[4], 'text' => $part];
            } else {
                $notes[] = $part;
            }
        }
        return ['rules' => $rules, 'notes' => $notes];
    }

    /**
     * The rule that applies (most specific first: course, group, program, level).
     *
     * @param array $rules rows with levelid, programid, groupname, courseid, minletter, minpct
     * @param int|null $levelid
     * @param int|null $programid
     * @param string[] $groupnames groups the course belongs to in that program
     * @param int|null $courseid
     * @return array|null the rule row
     */
    public static function effective_rule(array $rules, ?int $levelid, ?int $programid, array $groupnames, ?int $courseid): ?array {
        $best = null;
        $bestrank = 0;
        foreach ($rules as $r) {
            $r = (array)$r;
            $rank = 0;
            if (!empty($r['courseid'])) {
                $rank = ($courseid && (int)$r['courseid'] === $courseid &&
                    (empty($r['programid']) || (int)$r['programid'] === (int)$programid)) ? 4 : 0;
            } else if (!empty($r['programid']) && (string)($r['groupname'] ?? '') !== '') {
                $rank = ((int)$r['programid'] === (int)$programid && in_array($r['groupname'], $groupnames, true)) ? 3 : 0;
            } else if (!empty($r['programid'])) {
                $rank = (int)$r['programid'] === (int)$programid ? 2 : 0;
            } else if (!empty($r['levelid'])) {
                $rank = (int)$r['levelid'] === (int)$levelid ? 1 : 0;
            }
            if ($rank > $bestrank) {
                $best = $r;
                $bestrank = $rank;
            }
        }
        return $best;
    }

    /**
     * Whether a grade passes under a rule.
     *
     * @param array $scale scale rows
     * @param string $letter grade earned
     * @param array|null $rule with minletter or minpct; null = no rule known
     * @return bool|null null when it cannot be decided (no rule, or a letter not on the scale)
     */
    public static function passes(array $scale, string $letter, ?array $rule): ?bool {
        if (!$rule) {
            return null;
        }
        $letters = self::ordered_letters($scale);
        $pos = array_search($letter, $letters, true);
        if ($pos === false) {
            return null;
        }
        if (!empty($rule['minletter'])) {
            $min = array_search($rule['minletter'], $letters, true);
            return $min === false ? null : $pos <= $min;
        }
        if (isset($rule['minpct']) && $rule['minpct'] !== null && $rule['minpct'] !== '') {
            foreach ($scale as $row) {
                $row = (array)$row;
                if ($row['letter'] === $letter) {
                    return (float)$row['minpct'] >= (float)$rule['minpct'];
                }
            }
        }
        return null;
    }

    /**
     * Letter of a percentage on the scale: the best letter whose lowest percentage the grade reaches.
     *
     * With rounding on, the percentage first becomes a whole number and a half rounds up, so 93.5 reaches 94. With
     * rounding off, a grade in a gap between two ranges, such as 93.5 between 90-93 and 94-100, gets the lower letter.
     * This is the rule of Academic Transcript's calculator::letter(), so the two give the same letter on the same scale.
     *
     * @param array $scale rows with letter, minpct, points
     * @param float $pct
     * @param bool $round round to a whole percent first (catalog::round_percent() says what the school does)
     * @return string|null
     */
    public static function letter_for_pct(array $scale, float $pct, bool $round = true): ?string {
        $rows = array_values(array_filter(array_map(fn($r) => (array)$r, $scale), fn($r) =>
            isset($r['minpct']) && $r['minpct'] !== null && $r['minpct'] !== ''));
        usort($rows, fn($a, $b) => (float)$b['minpct'] <=> (float)$a['minpct']);
        if ($round) {
            // A course total can arrive as 89.499999999999986 for 89.5, so drop that float error before rounding.
            $pct = round(round($pct, 10));
        }
        foreach ($rows as $r) {
            if ($pct >= (float)$r['minpct'] - 0.00001) {
                return (string)$r['letter'];
            }
        }
        return null;
    }

    /**
     * Whole-number division rounded half away from zero. Official figures are worked out with this and not with round(),
     * because PHP 8.4 rounds a float that sits just below a half down where older versions round it up.
     *
     * @param int $number
     * @param int $divisor greater than 0
     * @return int
     */
    public static function divide_rounded(int $number, int $divisor): int {
        $quotient = intdiv(2 * abs($number) + $divisor, 2 * $divisor);
        return $number < 0 ? -$quotient : $quotient;
    }

    /**
     * The letter one step better than the given one, or null.
     *
     * @param string[] $letters best to worst
     * @param string $letter
     * @return string|null
     */
    public static function letter_above(array $letters, string $letter): ?string {
        $pos = array_search($letter, $letters, true);
        return ($pos === false || $pos === 0) ? null : $letters[$pos - 1];
    }

    /**
     * Course code in one form (upper case, no inner spaces).
     *
     * @param string $code
     * @return string
     */
    public static function normalise_code(string $code): string {
        return strtoupper(preg_replace('/\s+/', '', trim($code)));
    }

    /**
     * Group of the program that a phrase such as "Core" or "Gen Ed" refers to.
     *
     * @param string $phrase
     * @param string[] $groups
     * @return string|null the group name, or null when none or more than one match
     */
    protected static function match_group(string $phrase, array $groups): ?string {
        $phrase = self::simplify($phrase);
        if ($phrase === '') {
            return null;
        }
        $hits = [];
        foreach ($groups as $group) {
            $g = self::simplify($group);
            if ($g !== '' && (str_contains(' ' . $g . ' ', ' ' . $phrase . ' ') || $g === $phrase)) {
                $hits[] = $group;
            }
        }
        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * Courses whose title contains a phrase ("dissertation seminar", "thesis courses").
     *
     * @param string $phrase
     * @param array $courses code => title
     * @return string[] codes
     */
    protected static function match_courses(string $phrase, array $courses): array {
        $phrase = self::simplify($phrase);
        if ($phrase === '') {
            return [];
        }
        $out = [];
        foreach ($courses as $code => $title) {
            if (str_contains(' ' . self::simplify($title) . ' ', ' ' . $phrase . ' ')) {
                $out[] = $code;
            }
        }
        return $out;
    }

    /**
     * Lower case words without "course(s)", numbers in brackets and punctuation; "gen ed" means general education.
     *
     * @param string $text
     * @return string
     */
    protected static function simplify(string $text): string {
        $text = strtolower($text);
        $text = preg_replace('/\([^)]*\)/', ' ', $text);
        $text = preg_replace('/\bgen\.?\s*ed\.?\b/', 'general education', $text);
        $text = preg_replace('/\bcourses?\b/', ' ', $text);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
