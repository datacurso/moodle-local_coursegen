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
 * The /template-agent endpoints of the AI service.
 *
 * /init seeds the run and opening its SSE stream through the relay is what runs it. When the run pauses on a
 * question the teacher answers through /feedback and the stream is opened again.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_ai_api_service {
    /** @var string Prefix of every endpoint of the template agent. */
    private const BASE = '/template-agent';

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
     * Create the run of a template generation.
     *
     * @param array $payload Init payload, version 2.
     * @return string Thread id of the run.
     * @throws \moodle_exception When the service returns no thread id.
     */
    public function init(array $payload): string {
        $result = $this->client->request('POST', self::BASE . '/init', $payload);
        $threadid = $result['thread_id'] ?? '';
        if ($threadid === '') {
            throw new \moodle_exception('error_starting_course_planning', 'local_coursegen');
        }
        return (string) $threadid;
    }

    /**
     * Upload the file a question asked for.
     *
     * @param string $threadid Thread id of the run.
     * @param stored_file $file File the teacher chose.
     * @return array The service answer: file_id, original_filename, content_type and size.
     */
    public function upload_answer_file(string $threadid, stored_file $file): array {
        return (array) $this->client->upload_file(self::BASE . '/file/upload', $file, ['thread_id' => $threadid]);
    }

    /**
     * Upload the syllabus the teacher attached to the run, to the endpoint that reads documents.
     *
     * It is not the endpoint of the file a question asks for: the syllabus is read before the run starts.
     *
     * @param string $threadid Thread of the run, for example "3f2a9c1e-77b4".
     * @param stored_file $file Syllabus file.
     * @return array Decoded answer of the service.
     */
    public function upload_syllabus(string $threadid, stored_file $file): array {
        return (array) $this->client->upload_file(self::BASE . '/syllabus/upload', $file, ['thread_id' => $threadid]);
    }

    /**
     * Answer the question the run is paused on.
     *
     * @param string $threadid Thread id of the run.
     * @param string $callid Call id of the pending question.
     * @param array $answer Exactly one of file_id, text or choice.
     * @return array What the service stored.
     */
    public function answer(string $threadid, string $callid, array $answer): array {
        return (array) $this->client->request('POST', self::BASE . '/feedback', [
            'thread_id' => $threadid,
            'call_id' => $callid,
            'answer' => $answer,
        ]);
    }

    /**
     * Download a file attached to the draft of the run.
     *
     * @param string $threadid Thread id of the run.
     * @param string $fileid File id of the service.
     * @param string $filename Name to give the stored file.
     * @param array $filerecord Overrides of the stored file record.
     * @return stored_file|null The downloaded file in the draft area of the user.
     */
    public function download_generated_file(string $threadid, string $fileid, string $filename, array $filerecord): ?stored_file {
        $endpoint = self::BASE . '/file/' . rawurlencode($threadid) . '/' . rawurlencode($fileid);
        return $this->client->download_file($endpoint, $filename, $filerecord);
    }

    /**
     * The URL the browser reads the stream of the run from. It is the relay of this plugin, never the service.
     *
     * @param string $threadid Thread id of the run.
     * @return string Relay URL.
     */
    public function stream_url(string $threadid): string {
        return streaming_url_builder::relay(stream_type::TEMPLATE, $threadid);
    }

    /**
     * The snapshot a reloaded page repaints from.
     *
     * @param string $threadid Thread id of the run.
     * @return array status, pending_question, draft, messages, progress_events and usage.
     */
    public function get_state(string $threadid): array {
        return (array) $this->client->request('GET', self::BASE . '/state/' . rawurlencode($threadid));
    }

    /**
     * The result document of a completed run.
     *
     * @param string $threadid Thread id of the run.
     * @return array Result document.
     */
    public function get_result(string $threadid): array {
        return (array) $this->client->request('GET', self::BASE . '/result/' . rawurlencode($threadid));
    }
}
