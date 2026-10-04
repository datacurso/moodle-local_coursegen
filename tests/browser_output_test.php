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

namespace local_coursegen;

use local_coursegen\local\streaming\browser_output;
use local_coursegen\tests\fixtures\recording_browser_output;

/**
 * Unit tests for the browser output of the stream relay.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\streaming\browser_output
 */
final class browser_output_test extends \basic_testcase {
    /**
     * Load the recording output.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        require_once(__DIR__ . '/fixtures/recording_browser_output.php');
    }

    /**
     * Blocks reach the browser as they are given.
     */
    public function test_send_writes_the_block(): void {
        $output = new recording_browser_output();

        $output->send("event: done\ndata: \n\n");

        $this->assertSame(["event: done\ndata: \n\n"], $output->written);
    }

    /**
     * Nothing is written before the heartbeat interval has passed.
     */
    public function test_no_heartbeat_before_the_interval(): void {
        $output = new recording_browser_output();
        $output->clock += browser_output::HEARTBEAT_SECONDS - 1;

        $output->heartbeat_if_idle();

        $this->assertSame([], $output->written);
    }

    /**
     * A comment is written once the interval has passed.
     */
    public function test_heartbeat_is_a_comment_after_the_interval(): void {
        $output = new recording_browser_output();
        $output->clock += browser_output::HEARTBEAT_SECONDS;

        $output->heartbeat_if_idle();

        $this->assertSame([": keepalive\n\n"], $output->written);
    }

    /**
     * Writing a heartbeat restarts the idle time, so comments are spaced by the interval.
     */
    public function test_heartbeat_restarts_the_idle_time(): void {
        $output = new recording_browser_output();
        $output->clock += browser_output::HEARTBEAT_SECONDS;
        $output->heartbeat_if_idle();
        $output->clock += browser_output::HEARTBEAT_SECONDS - 1;

        $output->heartbeat_if_idle();

        $this->assertCount(1, $output->written);
    }

    /**
     * An event counts as traffic, so no heartbeat follows it right away.
     */
    public function test_sending_an_event_restarts_the_idle_time(): void {
        $output = new recording_browser_output();
        $output->clock += browser_output::HEARTBEAT_SECONDS - 1;
        $output->send("data: x\n\n");
        $output->clock += browser_output::HEARTBEAT_SECONDS - 1;

        $output->heartbeat_if_idle();

        $this->assertSame(["data: x\n\n"], $output->written);
    }

    /**
     * Under the command line the connection is never reported as closed.
     */
    public function test_connection_is_open_by_default(): void {
        $output = new browser_output();

        $this->assertFalse($output->is_aborted());
    }
}
