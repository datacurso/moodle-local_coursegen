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
    /** Builds the client of the course service by hand. */
    private const HAND_BUILT_CLIENT = '/new\s+\\\\?(?:aiprovider_datacurso\\\\httpclient\\\\)?ai_course_api\s*\(/';

    /**
     * Skip the test when the real provider, which can name the caller, is not installed.
     */
    private function require_the_provider_that_names_the_caller(): void {
        if (!class_exists(\aiprovider_datacurso\httpclient\plugin_identity::class)) {
            $this->markTestSkipped('The installed aiprovider_datacurso does not send plugin headers.');
        }
    }

    /**
     * The client identifies as this plugin and reports its release.
     */
    public function test_the_client_identifies_as_this_plugin_with_its_release(): void {
        $this->resetAfterTest();
        $this->require_the_provider_that_names_the_caller();
        // The real client reads the license and asks the shop for its region: give it both, offline.
        set_config('licensekey', 'test-license-key', 'aiprovider_datacurso');
        \curl::mock_response('{"is_for_eu": false}');
        $manager = \core_plugin_manager::instance();
        $plugin = $manager->get_plugin_info('local_coursegen');
        $release = $plugin->release;
        $this->assertNotEmpty($release);

        $client = api_client_factory::ai_course_api(null, null);
        $headers = $client->get_plugin_headers();

        $this->assertContains('X-Plugin-Name: local_coursegen', $headers);
        $this->assertContains('X-Plugin-Version: ' . $release, $headers);
    }

    /**
     * No class builds the client by hand: a client built outside the factory would not identify itself.
     */
    public function test_no_class_builds_the_client_without_the_factory(): void {
        $offenders = $this->find_classes_that_build_the_client_by_hand();

        $this->assertSame([], $offenders, 'Build the client with api_client_factory::ai_course_api().');
    }

    /**
     * List the classes of the plugin that create the client themselves.
     *
     * @return string[] Paths relative to the Moodle root.
     */
    private function find_classes_that_build_the_client_by_hand(): array {
        global $CFG;

        $factory = realpath($CFG->dirroot . '/local/coursegen/classes/local/api_client_factory.php');
        $paths = $this->list_php_files();
        $rootlength = strlen($CFG->dirroot);
        $offenders = [];
        foreach ($paths as $path) {
            if ($path !== $factory && $this->builds_the_client_by_hand($path)) {
                $offenders[] = substr($path, $rootlength);
            }
        }

        return $offenders;
    }

    /**
     * List every PHP file under the classes directory of the plugin.
     *
     * @return string[] Real paths.
     */
    private function list_php_files(): array {
        global $CFG;

        $directory = $CFG->dirroot . '/local/coursegen/classes';
        $recursive = new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($recursive);
        $paths = [];
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $paths[] = $file->getRealPath();
            }
        }

        return $paths;
    }

    /**
     * Whether a file creates the client of the course service itself.
     *
     * @param string $path Real path of the file.
     * @return bool
     */
    private function builds_the_client_by_hand(string $path): bool {
        $source = file_get_contents($path);
        $matches = preg_match(self::HAND_BUILT_CLIENT, $source);

        return $matches === 1;
    }
}
