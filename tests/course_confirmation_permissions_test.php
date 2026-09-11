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

use local_coursegen\external\course_planning_feedback;
use local_coursegen\external\create_course;
use local_coursegen\external\get_course_session_state;
use local_coursegen\external\regenerate_detailed_item;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\create_course_service;

/**
 * Capability gate tests for the full-course confirmation flow web services.
 *
 * The AI service is mocked (or never reached, because the gates fire first),
 * so no network request is ever performed. The testable subclass fixture and
 * the external classes load lib/externallib.php, which requires each test to
 * run in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\create_course
 * @covers     \local_coursegen\external\get_course_settings
 * @covers     \local_coursegen\external\course_planning_feedback
 * @covers     \local_coursegen\external\get_course_session_state
 * @covers     \local_coursegen\external\regenerate_detailed_item
 * @covers     \local_coursegen\local\service\create_course_service
 *
 * @runTestsInSeparateProcesses
 */
final class course_confirmation_permissions_test extends \advanced_testcase {
    /**
     * Load the testable subclass in the isolated process.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/testable_get_course_settings.php');

        // Any accidental real API call must fail fast instead of reaching the network.
        set_config('datacurso_service_url', 'https://invalid.invalid', 'local_coursegen');
    }

    /**
     * Reset the injected doubles between tests.
     */
    protected function tearDown(): void {
        testable_get_course_settings::$mockservice = null;
        parent::tearDown();
    }

    /**
     * Create a planning session owned by the given user.
     *
     * @param int $userid Owner user id.
     * @return course_session
     */
    private function create_session(int $userid): course_session {
        return course_session_service::create_from_form_data(
            (object) ['fullname' => 'Planned course'],
            $userid,
            'thread-test-1'
        );
    }

    /**
     * Give the user the local/coursegen:createcoursewithai capability at system level.
     *
     * @param int $userid User id.
     * @return void
     */
    private function allow_createcoursewithai_at_system(int $userid): void {
        $systemcontext = \context_system::instance();
        $roleid = create_role('AI course creator', 'aicoursecreator', '');
        assign_capability('local/coursegen:createcoursewithai', CAP_ALLOW, $roleid, $systemcontext->id, true);
        role_assign($roleid, $userid, $systemcontext->id);
    }

    /**
     * Give the user moodle/course:create in one category only.
     *
     * @param int $userid User id.
     * @param \core_course_category $category Category where the user may create courses.
     * @return void
     */
    private function allow_course_create_in_category(int $userid, \core_course_category $category): void {
        $categorycontext = \context_coursecat::instance($category->id);
        $roleid = create_role('Category course creator', 'catcoursecreator', '');
        assign_capability('moodle/course:create', CAP_ALLOW, $roleid, $categorycontext->id, true);
        role_assign($roleid, $userid, $categorycontext->id);
    }

    /**
     * Run a callback and assert it throws required_capability_exception.
     *
     * Used instead of expectException so pending debugging notices emitted
     * before the throw can still be consumed afterwards.
     *
     * @param callable $callback Web service call.
     * @param string $label Failure label.
     * @return void
     */
    private function assert_requires_capability(callable $callback, string $label): void {
        try {
            $callback();
            $this->fail($label . ' must throw required_capability_exception for a user without the capabilities.');
        } catch (\required_capability_exception $e) {
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }
        $this->resetDebugging();
    }

    /**
     * A user without the capabilities is rejected by create_course.
     */
    public function test_create_course_rejects_user_without_capabilities(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $session = $this->create_session($user->id);

        $this->assert_requires_capability(static function () use ($session): void {
            create_course::execute((int) $session->get('id'));
        }, 'create_course');
    }

    /**
     * A user without the capabilities is rejected by get_course_settings.
     */
    public function test_get_course_settings_rejects_user_without_capabilities(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $session = $this->create_session($user->id);

        $this->assert_requires_capability(static function () use ($session): void {
            testable_get_course_settings::execute((int) $session->get('id'));
        }, 'get_course_settings');
    }

    /**
     * A user with createcoursewithai but no course:create anywhere is rejected
     * by get_course_settings.
     */
    public function test_get_course_settings_rejects_user_without_any_course_create(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->allow_createcoursewithai_at_system($user->id);
        $this->setUser($user);
        $session = $this->create_session($user->id);

        $this->assert_requires_capability(static function () use ($session): void {
            testable_get_course_settings::execute((int) $session->get('id'));
        }, 'get_course_settings without course:create');
    }

    /**
     * A user without the capabilities is rejected by course_planning_feedback
     * even for a session they own.
     */
    public function test_course_planning_feedback_rejects_user_without_capabilities(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $session = $this->create_session($user->id);

        $this->assert_requires_capability(static function () use ($session): void {
            course_planning_feedback::execute((int) $session->get('id'), [
                'action' => 'accept',
                'target_ids' => [],
            ]);
        }, 'course_planning_feedback');
    }

