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

namespace local_coursegen\local\files;

use local_coursegen\local\service\create_mod_service;
use local_coursegen\local\space\file_space;
use local_coursegen\local\space\space_scope;
use local_coursegen\local\space\space_selection;
use local_coursegen\tests\fixtures\file_scenarios;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../fixtures/file_scenarios.php');

/**
 * A page that shows the file of a space in a frame gets the teacher's file, or loses the frame.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\files\space_file_source
 * @covers     \local_coursegen\local\files\space_element_remover
 * @covers     \local_coursegen\local\files\activity_file_pass
 */
final class space_in_page_test extends \advanced_testcase {
    protected function tearDown(): void {
        space_scope::leave();
        parent::tearDown();
    }

    /**
     * The template's resource with its file, the teacher's file, and the page text that frames the template's file.
     *
     * @param bool $filled Whether the teacher brought the file.
     * @return string The page content.
     */
    private function scope_and_content(bool $filled): string {
        global $USER;
        $source = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $source->id]);
        $context = \context_module::instance($resource->cmid);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_resource', 'content');
        $template = $fs->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_resource', 'filearea' => 'content', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'GD Template.pdf',
        ], 'TEMPLATE');
        $files = [];
        if ($filled) {
            $usercontext = \context_user::instance($USER->id);
            $files[(int) $resource->cmid] = $fs->create_file_from_string([
                'contextid' => $usercontext->id, 'component' => 'local_coursegen', 'filearea' => 'spacefile',
                'itemid' => 9, 'filepath' => '/' . $resource->cmid . '/', 'filename' => 'mine.pdf',
            ], 'MINE');
        }
        space_scope::enter(new space_selection([new file_space((int) $resource->cmid, 'Guide', '', false, [$template])], $files));
        $url = \moodle_url::make_pluginfile_url($context->id, 'mod_resource', 'content', 1, '/', 'GD Template.pdf');
        return '<h3>Guide</h3><p><iframe src="' . $url->out(false) . '" width="100%"></iframe></p><p>End</p>';
    }

    /**
     * A page result with the content.
     *
     * @param string $content
     * @return array
     */
    private function page_result(string $content): array {
        $parameters = file_scenarios::base_params('page', [
            'name' => 'Guide page', 'page' => ['text' => $content, 'format' => 1], 'display' => 5, 'printintro' => 0,
            'printlastmodified' => 1, 'printheading' => 1,
        ]);
        return ['resource_type' => 'page', 'parameters' => $parameters];
    }

    /**
     * With the teacher's file the page keeps the frame, pointing at its own copy of that file.
     */
    public function test_the_page_gets_its_own_copy_of_the_teachers_file(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_course($course);
        $content = $this->scope_and_content(true);

        $cm = create_mod_service::create_from_ai_result($this->page_result($content), $course, 1);

        $saved = (string) $DB->get_field('page', 'content', ['id' => $cm->instance]);
        $this->assertStringContainsString('<iframe src="@@PLUGINFILE@@/mine.pdf" width="100%"></iframe>', $saved);
        $this->assertStringNotContainsString('GD Template', $saved);
        $this->assertStringNotContainsString('pluginfile.php', $saved);
        $context = \context_module::instance($cm->coursemodule);
        $copy = get_file_storage()->get_file($context->id, 'mod_page', 'content', 0, '/', 'mine.pdf');
        $this->assertNotFalse($copy);
        $this->assertSame('MINE', $copy->get_content());
        $this->assertFalse(get_file_storage()->get_file($context->id, 'mod_page', 'content', 0, '/', 'GD Template.pdf'));
    }

    /**
     * Without a file only the frame goes; the heading and the text around it stay.
     */
    public function test_the_frame_is_removed_when_the_teacher_brought_nothing(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_course($course);
        $content = $this->scope_and_content(false);

        $cm = create_mod_service::create_from_ai_result($this->page_result($content), $course, 1);

        $saved = (string) $DB->get_field('page', 'content', ['id' => $cm->instance]);
        $this->assertSame('<h3>Guide</h3><p></p><p>End</p>', $saved);
        $context = \context_module::instance($cm->coursemodule);
        $files = get_file_storage()->get_area_files($context->id, 'mod_page', 'content', false, 'id', false);
        $this->assertCount(0, $files);
    }
}
