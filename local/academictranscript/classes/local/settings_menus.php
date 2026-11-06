<?php

namespace local_academictranscript\local;

/**
 * Option lists shared by the settings page and the setup wizard.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class settings_menus {
    /**
     * Repeat rules.
     *
     * @return array
     */
    public static function repeatrules(): array {
        $p = 'local_academictranscript';
        return [calculator::REPEAT_LATEST => get_string('repeat_latest', $p),
            calculator::REPEAT_HIGHEST => get_string('repeat_highest', $p),
            calculator::REPEAT_ALL => get_string('repeat_all', $p)];
    }

    /**
     * Page sizes.
     *
     * @return array
     */
    public static function pagesizes(): array {
        $p = 'local_academictranscript';
        return ['LETTER' => get_string('pagesize_letter', $p), 'A4' => get_string('pagesize_a4', $p)];
    }

    /**
     * Signature modes.
     *
     * @return array
     */
    public static function signaturemodes(): array {
        $p = 'local_academictranscript';
        return ['lines' => get_string('signaturemode_lines', $p), 'images' => get_string('signaturemode_images', $p)];
    }
}
