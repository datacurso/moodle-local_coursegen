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

use local_coursegen\external\get_course_preview;
use local_coursegen\local\models\template;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../capability_user_trait.php');

/**
 * The preview of a course for the template editor asks for the capability of what the user is doing:
 * to create a template when there is none yet, to edit it when it exists.
 *
 * Rendering the preview needs nothing but the capability, so the tests assert on the answer
 * (the course it describes and the review it renders) and not on the markup of each row.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\get_course_preview
 *
 * @runTestsInSeparateProcesses
 */
final class get_course_preview_capabilities_test extends \advanced_testcase {
    use capability_user_trait;

    /**
     * A saved template on the given course.
     *
     * @param \stdClass $course The base course.
     * @return int The template id.
     */
    private function create_template_on(\stdClass $course): int {
        $template = new template(0, (object) ['name' => 'Saved', 'courseid' => $course->id]);
        $template->create();
        return (int) $template->get('id');
    }

    /**
     * Assert that a preview was served for the course.
     *
     * @param array $result The answer of get_course_preview::execute().
     * @param \stdClass $course The course it was asked for.
     */
    private function assert_preview_of(array $result, \stdClass $course): void {
        $this->assertSame((int) $course->id, (int) $result['courseid']);
        $this->assertStringContainsString('data-region="course-sections"', $result['html']);
    }

    /**
     * A new template can be previewed with the capability to create one.
     */
    public function test_a_new_template_is_previewed_with_only_the_create_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->login_user_with(['local/coursegen:createtemplates']);

        $result = get_course_preview::execute((int) $course->id, 0);

        $this->assert_preview_of($result, $course);
    }

    /**
     * The capability to edit does not allow previewing a template that does not exist yet.
     */
    public function test_a_new_template_is_refused_with_only_the_edit_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->login_user_with(['local/coursegen:edittemplates']);

        $this->expectException(\required_capability_exception::class);
        get_course_preview::execute((int) $course->id, 0);
    }

    /**
     * An existing template can be previewed with the capability to edit it.
     */
    public function test_an_existing_template_is_previewed_with_only_the_edit_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $templateid = $this->create_template_on($course);
        $this->login_user_with(['local/coursegen:edittemplates']);

        $result = get_course_preview::execute((int) $course->id, $templateid);

        $this->assert_preview_of($result, $course);
    }

    /**
     * The capability to create does not allow previewing a template that already exists.
     */
    public function test_an_existing_template_is_refused_with_only_the_create_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $templateid = $this->create_template_on($course);
        $this->login_user_with(['local/coursegen:createtemplates']);

        $this->expectException(\required_capability_exception::class);
        get_course_preview::execute((int) $course->id, $templateid);
    }

    /**
     * A user with no capability is refused.
     */
    public function test_a_user_with_no_capability_is_refused(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->login_user_with([]);

        $this->expectException(\required_capability_exception::class);
        get_course_preview::execute((int) $course->id, 0);
    }

    /**
     * Holding every other capability of the plugin does not allow previewing an existing template.
     */
    public function test_every_other_capability_does_not_allow_an_existing_template(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $templateid = $this->create_template_on($course);
        $this->login_without('local/coursegen:edittemplates');

        $this->expectException(\required_capability_exception::class);
        get_course_preview::execute((int) $course->id, $templateid);
    }

    /**
     * The administrator can preview a new template and an existing one.
     */
    public function test_the_administrator_can_preview_both(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $templateid = $this->create_template_on($course);
        $this->setAdminUser();

        $new = get_course_preview::execute((int) $course->id, 0);
        $existing = get_course_preview::execute((int) $course->id, $templateid);

        $this->assert_preview_of($new, $course);
        $this->assert_preview_of($existing, $course);
    }
}
