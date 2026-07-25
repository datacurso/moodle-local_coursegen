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

/**
 * Tests for chat_hook: admin settings gates, capability checks, and is_course_empty logic.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2025 DataCurso <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\hook\chat_hook
 */
final class chat_hook_test extends \advanced_testcase {

    /**
     * Helper: invoke a private static method on chat_hook via Reflection.
     *
     * @param string $method Method name.
     * @param array $args Arguments to pass.
     * @return mixed
     */
    private function invoke_private(string $method, array $args = []) {
        $ref = new \ReflectionMethod(\local_coursegen\hook\chat_hook::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke(null, ...$args);
    }

    /**
     * Helper: set up $PAGE to simulate /course/edit.php with optional params.
     *
     * @param array $params URL parameters (e.g. ['id' => 5] or ['category' => 1]).
     */
    private function set_page_to_course_edit(array $params = []): void {
        global $PAGE;
        $url = new \moodle_url('/course/edit.php', $params);
        $PAGE->set_url($url);
    }

    /**
     * Helper: create a manager user enrolled in a course with the coursegen capabilities.
     *
     * @param object $course The course object.
     * @param array $caps Extra capabilities to assign (e.g. 'local/coursegen:generatecourseimages').
     * @return object The user object.
     */
    private function create_manager_for_course(object $course, array $caps = []): object {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'manager');

        $context = \context_course::instance($course->id);
        $roleid = $this->getDataGenerator()->create_role();
        foreach ($caps as $cap) {
            assign_capability($cap, CAP_ALLOW, $roleid, $context);
        }
        if (!empty($caps)) {
            role_assign($roleid, $user->id, $context);
        }

        return $user;
    }

    // -----------------------------------------------------------------------
    // is_course_empty() tests.
    // -----------------------------------------------------------------------

    /**
     * A brand new course with only the default Announcements forum is empty.
     */
    public function test_is_course_empty_with_default_forum_only(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();

        $this->assertTrue(
            $this->invoke_private('is_course_empty', [(int)$course->id]),
            'A course with only the default Announcements forum must be considered empty.'
        );
    }

    /**
     * A course with an added activity is not empty.
     */
    public function test_is_course_empty_false_with_extra_module(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->assertFalse(
            $this->invoke_private('is_course_empty', [(int)$course->id]),
            'A course with an extra module must NOT be considered empty.'
        );
    }

