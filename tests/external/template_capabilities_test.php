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

use local_coursegen\external\delete_template;
use local_coursegen\external\get_category_tree;
use local_coursegen\external\get_course_structure;
use local_coursegen\external\get_courses_by_category;
use local_coursegen\external\get_templates;
use local_coursegen\external\save_template;
use local_coursegen\local\models\template;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../capability_user_trait.php');

/**
 * The web services of the course templates ask for the capability of exactly what they do.
 *
 * A user who has every other capability of the plugin must still be refused, and a user who
 * has only the one capability must be let in.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\get_templates
 * @covers     \local_coursegen\external\delete_template
 * @covers     \local_coursegen\external\save_template
 * @covers     \local_coursegen\external\get_category_tree
 * @covers     \local_coursegen\external\get_courses_by_category
 * @covers     \local_coursegen\external\get_course_structure
 * @covers     \local_coursegen\local\service\access_guard
 *
 * @runTestsInSeparateProcesses
 */
final class template_capabilities_test extends \advanced_testcase {
    use capability_user_trait;

    /**
     * A saved template on a new course.
     *
     * @return template
     */
    private function create_template(): template {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $template = new template(0, (object) ['name' => 'Saved', 'courseid' => $course->id]);
        $template->create();
        return $template;
    }

    /**
     * The arguments of a save of a template with no sections.
     *
     * @param int $courseid The base course.
     * @param int $templateid The template, 0 for a new one.
     * @return array The arguments of save_template::execute().
     */
    private function plain_save(int $courseid, int $templateid = 0): array {
        return [$templateid, 'Plain', '', $courseid, 0, false, '', 1, []];
    }

    /**
     * The templates list needs the capability to view templates.
     */
    public function test_the_list_allows_the_view_capability(): void {
        $this->resetAfterTest();
        $this->create_template();
        $this->login_user_with(['local/coursegen:viewtemplates']);

        $result = get_templates::execute();

        $this->assertCount(1, $result);
    }

    /**
     * Having every other capability is not enough to list the templates.
     */
    public function test_the_list_refuses_every_other_capability(): void {
        $this->resetAfterTest();
        $this->login_without('local/coursegen:viewtemplates');

        $this->expectException(\required_capability_exception::class);
        get_templates::execute();
    }

    /**
     * A user with no capability cannot list the templates.
     */
    public function test_the_list_refuses_a_user_with_no_capability(): void {
        $this->resetAfterTest();
        $this->login_user_with([]);

        $this->expectException(\required_capability_exception::class);
        get_templates::execute();
    }

    /**
     * A guest cannot list the templates.
     */
    public function test_the_list_refuses_a_guest(): void {
        $this->resetAfterTest();
        $this->setGuestUser();

        $this->expectException(\required_capability_exception::class);
        get_templates::execute();
    }

    /**
     * The administrator may list the templates.
     */
    public function test_the_list_allows_the_administrator(): void {
        $this->resetAfterTest();
        $this->create_template();
        $this->setAdminUser();

        $result = get_templates::execute();

        $this->assertCount(1, $result);
    }

    /**
     * A role override that prevents the capability takes it away.
     */
    public function test_the_list_respects_a_prevent_override(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $user = $this->login_user_with(['local/coursegen:viewtemplates']);
        $roles = get_user_roles($context, $user->id);
        $role = reset($roles);
        assign_capability('local/coursegen:viewtemplates', CAP_PREVENT, $role->roleid, $context->id, true);

        $this->expectException(\required_capability_exception::class);
        get_templates::execute();
    }

    /**
     * Deleting a template needs the capability to delete templates.
     */
    public function test_delete_allows_the_delete_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $template = $this->create_template();
        $id = (int) $template->get('id');
        $this->login_user_with(['local/coursegen:deletetemplates']);

        $result = delete_template::execute($id);

