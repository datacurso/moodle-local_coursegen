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

namespace local_coursegen\local\service;

use aiprovider_datacurso\httpclient\ai_course_api;
use stored_file;

/**
 * The /course-template endpoints of the AI service.
 *
 * The run is started EXPLICITLY (start_generation), not by /init: reference
 * files can only be attached once /init has returned a thread id, so an
 * auto-started run would race the syllabus upload and generate without it.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_ai_api_service {
    /** @var ai_course_api Datacurso course API client. */
    private ai_course_api $client;

    /**
     * Constructor.
     *
     * @param ai_course_api|null $client Optional pre-built client; tests pass a mock.
     */
    public function __construct(?ai_course_api $client = null) {
        if ($client !== null) {
            $this->client = $client;
            return;
        }
        $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;
        $this->client = new ai_course_api(null, $baseurl, $baseurleu);
    }

    /**
     * Create the generation session (does not start it - see start_generation).
     *
     * @param array $payload Built by template_export_service::build_init_payload().
     * @return string The thread id.
     */
    public function init(array $payload): string {
        $result = $this->client->request('POST', '/course-template/init', $payload);
        $threadid = $result['thread_id'] ?? '';
        if ($threadid === '') {
            throw new \moodle_exception('error_starting_course_planning', 'local_coursegen');
        }
        return (string) $threadid;
    }

    /**
     * Attach a reference file (the syllabus) to a session.
     *
     * @param string $threadid
     * @param stored_file $file
     * @return array Decoded response.
     */
    public function upload_reference_file(string $threadid, stored_file $file): array {
        return $this->client->upload_file(
            '/course-template/file/upload',
            $file,
            ['thread_id' => $threadid, 'scope' => 'general']
        );
    }

    /**
     * Run a session created by init(), once its files are attached.
     *
     * @param string $threadid
     * @return array Decoded response.
     */
    public function start_generation(string $threadid): array {
        return $this->client->request('POST', '/course-template/start/' . $threadid, []);
    }

    /**
     * Where the run stands: running, completed or failed.
     *
     * Polled instead of /result because that endpoint answers 404 for three
     * different situations at once (unknown thread, failed run, not finished
     * yet), which the HTTP client turns into an exception - indistinguishable
     * from a real error while the run is simply still working.
     *
     * @param string $threadid
     * @return array {status, error_message}
     */
    public function get_status(string $threadid): array {
        $result = $this->client->request('GET', '/course-template/status/' . $threadid);
        return [
            'status' => (string) ($result['status'] ?? 'running'),
            'error_message' => (string) ($result['error_message'] ?? ''),
        ];
    }

    /**
     * Fetch the finished result. Only call this once get_status() reports
     * "completed" - it errors while the run is still in flight.
     *
     * @param string $threadid
     * @return array
     */
    public function get_result(string $threadid): array {
        return (array) $this->client->request('GET', '/course-template/result/' . $threadid);
    }
}
