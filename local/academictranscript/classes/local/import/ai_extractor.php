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

/**
 * Pulls the grading scale, marks, programs and courses out of a document with Moodle's AI subsystem
 * (core_ai, "Generate text" action of the school's own AI provider). Results are only drafts.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_extractor {
    /** @var string[] Item types. */
    const TYPES = ['grade', 'mark', 'program', 'course'];

    /** @var int Characters of document text sent per request. */
    const CHUNK = 12000;

    /**
     * Whether an AI provider with "Generate text" is set up and enabled.
     *
     * @return bool
     */
    public static function available(): bool {
        if (!class_exists('\core_ai\manager') || !class_exists('\core_ai\aiactions\generate_text')) {
            return false;
        }
        try {
            return \core\di::get(\core_ai\manager::class)->is_action_available(\core_ai\aiactions\generate_text::class);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether the user has accepted the AI policy.
     *
     * @param int $userid
     * @return bool
     */
    public static function policy_accepted(int $userid): bool {
        return \core_ai\manager::get_user_policy_status($userid);
    }

    /**
     * Record the user's acceptance of the AI policy.
     *
     * @param int $userid
     */
    public static function accept_policy(int $userid): void {
        \core_ai\manager::user_policy_accepted($userid, \context_system::instance()->id);
    }

    /**
     * The prompt for one chunk of text.
     *
     * @param string $text
     * @param string[] $types
     * @return string
     */
    public static function prompt(string $text, array $types): string {
        $shapes = [
            'grade' => '"grades":[{"letter":"A","min":93,"max":100,"points":4.0,"ingpa":true,"earns":true,'
                . '"description":"Excellent","source":"..."}]',
            'mark' => '"marks":[{"code":"W","label":"Withdrawal","description":"...","earns":false,"source":"..."}]',
            'program' => '"programs":[{"name":"...","credential":"...","unittype":"credit","totalhours":60,"source":"..."}]',
            'course' => '"courses":[{"code":"ABC 101","title":"...","hours":3,"unittype":"credit","program":"...",'
                . '"source":"..."}]',
        ];
        $wanted = array_intersect_key($shapes, array_flip($types));
        return get_string('ai_prompt', 'local_academictranscript', [
            'shape' => '{' . implode(',', $wanted) . '}',
            'text' => $text,
        ]);
    }

    /**
     * Split long text into chunks at paragraph breaks.
     *
     * @param string $text
     * @param int $size
     * @return string[]
     */
    public static function chunks(string $text, int $size = self::CHUNK): array {
        $chunks = [];
        $current = '';
        foreach (preg_split("/\n{2,}/", $text) as $para) {
            if ($current !== '' && \core_text::strlen($current) + \core_text::strlen($para) > $size) {
                $chunks[] = $current;
                $current = '';
            }
            while (\core_text::strlen($para) > $size) {
                $chunks[] = \core_text::substr($para, 0, $size);
                $para = \core_text::substr($para, $size);
            }
            $current .= ($current === '' ? '' : "\n\n") . $para;
        }
        if (trim($current) !== '') {
            $chunks[] = $current;
        }
        return $chunks;
    }

    /**
     * Parse the AI answer into items by type. Invalid items are dropped.
     *
     * @param string $response
     * @return array type => list of item arrays (each with 'source')
     */
    public static function parse_response(string $response): array {
        $out = array_fill_keys(self::TYPES, []);
        $response = trim($response);
        // Drop a Markdown code fence around the JSON, if any.
        $response = preg_replace('/^\x60{3}(?:json)?\s*|\s*\x60{3}$/i', '', $response);
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false) {
            return $out;
        }
        $json = json_decode(substr($response, $start, $end - $start + 1), true);
        if (!is_array($json)) {
            return $out;
        }
        $map = ['grades' => 'grade', 'marks' => 'mark', 'programs' => 'program', 'courses' => 'course'];
        foreach ($map as $key => $type) {
            foreach ((array)($json[$key] ?? []) as $item) {
                if (is_array($item) && ($clean = self::clean_item($type, $item))) {
                    $out[$type][] = $clean;
                }
            }
        }
        return $out;
    }

    /**
     * Validate and normalise one item; null if unusable.
     *
     * @param string $type
     * @param array $item
     * @return array|null
     */
    public static function clean_item(string $type, array $item): ?array {
        $str = fn($k, $len = 255) => \core_text::substr(trim((string)($item[$k] ?? '')), 0, $len);
        $num = fn($k) => is_numeric($item[$k] ?? null) ? (float)$item[$k] : null;
        $bool = fn($k, $default) => isset($item[$k]) ? filter_var($item[$k], FILTER_VALIDATE_BOOLEAN) : $default;
        $source = $str('source', 1000);
        switch ($type) {
            case 'grade':
                if ($str('letter', 10) === '' || $num('min') === null || $num('points') === null) {
                    return null;
                }
                return ['letter' => $str('letter', 10), 'minpct' => $num('min'), 'maxpct' => $num('max') ?? 100,
                    'points' => $num('points'), 'ingpa' => $bool('ingpa', true) ? 1 : 0,
                    'earns' => $bool('earns', $num('points') > 0) ? 1 : 0, 'description' => $str('description'),
                    'source' => $source];
            case 'mark':
                if ($str('code', 10) === '') {
                    return null;
                }
                return ['code' => \core_text::strtoupper($str('code', 10)), 'label' => $str('label', 100) ?: $str('code', 10),
                    'description' => $str('description'), 'earns' => $bool('earns', false) ? 1 : 0, 'source' => $source];
            case 'program':
                if ($str('name') === '') {
                    return null;
                }
                return ['name' => $str('name'), 'credential' => $str('credential'),
                    'unittype' => programs::clean_unittype($str('unittype', 20)), 'totalhours' => $num('totalhours'),
                    'source' => $source];
            case 'course':
                if ($str('code', 50) === '' || $num('hours') === null) {
                    return null;
                }
                return ['code' => $str('code', 50), 'title' => $str('title'), 'hours' => $num('hours'),
                    'unittype' => programs::clean_unittype($str('unittype', 20)), 'program' => $str('program'),
                    'source' => $source];
        }
        return null;
    }

    /**
     * Ask the AI provider about one document. Returns items by type; nothing is saved here.
     *
     * @param string $text document text
     * @param string[] $types item types wanted
     * @param int $userid
     * @return array type => items
     * @throws \moodle_exception when the provider fails
     */
    public static function extract(string $text, array $types, int $userid): array {
        $manager = \core\di::get(\core_ai\manager::class);
        $all = array_fill_keys(self::TYPES, []);
        foreach (self::chunks($text) as $chunk) {
            $action = new \core_ai\aiactions\generate_text(
                contextid: \context_system::instance()->id,
                userid: $userid,
                prompttext: self::prompt($chunk, $types),
            );
            $response = $manager->process_action($action);
            if (!$response->get_success()) {
                throw new \moodle_exception('err_ai', 'local_academictranscript', '', $response->get_errormessage());
            }
            $data = $response->get_response_data();
            foreach (self::parse_response((string)($data['generatedcontent'] ?? '')) as $type => $items) {
                if (in_array($type, $types)) {
                    $all[$type] = array_merge($all[$type], $items);
                }
            }
        }
        return $all;
    }
}
