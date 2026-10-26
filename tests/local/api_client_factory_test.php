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

namespace local_coursegen\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/coursegen/tests/fixtures/aiprovider_datacurso_stub.php');

/**
 * Tests that every call to the course service says which plugin makes it.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\api_client_factory
 */
final class api_client_factory_test extends \advanced_testcase {
    /**
     * The client identifies as this plugin and reports its release.
     */
    public function test_the_client_identifies_as_this_plugin_with_its_release(): void {
        $this->resetAfterTest();
        // The real client reads the license and asks the shop for its region: give it both, offline.
        set_config('licensekey', 'test-license-key', 'aiprovider_datacurso');
        \curl::mock_response(json_encode(['is_for_eu' => false]));
        $release = \core_plugin_manager::instance()->get_plugin_info('local_coursegen')->release;
        $this->assertNotEmpty($release);

        $headers = api_client_factory::ai_course_api(null, null)->get_plugin_headers();

        $this->assertContains('X-Plugin-Name: local_coursegen', $headers);
        $this->assertContains('X-Plugin-Version: ' . $release, $headers);
    }

    /**
     * No class builds the client by hand: a client built outside the factory would not identify itself.
     */
    public function test_no_class_builds_the_client_without_the_factory(): void {
        global $CFG;

        $factory = realpath($CFG->dirroot . '/local/coursegen/classes/local/api_client_factory.php');
        $offenders = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($CFG->dirroot . '/local/coursegen/classes', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getRealPath() === $factory) {
                continue;
            }
            if (preg_match('/new\s+\\\\?(?:aiprovider_datacurso\\\\httpclient\\\\)?ai_course_api\s*\(/', file_get_contents($file->getRealPath()))) {
                $offenders[] = substr($file->getRealPath(), strlen($CFG->dirroot));
            }
        }

        $this->assertSame([], $offenders, 'Build the client with api_client_factory::ai_course_api().');
    }
}
