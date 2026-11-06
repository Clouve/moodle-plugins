<?php

namespace local_academictranscript\local;

/**
 * Plain text for names.
 *
 * Names (courses, programs, marks) are stored and passed on as plain text, and whatever prints a name escapes it once.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class text {
    /**
     * A name as plain text: filters applied, tags removed, and no HTML entities, so an ampersand is "&".
     *
     * The escape option of format_string() is not enough. While the site setting "formatstringstriptags" is off, Moodle
     * ignores that option, runs its HTML cleaner, and gives "&amp;" and the tags that are safe. Taking out the tags and
     * decoding the entities afterwards gives the same plain text with the setting on or off.
     *
     * The result can hold "<", ">" and "&" as plain characters, so it must be escaped where it is shown.
     *
     * @param string $text The name as it was typed
     * @param \context $context The context for the filters: the course for the name of a course, else the system
     * @return string
     */
    public static function plain(string $text, \context $context): string {
        $formatted = format_string($text, true, ['context' => $context, 'escape' => false]);
        return html_entity_decode(strip_tags($formatted), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
