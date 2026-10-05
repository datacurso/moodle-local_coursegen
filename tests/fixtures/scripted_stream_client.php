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

use local_coursegen\local\streaming\upstream_stream_client;

/**
 * Upstream client that replays a script instead of opening a connection.
 *
 * Each step of the script is either an event array with the keys 'event' and 'data', or the string 'tick'
 * to run the tick callback.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scripted_stream_client extends upstream_stream_client {
    /** @var array Steps to replay. */
    public array $script = [];

    /** @var \Throwable|null Exception to throw after the script. */
    public ?\Throwable $failure = null;

    /** @var array Url and license key of each stream() call. */
    public array $calls = [];

    /** @var callable|null Event callback registered by listen(). */
    private $eventcallback = null;

    /** @var callable|null Tick callback registered by listen(). */
    private $tickcallback = null;

    #[\Override]
    public function listen(callable $onevent, callable $ontick): void {
        $this->eventcallback = $onevent;
        $this->tickcallback = $ontick;
    }

    #[\Override]
    public function stream(string $url, string $licenseheader): void {
        $this->calls[] = ['url' => $url, 'licenseheader' => $licenseheader];

        foreach ($this->script as $step) {
            if ($this->play($step)) {
                return;
            }
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    /**
     * Play one step of the script.
     *
     * @param mixed $step Event array or 'tick'.
     * @return bool Whether the tick callback asked to stop.
     */
    private function play($step): bool {
        if (is_array($step)) {
            ($this->eventcallback)($step);
            return false;
        }

        return (bool) ($this->tickcallback)();
    }
}
