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

use local_coursegen\local\api_client_factory;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../fixtures/aiprovider_datacurso_stub.php');
require_once(__DIR__ . '/../fixtures/generated_images_test_trait.php');
require_once($CFG->dirroot . '/mod/lesson/locallib.php');

/**
 * Tests for lesson_settings: the generated images of the lesson pages.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_settings\lesson_settings
 */
final class lesson_settings_test extends \advanced_testcase {
    use \local_coursegen\generated_images_test_trait;

    /**
     * Reset the injected client.
     */
    protected function tearDown(): void {
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

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
     * A content page with the given HTML.
     *
     * @param string $html
     * @return array
     */
    private function content_page(string $html): array {
        return ['page_type' => 'content', 'title' => 'Page', 'content_html' => $html, 'button_text' => 'Next'];
    }

    /**
     * A generated image of a page is downloaded and stored in the page, and the page points at it.
     */
    public function test_generated_image_is_stored_in_the_page(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->inject_image_client();
        $cm = $this->make_lesson_cm();
        $source = '/tmp/resource_files/generated_images/img_lesson01.png';

        (new lesson_settings($cm, ['pages' => [
            $this->content_page('<p>Intro</p><img src="' . $source . '" alt="Water levels">'),
        ]]))->add_settings();

        $page = $DB->get_record('lesson_pages', ['lessonid' => $cm->instance], '*', MUST_EXIST);
        $this->assertStringContainsString('@@PLUGINFILE@@/img_lesson01.png', $page->contents);
        $this->assertStringNotContainsString('/tmp/resource_files', $page->contents);

        $context = \context_module::instance($cm->coursemodule);
        $this->assertTrue(get_file_storage()->file_exists(
            $context->id, 'mod_lesson', 'page_contents', $page->id, '/', 'img_lesson01.png'));
    }

    /**
     * Each page keeps its own images.
     */
    public function test_each_page_keeps_its_own_images(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->inject_image_client();
        $cm = $this->make_lesson_cm();

        (new lesson_settings($cm, ['pages' => [
            $this->content_page('<img src="/tmp/resource_files/generated_images/img_first.png">'),
            $this->content_page('<img src="/tmp/resource_files/generated_images/img_second.png">'),
        ]]))->add_settings();

        $context = \context_module::instance($cm->coursemodule);
        $fs = get_file_storage();
        $pages = array_values($DB->get_records('lesson_pages', ['lessonid' => $cm->instance], 'id ASC'));
        $this->assertCount(2, $pages);
        $this->assertTrue($fs->file_exists($context->id, 'mod_lesson', 'page_contents', $pages[0]->id, '/', 'img_first.png'));
        $this->assertTrue($fs->file_exists($context->id, 'mod_lesson', 'page_contents', $pages[1]->id, '/', 'img_second.png'));
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
