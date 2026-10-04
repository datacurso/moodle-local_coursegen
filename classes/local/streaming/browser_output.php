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
 * What the relay writes to the browser, and whether the browser is still there.
 *
 * The service is silent during long phases, so a comment is written when nothing has been sent for a while.
 * Without it proxies close the idle connection, and PHP only learns that the browser left when it writes.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class browser_output {
    /** @var int Seconds without output after which a comment is written. */
    public const HEARTBEAT_SECONDS = 10;

    /** @var int Time of the last write. */
    private int $lastwrite;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->lastwrite = $this->now();
    }

    /**
     * Write a block of the stream.
     *
     * @param string $block Block built by the formatter.
     */
    public function send(string $block): void {
        $this->write($block);
        $this->lastwrite = $this->now();
    }

    /**
     * Write a comment when nothing has been sent for HEARTBEAT_SECONDS.
     */
    public function heartbeat_if_idle(): void {
        $idle = $this->now() - $this->lastwrite;
        if ($idle < self::HEARTBEAT_SECONDS) {
            return;
        }

        $comment = sse_formatter::comment('keepalive');
        $this->send($comment);
    }

    /**
     * Whether the browser closed the connection. PHP notices it when it writes.
     *
     * @return bool
     */
    public function is_aborted(): bool {
        $status = connection_aborted();
        return $status === 1;
    }

    /**
     * Send bytes to the browser right away.
     *
     * @param string $chunk Bytes to send.
     */
    protected function write(string $chunk): void {
        echo $chunk;
        flush();
    }

    /**
     * Current time, apart so tests can control it.
     *
     * @return int
     */
    protected function now(): int {
        return time();
    }
}
