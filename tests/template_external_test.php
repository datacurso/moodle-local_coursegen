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

use core_external\external_api;
use local_coursegen\external\delete_template;
use local_coursegen\external\get_course_preview;
use local_coursegen\external\save_template;
use local_coursegen\external\search_template_courses;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/template_test_helper.php');

/**
 * Tests for the web services of the templates: what they return and who may call them.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\save_template
 * @covers     \local_coursegen\external\delete_template
 * @covers     \local_coursegen\external\search_template_courses
 * @covers     \local_coursegen\external\get_course_preview
 * @covers     \local_coursegen\local\template\template_access
 */
final class template_external_test extends \advanced_testcase {
    use template_test_helper;

    /**
     * Start with a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Create a user with a system role that holds only the capability to manage templates.
     *
     * @return \stdClass The user.
     */
    private function make_template_manager(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $systemcontext = \context_system::instance();
        assign_capability('local/coursegen:managetemplates', CAP_ALLOW, $roleid, $systemcontext->id, true);
        role_assign($roleid, $user->id, $systemcontext->id);

        return $user;
    }

    /**
     * Save a template through the web service and clean what it returns.
     *
     * @param \stdClass $course Course of the template.
     * @param array $items Activities of the template.
     * @param int $templateid Template to replace, or 0.
     * @return array What the web service returns.
     */
    private function call_save(\stdClass $course, array $items, int $templateid = 0): array {
        $result = save_template::execute($templateid, (int) $course->id, 'Marketing', 'Base', $items);
        $definition = save_template::execute_returns();

        return external_api::clean_returnvalue($definition, $result);
    }

    /**
     * An admin saves a template and the web service returns its id and how many activities it has.
     */
    public function test_admin_saves_a_template(): void {
        global $DB;
        $this->setAdminUser();
        [$course, $page, $quiz] = $this->make_course();
        $items = [
            ['cmid' => $page, 'action' => 'ai', 'instruction' => 'Make it shorter'],
            ['cmid' => $quiz, 'action' => 'keep', 'instruction' => ''],
        ];

        $returned = $this->call_save($course, $items);

        $this->assertSame(2, $returned['itemcount']);
        $exists = $DB->record_exists('local_coursegen_template', ['id' => $returned['templateid']]);
        $this->assertTrue($exists);
    }

    /**
     * A user with the capability to manage templates and to see the course can save.
     */
    public function test_template_manager_who_sees_the_course_can_save(): void {
        [$course] = $this->make_course();
        $manager = $this->make_template_manager();
        $this->getDataGenerator()->enrol_user($manager->id, $course->id, 'editingteacher');
        $this->setUser($manager);

        $returned = $this->call_save($course, []);

        $this->assertGreaterThan(0, $returned['templateid']);
    }

    /**
     * A template manager who cannot see the course cannot base a template on it.
     */
    public function test_template_manager_who_cannot_see_the_course_is_rejected(): void {
        global $DB;
        [$course] = $this->make_course();
        $manager = $this->make_template_manager();
        $this->setUser($manager);

        try {
            $this->call_save($course, []);
            $this->fail('A user who cannot see the course must be rejected.');
        } catch (\required_capability_exception $exception) {
            $value = $exception->getMessage();
            $this->assertStringContainsString('course:view', $value);
        }

        $count = $DB->count_records('local_coursegen_template');
        $this->assertSame(0, $count);
    }

    /**
     * Users without the capability cannot save, delete or search, whatever their role.
     */
    public function test_users_without_the_capability_are_rejected(): void {
        global $DB;
        [$course] = $this->make_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setAdminUser();
        $returned = $this->call_save($course, []);
        $users = ['teacher' => $teacher, 'student' => $student];

        foreach ($users as $label => $user) {
            $this->setUser($user);
            try {
                $this->call_save($course, []);
                $this->fail($label . ' must not save.');
            } catch (\moodle_exception $exception) {
                $this->assertInstanceOf(\required_capability_exception::class, $exception, $label);
            }
        }

        $count = $DB->count_records('local_coursegen_template');
        $this->assertSame(1, $count);
        $exists = $DB->record_exists('local_coursegen_template', ['id' => $returned['templateid']]);
        $this->assertTrue($exists);
    }

    /**
     * A guest and a user who is not logged in are rejected.
     */
    public function test_guest_and_anonymous_are_rejected(): void {
        [$course] = $this->make_course();

        $this->setGuestUser();
        $this->expectException(\moodle_exception::class);

        $this->call_save($course, []);
    }

    /**
     * Deleting needs the capability, and an admin can delete.
     */
    public function test_delete_needs_the_capability(): void {
        global $DB;
        [$course] = $this->make_course();
        $this->setAdminUser();
        $returned = $this->call_save($course, []);
        $templateid = $returned['templateid'];
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setUser($student);
        try {
            delete_template::execute($templateid);
            $this->fail('A student must not delete a template.');
        } catch (\required_capability_exception $exception) {
            $exists = $DB->record_exists('local_coursegen_template', ['id' => $templateid]);
            $this->assertTrue($exists);
        }

        $this->setAdminUser();
        $deleted = delete_template::execute($templateid);
        $this->assertTrue($deleted['deleted']);
        $exists2 = $DB->record_exists('local_coursegen_template', ['id' => $templateid]);
        $this->assertFalse($exists2);
    }

