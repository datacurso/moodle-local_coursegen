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

namespace local_coursegen\local\space;

/**
 * Tests for space_resource_file.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\space\space_resource_file
 */
final class space_resource_file_test extends \advanced_testcase {
    /**
     * The resource ends with the teacher's file only, as its main file, and keeps its name.
     */
    public function test_the_teachers_file_replaces_the_file_of_the_resource(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $draft = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance(get_admin()->id);
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => $usercontext->id, 'component' => 'user', 'filearea' => 'draft', 'itemid' => $draft,
            'filepath' => '/', 'filename' => 'template.pdf',
        ], 'TEMPLATE');
        $resource = $this->getDataGenerator()->create_module('resource', [
            'course' => $course->id, 'name' => 'Guide', 'files' => $draft,
        ]);
        $teacher = $fs->create_file_from_string([
            'contextid' => $usercontext->id, 'component' => 'local_coursegen', 'filearea' => 'spacefile', 'itemid' => 3,
            'filepath' => '/1/', 'filename' => 'mine.pdf',
        ], 'MINE');
        $before = (int) $DB->get_field('resource', 'revision', ['id' => $resource->id]);

        space_resource_file::replace((int) $resource->cmid, $teacher);

        $context = \context_module::instance($resource->cmid);
        $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'id', false);
        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertSame('mine.pdf', $file->get_filename());
        $this->assertSame('MINE', $file->get_content());
        $this->assertSame(1, (int) $file->get_sortorder());
        $this->assertSame('Guide', $DB->get_field('resource', 'name', ['id' => $resource->id]));
        $this->assertSame($before + 1, (int) $DB->get_field('resource', 'revision', ['id' => $resource->id]));
    }
}
