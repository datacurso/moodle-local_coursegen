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

namespace local_coursegen\local\service;

use aiprovider_datacurso\httpclient\ai_course_api;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\image_generation\activities;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/coursegen/tests/fixtures/aiprovider_datacurso_stub.php');

/**
 * Tests that the course planning request respects the teacher's image toggle.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\course_planning_service
 */
final class course_planning_image_policy_test extends \advanced_testcase {
    /** @var array|null Payload sent to the course init endpoint. */
    private ?array $payload = null;

    /**
     * Keep any accidental real call away from the network.
     */
    protected function setUp(): void {
        parent::setUp();
        set_config('datacurso_service_url', 'https://invalid.invalid', 'local_coursegen');
    }

    /**
     * Drop the injected client between tests.
     */
    protected function tearDown(): void {
        api_client_factory::set_test_client(null);
        $this->payload = null;
        parent::tearDown();
    }

    /**
     * A disabled policy must not reach the service when the teacher turns images on.
     */
    public function test_disabled_policy_is_left_out_when_images_are_on(): void {
        $this->start_planning(activities::MODE_DISABLED, true);

        $this->assertTrue($this->payload['with_images']);
        $this->assertArrayNotHasKey('image_policy', $this->payload);
    }

    /**
     * A policy that was never configured behaves as disabled and is left out too.
     */
    public function test_unconfigured_policy_is_left_out_when_images_are_on(): void {
        $this->start_planning(null, true);

        $this->assertTrue($this->payload['with_images']);
        $this->assertArrayNotHasKey('image_policy', $this->payload);
    }

    /**
     * A manual policy travels with the request so its per-part limits apply.
     */
    public function test_manual_policy_is_sent_when_images_are_on(): void {
        $this->start_planning(activities::MODE_MANUAL, true);

        $this->assertSame(activities::MODE_MANUAL, $this->payload['image_policy']['mode']);
    }

    /**
     * With the toggle off no policy is sent, whatever the site mode.
     */
    public function test_no_policy_is_sent_when_images_are_off(): void {
        $this->start_planning(activities::MODE_AUTO, false);

        $this->assertFalse($this->payload['with_images']);
        $this->assertArrayNotHasKey('image_policy', $this->payload);
    }

    /**
     * Start a planning session with the given site mode and teacher toggle.
     *
     * @param string|null $mode Site image generation mode, or null to leave it unset.
     * @param bool $withimages Teacher's image toggle.
     */
    private function start_planning(?string $mode, bool $withimages): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        if ($mode !== null) {
            set_config('generationmode', $mode, 'local_coursegen');
        }
        $this->inject_recording_client();

        $result = course_planning_service::start_course_planning(
            'Create a short course about volcanoes',
            'es',
            $withimages,
            0,
            (int) $USER->id
        );

        $this->assertTrue($result['success']);
        $this->assertIsArray($this->payload);
    }

    /**
     * Replace the API client with a double that records the course init payload.
     */
    private function inject_recording_client(): void {
        $client = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request', 'get_base_url'])
            ->getMock();
        $client->method('get_base_url')->willReturn('https://ai.invalid');
        $client->method('request')->willReturnCallback(
            function (string $method, string $path, array $body = []): array {
                if ($path === '/course/init') {
                    $this->payload = $body;
                }
                return ['thread_id' => 'thread-1', 'status' => 'started'];
            }
        );
        api_client_factory::set_test_client($client);
    }
}
