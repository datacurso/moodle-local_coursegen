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

use aiprovider_datacurso\httpclient\ai_course_api;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\service\create_mod_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/h5p_package_fixture.php');

/**
 * Tests for the download service configuration of the H5P package.
 *
 * Note about the regional selection: the region is determined by the license
 * through a live service call (ai_course_api::is_for_ue()), so it is not unit
 * testable without network access. These tests cover the configuration
 * override path: the plugin settings must be read and handed to the client
 * factory, which is what governs the download origin without code changes.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\api_client_factory
 */
final class h5pactivity_download_config_test extends \advanced_testcase {
    /**
     * Always remove the injected factory test double between tests.
     */
    protected function tearDown(): void {
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * Make the given course the current one.
     *
     * The module edit form resolves section info through the global $COURSE.
     * In a web request require_login() binds the page (and $COURSE) to the
     * course; without it, the theme initialisation triggered by the form
     * falls back to the site course and the target section cannot resolve.
     *
     * @param \stdClass $course Course record.
     * @return void
     */
    private function set_current_course(\stdClass $course): void {
        global $PAGE;
        $PAGE->set_course($course);
    }

    /**
     * Inject an ai_course_api mock whose download_file() returns a real draft file.
     *
     * @return void
     */
    private function inject_download_client(): void {
        $mock = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['download_file'])
            ->getMock();

        $mock->method('download_file')->willReturnCallback(
            function (string $endpoint, string $filename): \stored_file {
                global $USER;

                $fs = get_file_storage();
                $record = (object) [
                    'contextid' => \context_user::instance($USER->id)->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => file_get_unused_draft_itemid(),
                    'filepath' => '/',
                    'filename' => $filename,
                ];

                return $fs->create_file_from_string($record, h5p_package_fixture::bytes());
            }
        );

        api_client_factory::set_test_client($mock);
    }

    /**
     * Build an AI result payload for an H5P activity.
     *
     * @return array
     */
    private function h5p_resultinfo(): array {
        return [
            'resource_type' => 'h5pactivity',
            'parameters' => [
                'modulename' => 'h5pactivity',
                'name' => 'AI generated H5P',
                'introeditor' => ['text' => '<p>AI generated intro</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                'visible' => 1,
                'cmidnumber' => '',
                'grade' => 100,
                'grademethod' => 1,
                'gradepass' => 70,
                'enabletracking' => 1,
                'reviewmode' => 1,
                'mod_settings' => [
                    'file_path' => 'generated/packages/sample-activity.h5p',
                    'file_name' => 'sample-activity.h5p',
                ],
            ],
        ];
    }

    /**
     * MDL-INT-008: Development override URLs configured in the plugin
     * administration are read and handed to the client factory when the H5P
     * package is downloaded.
     */
    public function test_configured_override_urls_reach_client_factory(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('datacurso_service_url', 'https://dev-us.example.com/api/v1', 'local_coursegen');
        set_config('datacurso_service_url_eu', 'https://dev-eu.example.com/api/v1', 'local_coursegen');

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);
        $this->inject_download_client();

        create_mod_service::create_from_ai_result($this->h5p_resultinfo(), $course, 1);

        $urls = api_client_factory::get_last_urls();
        $this->assertNotNull($urls, 'The download must build its client through the factory.');
        $this->assertSame('https://dev-us.example.com/api/v1', $urls['baseurl']);
        $this->assertSame('https://dev-eu.example.com/api/v1', $urls['baseurleu']);
    }

    /**
     * MDL-INT-008: Without configured overrides the factory receives nulls, so
     * the client falls back to the default service URLs for each region.
     */
    public function test_unconfigured_urls_fall_back_to_client_defaults(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        unset_config('datacurso_service_url', 'local_coursegen');
        unset_config('datacurso_service_url_eu', 'local_coursegen');

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);
        $this->inject_download_client();

        create_mod_service::create_from_ai_result($this->h5p_resultinfo(), $course, 1);

        $urls = api_client_factory::get_last_urls();
        $this->assertNotNull($urls, 'The download must build its client through the factory.');
        $this->assertNull($urls['baseurl']);
        $this->assertNull($urls['baseurleu']);
    }

    /**
     * MDL-INT-009: After deploying a plugin version with new classes, H5P
     * activity creation works once the site caches are purged.
     *
     * Manual deployment procedure (not a pending feature): purging caches
     * cannot be automated from PHPUnit. With a stale class map the symptom is
     * now a clear diagnostic error that blocks the package-less creation
     * (see MDL-UNIT-001, fixed 14/08/2026).
     */
    public function test_class_map_purge_after_deployment(): void {
        $this->markTestSkipped('Procedimiento manual de despliegue: purga de caches');
    }
}
