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

/**
 * Test stub for the aiprovider_datacurso HTTP client.
 *
 * The PHPUnit suite mocks aiprovider_datacurso\httpclient\ai_course_api and
 * injects the mock through api_client_factory::set_test_client(): the real
 * class only ever satisfies type hints, its logic is never exercised. On CI
 * sites where the provider plugin is not installed (the class would not
 * exist and every createMock() call would fail with a ReflectionException),
 * this file declares a signature-compatible stand-in instead. When the real
 * provider is installed the guard below is true and nothing is declared.
 *
 * Every method throws: if a test ever reaches a stub body, it means the test
 * depends on real provider behaviour and must be skipped without the plugin.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiprovider_datacurso\httpclient;

if (!class_exists(ai_course_api::class)) {
    /**
     * Signature-compatible stand-in for the provider's course API client.
     *
     * Mirrors the public surface of aiprovider_datacurso\httpclient\ai_course_api
     * (including the methods inherited from datacurso_api_base) so that partial
     * mocks built with onlyMethods() keep working.
     *
     * @package    local_coursegen
     * @copyright  2026 Wilber Narvaez <https://datacurso.com>
     * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
     */
    class ai_course_api {
        /**
         * Mirror of the real constructor signature. Never used by mocks.
         *
         * @param string|null $licensekey License key override.
         * @param string|null $baseurl Base URL override.
         * @param string|null $baseurleu EU base URL override.
         */
        public function __construct(?string $licensekey = null, ?string $baseurl = null, ?string $baseurleu = null) {
        }

        /**
         * Mirror of datacurso_api_base::get_base_url().
         *
         * @return string
         */
        public function get_base_url(): string {
            throw new \coding_exception('aiprovider_datacurso stub: real provider behaviour required.');
        }

        /**
         * Mirror of datacurso_api_base::download_file().
         *
         * @param mixed $endpoint Endpoint path.
         * @param mixed $filename Target file name.
         * @param array $filerecord Optional file record overrides.
         * @return \stored_file|null
         */
        public function download_file($endpoint, $filename, $filerecord = []): ?\stored_file {
            throw new \coding_exception('aiprovider_datacurso stub: real provider behaviour required.');
        }

        /**
         * Mirror of datacurso_api_base::request().
         *
         * @param string $method HTTP method.
         * @param string $path Endpoint path.
         * @param array $body Request body.
         * @return array|null
         */
        public function request(string $method, string $path, array $body = []): ?array {
            throw new \coding_exception('aiprovider_datacurso stub: real provider behaviour required.');
        }

        /**
         * Mirror of datacurso_api_base::upload_file().
         *
         * @param string $path Endpoint path.
         * @param \stored_file $file File to upload.
         * @param array $extraparams Extra multipart parameters.
         * @return array|null
         */
        public function upload_file(string $path, \stored_file $file, array $extraparams = []): ?array {
            throw new \coding_exception('aiprovider_datacurso stub: real provider behaviour required.');
        }

        /**
         * Mirror of datacurso_api_base::is_for_ue().
         *
         * @return bool
         */
        public function is_for_ue(): bool {
            throw new \coding_exception('aiprovider_datacurso stub: real provider behaviour required.');
        }
    }
}
