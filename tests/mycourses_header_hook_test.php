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

use core\context\system;
use core\hook\output\before_footer_html_generation;
use core\hook\output\before_http_headers;
use core\url;
use local_coursegen\hook\mycourses_header_hook;

/**
 * Tests for the My courses "Create with AI" button hook.
 *
 * The hook starts an output buffer from before_http_headers and splices the
 * pre-rendered button into the page HTML next to core's course action
 * buttons. The splice is a pure function tested with small HTML fixtures that
 * mirror the containers core renders on each supported Moodle version; the
 * page and capability gating is tested through should_inject().
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\hook\mycourses_header_hook
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\hook\mycourses_header_hook::class)]
final class mycourses_header_hook_test extends \advanced_testcase {
    /** @var string Button fragment used by the splice tests; carries the template's form id. */
    private const FRAGMENT = '<form action="/local/coursegen/aicoursecreation.php" method="get" '
        . 'id="local_coursegen_aicourseform"><button type="submit">Create with AI</button></form>';

    /** @var string Opening tag of the Moodle 4.5/5.0 page header button group. */
    private const HEADER_GROUP_OPEN = '<div class="my-action-buttons my-action-buttons-right d-flex gap-2">';

    /** @var string "Manage courses" form as rendered by block_myoverview on Moodle 5.2. */
    private const MANAGEMENT_FORM = '<form action="https://example.com/course/management.php" method="post">'
        . '<button type="submit" class="btn btn-secondary">Manage courses</button></form>';

    /** @var string "Create course" form as rendered by block_myoverview on Moodle 5.2. */
    private const EDIT_FORM = '<form action="https://example.com/course/edit.php?category=1" method="post">'
        . '<button type="submit" class="btn btn-primary">Create course</button></form>';

    /**
     * Page header markup from Moodle 4.5/5.0 (my/courses.php with enrolled courses).
     *
     * @return string
     */
    private function header_group_fixture(): string {
        return '<header id="page-header"><div class="d-flex">'
            . self::HEADER_GROUP_OPEN . "\n"
            . '    <form action="https://example.com/course/management.php" method="post">'
            . '<button type="submit" class="btn btn-secondary">Manage courses</button></form>' . "\n"
            . '    <form action="https://example.com/course/edit.php?category=1" method="post">'
            . '<button type="submit" class="btn btn-primary">Create course</button></form>' . "\n"
            . '</div></div></header>';
    }

    /**
     * Course overview block markup from Moodle 5.2 (blocks/myoverview/templates/main.mustache).
     *
     * @param bool $withactions Whether the block renders the course action forms.
     * @return string
     */
    private function myoverview_block_fixture(bool $withactions = true): string {
        $actions = '';
        if ($withactions) {
            $actions = '    <div class="d-flex flex-wrap gap-2 my-2">' . "\n"
                . '        ' . self::MANAGEMENT_FORM . "\n"
                . '        ' . self::EDIT_FORM . "\n"
                . '    </div>' . "\n"
                . '    <hr class="border-bottom">' . "\n";
        }

        return '<div id="block-myoverview-1" class="block-myoverview block-cards" data-region="myoverview">' . "\n"
            . $actions
            . '    <div role="search" data-region="filter" class="d-flex align-items-center my-2"></div>' . "\n"
            . '    <div data-region="courses-view"></div>' . "\n"
            . '</div>';
    }

    /**
     * Empty state markup (blocks/myoverview/templates/zero-state.mustache) shown when
     * the user has no enrolled courses.
     *
     * @return string
     */
    private function zero_state_fixture(): string {
        return '<div class="zero-state-content"><div id="action_bar" class="d-flex gap-2 justify-content-center">'
            . '<div class="singlebutton"><form action="https://example.com/course/edit.php" method="post">'
            . '<button type="submit" class="btn btn-primary">Create course</button></form></div>'
            . '</div></div>';
    }

    /**
     * Point the global page at the given URL with a system context.
     *
     * @param string $path Site-relative path, e.g. '/my/courses.php'.
     * @return void
     */
    private function set_page_url(string $path): void {
        global $PAGE;

        $PAGE->set_context(system::instance());
        $PAGE->set_url($path);
    }

    /**
     * Create a user holding exactly the given capabilities in system context.
     *
     * @param string[] $capabilities Capability names to allow.
     * @return \stdClass The user.
     */
    private function create_user_with_capabilities(array $capabilities): \stdClass {
        $generator = $this->getDataGenerator();
        $systemcontext = system::instance();

        $user = $generator->create_user();
        $roleid = $generator->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext);
        }
        role_assign($roleid, $user->id, $systemcontext);

        return $user;
    }

    /**
     * Moodle 4.5/5.0: the button goes right after the opening tag of the header button group.
     */
    public function test_inject_places_button_first_in_header_button_group(): void {
        $html = $this->header_group_fixture();

        $result = mycourses_header_hook::inject_button_into_buffer($html, self::FRAGMENT);

        $expected = str_replace(self::HEADER_GROUP_OPEN, self::HEADER_GROUP_OPEN . self::FRAGMENT, $html);
        $this->assertSame($expected, $result);
        $this->assertSame(1, substr_count($result, self::FRAGMENT));
    }

    /**
     * Moodle 5.2: the button goes right after the "Create course" form inside the Course overview block.
     */
    public function test_inject_places_button_after_create_course_form_in_myoverview_block(): void {
        $html = $this->myoverview_block_fixture();

        $result = mycourses_header_hook::inject_button_into_buffer($html, self::FRAGMENT);

        $expected = str_replace(self::EDIT_FORM, self::EDIT_FORM . self::FRAGMENT, $html);
        $this->assertSame($expected, $result);
        $this->assertStringNotContainsString(self::MANAGEMENT_FORM . self::FRAGMENT, $result);
        $this->assertSame(1, substr_count($result, self::FRAGMENT));
    }

    /**
     * Empty state: the button goes first in the action bar, wrapped like core's singlebutton.
     */
    public function test_inject_wraps_button_in_singlebutton_inside_zero_state_action_bar(): void {
        $html = $this->zero_state_fixture();

        $result = mycourses_header_hook::inject_button_into_buffer($html, self::FRAGMENT);

        $actionbaropen = '<div id="action_bar" class="d-flex gap-2 justify-content-center">';
        $expected = str_replace(
            $actionbaropen,
            $actionbaropen . '<div class="singlebutton">' . self::FRAGMENT . '</div>',
            $html
        );
        $this->assertSame($expected, $result);
    }

    /**
     * A page without any of the known containers is returned untouched.
     */
    public function test_inject_returns_buffer_unchanged_without_known_container(): void {
        $html = '<html><body><div id="page"><form action="https://example.com/login/index.php"></form></div></body></html>';

        $this->assertSame($html, mycourses_header_hook::inject_button_into_buffer($html, self::FRAGMENT));
    }

    /**
     * A "Create course" form outside the Course overview block is not a target.
     */
    public function test_inject_ignores_create_course_form_outside_myoverview_block(): void {
        $html = '<nav>' . self::EDIT_FORM . '</nav>'
            . $this->myoverview_block_fixture(false)
            . '<footer>' . self::EDIT_FORM . '</footer>';

        $this->assertSame($html, mycourses_header_hook::inject_button_into_buffer($html, self::FRAGMENT));
    }

    /**
     * The splice is idempotent: a page already carrying the button is not touched again.
     */
    public function test_inject_does_not_insert_when_button_is_already_present(): void {
        $html = mycourses_header_hook::inject_button_into_buffer($this->header_group_fixture(), self::FRAGMENT);
        $this->assertSame(1, substr_count($html, self::FRAGMENT));

        $result = mycourses_header_hook::inject_button_into_buffer($html, self::FRAGMENT);

        $this->assertSame($html, $result);
        $this->assertSame(1, substr_count($result, self::FRAGMENT));
    }

    /**
     * When several containers exist on the page only the first matching rule inserts the button.
     */
    public function test_inject_inserts_only_once_when_header_group_and_block_both_exist(): void {
        $html = $this->header_group_fixture() . $this->myoverview_block_fixture() . $this->zero_state_fixture();

        $result = mycourses_header_hook::inject_button_into_buffer($html, self::FRAGMENT);

        $this->assertSame(1, substr_count($result, self::FRAGMENT));
        $this->assertStringContainsString(self::HEADER_GROUP_OPEN . self::FRAGMENT, $result);
        $this->assertStringNotContainsString(self::EDIT_FORM . self::FRAGMENT, $result);
        $this->assertStringNotContainsString('<div class="singlebutton">' . self::FRAGMENT, $result);
    }

    /**
     * The rendered template carries the form id the splice uses as its idempotency marker.
     */
    public function test_button_template_renders_form_with_expected_id_and_url(): void {
        global $OUTPUT;

        $url = (new url('/local/coursegen/aicoursecreation.php'))->out(false);
        $html = $OUTPUT->render_from_template('local_coursegen/add_ai_course_button', ['url' => $url]);

        $this->assertStringContainsString('id="local_coursegen_aicourseform"', $html);
        $this->assertStringContainsString('action="' . $url . '"', $html);
        $this->assertStringContainsString('data-action="local_coursegen/add_ai_course"', $html);
    }

    /**
     * An admin on My courses gets the button.
     */
    public function test_should_inject_for_admin_on_my_courses(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_page_url('/my/courses.php');

        $this->assertTrue(mycourses_header_hook::should_inject($PAGE));
    }

    /**
     * A non-admin user holding both capabilities gets the button.
     */
    public function test_should_inject_for_user_with_both_capabilities(): void {
        global $PAGE;

        $this->resetAfterTest();
        $user = $this->create_user_with_capabilities([
            'moodle/course:create',
            'local/coursegen:createcoursewithai',
        ]);
        $this->setUser($user);
        $this->set_page_url('/my/courses.php');

        $this->assertTrue(mycourses_header_hook::should_inject($PAGE));
    }

    /**
     * Pages other than My courses are never decorated.
     */
    public function test_should_not_inject_on_other_pages(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_page_url('/my/index.php');

        $this->assertFalse(mycourses_header_hook::should_inject($PAGE));
    }

    /**
     * A user without local/coursegen:createcoursewithai does not get the button.
     */
    public function test_should_not_inject_without_createcoursewithai(): void {
        global $PAGE;

        $this->resetAfterTest();
        $user = $this->create_user_with_capabilities(['moodle/course:create']);
        $this->setUser($user);
        $this->set_page_url('/my/courses.php');

        $this->assertFalse(mycourses_header_hook::should_inject($PAGE));
    }

    /**
     * A user without moodle/course:create does not get the button.
     */
    public function test_should_not_inject_without_course_create(): void {
        global $PAGE;

        $this->resetAfterTest();
        $user = $this->create_user_with_capabilities(['local/coursegen:createcoursewithai']);
        $this->setUser($user);
        $this->set_page_url('/my/courses.php');

        $this->assertFalse(mycourses_header_hook::should_inject($PAGE));
    }

    /**
     * A user with neither capability does not get the button.
     */
    public function test_should_not_inject_without_any_capability(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setUser($this->create_user_with_capabilities([]));
        $this->set_page_url('/my/courses.php');

        $this->assertFalse(mycourses_header_hook::should_inject($PAGE));
    }

    /**
     * A page whose URL has not been set is not decorated (and no debugging notice is raised).
     */
    public function test_should_not_inject_when_page_url_is_not_set(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $page = new \moodle_page();
        $page->set_context(system::instance());

        $this->assertFalse(mycourses_header_hook::should_inject($page));
    }

    /**
     * The hook is registered for before_http_headers and no longer for the footer hook.
     */
    public function test_hook_is_registered_for_before_http_headers_only(): void {
        $manager = \core\hook\manager::get_instance();

        $headercallbacks = array_column(
            $manager->get_callbacks_for_hook(before_http_headers::class),
            'callback'
        );
        $this->assertContains(mycourses_header_hook::class . '::before_http_headers', $headercallbacks);

        $footercallbacks = array_column(
            $manager->get_callbacks_for_hook(before_footer_html_generation::class),
            'callback'
        );
        $this->assertContains(\local_coursegen\hook\chat_hook::class . '::before_footer_html_generation', $footercallbacks);
        foreach ($footercallbacks as $callback) {
            $this->assertStringNotContainsString('mycourses_header_hook', $callback);
        }
    }
}
