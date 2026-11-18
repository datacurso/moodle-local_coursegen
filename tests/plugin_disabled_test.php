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
use local_coursegen\hook\chat_hook;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Entry points of local_coursegen when the plugin is switched off in the Datacurso AI provider.
 *
 * Disabled: the creation page refuses with plugindisabled, the "Create with AI" entry under
 * Site administration > Courses is hidden and the activity AI button is not loaded. The web
 * services are covered by external\plugin_disabled_external_test (process isolation).
 * Enabled (no state saved in the provider): every entry point behaves as before.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\hook\chat_hook
 */
final class plugin_disabled_test extends \advanced_testcase {
    /**
     * Skip the whole class when the Datacurso AI provider is not installed.
     */
    protected function setUp(): void {
        parent::setUp();
        // The CI pipeline does not install declared dependencies: without the Datacurso AI provider
        // these tests cannot run.
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
    }

    /**
     * Switch the plugin off in the provider.
     *
     * @return void
     */
    private function disable_plugin(): void {
        set_config('plugin_enabled_local_coursegen', '0', 'aiprovider_datacurso');
    }

    /**
     * Run the footer hook on a course page as admin and return the page's end code.
     *
     * @return string JavaScript collected for the page footer.
     */
    private function run_footer_hook_on_course_page(): string {
        global $PAGE;

        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        $PAGE->set_course($course);
        $PAGE->set_pagelayout('course');

        chat_hook::before_footer_html_generation(new before_footer_html_generation($PAGE->get_renderer('core')));
        return $PAGE->requires->get_end_code();
    }

    /**
     * Enabled: the activity AI button is loaded on course pages.
     *
     * @return void
     */
    public function test_activity_button_is_loaded_when_enabled(): void {
        $this->resetAfterTest();

        $this->assertStringContainsString('local_coursegen/activityai', $this->run_footer_hook_on_course_page());
    }

    /**
     * Disabled: the activity AI button is not loaded.
     *
     * @return void
     */
    public function test_activity_button_is_not_loaded_when_disabled(): void {
        $this->resetAfterTest();
        $this->disable_plugin();

        $this->assertStringNotContainsString('local_coursegen/activityai', $this->run_footer_hook_on_course_page());
    }

    /**
     * The "Create course with AI" entry under Site administration > Courses follows the state.
     *
     * @return void
     */
    public function test_admin_course_entry_is_hidden_when_disabled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertInstanceOf(\admin_externalpage::class, admin_get_root(true, false)->locate('local_coursegen_addnewcourseai'));

        $this->disable_plugin();
        $root = admin_get_root(true, false);
        $this->assertNull($root->locate('local_coursegen_addnewcourseai'));
        // The plugin's own administration pages stay reachable to configure it.
        $this->assertInstanceOf(\admin_externalpage::class, $root->locate('local_coursegen_manage_system_instructions'));
    }

    /**
     * The creation page refuses through the provider right after the login check.
     *
     * Page scripts cannot run inside PHPUnit, so the contract is checked on the script itself;
     * the refusal itself is the provider's require_enabled(), exercised by external\plugin_disabled_external_test.
     *
     * @return void
     */
    public function test_creation_page_requires_the_plugin_enabled(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/coursegen/aicoursecreation.php');

        $login = strpos($source, 'require_login();');
        $gate = strpos($source, "plugin_settings::require_enabled('local_coursegen');");
        $this->assertNotFalse($login);
        $this->assertNotFalse($gate);
        $this->assertGreaterThan($login, $gate);
    }
}
