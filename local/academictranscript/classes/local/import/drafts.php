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

use local_academictranscript\local\programs;
use local_academictranscript\local\scale;

/**
 * Drafts: items pulled from documents (AI) or CSV files. Nothing reaches the real settings until an
 * admin approves it.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class drafts {
    /** @var string[] Editable fields per item type. */
    const FIELDS = [
        'grade' => ['letter', 'minpct', 'maxpct', 'points', 'ingpa', 'earns', 'description'],
        'mark' => ['code', 'label', 'description', 'earns'],
        'program' => ['name', 'credential', 'unittype', 'totalhours'],
        'course' => ['code', 'title', 'hours', 'unittype', 'program'],
    ];

    /**
     * Compare text ignoring case, spacing and punctuation differences.
     *
     * @param string $text
     * @return string
     */
    public static function normalise(string $text): string {
        $text = \core_text::strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}%.]+/u', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Whether a quote appears in the document text.
     *
     * @param string $quote
     * @param string $doctext
     * @return bool
     */
    public static function quote_found(string $quote, string $doctext): bool {
        $q = self::normalise($quote);
        return $q !== '' && strpos(self::normalise($doctext), $q) !== false;
    }

    /**
     * Store items as drafts.
     *
     * @param array $itemsbytype type => items (each may have 'source')
     * @param string $source ai or csv
     * @param string $sourcename file name
     * @param int $fileid stored file id (0 for none)
     * @param string $doctext document text, to check quotes
     * @return int number of drafts stored
     */
    public static function store(array $itemsbytype, string $source, string $sourcename, int $fileid, string $doctext): int {
        global $DB, $USER;
        $count = 0;
        foreach ($itemsbytype as $type => $items) {
            if (!isset(self::FIELDS[$type])) {
                continue;
            }
            foreach ($items as $item) {
                $excerpt = (string)($item['source'] ?? '');
                unset($item['source']);
                $DB->insert_record('local_academictranscript_draft', (object)[
                    'itemtype' => $type, 'data' => json_encode($item), 'source' => $source,
                    'sourcename' => \core_text::substr($sourcename, 0, 255), 'sourcefileid' => $fileid ?: null,
                    'excerpt' => $excerpt, 'quotefound' => self::quote_found($excerpt, $doctext) ? 1 : 0,
                    'status' => 'draft', 'userid' => $USER->id, 'timecreated' => time(),
                ]);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Open drafts of a type.
     *
     * @param string $type
     * @return \stdClass[]
     */
    public static function open(string $type): array {
        global $DB;
        return $DB->get_records('local_academictranscript_draft', ['itemtype' => $type, 'status' => 'draft'], 'id');
    }

    /**
     * Count of open drafts by type.
     *
     * @return array type => count
     */
    public static function counts(): array {
        global $DB;
        $out = array_fill_keys(array_keys(self::FIELDS), 0);
        foreach (
            $DB->get_records_sql("SELECT itemtype, COUNT(1) AS n FROM {local_academictranscript_draft}
                                        WHERE status = 'draft' GROUP BY itemtype") as $r
        ) {
            $out[$r->itemtype] = (int)$r->n;
        }
        return $out;
    }

    /**
     * Approve drafts (with the admin's edits) and apply them. Grade rows replace the whole active scale.
     *
     * @param string $type
     * @param array $edited draftid => field values
     * @return string[] errors (nothing is saved when there are errors)
     */
    public static function approve(string $type, array $edited): array {
        global $DB, $USER;
        if (!$edited) {
            return [];
        }
        $items = [];
        foreach ($edited as $id => $values) {
            $draft = $DB->get_record(
                'local_academictranscript_draft',
                ['id' => $id, 'itemtype' => $type, 'status' => 'draft'],
                '*',
                MUST_EXIST
            );
            $item = json_decode($draft->data, true) ?: [];
            foreach (self::FIELDS[$type] as $field) {
                if (array_key_exists($field, $values)) {
                    $item[$field] = $values[$field];
                }
            }
            $items[$id] = $item;
        }
        if ($type === 'grade') {
            $errors = scale::validate_rows(array_values($items));
            if ($errors) {
                return $errors;
            }
        }
        $transaction = $DB->start_delegated_transaction();
        if ($type === 'grade') {
            scale::replace_grades(array_values($items));
        } else if ($type === 'mark') {
            scale::save_marks(array_values($items));
        } else {
            foreach ($items as $item) {
                if ($type === 'program') {
                    programs::save($item);
                } else {
                    programs::save_course($item);
                }
            }
        }
        foreach ($items as $id => $item) {
            $DB->update_record('local_academictranscript_draft', (object)['id' => $id, 'status' => 'approved',
                'data' => json_encode($item), 'decidedby' => $USER->id, 'timedecided' => time()]);
        }
        $transaction->allow_commit();
        return [];
    }

    /**
     * Reject drafts (kept with status rejected).
     *
     * @param int[] $ids
     */
    public static function reject(array $ids): void {
        global $DB, $USER;
        foreach ($ids as $id) {
            $DB->set_field_select('local_academictranscript_draft', 'status', 'rejected', "id = ? AND status = 'draft'", [$id]);
            $DB->set_field('local_academictranscript_draft', 'decidedby', $USER->id, ['id' => $id]);
            $DB->set_field('local_academictranscript_draft', 'timedecided', time(), ['id' => $id]);
        }
    }
}
