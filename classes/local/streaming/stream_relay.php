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

namespace local_coursegen\local\streaming;

use local_coursegen\local\service\ai_course_api_service;

/**
 * Streams a generation of the AI service to the browser through Moodle.
 *
 * The browser asks this plugin, not the service, so Moodle decides who may read a stream. The relay
 * opens the service stream, passes its events on unchanged and stops when the browser leaves. The
 * service decides how many readers a thread has, so the relay holds no lock of its own.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stream_relay {
    /** @var int Seconds the relay may run, which bounds the longest generation it carries. */
    public const MAX_SECONDS = 3600;

    /** @var stream_authorizer Ownership and capability check. */
    private stream_authorizer $authorizer;

    /** @var ai_course_api_service Source of the service URL and license key. */
    private ai_course_api_service $service;

    /** @var upstream_stream_client Reader of the service stream. */
    private upstream_stream_client $client;

    /** @var browser_output Writer to the browser. */
    private browser_output $output;

    /** @var bool Whether the service ended the stream with its done event. */
    private bool $finished = false;

    /**
     * Constructor. Every collaborator can be replaced, which is how tests run without a service.
     *
     * @param stream_authorizer|null $authorizer Ownership and capability check.
     * @param ai_course_api_service|null $service Source of the service URL and license key.
     * @param upstream_stream_client|null $client Reader of the service stream.
     * @param browser_output|null $output Writer to the browser.
     */
    public function __construct(
        ?stream_authorizer $authorizer = null,
        ?ai_course_api_service $service = null,
        ?upstream_stream_client $client = null,
        ?browser_output $output = null
    ) {
        if ($authorizer === null) {
            $authorizer = new stream_authorizer();
        }
        if ($service === null) {
            $service = new ai_course_api_service();
        }
        if ($client === null) {
            $client = new upstream_stream_client();
        }
        if ($output === null) {
            $output = new browser_output();
        }

        $this->authorizer = $authorizer;
        $this->service = $service;
        $this->client = $client;
        $this->output = $output;
    }

    /**
     * Relay one stream to the browser.
     *
     * Checks happen first, and fail as ordinary errors, before anything is streamed.
     *
     * @param string $streamtype Stream type, one of the stream_type constants.
     * @param string $threadid External thread identifier.
     * @throws \moodle_exception When the stream cannot be read by this user.
     */
    public function run(string $streamtype, string $threadid): void {
        $this->authorizer->authorize($streamtype, $threadid);
        $url = $this->service->get_upstream_stream_url($streamtype, $threadid);
        $licensekey = $this->service->get_license_key();

        try {
            $this->prepare_output();
            $this->relay($url, $licensekey);
        } catch (\Throwable $e) {
            $this->report_failure($e);
        }
    }

    /**
     * Pass an event of the service on to the browser. Called by the upstream client.
     *
     * @param array $event Event with the keys 'event' and 'data'.
     */
    public function forward(array $event): void {
        $block = sse_formatter::event($event['event'], $event['data']);
        $this->output->send($block);

        if ($event['event'] === 'done') {
            $this->finished = true;
        }
    }

    /**
     * Tell whether the browser left. Called by the upstream client.
     *
     * @return bool True when the transfer must stop.
     */
    public function tick(): bool {
        return $this->output->is_aborted();
    }

    /**
     * Set up the response for a long lived stream.
     *
     * The session lock is released first, or every other request of the user would wait for the generation.
     */
    private function prepare_output(): void {
        \core\session\manager::write_close();
        ignore_user_abort(true);
        \core_php_time_limit::raise(self::MAX_SECONDS);

        if (headers_sent()) {
            return;
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
    }

    /**
     * Read the service stream until it ends, and close the stream for the browser when it ended badly.
     *
     * @param string $url Service stream URL.
     * @param string $licensekey License key.
     */
    private function relay(string $url, string $licensekey): void {
        $this->finished = false;
        $this->client->listen([$this, 'forward'], [$this, 'tick']);
        $this->client->stream($url, $licensekey);

        if ($this->finished || $this->output->is_aborted()) {
            return;
        }

        $this->send_failure(true);
    }

    /**
     * Tell the browser that the stream failed, after logging why.
     *
     * @param \Throwable $e What went wrong.
     */
    private function report_failure(\Throwable $e): void {
        $class = get_class($e);
        debugging('local_coursegen stream relay: ' . $class . ': ' . $e->getMessage(), DEBUG_DEVELOPER);

        if ($this->output->is_aborted()) {
            return;
        }

        $this->send_failure(true);
    }

    /**
     * Write a failed event and the done event, in the shape the service uses.
     *
     * @param bool $retryable Whether the browser may offer to try again.
     */
    private function send_failure(bool $retryable): void {
        $message = get_string('error_stream_unreachable', 'local_coursegen');
        $payload = json_encode(
            ['type' => 'failed', 'message' => $message, 'retryable' => $retryable],
            JSON_UNESCAPED_UNICODE
        );

        $failed = sse_formatter::event('message', $payload);
        $done = sse_formatter::event('done', '');
        $this->output->send($failed);
        $this->output->send($done);
    }
}
