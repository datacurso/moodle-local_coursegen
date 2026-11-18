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

use local_coursegen\local\api_client_factory;
use local_coursegen\local\streaming\upstream_stream_client;

/**
 * Tests that the stream request says which plugin asks, as every other call to the service does.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\streaming\upstream_stream_client::request_headers
 */
final class upstream_stream_headers_test extends \advanced_testcase {
    /**
     * The request asks for an event stream and carries the license.
     */
    public function test_the_request_asks_for_events_with_the_license(): void {
        $headers = (new upstream_stream_client())->request_headers('License-Key: abc');

        $this->assertContains('Accept: text/event-stream', $headers);
        $this->assertContains('License-Key: abc', $headers);
    }

    /**
     * The request names this plugin and its build, which the service needs to accept it.
     */
    public function test_the_request_names_the_plugin_and_its_build(): void {
        $plugin = \core_plugin_manager::instance()->get_plugin_info(api_client_factory::COMPONENT);
        $headers = (new upstream_stream_client())->request_headers('License-Key: abc');

        $this->assertContains('X-Plugin-Name: local_coursegen', $headers);
        $this->assertContains('X-Plugin-Build: ' . $plugin->versiondisk, $headers);
    }
}
