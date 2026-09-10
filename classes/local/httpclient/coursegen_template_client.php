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
     * Perform a GET request against a JSON endpoint of the service.
     *
     * @param string $path Relative endpoint (starting with "/"), e.g. '/api/activities/page'.
     * @return array|null Decoded JSON body, or null when the endpoint responds 404
     *     (used by this service to mean "type not supported").
     * @throws \moodle_exception On connection failure, non-2xx/404 HTTP status, or invalid JSON.
     */
    public function get_json(string $path): ?array {
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $curl = new \curl();
        $options = [
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
            'CURLOPT_TIMEOUT' => self::REQUEST_TIMEOUT,
        ];

        $url = $this->baseurl . $path;
        $response = $curl->get($url, [], $options);

        if ($curl->error) {
            debugging('coursegen-template client: cURL error (' . $curl->error . ') for ' . $url, DEBUG_DEVELOPER);
            throw new \moodle_exception('error_template_service_unreachable', 'local_coursegen', '', $curl->error);
        }

        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($httpcode === 404) {
            return null;
        }
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
     * Download the raw bytes of a package/asset served by the coursegen-template
     * service (e.g. the generated PDF behind a "resource" activity's file_path).
     *
     * @param string $url Absolute URL to download, as returned by the service itself.
     * @return string Raw file content.
     * @throws \moodle_exception On connection failure or non-2xx HTTP status.
     */
    public function download_raw(string $url): string {
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