    /**
     * A user without the capabilities is rejected by get_course_session_state
     * even for a session they own.
     */
    public function test_get_course_session_state_rejects_user_without_capabilities(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $session = $this->create_session($user->id);

        $this->assert_requires_capability(static function () use ($session): void {
            get_course_session_state::execute((int) $session->get('id'));
        }, 'get_course_session_state');
    }

    /**
     * A user without the capabilities is rejected by regenerate_detailed_item
     * even for a session they own.
     */
    public function test_regenerate_detailed_item_rejects_user_without_capabilities(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $session = $this->create_session($user->id);

        $this->assert_requires_capability(static function () use ($session): void {
            regenerate_detailed_item::execute((int) $session->get('id'), 'section', 0);
        }, 'regenerate_detailed_item');
    }

    /**
     * A user holding course:create in one category only is offered exactly that
     * category by get_course_settings.
     */
    public function test_category_creator_is_offered_only_their_category(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $cata = \core_course_category::create(['name' => 'Allowed category']);
        $catb = \core_course_category::create(['name' => 'Forbidden category']);

        $user = $generator->create_user();
        $this->allow_createcoursewithai_at_system($user->id);
        $this->allow_course_create_in_category($user->id, $cata);
        $this->setUser($user);

        $session = $this->create_session($user->id);

        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_course_result'])
            ->getMock();
        $service->method('get_course_result')->willReturn([
            'result' => [
                'course_configuration' => [
                    'fullname' => 'AI planned course',
                    'shortname' => 'aiplanned',
                    'category' => (int) $cata->id,
                ],
            ],
        ]);
        testable_get_course_settings::$mockservice = $service;

        $result = testable_get_course_settings::execute((int) $session->get('id'));

        $this->assertSame('AI planned course', $result['fullname']);
        $offeredids = array_column($result['categories'], 'id');
        $this->assertSame([(int) $cata->id], $offeredids);
        $this->assertNotContains((int) $catb->id, $offeredids);
    }

    /**
     * A category-level creator can create a course into their own category.
     */
    public function test_category_creator_can_create_course_in_own_category(): void {
        global $DB;

        $this->resetAfterTest();

        $cata = \core_course_category::create(['name' => 'Allowed category']);

        $user = $this->getDataGenerator()->create_user();
        $this->allow_createcoursewithai_at_system($user->id);
        $this->allow_course_create_in_category($user->id, $cata);
        $this->setUser($user);

        $session = $this->create_session($user->id);

        $result = create_course_service::create_course($session, [], ['category' => (int) $cata->id]);

        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $course = $DB->get_record('course', ['id' => $result['courseid']], '*', MUST_EXIST);
        $this->assertEquals($cata->id, $course->category);
    }

    /**
     * A category-level creator is rejected when creating into another category.
     */
    public function test_category_creator_rejected_creating_in_other_category(): void {
        global $DB;

        $this->resetAfterTest();

        $cata = \core_course_category::create(['name' => 'Allowed category']);
        $catb = \core_course_category::create(['name' => 'Forbidden category']);

        $user = $this->getDataGenerator()->create_user();
        $this->allow_createcoursewithai_at_system($user->id);
        $this->allow_course_create_in_category($user->id, $cata);
        $this->setUser($user);

        $session = $this->create_session($user->id);
        $coursesbefore = $DB->count_records('course');

        try {
            create_course_service::create_course($session, [], ['category' => (int) $catb->id]);
            $this->fail('Creating into a category without moodle/course:create must throw.');
        } catch (\required_capability_exception $e) {
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }

        // No residue: no course was created anywhere.
        $this->assertSame($coursesbefore, $DB->count_records('course'));
    }

    /**
     * Foreign-session access is still rejected with the existing session error,
     * even for a fully privileged user.
     */
    public function test_foreign_session_access_keeps_existing_session_error(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $session = $this->create_session($owner->id);
        $recordid = (int) $session->get('id');

        $this->setAdminUser();

        $expected = get_string('error_no_session_found', 'local_coursegen');
        $calls = [
            'course_planning_feedback' => static function () use ($recordid): void {
                course_planning_feedback::execute($recordid, ['action' => 'accept', 'target_ids' => []]);
            },
            'get_course_session_state' => static function () use ($recordid): void {
                get_course_session_state::execute($recordid);
            },
            'regenerate_detailed_item' => static function () use ($recordid): void {
                regenerate_detailed_item::execute($recordid, 'section', 0);
            },
        ];

        foreach ($calls as $label => $call) {
            try {
                $call();
                $this->fail($label . ' must reject a foreign session.');
            } catch (\required_capability_exception $e) {
                $this->fail($label . ' must keep the session-not-found error for foreign sessions.');
            } catch (\moodle_exception $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), $label);
            }
            $this->resetDebugging();
        }
    }
}
