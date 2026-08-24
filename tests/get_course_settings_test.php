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

use local_coursegen\local\models\course_session;
use local_coursegen\local\service\ai_course_api_service;

/**
 * Tests for the final review panel data of the generated course.
 *
 * The AI service is mocked, so no network request is ever performed. Loading
 * the external class pulls in lib/externallib.php, so each test runs in an
 * isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\get_course_settings
 *
 * @runTestsInSeparateProcesses
 */
final class get_course_settings_test extends \advanced_testcase {
    /**
     * Load the testable subclass fixture in the isolated process.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/testable_get_course_settings.php');
    }

    /**
     * Reset the injected double between tests.
     */
    protected function tearDown(): void {
        testable_get_course_settings::$mockservice = null;
        parent::tearDown();
    }

    /**
     * Create a planning session persistent owned by the given user.
     *
     * @param int $userid Owner user id.
     * @return course_session
     */
    private function make_session(int $userid): course_session {
        $session = new course_session(0, (object)[
            'userid' => $userid,
            'session_id' => 'thread-settings',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode([]),
        ]);
        $session->create();

        return $session;
    }

    /**
     * Inject an ai_course_api_service mock returning the given course result.
     *
     * @param array $resultdata Result payload under the 'result' key.
     * @return void
     */
    private function inject_result(array $resultdata): void {
        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_course_result'])
            ->getMock();
        $service->method('get_course_result')->willReturn(['result' => $resultdata]);

        testable_get_course_settings::$mockservice = $service;
    }

    /**
     * MDL-INT-017: The service returns the AI-inferred fullname and shortname
     * plus the list of site categories for the review panel.
     */
    public function test_settings_return_ai_names_and_site_categories(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $category = $this->getDataGenerator()->create_category();
        $session = $this->make_session(get_admin()->id);

        $this->inject_result([
            'course_configuration' => [
                'fullname' => 'Historia del arte moderno',
                'shortname' => 'arte-moderno',
                'category' => (int)$category->id,
            ],
        ]);

        $result = testable_get_course_settings::execute((int)$session->get('id'));

        $this->assertSame('Historia del arte moderno', $result['fullname']);
        $this->assertSame('arte-moderno', $result['shortname']);
        $this->assertSame((int)$category->id, (int)$result['category']);

        // The category list carries the site categories with their path names.
        $offeredids = array_map('intval', array_column($result['categories'], 'id'));
        $this->assertContains((int)$category->id, $offeredids);
        foreach ($result['categories'] as $offered) {
            $this->assertNotSame('', (string)$offered['pathname']);
        }
    }

    /**
     * MDL-INT-017: With a result lacking the course configuration the panel
     * receives the fallback values instead of failing.
     */
    public function test_settings_fall_back_without_course_configuration(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->make_session(get_admin()->id);
        $this->inject_result([]);

        $result = testable_get_course_settings::execute((int)$session->get('id'));

        $this->assertSame(get_string('createwithai', 'local_coursegen'), $result['fullname']);
        $this->assertStringStartsWith('courseai-', $result['shortname']);
        $this->assertSame((int)\core_course_category::get_default()->id, (int)$result['category']);
        $this->assertIsArray($result['categories']);
    }
}
