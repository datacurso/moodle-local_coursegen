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

use core\context\course;
use core\context\system;
use core\hook\output\before_footer_html_generation;
use local_coursegen\hook\chat_hook;

/**
 * Tests for the footer hook that loads the activity AI button.
 *
 * The hook queues the local_coursegen/activityai AMD module on course pages
 * for users who may create activities with AI. Whether the module was queued
 * is observed through the page requirements footer code.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\hook\chat_hook
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\hook\chat_hook::class)]
final class chat_hook_test extends \advanced_testcase {
    /**
     * Regular expression matching the AMD call queued by the hook for the given course.
     *
     * The course id travels through json_encode(), so it may be quoted or not
     * depending on the type of the bound course record.
     *
     * @param \stdClass $course Course bound to the page.
     * @return string
     */
    private function amd_call_pattern(\stdClass $course): string {
        return "~require\\(\\['local_coursegen/activityai'\\], function\\(amd\\) \\{amd\\.init\\(\"?"
            . preg_quote((string) $course->id, '~') . '"?, ~';
    }

    /**
     * Point the global page at a course page.
     *
     * @param \stdClass $course Course record.
     * @param string $pagelayout Page layout.
     * @param string $pagetype Page type.
     * @return void
     */
    private function set_course_page(\stdClass $course, string $pagelayout = 'course', string $pagetype = 'course-view'): void {
        global $PAGE;

        $PAGE->set_context(course::instance($course->id));
        $PAGE->set_course($course);
        $PAGE->set_url('/course/view.php', ['id' => $course->id]);
        $PAGE->set_pagelayout($pagelayout);
        $PAGE->set_pagetype($pagetype);
    }

    /**
     * Point the global page at a page outside any course.
     *
     * @return void
     */
    private function set_non_course_page(): void {
        global $PAGE;

        $PAGE->set_context(system::instance());
        $PAGE->set_url('/my/index.php');
        $PAGE->set_pagelayout('mydashboard');
        $PAGE->set_pagetype('my-index');
    }

    /**
     * Run the hook and return the footer code of the global page.
     *
     * @return string
     */
    private function run_hook(): string {
        global $PAGE;

        // A plain renderer bound to the page: the hook never renders, and
        // initialising the theme would freeze the page course and layout.
        $renderer = new \core_renderer($PAGE, RENDERER_TARGET_GENERAL);
        chat_hook::before_footer_html_generation(new before_footer_html_generation($renderer));

        return $PAGE->requires->get_end_code();
    }

    /**
     * Create a course with an enrolled user of the given role and log that user in.
     *
     * @param string $role Role shortname.
     * @return \stdClass The course.
     */
    private function create_course_as(string $role): \stdClass {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, $role);
        $this->setUser($user);

        return $course;
    }

    /**
     * On a course page, a teacher gets the activity AI module with the course id and language.
     */
    public function test_module_queued_on_course_page_for_teacher(): void {
        $this->resetAfterTest();

        $course = $this->create_course_as('editingteacher');
        $this->set_course_page($course);

        $code = $this->run_hook();

        $this->assertMatchesRegularExpression($this->amd_call_pattern($course), $code);
        // The current language (English in tests) is supported, so it is the default.
        $this->assertStringContainsString('"code":"en"', $code);
        $this->assertStringContainsString(', "en"); M.util.js_complete(\'local_coursegen/activityai\')', $code);
    }

    /**
     * A module page inside the course also gets the activity AI module.
     */
    public function test_module_queued_on_activity_page(): void {
        $this->resetAfterTest();

        $course = $this->create_course_as('editingteacher');
        $this->set_course_page($course, 'incourse', 'mod-page-view');

        $this->assertMatchesRegularExpression($this->amd_call_pattern($course), $this->run_hook());
    }

    /**
     * Outside a course nothing is queued, even for an admin.
     */
    public function test_module_not_queued_outside_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_non_course_page();

        $this->assertStringNotContainsString('local_coursegen/activityai', $this->run_hook());
    }

    /**
     * An enrolled student does not get the module on the course page.
     */
    public function test_module_not_queued_for_student(): void {
        $this->resetAfterTest();

        $course = $this->create_course_as('student');
        $this->set_course_page($course);

        $this->assertStringNotContainsString('local_coursegen/activityai', $this->run_hook());
    }

    /**
     * A teacher who lost the plugin capability does not get the module either.
     */
    public function test_module_not_queued_without_createactivitywithai(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->create_course_as('editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('local/coursegen:createactivitywithai', CAP_PROHIBIT, $roleid, course::instance($course->id));
        accesslib_clear_all_caches_for_unit_testing();
        $this->set_course_page($course);

        $this->assertStringNotContainsString('local_coursegen/activityai', $this->run_hook());
    }

    /**
     * The hook is registered for the footer hook.
     */
    public function test_hook_is_registered(): void {
        $callbacks = array_column(
            \core\hook\manager::get_instance()->get_callbacks_for_hook(before_footer_html_generation::class),
            'callback'
        );

        $this->assertContains(chat_hook::class . '::before_footer_html_generation', $callbacks);
    }
}
