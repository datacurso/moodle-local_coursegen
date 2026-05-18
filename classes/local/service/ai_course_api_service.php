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
use stdClass;

/**
 * Service wrapper for the Datacurso AI course API.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_course_api_service {
    /** @var ai_course_api Datacurso course API client. */
    private ai_course_api $client;

    /**
     * Constructor.
     */
    public function __construct() {
        $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;

        $this->client = new ai_course_api(null, $baseurl, $baseurleu);
    }

    /**
     * Start the AI course planning process by sending a JSON payload to the init endpoint.
     *
     * @param array $payload Payload to send to the API (config, instructions, course data, etc.).
     * @return array Decoded response from the API.
     */
    public function start_course_planning(array $payload): array {
        $result = $this->client->request('POST', '/course/init', $payload);

        if (!is_array($result) || empty($result['thread_id'])) {
            throw new \moodle_exception('error_starting_course_planning', 'local_coursegen');
        }

        return $result;
    }

    /**
     * Get the streaming URL for a given activity generation thread/job.
     *
     * @param string $jobid External job/thread identifier.
     * @return string Streaming URL.
     */
    public function get_mod_streaming_url_for_job(string $jobid): string {
        return $this->client->get_mod_streaming_url_for_job($jobid);
    }

    /**
     * Get the streaming URL for a given course planning session.
     *
     * @param string $sessionid External planning thread identifier.
     * @return string Streaming URL.
     */
    public function get_course_streaming_url(string $sessionid): string {
        return $this->client->get_streaming_url_for_session($sessionid);
    }

    /**
     * Start the AI activity generation process by sending a JSON payload to the activity init endpoint.
     *
     * @param array $payload Payload to send to the API (instructions, config, etc.).
     * @return array Decoded response from the API.
     */
    public function start_activity(array $payload): array {
        $result = $this->client->request('POST', '/activity/init', $payload);

        if (!is_array($result) || empty($result['thread_id'])) {
            throw new \moodle_exception('error_generating_resource', 'local_coursegen');
        }

        return $result;
    }

    /**
     * Upload the syllabus file for an existing planning session.
     *
     * @param string $sessionid External planning session identifier (thread_id).
     * @param stored_file $file Syllabus file to upload.
     * @return array Decoded response from the API.
     */
    public function upload_syllabus(string $sessionid, stored_file $file): array {
        $extra = [
            'thread_id' => $sessionid,
        ];

        return $this->client->upload_file(
            '/course/sillabus/upload',
            $file,
            $extra
        );
    }

    /**
     * Upload a file associated with an existing AI activity generation thread.
     *
     * This is used to send additional resources (e.g. syllabus, statements) for
     * the current activity "thread" so the external AI service can use them as
     * context.
     *
     * @param string $threadid External activity generation identifier (thread_id).
     * @param stored_file $file File to upload.
     * @return array Decoded response from the API.
     */
    public function upload_activity_file(string $threadid, stored_file $file): array {
        $extra = [
            'thread_id' => $threadid,
        ];

        return $this->client->upload_file(
            '/activity/file/upload',
            $file,
            $extra
        );
    }

    /**
     * Send human feedback for an existing AI course planning session.
     *
     * @param string $sessionid External planning session identifier.
     * @param string $approvalstatus Approval status (accept|adjust).
     * @param string $instruction Optional feedback text.
     * @param array|null $selectedimageids Selected image IDs from detailed planning review.
     * @return array Decoded response from the API.
     */
    public function send_planning_feedback(
        string $sessionid,
        string $approvalstatus,
        string $instruction = '',
        ?array $selectedimageids = null
    ): array {
        $payload = [
            'approval_status' => $approvalstatus,
            'instruction' => $instruction,
            'thread_id' => $sessionid,
        ];

        if ($selectedimageids !== null) {
            $payload['selected_image_ids'] = array_values(array_map(
                static function($value): string {
                    return trim((string) $value);
                },
                $selectedimageids
            ));
        }

        $endpoint = '/course/feedback';

        return $this->client->request('POST', $endpoint, $payload);
    }

    /**
     * Send human feedback for an existing AI activity generation job.
     *
     * @param string $threadid External activity generation identifier.
     * @param string $approvalstatus Approval status (accept|adjust).
     * @param string $instruction Optional feedback text.
     * @return array Decoded response from the API.
     */
    public function send_activity_feedback(string $threadid, string $approvalstatus, string $instruction = ''): array {
        $payload = [
            'thread_id' => $threadid,
            'approval_status' => $approvalstatus,
            'instruction' => $instruction,
        ];

        $endpoint = '/activity/feedback';

        return $this->client->request('POST', $endpoint, $payload);
    }

    /**
     * Retrieve the final course result for a planning session.
     *
     * @param string $sessionid External planning session identifier.
     * @return array Decoded response from the API.
     */
    public function get_course_result(string $sessionid): array {
        $endpoint = '/course/result/' . urlencode($sessionid);
        return $this->client->request('GET', $endpoint);
    }

    /**
     * Retrieve the activity result for a module generation thread.
     *
     * @param string $threadid External activity generation identifier (currentThreadId).
     * @return array Decoded response from the API.
     */
    public function get_activity_result(string $threadid): array {
        $endpoint = '/activity/result/' . urlencode($threadid);
        return $this->client->request('GET', $endpoint);
    }
}