    /**
     * The parameters are validated: a wrong type is rejected before anything is saved.
     */
    public function test_wrong_parameter_types_are_rejected(): void {
        $this->setAdminUser();
        [$course] = $this->make_course();

        $this->expectException(\invalid_parameter_exception::class);

        save_template::execute(0, (int) $course->id, 'Name', 'Base', [['cmid' => 'abc', 'action' => 'keep']]);
    }

    /**
     * Searching needs the capability and returns only courses the user can see.
     */
    public function test_search_returns_only_visible_courses(): void {
        $generator = $this->getDataGenerator();
        $first = $generator->create_course(['fullname' => 'Digital marketing', 'shortname' => 'DM1']);
        $second = $generator->create_course(['fullname' => 'Cooking', 'shortname' => 'CK1']);
        $manager = $this->make_template_manager();
        $generator->enrol_user($manager->id, $first->id, 'editingteacher');
        $this->setUser($manager);

        $found = search_template_courses::execute(0, '');

        $ids = array_column($found, 'id');
        $this->assertSame([(int) $first->id], $ids);
        $this->assertNotContains((int) $second->id, $ids);
    }

    /**
     * A user without the capability cannot search.
     */
    public function test_search_needs_the_capability(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);

        search_template_courses::execute(0, '');
    }

    /**
     * Call the preview web service and clean what it returns.
     *
     * @param int $courseid Course whose sections are drawn.
     * @param int $templateid Template whose saved choices preselect the review, or 0.
     * @return array What the web service returns.
     */
    private function call_preview(int $courseid, int $templateid = 0): array {
        $result = get_course_preview::execute($courseid, $templateid);
        $definition = get_course_preview::execute_returns();

        return external_api::clean_returnvalue($definition, $result);
    }

    /**
     * The preview draws the sections of the course with every activity kept.
     */
    public function test_preview_draws_the_sections_of_the_course(): void {
        $this->setAdminUser();
        [$course, $page, $quiz] = $this->make_course();

        $returned = $this->call_preview((int) $course->id);

        $this->assertSame((int) $course->id, $returned['courseid']);
        $this->assertSame($course->shortname, $returned['shortname']);
        $this->assertStringContainsString('data-for="cmitem" data-id="' . $page . '"', $returned['html']);
        $this->assertStringContainsString('data-for="cmitem" data-id="' . $quiz . '"', $returned['html']);
        $this->assertSame(0, preg_match('/<option value="ai"[^>]* selected/', $returned['html']));
    }

    /**
     * The preview preselects what the template saved for each activity.
     */
    public function test_preview_preselects_what_the_template_saved(): void {
        $this->setAdminUser();
        [$course, $page] = $this->make_course();
        $saved = $this->call_save($course, [['cmid' => $page, 'action' => 'ai', 'instruction' => 'Shorter']]);

        $returned = $this->call_preview((int) $course->id, $saved['templateid']);

        $this->assertSame(1, preg_match('/<option value="ai"[^>]* selected/', $returned['html']));
        $this->assertStringContainsString('Shorter</textarea>', $returned['html']);
    }

    /**
     * A course that does not exist cannot be previewed.
     */
    public function test_preview_rejects_a_course_that_does_not_exist(): void {
        $this->setAdminUser();

        $this->expectException(\moodle_exception::class);

        $this->call_preview(987654);
    }

    /**
     * A template that does not exist cannot be previewed.
     */
    public function test_preview_rejects_a_template_that_does_not_exist(): void {
        $this->setAdminUser();
        [$course] = $this->make_course();

        $this->expectException(\moodle_exception::class);

        $this->call_preview((int) $course->id, 987654);
    }

    /**
     * Users without the capability cannot preview, and neither can a manager who cannot see the course.
     */
    public function test_preview_needs_the_capability_and_the_course(): void {
        [$course] = $this->make_course();
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        try {
            $this->call_preview((int) $course->id);
            $this->fail('A user without the capability must be rejected.');
        } catch (\required_capability_exception $exception) {
            $this->assertStringContainsString('managetemplates', $exception->getMessage());
        }

        $manager = $this->make_template_manager();
        $this->setUser($manager);

        $this->expectException(\required_capability_exception::class);

        $this->call_preview((int) $course->id);
    }

    /**
     * Anonymous users and guests cannot preview.
     */
    public function test_preview_rejects_guest_and_anonymous(): void {
        [$course] = $this->make_course();
        $this->setGuestUser();

        $this->expectException(\required_capability_exception::class);

        $this->call_preview((int) $course->id);
    }
}
