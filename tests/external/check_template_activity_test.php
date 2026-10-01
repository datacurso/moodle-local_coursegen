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

require_once(__DIR__ . '/../capability_user_trait.php');

use local_coursegen\external\check_template_activity;

/**
 * The web service the template editor asks before it marks an activity as a template.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\check_template_activity
 * @covers     \local_coursegen\local\service\template_placeholder_guard
 *
 * @runTestsInSeparateProcesses
 */
final class check_template_activity_test extends \advanced_testcase {
    use capability_user_trait;

    /**
     * Create a page and answer its course module id.
     *
     * @param string $content The content of the page.
     * @return int
     */
    private function page_cmid(string $content): int {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id, 'content' => $content]);
        return (int) $page->cmid;
    }

    /**
     * An activity with a placeholder is allowed.
     */
    public function test_activity_with_a_placeholder_is_allowed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $cmid = $this->page_cmid('<p>[[coursegen:aiprompt: write the lesson]]</p>');

        $result = check_template_activity::execute($cmid);

        $this->assertTrue($result['allowed']);
    }

    /**
     * An activity with no placeholder is refused with the message that says what to add.
     */
    public function test_activity_without_a_placeholder_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $cmid = $this->page_cmid('<p>Plain lesson text.</p>');

        try {
            check_template_activity::execute($cmid);
            $this->fail('An activity without a placeholder was allowed.');
        } catch (\moodle_exception $exception) {
            $message = $exception->getMessage();
            $this->assertSame('template_activity_placeholder_required', $exception->errorcode);
            $this->assertStringContainsString('coursegen:aiprompt', $message);
        }
    }

    /**
     * An activity that does not exist is an invalid course module, not a pass.
     */
    public function test_unknown_activity_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\moodle_exception::class);
        check_template_activity::execute(999999);
    }

    /**
     * The check is open to who can create or edit templates, and to nobody else.
     */
    public function test_check_needs_a_capability_to_create_or_edit_templates(): void {
        $this->resetAfterTest();
        $cmid = $this->page_cmid('<p>[[coursegen:aiprompt: write the lesson]]</p>');
        $this->login_user_with(['local/coursegen:edittemplates']);
        $result = check_template_activity::execute($cmid);
        $this->assertTrue($result['allowed']);

        $this->login_user_with(['local/coursegen:createtemplates']);
        $result = check_template_activity::execute($cmid);
        $this->assertTrue($result['allowed']);
    }

    /**
     * Every other capability of the plugin is not enough.
     */
    public function test_check_refuses_every_other_capability(): void {
        $this->resetAfterTest();
        $cmid = $this->page_cmid('<p>[[coursegen:aiprompt: write the lesson]]</p>');
        $others = $this->all_capabilities_except(['local/coursegen:createtemplates', 'local/coursegen:edittemplates']);
        $this->login_user_with($others);

        $this->expectException(\required_capability_exception::class);
        check_template_activity::execute($cmid);
    }
}
