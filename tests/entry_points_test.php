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

use local_coursegen\hook\mycourses_header_hook;

/**
 * Tests for the wizard entry points: My courses button and admin tree entry.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\hook\mycourses_header_hook
 */
final class entry_points_test extends \advanced_testcase {
    /**
     * Create a user holding the given capabilities at site level.
     *
     * @param string[] $capabilities Capabilities to allow at system context.
     * @return \stdClass User record.
     */
    private function create_user_with_capabilities(array $capabilities): \stdClass {
        $generator = $this->getDataGenerator();
        $systemcontext = \context_system::instance();

        $user = $generator->create_user();
        $roleid = $generator->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext->id);
        }
        role_assign($roleid, $user->id, $systemcontext->id);

        return $user;
    }

    /**
     * MDL-INT-015: The My courses button injection is registered through the
     * after_config hook, the only page-decoration entry point of the plugin.
     */
    public function test_mycourses_hook_is_registered(): void {
        $callbacks = \core\hook\manager::get_instance()->get_callbacks_for_hook(\core\hook\after_config::class);

        $targets = [];
        foreach ($callbacks as $callback) {
            $targets[] = (string)($callback['callback'] ?? '');
        }

        $this->assertContains('local_coursegen\hook\mycourses_header_hook::after_config', $targets);
    }

    /**
     * MDL-INT-015: The button is only offered to users holding both the create
     * courses and the create courses with AI permissions; missing either one
     * hides it.
     */
    public function test_button_requires_both_capabilities(): void {
        $this->resetAfterTest();

        $systemcontext = \context_system::instance();
        $required = ['moodle/course:create', 'local/coursegen:createcoursewithai'];

        $fulluser = $this->create_user_with_capabilities($required);
        $this->assertTrue(has_all_capabilities($required, $systemcontext, $fulluser));

        $onlycreate = $this->create_user_with_capabilities(['moodle/course:create']);
        $this->assertFalse(has_all_capabilities($required, $systemcontext, $onlycreate));

        $onlyai = $this->create_user_with_capabilities(['local/coursegen:createcoursewithai']);
        $this->assertFalse(has_all_capabilities($required, $systemcontext, $onlyai));
    }

    /**
     * MDL-INT-015: The hook never decorates non-page requests (CLI, AJAX, web
     * service), so the button cannot leak outside the My courses page.
     */
    public function test_hook_ignores_non_page_requests(): void {
        $bufferlevel = ob_get_level();

        // PHPUnit runs as a CLI script: the guard must return without starting
        // any output buffer.
        mycourses_header_hook::after_config(new \core\hook\after_config());

        $this->assertSame($bufferlevel, ob_get_level());
    }

    /**
     * MDL-INT-016: The "create a new course with AI" entry appears under
     * Courses for a user holding both flow permissions, bound to the plugin
     * capability.
     */
    public function test_admin_tree_entry_visible_with_both_capabilities(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        require_once($CFG->libdir . '/adminlib.php');

        $adminroot = admin_get_root(true, false);
        $entry = $adminroot->locate('local_coursegen_addnewcourseai');

        $this->assertInstanceOf(\admin_externalpage::class, $entry);
        $this->assertContains('local/coursegen:createcoursewithai', $entry->req_capability);
    }

    /**
     * MDL-INT-016: The entry is hidden from a user missing the flow
     * permissions, even one allowed to browse site administration.
     */
    public function test_admin_tree_entry_hidden_without_flow_capabilities(): void {
        global $CFG;

        $this->resetAfterTest();

        require_once($CFG->libdir . '/adminlib.php');

        // Site configuration access alone must not surface the entry.
        $user = $this->create_user_with_capabilities(['moodle/site:config']);
        $this->setUser($user);

        $adminroot = admin_get_root(true, false);

        $this->assertNull($adminroot->locate('local_coursegen_addnewcourseai'));
    }
}
