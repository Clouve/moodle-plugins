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

namespace local_academictranscript\local\import;

/**
 * CSV templates and CSV import (the way to load data when no AI provider is set up). Imports become drafts.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class csv {
    /** @var array Template columns and example rows per type. */
    const TEMPLATES = [
        'grade' => [['letter', 'min', 'max', 'points', 'ingpa', 'earns', 'description'],
            ['A', '93', '100', '4.0', '1', '1', 'Excellent'], ['B', '83', '86', '3.0', '1', '1', 'Good'],
            ['F', '0', '59', '0.0', '1', '0', 'Failing']],
        'mark' => [['code', 'label', 'description', 'earns'],
            ['P', 'Pass', 'Passed; hours earned, no grade points', '1'], ['W', 'Withdrawn', 'Withdrew from the course', '0']],
        'program' => [['name', 'credential', 'unittype', 'totalhours'],
            ['Business Administration', 'Associate of Science', 'credit', '60'],
            ['Medical Assistant', 'Certificate', 'clock', '900']],
        'course' => [['code', 'title', 'hours', 'unittype', 'program'],
            ['BUS 101', 'Introduction to Business', '3', 'credit', 'Business Administration'],
            ['MA 110', 'Clinical Procedures I', '120', 'clock', 'Medical Assistant']],
    ];

    /**
     * Template CSV text for a type.
     *
     * @param string $type
     * @return string
     */
    public static function template(string $type): string {
        $fh = fopen('php://temp', 'w+');
        foreach (self::TEMPLATES[$type] as $row) {
            fputcsv($fh, $row, ',', '"', '');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /**
     * Parse CSV text into items (header names must match the template). Each item's 'source' is its line.
     *
     * @param string $type
     * @param string $content
     * @return array ['items' => list, 'errors' => list of strings]
     */
    public static function parse(string $type, string $content): array {
        $items = [];
        $errors = [];
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $header = null;
        foreach ($lines as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, ',', '"', '');
            if ($header === null) {
                $header = array_map(fn($h) => strtolower(trim($h)), $cells);
                $missing = array_diff(self::TEMPLATES[$type][0], $header);
                if ($missing) {
                    $errors[] = get_string('err_csvheader', 'local_academictranscript', implode(', ', $missing));
                    return ['items' => [], 'errors' => $errors];
                }
                continue;
            }
            $row = [];
            foreach ($header as $i => $name) {
                $row[$name] = $cells[$i] ?? '';
            }
            $row['source'] = $line;
            $item = ai_extractor::clean_item($type, $row);
            if ($item) {
                $items[] = $item;
            } else {
                $errors[] = get_string('err_csvline', 'local_academictranscript', $n + 1);
            }
        }
        return ['items' => $items, 'errors' => $errors];
    }
}
