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

namespace local_coursegen\mod_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/lesson/locallib.php');

/**
 * Tests for lesson_settings: the pages of a lesson and the cleaning of the HTML the AI wrote.
 *
 * The generated images of a page are given to it by activity_file_pass once the activity exists, see
 * tests/files/activity_file_pass_test.php.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_settings\lesson_settings
 */
final class lesson_settings_test extends \advanced_testcase {
    /**
     * Create a lesson and return a cm-like object shaped as create_mod_service passes it.
     *
     * @return object Object with ->coursemodule (cmid) and ->instance (lesson id).
     */
    private function make_lesson_cm(): object {
        $course = $this->getDataGenerator()->create_course();
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        return (object) ['coursemodule' => $lesson->cmid, 'instance' => $lesson->id];
    }

    /**
     * The HTML the AI writes in a page, its options and their feedback is cleaned, since the lesson
     * shows it without cleaning; the layout stays.
     */
    public function test_ai_html_is_cleaned(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $cm = $this->make_lesson_cm();

        (new lesson_settings($cm, ['pages' => [[
            'page_type' => 'multi_choice',
            'title' => 'Question',
            'content_html' => '<p>Pick one</p><script>alert(1)</script><img src="x.png" onerror="alert(2)">',
            'options' => [
                ['text' => '<b>Right</b><script>alert(3)</script>', 'correct' => true,
                    'feedback' => '<a href="javascript:alert(4)">Why</a>'],
                ['text' => 'Wrong', 'correct' => false, 'feedback' => '<p>No</p>'],
            ],
        ]]]))->add_settings();

        $page = $DB->get_record('lesson_pages', ['lessonid' => $cm->instance], '*', MUST_EXIST);
        $this->assertStringContainsString('<p>Pick one</p>', $page->contents);
        $this->assertStringNotContainsString('<script', $page->contents);
        $this->assertStringNotContainsString('onerror', $page->contents);

        $answers = $DB->get_records('lesson_answers', ['pageid' => $page->id], 'id ASC');
        $this->assertCount(2, $answers);
        $right = reset($answers);
        $this->assertStringContainsString('<b>Right</b>', $right->answer);
        $this->assertStringNotContainsString('<script', $right->answer);
        $this->assertStringNotContainsString('javascript:', $right->response);
    }
}
