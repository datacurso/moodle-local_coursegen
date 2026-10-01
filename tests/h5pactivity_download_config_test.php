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
use local_coursegen\local\service\create_mod_service;
use local_coursegen\tests\api_testcase;

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
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\local\api_client_factory::class)]
final class h5pactivity_download_config_test extends api_testcase {
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

        create_mod_service::create_from_ai_result($this->h5p_activity_result(), $course, 1);

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

        create_mod_service::create_from_ai_result($this->h5p_activity_result(), $course, 1);

        $urls = api_client_factory::get_last_urls();
        $this->assertNotNull($urls, 'The download must build its client through the factory.');
        $this->assertNull($urls['baseurl']);
        $this->assertNull($urls['baseurleu']);
    }
}
