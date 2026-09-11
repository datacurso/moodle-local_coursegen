<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_coursegen\local\httpclient;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Class coursegen_template_client
 *
 * Thin HTTP client for the standalone `coursegen-template` test/dev service
 * (a Docker container reachable from this site at, by default,
 * http://coursegen-template:3000). Mirrors the shape/conventions of
 * aiprovider_datacurso\httpclient\datacurso_api_base (curl construction,
 * timeouts, error handling via moodle_exception) but is deliberately much
 * simpler: no license key, no rate limiting, no per-site headers — this is a
 * test/dev integration against a canned-response service, not the production
 * Datacurso backend.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coursegen_template_client {
    /** Default base URL: the `coursegen-template` container on the shared "moodle" Docker network. */
    private const DEFAULT_BASE_URL = 'http://coursegen-template:3000';

    /** Connection timeout, in seconds. */
    private const CONNECT_TIMEOUT = 5;

    /** Total request timeout, in seconds. */
    private const REQUEST_TIMEOUT = 20;

    /** @var string Base URL for the coursegen-template service (no trailing slash). */
    private string $baseurl;

    /**
     * Constructor.
     *
     * @param string|null $baseurl Optional base URL override. When null, falls back to the
     *     'coursegen_template_service_url' admin setting, then to DEFAULT_BASE_URL.
     */
    public function __construct(?string $baseurl = null) {
        if ($baseurl === null) {
            $baseurl = get_config('local_coursegen', 'coursegen_template_service_url') ?: null;
        }
        $this->baseurl = rtrim($baseurl ?: self::DEFAULT_BASE_URL, '/');
    }

    /**
     * Return the base URL this client talks to.
     *
     * @return string
     */
    public function get_base_url(): string {
        return $this->baseurl;
    }

    /**
     * Upload a single stored file to the service as a multipart/form-data POST.
     *
     * Mirrors aiprovider_datacurso\httpclient\datacurso_api_base::upload_file() exactly:
     * the file is materialised to a real temp path via \stored_file::copy_content_to_temp(),
     * wrapped in a \CURLFile, and handed to \curl::post() as an ARRAY payload (never
     * json_encode()d) so PHP's curl extension builds the multipart body — and therefore
     * its own `Content-Type: multipart/form-data; boundary=...` header — itself. That is
     * why no Content-Type is set here. The temp file is always removed in a `finally`.
     *
     * The file content is never read into PHP memory and never base64-encoded: it travels
     * only as a \CURLFile pointing at a real path on disk.
     *
     * @param string $path Relative endpoint (starting with "/"), e.g. '/api/course/backup'.
     * @param \stored_file $file The file to upload (a real course .mbz backup package).
     * @param array $extraparams Optional extra multipart form fields to send alongside the file.
     * @return array|null Decoded JSON response body — or null when the body is
     *     valid JSON but not an array/object.
     * @throws \coding_exception If the temp copy of the file could not be created.
     * @throws \moodle_exception On connection failure, non-2xx HTTP status, or invalid JSON.
     */
    public function upload_file(string $path, \stored_file $file, array $extraparams = []): ?array {
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $filepath = $file->copy_content_to_temp();
        $filename = $file->get_filename();
        $mimetype = $file->get_mimetype();

        if (!$filepath || !file_exists($filepath)) {
            throw new \coding_exception('Temporary file could not be created for upload.');
        }

        try {
            $curl = new \curl();
            $options = [
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
                'CURLOPT_TIMEOUT' => self::REQUEST_TIMEOUT,
            ];

            $postdata = array_merge($extraparams, [
                'file' => new \CURLFile($filepath, $mimetype, $filename),
            ]);

            $url = $this->baseurl . $path;
            // Array payload on purpose: curl builds the multipart body (and its own
            // Content-Type boundary header) from it. Do not json_encode this.
            $response = $curl->post($url, $postdata, $options);

            return $this->handle_json_response($curl, $url, $response);
        } finally {
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
        }
    }

    /**
     * Shared response handling for upload_file(): connection error, HTTP status, JSON decode.
     *
     * @param \curl $curl The curl instance that performed the request.
     * @param string $url Absolute URL that was requested (for error messages only).
     * @param mixed $response Raw response body returned by \curl.
     * @return array|null Decoded JSON body, or null when it is not an array/object.
     * @throws \moodle_exception On connection failure, non-2xx HTTP status, or invalid JSON.
     */
    private function handle_json_response(\curl $curl, string $url, $response): ?array {
        if ($curl->error) {
            debugging('coursegen-template client: cURL error (' . $curl->error . ') for ' . $url, DEBUG_DEVELOPER);
            throw new \moodle_exception('error_template_service_unreachable', 'local_coursegen', '', $curl->error);
        }

        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($httpcode >= 400 || $httpcode === 0) {
            debugging("coursegen-template client: HTTP {$httpcode} for {$url}: {$response}", DEBUG_DEVELOPER);
            throw new \moodle_exception('error_template_service_unreachable', 'local_coursegen', '', "HTTP {$httpcode}");
        }

        $decoded = json_decode((string) $response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            debugging('coursegen-template client: JSON decode error: ' . json_last_error_msg(), DEBUG_DEVELOPER);
            throw new \moodle_exception('error_template_service_response', 'local_coursegen', '', json_last_error_msg());
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Download the raw bytes of an asset served by the coursegen-template
     * service (e.g. a real image or file referenced in its response JSON).
     *
     * @param string $url Absolute or relative (resolved against get_base_url()) URL.
     * @return string Raw file content.
     * @throws \moodle_exception On connection failure or non-2xx HTTP status.
     */
    public function download_raw(string $url): string {
        if (!preg_match('#^https?://#i', $url)) {
            $url = $this->baseurl . $url;
        }

        $curl = new \curl();
        $options = [
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
            'CURLOPT_TIMEOUT' => self::REQUEST_TIMEOUT,
        ];

        $content = $curl->get($url, [], $options);

        if ($curl->error) {
            debugging('coursegen-template client: cURL error (' . $curl->error . ') downloading ' . $url, DEBUG_DEVELOPER);
            throw new \moodle_exception('error_template_service_unreachable', 'local_coursegen', '', $curl->error);
        }

        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($httpcode >= 400 || $httpcode === 0) {
            debugging("coursegen-template client: HTTP {$httpcode} downloading {$url}", DEBUG_DEVELOPER);
            throw new \moodle_exception('error_template_service_unreachable', 'local_coursegen', '', "HTTP {$httpcode}");
        }

        return (string) $content;
    }
}
