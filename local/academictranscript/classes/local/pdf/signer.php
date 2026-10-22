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

namespace local_academictranscript\local\pdf;

use local_academictranscript\local\transcript;

/**
 * Digital signature credentials for Official transcripts.
 *
 * Uses the certificate uploaded in the plugin settings (PKCS#12 .p12/.pfx) when there is one;
 * otherwise a self-signed certificate created on first use and kept in moodledata (never web-served).
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signer {
    /**
     * Folder holding the self-signed certificate.
     *
     * @return string
     */
    protected static function dir(): string {
        global $CFG;
        return $CFG->dataroot . '/local_academictranscript/signing';
    }

    /**
     * Signing credentials, or null if signing is not possible.
     *
     * @return array|null ['cert' => PEM, 'key' => PEM, 'extracerts' => file path or '', 'source' => 'uploaded'|'selfsigned']
     */
    public static function credentials(): ?array {
        if (!function_exists('openssl_pkcs12_read')) {
            return null;
        }
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_academictranscript',
            'signingcert',
            0,
            'id',
            false
        );
        if ($file = reset($files)) {
            $certs = [];
            $password = (string)get_config('local_academictranscript', 'signingcertpassword');
            if (openssl_pkcs12_read($file->get_content(), $certs, $password)) {
                $extra = '';
                if (!empty($certs['extracerts'])) {
                    $extra = make_request_directory() . '/extracerts.pem';
                    file_put_contents($extra, implode("\n", $certs['extracerts']));
                }
                return ['cert' => $certs['cert'], 'key' => $certs['pkey'], 'extracerts' => $extra, 'source' => 'uploaded'];
            }
            debugging('local_academictranscript: the uploaded signing certificate could not be read (wrong password?). '
                . 'Using the self-signed certificate.', DEBUG_DEVELOPER);
        }
        return self::selfsigned();
    }

    /**
     * The self-signed certificate, created if missing.
     *
     * @return array|null
     */
    public static function selfsigned(): ?array {
        $dir = self::dir();
        $certfile = $dir . '/selfsigned-cert.pem';
        $keyfile = $dir . '/selfsigned-key.pem';
        if (!is_readable($certfile) || !is_readable($keyfile)) {
            if (!check_dir_exists($dir, true, true)) {
                return null;
            }
            $school = self::school_name();
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'config' => self::config_file()]);
            if (!$key) {
                return null;
            }
            $dn = ['commonName' => \core_text::substr(get_string('signername', 'local_academictranscript', $school), 0, 64),
                'organizationName' => \core_text::substr($school, 0, 64)];
            // Minimal OpenSSL config so no sample defaults (country, state) end up in the certificate.
            $opts = ['digest_alg' => 'sha256', 'config' => self::config_file()];
            $csr = openssl_csr_new($dn, $key, $opts);
            $cert = $csr ? openssl_csr_sign($csr, null, $key, 3650, $opts, random_int(1, PHP_INT_MAX)) : false;
            if (!$cert || !openssl_x509_export($cert, $certpem) || !openssl_pkey_export($key, $keypem)) {
                return null;
            }
            file_put_contents($keyfile, $keypem);
            @chmod($keyfile, 0600);
            file_put_contents($certfile, $certpem);
        }
        return ['cert' => file_get_contents($certfile), 'key' => file_get_contents($keyfile), 'extracerts' => '',
            'source' => 'selfsigned'];
    }

    /**
     * The name of the school: the School name setting, or the full name of the site when that is empty. It is the name
     * that the transcript prints.
     *
     * @return string plain text
     */
    protected static function school_name(): string {
        return transcript::school_name();
    }

    /**
     * A minimal OpenSSL config file (no default distinguished name values).
     *
     * @return string path
     */
    protected static function config_file(): string {
        $cnf = make_request_directory() . '/openssl.cnf';
        file_put_contents($cnf, "[ req ]\ndistinguished_name = req_dn\n[ req_dn ]\n");
        return $cnf;
    }

    /**
     * Apply the signature to a PDF document (call before output). Changes to the file are not permitted.
     *
     * @param \pdf $doc
     * @return bool whether the document will be signed
     */
    public static function apply(\pdf $doc): bool {
        $cred = self::credentials();
        if (!$cred) {
            return false;
        }
        $doc->setSignature($cred['cert'], $cred['key'], '', $cred['extracerts'], 1, [
            'Name' => get_string('signername', 'local_academictranscript', self::school_name()),
            'Location' => transcript::setting('address'),
            'Reason' => get_string('signaturereason', 'local_academictranscript'),
            'ContactInfo' => transcript::setting('email'),
        ]);
        return true;
    }
}
