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

/**
 * Reads a server-sent events stream of the AI service.
 *
 * One instance serves one stream, after {@see listen()}. The transfer has no total time limit, since a generation can last
 * many minutes, but it is dropped when nothing arrives for IDLE_TIMEOUT_SECONDS, and it is cancelled as
 * soon as the tick callback asks for it, which is how a browser that left stops the transfer.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upstream_stream_client {
    /** @var int Seconds to wait for the connection to open. */
    public const CONNECT_TIMEOUT_SECONDS = 10;

    /** @var int Seconds without any byte after which the transfer is dropped. */
    public const IDLE_TIMEOUT_SECONDS = 300;

    /** @var sse_parser Parser of the bytes received. */
    private sse_parser $parser;

    /** @var callable Called with each event, as an array with the keys 'event' and 'data'. */
    private $onevent;

    /** @var callable Called regularly. Returns true to cancel the transfer. */
    private $ontick;

    /** @var bool Whether the transfer was cancelled on request. */
    private bool $cancelled = false;

    /**
     * Set what receives the events and decides when the transfer stops.
     *
     * @param callable $onevent Called with each event.
     * @param callable $ontick Called regularly, even while the service is silent. Returns true to cancel.
     */
    public function listen(callable $onevent, callable $ontick): void {
        $this->parser = new sse_parser();
        $this->onevent = $onevent;
        $this->ontick = $ontick;
        $this->cancelled = false;
    }

    /**
     * Open the stream and pass each event on until it ends or is cancelled.
     *
     * @param string $url Service stream URL.
     * @param string $licensekey License key sent with the request.
     * @throws \moodle_exception When the service cannot be reached or does not answer 200.
     */
    public function stream(string $url, string $licensekey): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setHeader([
            'Accept: text/event-stream',
            'Cache-Control: no-cache',
            'License-Key: ' . $licensekey,
        ]);
        $curl->get($url, [], $this->options());

        $this->check_outcome($curl);
    }

    /**
     * Receive bytes of the stream. Called by curl.
     *
     * @param \CurlHandle $handle Transfer handle.
     * @param string $data Bytes received.
     * @return int Number of bytes taken, or 0 to stop the transfer.
     */
    public function receive($handle, string $data): int {
        $events = $this->parser->feed($data);
        foreach ($events as $event) {
            ($this->onevent)($event);
        }

        if ($this->tick_requests_cancel()) {
            return 0;
        }

        return strlen($data);
    }

    /**
     * Check progress of the transfer, which curl does about once a second even when nothing arrives.
     *
     * @param \CurlHandle $handle Transfer handle.
     * @param int $downloadtotal Bytes expected.
     * @param int $downloaded Bytes received.
     * @param int $uploadtotal Bytes to send.
     * @param int $uploaded Bytes sent.
     * @return int Non zero to stop the transfer.
     */
    public function progress($handle, int $downloadtotal, int $downloaded, int $uploadtotal, int $uploaded): int {
        if ($this->tick_requests_cancel()) {
            return 1;
        }

        return 0;
    }

    /**
     * Run the tick callback unless the transfer was already cancelled.
     *
     * @return bool Whether the transfer must stop.
     */
    private function tick_requests_cancel(): bool {
        if ($this->cancelled) {
            return true;
        }

        $requested = ($this->ontick)();
        $this->cancelled = (bool) $requested;
        return $this->cancelled;
    }

    /**
     * Curl options of the stream transfer.
     *
     * @return array
     */
    private function options(): array {
        return [
            'CURLOPT_TIMEOUT' => 0,
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT_SECONDS,
            'CURLOPT_LOW_SPEED_LIMIT' => 1,
            'CURLOPT_LOW_SPEED_TIME' => self::IDLE_TIMEOUT_SECONDS,
            'CURLOPT_ENCODING' => 'identity',
            'CURLOPT_WRITEFUNCTION' => [$this, 'receive'],
            'CURLOPT_NOPROGRESS' => false,
            'CURLOPT_PROGRESSFUNCTION' => [$this, 'progress'],
        ];
    }

    /**
     * Turn a failed transfer into an exception. A transfer cancelled on request is not a failure.
     *
     * @param \curl $curl Client that made the request.
     * @throws \moodle_exception When the service cannot be reached or does not answer 200.
     */
    private function check_outcome(\curl $curl): void {
        if ($this->cancelled) {
            return;
        }

        if ($curl->get_errno()) {
            throw new \moodle_exception('error_stream_unreachable', 'local_coursegen');
        }

        $info = $curl->get_info();
        $code = $info['http_code'] ?? 0;
        $status = (int) $code;
        if ($status !== 200) {
            throw new \moodle_exception('error_stream_upstream_status', 'local_coursegen', '', $status);
        }
    }
}
