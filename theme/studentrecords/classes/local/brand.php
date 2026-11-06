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

namespace theme_studentrecords\local;

/**
 * The school's look: name, initials, logo and accent colour, from the theme settings.
 *
 * @package    theme_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class brand {
    /** Default accent colour (meets WCAG AA on white). */
    public const DEFAULT_ACCENT = '#416180';

    /** Lowest contrast against white allowed for the accent colour (WCAG 2.1 AA, normal text). */
    public const MIN_CONTRAST = 4.5;

    /**
     * The school name: the theme setting, or the site's full name.
     *
     * @return string Plain text.
     */
    public static function school_name(): string {
        global $SITE;
        $name = trim((string)get_config('theme_studentrecords', 'schoolname'));
        if ($name === '') {
            $name = (string)($SITE->fullname ?? '');
        }
        return format_string($name, true, ['context' => \context_system::instance(), 'escape' => false]);
    }

    /**
     * Two letters for the logo square when there is no logo picture ("Riverside College" gives "RC").
     *
     * @param string $name
     * @return string
     */
    public static function initials(string $name): string {
        $small = ['a', 'an', 'and', 'at', 'for', 'in', 'of', 'on', 'the', 'to'];
        $letters = '';
        foreach (preg_split('/[\s\-]+/u', trim($name)) as $word) {
            $word = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
            if ($word === '' || in_array(\core_text::strtolower($word), $small, true)) {
                continue;
            }
            $letters .= \core_text::substr($word, 0, 1);
            if (\core_text::strlen($letters) == 2) {
                break;
            }
        }
        if (\core_text::strlen($letters) < 2) {
            $letters = \core_text::substr(preg_replace('/[^\p{L}\p{N}]/u', '', $name), 0, 2);
        }
        return \core_text::strtoupper($letters);
    }

    /**
     * Logo picture: the theme setting, then Moodle's own compact logo, then none (the initials are shown).
     *
     * @return string|null
     */
    public static function logo_url(): ?string {
        global $OUTPUT, $PAGE;
        if (get_config('theme_studentrecords', 'logo')) {
            $url = $PAGE->theme->setting_file_url('logo', 'logo');
            if ($url) {
                return (string)$url;
            }
        }
        $url = $OUTPUT->get_compact_logo_url();
        return $url ? $url->out(false) : null;
    }

    /**
     * Accent colour (#rrggbb). A saved colour that fails the contrast check falls back to the default.
     *
     * @return string
     */
    public static function accent(): string {
        $colour = strtolower(trim((string)get_config('theme_studentrecords', 'accentcolor')));
        if (!preg_match('/^#[0-9a-f]{6}$/', $colour) || self::contrast($colour, '#ffffff') < self::MIN_CONTRAST) {
            return self::DEFAULT_ACCENT;
        }
        return $colour;
    }

    /**
     * WCAG contrast ratio of two #rrggbb colours.
     *
     * @param string $a
     * @param string $b
     * @return float From 1 to 21.
     */
    public static function contrast(string $a, string $b): float {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * WCAG relative luminance of a #rrggbb colour.
     *
     * @param string $hex
     * @return float
     */
    protected static function luminance(string $hex): float {
        $hex = ltrim($hex, '#');
        $sum = 0.0;
        foreach ([0.2126, 0.7152, 0.0722] as $i => $weight) {
            $c = hexdec(substr($hex, $i * 2, 2)) / 255;
            $c = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
            $sum += $weight * $c;
        }
        return $sum;
    }

    /**
     * The user menu with the user's full name next to the picture on wide screens.
     *
     * The name goes in as one more metadata item, the part of the user menu made for extra text, so that Moodle's own
     * user menu template is used on every version. The toggle has its own label, so the name is hidden from screen
     * readers. Moodle 5.3 can show the first name in the same place: that is left out so the name shows once.
     *
     * @param array $usermenu The user menu from \core\navigation\output\primary.
     * @return array
     */
    public static function user_menu(array $usermenu): array {
        if (empty($usermenu['userfullname'])) {
            return $usermenu;
        }
        unset($usermenu['userfirstname']);
        $usermenu['metadata'][] = [
            'classes' => 'sr-usermeta',
            'content' => \html_writer::span(s($usermenu['userfullname']), 'sr-username d-none d-lg-inline', [
                'aria-hidden' => 'true',
            ]),
        ];
        return $usermenu;
    }

    /**
     * Template data for the top bar and the login page.
     *
     * @return array
     */
    public static function export(): array {
        $name = self::school_name();
        return [
            'schoolname' => $name,
            'initials' => self::initials($name),
            'logourl' => self::logo_url(),
            'homeurl' => (new \moodle_url('/'))->out(false),
        ];
    }
}
