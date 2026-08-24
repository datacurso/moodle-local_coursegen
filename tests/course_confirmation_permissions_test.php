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
 * Capability tests for the plan adjustment and final confirmation endpoints.
 *
 * SECURITY REGRESSION GUARDS: these tests implement the CORRECT behavior
 * documented in the test-case definitions. The endpoints must verify the
 * course creation permissions besides session ownership, so a user who loses
 * them after starting a session can no longer adjust the plan, read the
 * generated settings or create the course. Do not soften them.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\get_course_settings
 * @covers     \local_coursegen\external\create_course
 * @covers     \local_coursegen\external\course_planning_feedback
 *
 * @runTestsInSeparateProcesses
 */
final class course_confirmation_permissions_test extends \advanced_testcase {
    /**
     * Load the testable subclass fixtures in the isolated process.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/testable_get_course_settings.php');
        require_once(__DIR__ . '/fixtures/testable_create_course.php');
        require_once(__DIR__ . '/fixtures/testable_course_planning_feedback.php');
    }

    /**
     * Reset the injected doubles between tests.
     */
    protected function tearDown(): void {
        testable_get_course_settings::$mockservice = null;
        testable_create_course::$mockservice = null;
        testable_course_planning_feedback::$mockservice = null;
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
            'session_id' => 'thread-caps',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode([]),
        ]);
        $session->create();

        return $session;
    }

    /**
     * Build an ai_course_api_service mock returning the given course result.
     *
     * @param array $resultdata Result payload under the 'result' key.
     * @return ai_course_api_service
     */
    private function result_service(array $resultdata): ai_course_api_service {
        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_course_result'])
            ->getMock();
        $service->method('get_course_result')->willReturn(['result' => $resultdata]);

        return $service;
    }

    /**
     * MDL-INT-002: Querying the generated course settings requires the same
     * permissions as starting the flow; a user who lost them after starting
     * the session must be rejected.
     */
    public function test_settings_query_requires_flow_capabilities(): void {
        $this->resetAfterTest();

        // The user owns the session but holds no capability at all, as if the
        // permissions were revoked after starting the flow.
        $user = $this->getDataGenerator()->create_user();
        $session = $this->make_session((int)$user->id);
        $this->setUser($user);

        testable_get_course_settings::$mockservice = $this->result_service([]);

        $this->expectException(\required_capability_exception::class);
        testable_get_course_settings::execute((int)$session->get('id'));
    }

    /**
     * MDL-INT-002: Creating the course requires the same permissions as
     * starting the flow; a user who lost them after starting the session must
     * not be able to complete the creation.
     */
    public function test_create_course_requires_flow_capabilities(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $session = $this->make_session((int)$user->id);
        $this->setUser($user);

        testable_create_course::$mockservice = $this->result_service([
            'course_configuration' => ['fullname' => 'Curso sin permisos', 'shortname' => 'sin-permisos'],
        ]);

        $this->expectException(\required_capability_exception::class);
        testable_create_course::execute((int)$session->get('id'));
    }

    /**
     * MDL-INT-003: Sending adjustments or accepting the plan requires the
     * course flow permissions in addition to session ownership.
     */
    public function test_plan_feedback_requires_flow_capabilities(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $session = $this->make_session((int)$user->id);
        $this->setUser($user);

        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['send_planning_feedback'])
            ->getMock();
        $service->method('send_planning_feedback')->willReturn([]);
        testable_course_planning_feedback::$mockservice = $service;

        $this->expectException(\required_capability_exception::class);
        testable_course_planning_feedback::execute((int)$session->get('id'), ['action' => 'accept']);
    }

    /**
     * MDL-INT-018: The offered category list corresponds to the categories
     * where the user can create courses.
     */
    public function test_offered_categories_follow_course_create_capability(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $categorycontext = \context_coursecat::instance($category->id);

        // The user can create courses inside that category only.
        $user = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('moodle/course:create', CAP_ALLOW, $roleid, $categorycontext->id);
        role_assign($roleid, $user->id, $categorycontext->id);

        $session = $this->make_session((int)$user->id);
        $this->setUser($user);

        testable_get_course_settings::$mockservice = $this->result_service([]);

        $result = testable_get_course_settings::execute((int)$session->get('id'));

        $offeredids = array_map('intval', array_column($result['categories'] ?? [], 'id'));
        $this->assertContains(
            (int)$category->id,
            $offeredids,
            'The category list must offer the categories where the user can create courses.'
        );
    }

    /**
     * MDL-INT-018: The category sent when creating the course is validated
     * against the user's permissions.
     */
    public function test_chosen_category_validated_against_user_permissions(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $allowedcategory = $generator->create_category();
        $forbiddencategory = $generator->create_category();

        // The user can create courses in the allowed category only.
        $user = $generator->create_user();
        $roleid = $generator->create_role();
        $allowedcontext = \context_coursecat::instance($allowedcategory->id);
        assign_capability('moodle/course:create', CAP_ALLOW, $roleid, $allowedcontext->id);
        role_assign($roleid, $user->id, $allowedcontext->id);

        $session = $this->make_session((int)$user->id);
        $this->setUser($user);

        testable_create_course::$mockservice = $this->result_service([
            'course_configuration' => ['fullname' => 'Curso en categoria ajena', 'shortname' => 'cat-ajena'],
        ]);

        $this->expectException(\required_capability_exception::class);
        testable_create_course::execute((int)$session->get('id'), '', '', (int)$forbiddencategory->id);
    }
}
