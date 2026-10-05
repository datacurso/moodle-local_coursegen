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
use local_coursegen\local\streaming\stream_type;
use stored_file;

/**
 * The /course-template endpoints of the AI service.
 *
 * /init only seeds the session; opening its SSE stream is what actually runs
 * the generation, exactly as free-mode course creation and single-activity
 * generation already work. Reference files are therefore attached between the
 * two, with no risk of the run starting without the syllabus.
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
        $baseurl = get_config('local_coursegen', 'datacurso_service_url');
        if (!$baseurl) {
            $baseurl = null;
        }
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu');
        if (!$baseurleu) {
            $baseurleu = null;
        }
        $this->client = new ai_course_api(null, $baseurl, $baseurleu);
    }

    /**
     * Create the generation session. Seeds it only: the stream runs it.
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
     * Attach one file of a template source activity that a reference marker points at.
     *
     * The file travels as a multipart upload, never inside JSON.
     *
     * @param string $threadid
     * @param string $uid The template source activity's uid in the payload.
     * @param stored_file $file
     * @return array Decoded response.
     */
    public function upload_template_reference_file(string $threadid, string $uid, stored_file $file): array {
        return $this->client->upload_file(
            '/course-template/reference-file/upload',
            $file,
            ['thread_id' => $threadid, 'uid' => $uid]
        );
    }

    /**
     * Download a file the run made for a reference marker, straight into a file record.
     *
     * @param string $threadid
     * @param string $fileid The id the result gives the file.
     * @param string $filename The name to store it under.
     * @param array $filerecord Where to store it (context, component, file area, item, path).
     * @return stored_file|null
     */
    public function download_generated_file(string $threadid, string $fileid, string $filename, array $filerecord): ?stored_file {
        $endpoint = '/course-template/generated-file/' . rawurlencode($threadid) . '/' . rawurlencode($fileid);
        return $this->client->download_file($endpoint, $filename, $filerecord);
    }

    /**
     * Answer the review of the generated course that a paused run is waiting on.
     *
     * The run stops inside its approval step until this lands. Writing the
     * answer is all this does; reopening the stream is what resumes it.
     *
     * @param string $threadid
     * @param array $action {action: accept|replan_activity, target_ids?: string[], instruction?: string}
     * @return array Decoded response.
     */
    public function send_feedback(string $threadid, array $action): array {
        return $this->client->request('POST', '/course-template/feedback', [
            'thread_id' => $threadid,
            'pending_action' => $action,
        ]);
    }

    /**
     * The URL the browser reads one session's generation stream from.
     *
     * It is the relay of this plugin, never the service URL: the relay checks who owns the session
     * and sends the license on every call to the service.
     *
     * @param string $threadid
     * @return string
     */
    public function stream_url(string $threadid): string {
        return streaming_url_builder::relay(stream_type::TEMPLATE, $threadid);
    }

    /**
     * Fetch the finished result. Only call this once the stream has reported
     * "completed" - it errors while the run is still in flight.
     *
     * @param string $threadid
     * @return array
     */
    public function get_result(string $threadid): array {
        return (array) $this->client->request('GET', '/course-template/result/' . $threadid);
    }
}
