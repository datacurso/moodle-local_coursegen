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
 * PHP only learns that the browser left after it writes to it.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class browser_output {
    /**
     * Write a block of the stream.
     *
     * @param string $block Block built by the formatter.
     */
    public function send(string $block): void {
        $this->write($block);
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
}
