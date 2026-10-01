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

use local_coursegen\external\start_template_generation;
use local_coursegen\local\models\course_session;
use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\service\template_export_service;

/**
 * A course is not generated from a template whose mold has no placeholder.
 *
 * Rows saved before the rule can still be in the database, so the build of the
 * payload checks them again and stops before the AI service is asked for anything.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_export_service
 * @covers     \local_coursegen\local\service\template_placeholder_guard
 * @covers     \local_coursegen\external\start_template_generation
 *
 * @runTestsInSeparateProcesses
 */
final class template_export_placeholder_test extends \advanced_testcase {
    /**
     * Create a course with one page and a template that marks the page with an action.
     *
     * @param string $content The content of the page.
     * @param string $action The action the template saved for the page.
     * @return array {0: the template, 1: the page}
     */
    private function template_with_page(string $content, string $action): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id, 'content' => $content]);
        $template = new template(0, (object) ['name' => 'Saved before the rule', 'courseid' => $course->id]);
        $template->create();
        $row = new template_activity(0, (object) [
            'templateid' => $template->get('id'),
            'sectionid' => 0,
            'cmid' => $page->cmid,
            'action' => $action,
        ]);
        $row->create();
        return [$template, $page];
    }

    /**
     * A mold with a placeholder is exported as a template.
     */
    public function test_mold_with_a_placeholder_is_exported(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$template, $page] = $this->template_with_page('<p>[[coursegen:aiprompt: write the lesson]]</p>', 'template');
        $templateid = (int) $template->get('id');

        $payload = template_export_service::build_init_payload($templateid);

        $cmids = array_column($payload['activities'], 'cmid');
        $this->assertContains((int) $page->cmid, $cmids);
    }

    /**
     * A mold with no placeholder stops the build, and the message names the activity.
     */
    public function test_mold_without_a_placeholder_stops_the_build(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$template, $page] = $this->template_with_page('<p>Plain lesson text.</p>', 'template');
        $templateid = (int) $template->get('id');

        try {
            template_export_service::build_init_payload($templateid);
            $this->fail('A payload was built from a mold with no placeholder.');
        } catch (\moodle_exception $exception) {
            $message = $exception->getMessage();
            $this->assertSame('template_activity_placeholder_required', $exception->errorcode);
            $this->assertStringContainsString($page->name, $message);
            $this->assertStringContainsString('coursegen:aiprompt', $message);
        }
    }

    /**
     * Only a template asks for a placeholder: the same plain page is exported when it is kept.
     */
    public function test_plain_activity_that_is_not_a_template_is_exported(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$template, $page] = $this->template_with_page('<p>Plain lesson text.</p>', 'keep');
        $templateid = (int) $template->get('id');

        $payload = template_export_service::build_init_payload($templateid);

        $cmids = array_column($payload['activities'], 'cmid');
        $this->assertContains((int) $page->cmid, $cmids);
    }

    /**
     * Starting a generation from such a template fails before the AI service is asked for anything:
     * the error is the placeholder one and no session was created.
     */
    public function test_generation_fails_before_the_ai_service_is_called(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$template] = $this->template_with_page('<p>Plain lesson text.</p>', 'template');
        $templateid = (int) $template->get('id');

        try {
            start_template_generation::execute($templateid, 'Make a course');
            $this->fail('A generation started from a mold with no placeholder.');
        } catch (\moodle_exception $exception) {
            $sessions = course_session::count_records();
            $this->assertSame('template_activity_placeholder_required', $exception->errorcode);
            $this->assertSame(0, $sessions);
        }
    }
}