        $exists = $DB->record_exists('local_coursegen_template', ['id' => $id]);
        $this->assertTrue($result['success']);
        $this->assertFalse($exists);
    }

    /**
     * Being allowed to view, create and edit templates does not allow deleting one.
     */
    public function test_delete_refuses_every_other_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $template = $this->create_template();
        $id = (int) $template->get('id');
        $this->login_without('local/coursegen:deletetemplates');

        try {
            delete_template::execute($id);
            $this->fail('The template was deleted without the capability to delete.');
        } catch (\required_capability_exception $exception) {
            $exists = $DB->record_exists('local_coursegen_template', ['id' => $id]);
            $this->assertTrue($exists);
        }
    }

    /**
     * Creating a template needs the capability to create, and nothing else.
     */
    public function test_creating_needs_only_the_create_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $arguments = $this->plain_save((int) $course->id);
        $this->login_user_with(['local/coursegen:createtemplates']);

        $result = save_template::execute(...$arguments);

        $this->assertGreaterThan(0, $result['id']);
    }

    /**
     * Holding every capability but the one to create is not enough to create.
     */
    public function test_creating_refuses_every_other_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $arguments = $this->plain_save((int) $course->id);
        $this->login_without('local/coursegen:createtemplates');

        $this->expectException(\required_capability_exception::class);
        save_template::execute(...$arguments);
    }

    /**
     * Editing an existing template needs the capability to edit, and nothing else.
     */
    public function test_editing_needs_only_the_edit_capability(): void {
        $this->resetAfterTest();
        $template = $this->create_template();
        $id = (int) $template->get('id');
        $arguments = $this->plain_save((int) $template->get('courseid'), $id);
        $this->login_user_with(['local/coursegen:edittemplates']);

        $result = save_template::execute(...$arguments);

        $this->assertSame($id, $result['id']);
    }

    /**
     * The capability to create does not allow editing an existing template.
     */
    public function test_editing_refuses_the_create_capability(): void {
        $this->resetAfterTest();
        $template = $this->create_template();
        $arguments = $this->plain_save((int) $template->get('courseid'), (int) $template->get('id'));
        $this->login_user_with(['local/coursegen:createtemplates']);

        $this->expectException(\required_capability_exception::class);
        save_template::execute(...$arguments);
    }

    /**
     * The capability to edit does not allow creating a template.
     */
    public function test_creating_refuses_the_edit_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $arguments = $this->plain_save((int) $course->id);
        $this->login_user_with(['local/coursegen:edittemplates']);

        $this->expectException(\required_capability_exception::class);
        save_template::execute(...$arguments);
    }

    /**
     * The services the editor builds a template with are open to who creates or who edits.
     *
     * @dataProvider builder_capability_provider
     * @param string $capability One of the two capabilities that allow them.
     */
    public function test_the_builder_services_allow_creating_or_editing(string $capability): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id]);
        $courseid = (int) $course->id;
        $categoryid = (int) $category->id;
        $this->login_user_with([$capability]);

        $tree = get_category_tree::execute();
        $courses = get_courses_by_category::execute($categoryid, false, '');
        $structure = get_course_structure::execute($courseid);

        $this->assertIsArray($tree);
        $this->assertCount(1, $courses);
        $this->assertIsArray($structure);
    }

    /**
     * The capabilities that allow the services the editor builds a template with.
     *
     * @return array
     */
    public static function builder_capability_provider(): array {
        return [
            'create' => ['local/coursegen:createtemplates'],
            'edit' => ['local/coursegen:edittemplates'],
        ];
    }

    /**
     * Viewing and deleting templates does not give the services the editor builds one with.
     */
    public function test_the_builder_services_refuse_viewing_and_deleting(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $courseid = (int) $course->id;
        $this->login_user_with(['local/coursegen:viewtemplates', 'local/coursegen:deletetemplates']);

        $this->expectException(\required_capability_exception::class);
        get_course_structure::execute($courseid);
    }

    /**
     * The tree of categories is refused to who can neither create nor edit.
     */
    public function test_the_category_tree_refuses_every_other_capability(): void {
        $this->resetAfterTest();
        $others = $this->all_capabilities_except(['local/coursegen:createtemplates', 'local/coursegen:edittemplates']);
        $this->login_user_with($others);

        $this->expectException(\required_capability_exception::class);
        get_category_tree::execute();
    }
}
