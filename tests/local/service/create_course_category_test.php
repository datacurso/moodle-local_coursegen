<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursegen\local\service;

use local_coursegen\local\models\course_session;

/**
 * Tests for the category a course generated with AI is created in.
 *
 * The AI service proposes a category without knowing the categories of the site, so the
 * proposal may not exist there, or the user may not be allowed to create courses in it.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_course_service
 */
final class create_course_category_test extends \advanced_testcase {
    use \local_coursegen\tests\requires_workplace;

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
            'thread-category-test'
        );
    }

    /**
     * Build the result of the AI service proposing a category.
     *
     * @param int $categoryid Category proposed by the AI service.
     * @return array
     */
    private function result_proposing(int $categoryid): array {
        return [
            'course_configuration' => [
                'fullname' => 'AI planned course',
                'shortname' => 'aiplanned',
                'category' => $categoryid,
            ],
        ];
    }

    /**
     * An id that no course category of the site holds.
     *
     * @return int
     */
    private function missing_category_id(): int {
        global $DB;
        return (int) $DB->get_field_sql('SELECT MAX(id) FROM {course_categories}') + 1000;
    }

    /**
     * Create a user who may create courses in one category only.
     *
     * @param \core_course_category $category Category where the user may create courses.
     * @return \stdClass The user.
     */
    private function create_category_creator(\core_course_category $category): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Category course creator', 'categorycoursecreator', '');
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        $categorycontext = \context_coursecat::instance($category->id);
        assign_capability('moodle/course:create', CAP_ALLOW, $roleid, $categorycontext->id, true);
        role_assign($roleid, $user->id, $categorycontext->id);
        return $user;
    }

    /**
     * A category proposed by the AI service that the site does not have falls back to the default category.
     */
    public function test_missing_proposed_category_falls_back_to_default(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $session = $this->create_session((int) get_admin()->id);

        $result = create_course_service::create_course($session, $this->result_proposing($this->missing_category_id()));

        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $course = $DB->get_record('course', ['id' => $result['courseid']], '*', MUST_EXIST);
        $this->assertEquals(\core_course_category::get_default()->id, $course->category);
    }

    /**
     * A missing proposed category falls back to the category of the user's tenant.
     */
    public function test_missing_proposed_category_falls_back_to_the_tenant_category(): void {
        global $DB, $USER;
        $this->require_tool_tenant();
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenantcategory = $this->getDataGenerator()->create_category();
        $generator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $tenantid = (int) $generator->create_tenant(['categoryid' => $tenantcategory->id])->id;
        $generator->allocate_user((int) $USER->id, $tenantid);
        $session = $this->create_session((int) $USER->id);

        $result = create_course_service::create_course($session, $this->result_proposing($this->missing_category_id()));

        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $this->assertEquals($tenantcategory->id, $DB->get_field('course', 'category', ['id' => $result['courseid']]));
    }

    /**
     * A proposed category the user cannot create in falls back to the first category they can create in.
     */
    public function test_forbidden_proposed_category_falls_back_to_an_allowed_one(): void {
        global $DB;
        $this->resetAfterTest();
        $allowed = \core_course_category::create(['name' => 'Allowed category']);
        $forbidden = \core_course_category::create(['name' => 'Forbidden category']);
        $user = $this->create_category_creator($allowed);
        $this->setUser($user);
        $session = $this->create_session((int) $user->id);

        $result = create_course_service::create_course($session, $this->result_proposing((int) $forbidden->id));

        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $course = $DB->get_record('course', ['id' => $result['courseid']], '*', MUST_EXIST);
        $this->assertEquals($allowed->id, $course->category);
    }

    /**
     * A usable proposed category is kept.
     */
    public function test_usable_proposed_category_is_kept(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $proposed = \core_course_category::create(['name' => 'Proposed category']);
        $session = $this->create_session((int) get_admin()->id);

        $result = create_course_service::create_course($session, $this->result_proposing((int) $proposed->id));

        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $this->assertEquals($proposed->id, $DB->get_field('course', 'category', ['id' => $result['courseid']]));
    }

    /**
     * A picked category that no longer exists is refused with a clear message and creates nothing.
     */
    public function test_missing_picked_category_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $session = $this->create_session((int) get_admin()->id);
        $coursesbefore = $DB->count_records('course');

        try {
            create_course_service::create_course($session, [], ['category' => $this->missing_category_id()]);
            $this->fail('A picked category that does not exist must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_category_unavailable', $e->errorcode);
        }

        $this->assertSame($coursesbefore, $DB->count_records('course'));
    }

    /**
     * The review panel is offered the category the course would be created in.
     */
    public function test_course_settings_offer_the_effective_category(): void {
        $this->resetAfterTest();
        $allowed = \core_course_category::create(['name' => 'Allowed category']);
        $user = $this->create_category_creator($allowed);
        $this->setUser($user);
        $session = $this->create_session((int) $user->id);

        $settings = create_course_service::get_course_settings($session, $this->result_proposing($this->missing_category_id()));

        $this->assertSame((int) $allowed->id, $settings['category']);
    }

    /**
     * Without any category where the user may create courses there is no first allowed category.
     */
    public function test_first_allowed_category_is_zero_without_permissions(): void {
        $this->resetAfterTest();
        $allowed = \core_course_category::create(['name' => 'Allowed category']);
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertSame(0, create_course_service::first_allowed_category());

        $this->setUser($this->create_category_creator($allowed));
        $this->assertSame((int) $allowed->id, create_course_service::first_allowed_category());
    }
}
