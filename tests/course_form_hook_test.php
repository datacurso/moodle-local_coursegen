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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/edit_form.php');
require_once($CFG->dirroot . '/course/tests/fixtures/testable_course_edit_form.php');

/**
 * Tests for course_form_hook: image generation gating on the course form.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2025 DataCurso <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\hook\course_form_hook
 * @runTestsInSeparateProcesses
 */
final class course_form_hook_test extends \advanced_testcase {

    /**
     * Helper: build a course edit form and fire the after_form_definition hook.
     *
     * @param object $course The course object.
     * @return \MoodleQuickForm The form instance after the hook has been applied.
     */
    private function build_form_with_hook(object $course): \MoodleQuickForm {
        global $DB, $COURSE;
        $COURSE = $course;

        $category = $DB->get_record('course_categories', ['id' => $course->category]);
        $args = [
            'course' => $course,
            'category' => $category,
            'editoroptions' => ['context' => \context_course::instance($course->id), 'subdirs' => 0],
            'returnto' => new \moodle_url('/'),
            'returnurl' => new \moodle_url('/'),
        ];
        $courseform = new \testable_course_edit_form(null, $args);
        $mform = $courseform->get_quick_form();
        $hook = new \core_course\hook\after_form_definition($courseform, $mform);
        \local_coursegen\hook\course_form_hook::after_form_definition($hook);

        return $mform;
    }

    /**
     * Image generation select is visible when setting is on and user is admin.
     */
    public function test_image_field_visible_when_enabled(): void {
        $this->resetAfterTest(true);
        set_config('enable_course_image_generation', 1, 'local_coursegen');

        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $mform = $this->build_form_with_hook($course);

        $this->assertTrue(
            $mform->elementExists('local_coursegen_generate_images'),
            'The generate images field must exist on the form.'
        );
        $element = $mform->getElement('local_coursegen_generate_images');
        $this->assertSame(
            'select',
            $element->getType(),
            'The generate images field must be a select element when the user can generate images.'
        );
    }

    /**
     * Image generation field is a hidden element when admin setting is off.
     */
    public function test_image_field_hidden_when_setting_off(): void {
        $this->resetAfterTest(true);
        set_config('enable_course_image_generation', 0, 'local_coursegen');

        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $mform = $this->build_form_with_hook($course);

        $this->assertTrue(
            $mform->elementExists('local_coursegen_generate_images'),
            'The generate images hidden field must still exist on the form.'
        );
        $element = $mform->getElement('local_coursegen_generate_images');
        $this->assertSame(
            'hidden',
            $element->getType(),
            'The generate images field must be a hidden element when the setting is off.'
        );
    }

    /**
     * Image generation field is hidden when user lacks the capability.
     */
    public function test_image_field_hidden_without_capability(): void {
        $this->resetAfterTest(true);
        set_config('enable_course_image_generation', 1, 'local_coursegen');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $this->setUser($user);

        // Simulate being on the edit page with course id.
        $_GET['id'] = $course->id;

        $mform = $this->build_form_with_hook($course);

        $element = $mform->getElement('local_coursegen_generate_images');
        $this->assertSame(
            'hidden',
            $element->getType(),
            'The generate images field must be hidden when user lacks generatecourseimages capability.'
        );

        unset($_GET['id']);
    }
}
