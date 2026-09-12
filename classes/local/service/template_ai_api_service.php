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
use local_coursegen\local\api_client_factory;
use stored_file;

defined('MOODLE_INTERNAL') || die();

/**
 * Service wrapper for the Datacurso "course template" AI API
 * (/course-template/*): the single-request planning backend for template-mode
 * course creation, replacing the old backup/restore + per-activity-mock-AI
 * approach (see template_course_builder_service).
 *
 * Mirrors ai_course_api_service's own DI/test-seam pattern exactly: the real
 * HTTP client is built through api_client_factory::ai_course_api(), which is
 * the plugin's single test seam (api_client_factory::set_test_client(),
 * PHPUNIT_TEST-gated) - not a builder-local AI service swap.
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
     * @param ai_course_api|null $client Optional pre-built API client. When null (production
     *     default) the client is created through api_client_factory, from the plugin
     *     configuration. Tests inject a double through api_client_factory::set_test_client()
     *     instead of passing one here (see that factory's own docblock).
     */
    public function __construct(?ai_course_api $client = null) {
        if ($client !== null) {
            $this->client = $client;
            return;
        }

        $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;

        $this->client = api_client_factory::ai_course_api($baseurl, $baseurleu);
    }

    /**
     * Start the AI template planning process by sending the template export
     * payload (see course_export_service::export_course_for_template()) to
     * the init endpoint.
     *
     * @param array $payload Template export payload.
     * @return string thread_id identifying this planning run.
     * @throws \moodle_exception If the API does not return a thread_id.
     */
    public function start_template_planning(array $payload): string {
        $result = $this->client->request('POST', '/course-template/init', $payload);

        if (!is_array($result) || empty($result['thread_id'])) {
            throw new \moodle_exception('error_starting_template_planning', 'local_coursegen');
        }

        return (string)$result['thread_id'];
    }

    /**
     * Upload one general reference file for an existing template planning thread.
     *
     * @param string $threadid External planning thread identifier.
     * @param stored_file $file Reference file to upload.
     * @return array|null Decoded response from the API.
     */
    public function upload_template_file(string $threadid, stored_file $file): ?array {
        return $this->client->upload_file(
            '/course-template/file/upload',
            $file,
            ['thread_id' => $threadid]
        );
    }

    /**
     * Retrieve the current result for a template planning thread.
     *
     * While the AI is still working, the endpoint is expected to return
     * either nothing/null or a {"status": "pending"}-shaped body; once ready,
     * it returns the final course JSON (see wait_for_template_result()'s own
     * docblock for the exact completion signal used).
     *
     * @param string $threadid External planning thread identifier.
     * @return array|null Decoded response from the API.
     */
    public function get_template_result(string $threadid): ?array {
        $endpoint = '/course-template/result/' . urlencode($threadid);
        return $this->client->request('GET', $endpoint);
    }

    /**
     * Bounded polling loop that waits for a template planning thread to finish.
     *
     * DELIBERATE SCOPE DECISION: the API also exposes
     * /course-template/stream/{thread_id} for live progress (a future UI, not
     * built here). This codebase has no PHP-side SSE consumption precedent
     * anywhere (SSE is only ever consumed client-side, via JS EventSource, for a
     * different, unrelated flow) - implementing real SSE parsing in PHP would
     * be new, unproven infrastructure for a synchronous builder flow that the
     * simple result-endpoint polling below already fully serves. Do not
     * attempt to build SSE consumption here.
     *
     * Completion signal: get_template_result() is treated as "still working"
     * when it returns null/empty or an array without a 'course_configuration'
     * key (e.g. a {"status": "pending"}-shaped body), and as "done" once its
     * response carries 'course_configuration' - the same shape
     * create_course_service::create_course() itself requires from resultdata.
     *
     * @param string $threadid External planning thread identifier.
     * @param int $timeoutseconds Maximum total time to wait before giving up.
     * @param int $pollintervalseconds Time to sleep between polling attempts.
     * @return array The final result data (course_configuration, sections_info,
     *     generated_activities, subsections_info, blocks_info).
     * @throws \moodle_exception If the result is not ready before the timeout.
     */
    public function wait_for_template_result(
        string $threadid,
        int $timeoutseconds = 300,
        int $pollintervalseconds = 3
    ): array {
        $deadline = time() + $timeoutseconds;

        do {
            $result = $this->get_template_result($threadid);
            if (is_array($result) && array_key_exists('course_configuration', $result)) {
                return $result;
            }

            if (time() >= $deadline) {
                break;
            }
            sleep($pollintervalseconds);
        } while (time() < $deadline);

        throw new \moodle_exception('error_template_result_timeout', 'local_coursegen');
    }
}
