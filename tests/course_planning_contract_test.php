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
use local_coursegen\local\image_generation\activities;
use local_coursegen\local\service\course_planning_service;

/**
 * Contract tests for the full-course planning request.
 *
 * The payload the plugin hands to the AI service is captured through a mocked
 * API client injected with the api_client_factory seam, so no network request
 * is ever performed.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\course_planning_service::start_course_planning
 */
final class course_planning_contract_test extends \advanced_testcase {
    /**
     * Reset the injected doubles between tests.
     */
    protected function tearDown(): void {
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * Inject an API client mock that captures the /course/init request body.
     *
     * @param array|null $captured Reference that receives the payload handed to the client.
     * @return void
     */
    private function inject_api_client(?array &$captured = null): void {
        $client = $this->getMockBuilder(\aiprovider_datacurso\httpclient\ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request', 'get_base_url'])
            ->getMock();

        $client->method('request')->willReturnCallback(
            function (string $method, string $endpoint, $payload = null) use (&$captured) {
                if ($endpoint === '/course/init') {
                    $captured = (array)$payload;
                }
                return ['thread_id' => 'thread-1'];
            }
        );
        $client->method('get_base_url')->willReturn('https://ai.example.com/api/v1');

        api_client_factory::set_test_client($client);
    }

    /**
     * A configured (non-disabled) admin image mode must travel with the request,
     * so the service applies the site image rules to the planned course.
     */
    public function test_configured_image_policy_travels_with_the_planning_request(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $captured = null;
        $this->inject_api_client($captured);

        set_config('generationmode', activities::MODE_MANUAL, 'local_coursegen');

        $result = course_planning_service::start_course_planning(
            'Curso de biotecnología',
            'es',
            true,
            0,
            (int)$USER->id
        );

        $this->assertTrue($result['success'], 'Planning must start: ' . ($result['message'] ?? ''));
        $this->assertIsArray($captured);
        $this->assertTrue($captured['with_images']);
        $this->assertArrayHasKey('image_policy', $captured);
        $this->assertSame(activities::MODE_MANUAL, $captured['image_policy']['mode']);
    }

    /**
     * An unconfigured (disabled-by-default) admin image mode must NOT travel with
     * the planning request. Sending with_images=true together with a
     * mode=disabled policy is a contradictory instruction, and the service may
     * answer it with both an image-less and an illustrated copy of the same
     * unit, which lands as duplicated content in the course.
     *
     * Mirrors create_mod_stream_contract_test::
     * test_disabled_image_mode_is_not_sent_with_images_enabled for the
     * single-activity flow.
     */
    public function test_disabled_image_mode_is_not_sent_with_images_enabled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $captured = null;
        $this->inject_api_client($captured);

        // The generationmode setting is deliberately NOT configured: defaults to disabled.
        $result = course_planning_service::start_course_planning(
            'Curso de biotecnología',
            'es',
            true,
            0,
            (int)$USER->id
        );

        $this->assertTrue($result['success'], 'Planning must start: ' . ($result['message'] ?? ''));
        $this->assertIsArray($captured);
        $this->assertTrue($captured['with_images']);
        $this->assertArrayNotHasKey(
            'image_policy',
            $captured,
            'A disabled-by-default policy must not travel with the teacher image toggle enabled.'
        );
    }

    /**
     * With images off no policy travels either, whatever the site mode is.
     */
    public function test_no_image_policy_without_images(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $captured = null;
        $this->inject_api_client($captured);

        set_config('generationmode', activities::MODE_AUTO, 'local_coursegen');

        course_planning_service::start_course_planning(
            'Curso de biotecnología',
            'es',
            false,
            0,
            (int)$USER->id
        );

        $this->assertIsArray($captured);
        $this->assertFalse($captured['with_images']);
        $this->assertArrayNotHasKey('image_policy', $captured);
    }
}
