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

namespace local_coursegen\tests\fixtures;

use local_coursegen\local\streaming\browser_output;

/**
 * Browser output that records what is written and lets a test move the clock and close the connection.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_browser_output extends browser_output {
    /** @var string[] Blocks written, in order. */
    public array $written = [];

    /** @var int Time the output believes it is. */
    public int $clock = 1000;

    /** @var bool Whether the browser has left. */
    public bool $aborted = false;

    #[\Override]
    public function is_aborted(): bool {
        return $this->aborted;
    }

    #[\Override]
    protected function write(string $chunk): void {
        $this->written[] = $chunk;
    }

    #[\Override]
    protected function now(): int {
        return $this->clock;
    }
}
