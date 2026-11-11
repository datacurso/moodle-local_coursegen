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

namespace local_coursegen\local\service;

use local_coursegen\utils\generated_file_cache;

/**
 * An activity created from a result with generated files ends up with those files in its own file area.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_mod_service
 */
final class create_mod_generated_files_test extends \advanced_testcase {
    /**
     * A generated file entry, stored in the cache so no download is needed.
     *
     * @return array
     */
    private function stored_entry(): array {
        $entry = [
            'filename' => 'foro-1a2b3c4d-1.png',
            'mimetype' => 'image/png',
            'size' => 7,
            'thread_id' => 'thread-1',
            'file_id' => str_repeat('a', 32) . '.png',
        ];
        get_file_storage()->create_file_from_string(generated_file_cache::file_record($entry), 'PNGDATA');
        return $entry;
    }

    /**
     * A page result whose text points at the generated file.
     *
     * @param array $entries
     * @return array
     */
    private function page_result(array $entries): array {
        $result = [
            'resource_type' => 'page',
            'parameters' => [
                'modulename' => 'page',
                'visible' => 1,
                'visibleoncoursepage' => 1,
                'name' => 'Page with an image',
                'section' => 1,
                'introeditor' => ['text' => '<p>Intro</p>', 'format' => 1],
                'page' => ['text' => '<p><img src="@@PLUGINFILE@@/foro-1a2b3c4d-1.png" alt="x"></p>', 'format' => 1],
                'display' => 5,
                'printintro' => 0,
                'printlastmodified' => 1,
                'printheading' => 1,
            ],
        ];
        if ($entries) {
            $result['generated_files'] = $entries;
        }
        return $result;
    }

    /**
     * The generated file is saved in the new page's file area and the text keeps pointing at it.
     */
    public function test_the_generated_file_is_saved_in_the_new_activity(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $entry = $this->stored_entry();

        $cm = create_mod_service::create_from_ai_result($this->page_result([$entry]), $course, 1);

        $context = \context_module::instance($cm->coursemodule);
        $files = get_file_storage()->get_area_files($context->id, 'mod_page', 'content', false, 'filename', false);
        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertSame('foro-1a2b3c4d-1.png', $file->get_filename());
        $this->assertSame('PNGDATA', $file->get_content());
        $page = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertStringContainsString('@@PLUGINFILE@@/foro-1a2b3c4d-1.png', $page->content);
    }

    /**
     * Once the activity exists, nothing keeps the stored copy.
     */
    public function test_the_stored_copy_is_removed_after_the_creation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $entry = $this->stored_entry();

        create_mod_service::create_from_ai_result($this->page_result([$entry]), $course, 1);

        $record = generated_file_cache::file_record($entry);
        $this->assertFalse(get_file_storage()->file_exists(
            $record['contextid'],
            $record['component'],
            $record['filearea'],
            $record['itemid'],
            $record['filepath'],
            $record['filename']
        ));
    }

    /**
     * A result without generated files is created as it always was.
     */
    public function test_a_result_without_generated_files_has_no_files(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);

        $cm = create_mod_service::create_from_ai_result($this->page_result([]), $course, 1);

        $context = \context_module::instance($cm->coursemodule);
        $this->assertCount(0, get_file_storage()->get_area_files($context->id, 'mod_page', 'content', false, 'filename', false));
    }
}
