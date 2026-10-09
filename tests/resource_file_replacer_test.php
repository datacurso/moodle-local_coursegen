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

/**
 * Putting another file in a file resource.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\resource_file_replacer
 */
final class resource_file_replacer_test extends \advanced_testcase {
    /**
     * A stored file in the system context, to be copied in.
     *
     * @param string $content Bytes of the file.
     * @param string $filename Name of the file.
     * @return \stored_file The file.
     */
    private function source_file(string $content, string $filename): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id, 'component' => 'local_coursegen', 'filearea' => 'test',
            'itemid' => mt_rand(1, 999999), 'filepath' => '/', 'filename' => $filename,
        ], $content);
    }

    /**
     * The files a resource holds.
     *
     * @param int $cmid Course module id.
     * @return \stored_file[] The files.
     */
    private function files_of(int $cmid): array {
        $context = \context_module::instance($cmid);
        return array_values(get_file_storage()->get_area_files($context->id, 'mod_resource', 'content', 0, 'id', false));
    }

    /**
     * The new file takes the place of the old one and the revision grows.
     */
    public function test_the_new_file_replaces_the_old_one_and_bumps_the_revision(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $before = $DB->get_field('resource', 'revision', ['id' => $resource->id]);

        resource_file_replacer::replace((int) $resource->cmid, $this->source_file('NEW CONTENT', 'new-guide.pdf'));

        $files = $this->files_of((int) $resource->cmid);
        $this->assertCount(1, $files);
        $this->assertSame('new-guide.pdf', $files[0]->get_filename());
        $this->assertSame('NEW CONTENT', $files[0]->get_content());
        $this->assertSame(1, $files[0]->get_sortorder());
        $this->assertSame((int) $before + 1, (int) $DB->get_field('resource', 'revision', ['id' => $resource->id]));
    }

    /**
     * Replacing twice leaves only the last file.
     */
    public function test_replacing_twice_leaves_only_the_last_file(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);

        resource_file_replacer::replace((int) $resource->cmid, $this->source_file('ONE', 'one.pdf'));
        resource_file_replacer::replace((int) $resource->cmid, $this->source_file('TWO', 'two.pdf'));

        $files = $this->files_of((int) $resource->cmid);
        $this->assertCount(1, $files);
        $this->assertSame('two.pdf', $files[0]->get_filename());
    }

    /**
     * A file with unicode in its name is kept as it is.
     */
    public function test_a_unicode_name_is_kept(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);

        resource_file_replacer::replace((int) $resource->cmid, $this->source_file('X', 'Guía didáctica 🙂.pdf'));

        $this->assertSame('Guía didáctica 🙂.pdf', $this->files_of((int) $resource->cmid)[0]->get_filename());
    }

    /**
     * A course module that is not a resource is refused.
     */
    public function test_a_module_that_is_not_a_resource_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->expectException(\moodle_exception::class);
        resource_file_replacer::replace((int) $page->cmid, $this->source_file('X', 'a.pdf'));
    }
}