    /**
     * A course with a manually added general forum in section 0 is not empty.
     */
    public function test_is_course_empty_false_with_general_forum(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();

        // Remove the default news forum first, then add a general forum.
        $modinfo = get_fast_modinfo($course->id);
        foreach ($modinfo->get_cms() as $cm) {
            course_delete_module($cm->id);
        }

        $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'type' => 'general',
            'section' => 0,
        ]);

        $this->assertFalse(
            $this->invoke_private('is_course_empty', [(int)$course->id]),
            'A course with a general forum (not news) must NOT be considered empty.'
        );
    }

    /**
     * A course with zero modules is empty.
     */
    public function test_is_course_empty_with_no_modules(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course(['newsitems' => 0]);

        // Remove any modules that were created.
        $modinfo = get_fast_modinfo($course->id);
        foreach ($modinfo->get_cms() as $cm) {
            course_delete_module($cm->id);
        }
        // Rebuild cache after deleting modules.
        rebuild_course_cache($course->id, true);

        $this->assertTrue(
            $this->invoke_private('is_course_empty', [(int)$course->id]),
            'A course with zero modules must be considered empty.'
        );
    }

    // -----------------------------------------------------------------------
    // can_create_activity() — admin setting gate.
    // -----------------------------------------------------------------------

    /**
     * Activity AI button is blocked when admin setting is off.
     */
    public function test_can_create_activity_blocked_when_setting_off(): void {
        global $PAGE, $COURSE;
        $this->resetAfterTest(true);

        set_config('enable_activity_ai', 0, 'local_coursegen');

        $course = $this->getDataGenerator()->create_course();
        $COURSE = $course;

        $user = $this->create_manager_for_course($course);
        $this->setUser($user);

        $PAGE->set_context(\context_course::instance($course->id));
        $PAGE->set_pagelayout('course');

        $this->assertFalse(
            $this->invoke_private('can_create_activity'),
            'can_create_activity must return false when enable_activity_ai is off.'
        );
    }

    /**
     * Activity AI button is allowed when admin setting is on and user has capabilities.
     */
    public function test_can_create_activity_allowed_when_setting_on(): void {
        global $PAGE, $COURSE;
        $this->resetAfterTest(true);

        set_config('enable_activity_ai', 1, 'local_coursegen');

        $course = $this->getDataGenerator()->create_course();
        $COURSE = $course;

        // Use admin to guarantee all capabilities.
        $this->setAdminUser();

        $PAGE->set_context(\context_course::instance($course->id));
        $PAGE->set_pagelayout('course');

        $this->assertTrue(
            $this->invoke_private('can_create_activity'),
            'can_create_activity must return true when setting is on and user has capabilities.'
        );
    }

    // -----------------------------------------------------------------------
    // can_create_course() — admin setting gates + empty course logic.
    // -----------------------------------------------------------------------

    /**
     * Course AI button is blocked when admin setting is off (new course).
     */
    public function test_can_create_course_blocked_when_setting_off(): void {
        global $PAGE;
        $this->resetAfterTest(true);

        set_config('enable_course_ai', 0, 'local_coursegen');
        $this->setAdminUser();

        $category = $this->getDataGenerator()->create_category();
        $this->set_page_to_course_edit(['category' => $category->id]);
        $PAGE->set_context(\context_coursecat::instance($category->id));

        $this->assertFalse(
            $this->invoke_private('can_create_course'),
            'can_create_course must return false when enable_course_ai is off.'
        );
    }

    /**
     * Course AI button is allowed when admin setting is on (new course).
     */
    public function test_can_create_course_allowed_when_setting_on(): void {
        global $PAGE;
        $this->resetAfterTest(true);

        set_config('enable_course_ai', 1, 'local_coursegen');
        $this->setAdminUser();

        $category = $this->getDataGenerator()->create_category();
        $this->set_page_to_course_edit(['category' => $category->id]);
        $PAGE->set_context(\context_coursecat::instance($category->id));

        $this->assertTrue(
            $this->invoke_private('can_create_course'),
            'can_create_course must return true when setting is on and user is admin.'
        );
    }

    /**
     * Empty course AI button is blocked when enable_empty_course_ai is off.
     */
    public function test_can_create_course_empty_blocked_when_setting_off(): void {
        global $PAGE;
        $this->resetAfterTest(true);

        set_config('enable_empty_course_ai', 0, 'local_coursegen');

        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $this->set_page_to_course_edit(['id' => $course->id]);
        $PAGE->set_context(\context_course::instance($course->id));

        $this->assertFalse(
            $this->invoke_private('can_create_course'),
            'can_create_course must return false for existing courses when enable_empty_course_ai is off.'
        );
    }

    /**
     * Empty course AI button is allowed when enable_empty_course_ai is on and course is empty.
     */
    public function test_can_create_course_empty_allowed_when_setting_on(): void {
        global $PAGE;
        $this->resetAfterTest(true);

        set_config('enable_empty_course_ai', 1, 'local_coursegen');

        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $this->set_page_to_course_edit(['id' => $course->id]);
        $PAGE->set_context(\context_course::instance($course->id));

        $this->assertTrue(
            $this->invoke_private('can_create_course'),
            'can_create_course must return true for empty existing courses when setting is on.'
        );
    }

    /**
     * Existing course with content does not show AI button even when setting is on.
     */
    public function test_can_create_course_non_empty_blocked(): void {
        global $PAGE;
        $this->resetAfterTest(true);

        set_config('enable_empty_course_ai', 1, 'local_coursegen');

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->setAdminUser();

        $this->set_page_to_course_edit(['id' => $course->id]);
        $PAGE->set_context(\context_course::instance($course->id));

        $this->assertFalse(
            $this->invoke_private('can_create_course'),
            'can_create_course must return false for a non-empty existing course.'
        );
    }

    /**
     * can_create_course returns false on a non-edit page.
     */
    public function test_can_create_course_false_on_non_edit_page(): void {
        global $PAGE;
        $this->resetAfterTest(true);

        set_config('enable_course_ai', 1, 'local_coursegen');
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        $PAGE->set_context(\context_course::instance($course->id));

        $this->assertFalse(
            $this->invoke_private('can_create_course'),
            'can_create_course must return false when not on /course/edit.php.'
        );
    }

    // -----------------------------------------------------------------------
    // can_generate_activity_images() — admin setting + capability.
    // -----------------------------------------------------------------------

    /**
     * Activity image generation is blocked when admin setting is off.
     */
    public function test_activity_images_blocked_when_setting_off(): void {
        $this->resetAfterTest(true);

        set_config('enable_activity_image_generation', 0, 'local_coursegen');

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $this->assertFalse(
            $this->invoke_private('can_generate_activity_images', [$context]),
            'can_generate_activity_images must return false when setting is off.'
        );
    }

    /**
     * Activity image generation is blocked when user lacks capability.
     */
    public function test_activity_images_blocked_without_capability(): void {
        $this->resetAfterTest(true);

        set_config('enable_activity_image_generation', 1, 'local_coursegen');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $this->setUser($user);

        $context = \context_course::instance($course->id);

        $this->assertFalse(
            $this->invoke_private('can_generate_activity_images', [$context]),
            'can_generate_activity_images must return false when user lacks the capability.'
        );
    }

    /**
     * Activity image generation is allowed when setting is on and user has capability.
     */
    public function test_activity_images_allowed_with_setting_and_capability(): void {
        $this->resetAfterTest(true);

        set_config('enable_activity_image_generation', 1, 'local_coursegen');

        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $context = \context_course::instance($course->id);

        $this->assertTrue(
            $this->invoke_private('can_generate_activity_images', [$context]),
            'can_generate_activity_images must return true when setting is on and user is admin.'
        );
    }
}
