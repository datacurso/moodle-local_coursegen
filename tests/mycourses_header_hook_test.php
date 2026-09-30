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

use core\hook\output\before_footer_html_generation;
use local_coursegen\hook\mycourses_header_hook;

/**
 * Tests for the My courses "Create with AI" button footer hook.
 *
 * The button is injected client side by the local_coursegen/mycourses_ai_button
 * AMD module, which the hook requests through js_call_amd() only on the
 * My courses page and only for users allowed to create courses with AI.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\hook\mycourses_header_hook
 */
final class mycourses_header_hook_test extends \advanced_testcase {
    /** @var string AMD module the hook must request on the My courses page. */
    private const AMD_MODULE = 'local_coursegen/mycourses_ai_button';

    /**
     * Point the global page at the given URL with a system context.
     *
     * @param string $path Site-relative path, e.g. '/my/courses.php'.
     * @return void
     */
    private function set_page_url(string $path): void {
        global $PAGE;

        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url($path);
    }

    /**
     * Dispatch the footer hook through the hook manager, as core_renderer::footer() does.
     *
     * @return void
     */
    private function dispatch_footer_hook(): void {
        global $PAGE;

        $hook = new before_footer_html_generation($PAGE->get_renderer('core'));
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
    }

    /**
     * Create a user holding exactly the given capabilities in system context.
     *
     * @param string[] $capabilities Capability names to allow.
     * @return \stdClass The user.
     */
    private function create_user_with_capabilities(array $capabilities): \stdClass {
        $generator = $this->getDataGenerator();
        $systemcontext = \context_system::instance();

        $user = $generator->create_user();
        $roleid = $generator->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext);
        }
        role_assign($roleid, $user->id, $systemcontext);

        return $user;
    }

    /**
     * A user with both capabilities on My courses gets the AMD module requested.
     */
    public function test_requests_amd_module_on_my_courses_for_allowed_user(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_page_url('/my/courses.php');

        $this->dispatch_footer_hook();

        $endcode = $PAGE->requires->get_end_code();
        $this->assertStringContainsString(self::AMD_MODULE, $endcode);

        // The module receives the creation page URL as its (JSON encoded) init argument.
        $expectedurl = (new \moodle_url('/local/coursegen/aicoursecreation.php'))->out(false);
        $this->assertStringContainsString(json_encode(['url' => $expectedurl]), $endcode);
    }

    /**
     * A non-admin user holding both capabilities also gets the module.
     */
    public function test_requests_amd_module_for_user_with_both_capabilities(): void {
        global $PAGE;

        $this->resetAfterTest();
        $user = $this->create_user_with_capabilities([
            'moodle/course:create',
            'local/coursegen:createcoursewithai',
        ]);
        $this->setUser($user);
        $this->set_page_url('/my/courses.php');

        $this->dispatch_footer_hook();

        $this->assertStringContainsString(self::AMD_MODULE, $PAGE->requires->get_end_code());
    }

    /**
     * The module is not requested on pages other than My courses.
     */
    public function test_does_not_request_amd_module_on_other_pages(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_page_url('/my/index.php');

        $this->dispatch_footer_hook();

        $this->assertStringNotContainsString(self::AMD_MODULE, $PAGE->requires->get_end_code());
    }

    /**
     * A user without local/coursegen:createcoursewithai does not get the module.
     */
    public function test_does_not_request_amd_module_without_createcoursewithai(): void {
        global $PAGE;

        $this->resetAfterTest();
        $user = $this->create_user_with_capabilities(['moodle/course:create']);
        $this->setUser($user);
        $this->set_page_url('/my/courses.php');

        $this->dispatch_footer_hook();

        $this->assertStringNotContainsString(self::AMD_MODULE, $PAGE->requires->get_end_code());
    }

    /**
     * A user without moodle/course:create does not get the module.
     */
    public function test_does_not_request_amd_module_without_course_create(): void {
        global $PAGE;

        $this->resetAfterTest();
        $user = $this->create_user_with_capabilities(['local/coursegen:createcoursewithai']);
        $this->setUser($user);
        $this->set_page_url('/my/courses.php');

        $this->dispatch_footer_hook();

        $this->assertStringNotContainsString(self::AMD_MODULE, $PAGE->requires->get_end_code());
    }

    /**
     * The hook is registered for before_footer_html_generation and no longer for after_config.
     */
    public function test_hook_is_registered_for_footer_and_not_after_config(): void {
        $manager = \core\hook\manager::get_instance();

        $footercallbacks = array_column(
            $manager->get_callbacks_for_hook(before_footer_html_generation::class),
            'callback'
        );
        $this->assertContains(
            mycourses_header_hook::class . '::before_footer_html_generation',
            $footercallbacks
        );

        $afterconfigcallbacks = array_column(
            $manager->get_callbacks_for_hook(\core\hook\after_config::class),
            'callback'
        );
        foreach ($afterconfigcallbacks as $callback) {
            $this->assertStringNotContainsString('mycourses_header_hook', $callback);
        }
    }
}
