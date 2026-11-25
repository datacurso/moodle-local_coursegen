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

use local_coursegen\local\service\free_course_cover;

/**
 * The cover the AI service made for a free course becomes the course image.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\free_course_cover
 */
final class free_course_cover_test extends \advanced_testcase {
    /**
     * A downloader that stores the given bytes in a draft area and records the endpoint it was asked for.
     *
     * @param array $calls Receives every endpoint requested.
     * @return callable
     */
    private function downloader(array &$calls): callable {
        return function (string $endpoint, string $filename) use (&$calls): \stored_file {
            global $USER;
            $calls[] = $endpoint;
            return get_file_storage()->create_file_from_string([
                'contextid' => \context_user::instance($USER->id)->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => file_get_unused_draft_itemid(),
                'filepath' => '/',
                'filename' => $filename,
            ], 'cover bytes');
        };
    }

    /**
     * The names of the files in the course image area.
     *
     * @param int $courseid
     * @return string[]
     */
    private function cover_files(int $courseid): array {
        $files = get_file_storage()->get_area_files(
            \context_course::instance($courseid)->id, 'course', 'overviewfiles', 0, 'filename', false);
        return array_values(array_map(static fn(\stored_file $file): string => $file->get_filename(), $files));
    }

    public function test_the_cover_of_the_result_becomes_the_course_image(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $calls = [];

        $applied = free_course_cover::apply((int) $course->id, [
            'course_image' => ['file_path' => '/srv/files/generated_images/a b.png', 'file_name' => 'a b.png'],
        ], $this->downloader($calls));

        $this->assertTrue($applied);
        $this->assertSame(['/files/download?path=' . rawurlencode('/srv/files/generated_images/a b.png')], $calls);
        $this->assertSame(['a b.png'], $this->cover_files((int) $course->id));
    }

    public function test_a_result_without_cover_changes_nothing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $calls = [];

        $this->assertFalse(free_course_cover::apply((int) $course->id, ['sections_info' => []], $this->downloader($calls)));
        $this->assertSame([], $calls);
        $this->assertSame([], $this->cover_files((int) $course->id));
    }

    public function test_a_failed_download_leaves_the_course_without_cover(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $applied = free_course_cover::apply((int) $course->id, [
            'course_image' => ['file_path' => '/srv/files/x.png', 'file_name' => 'x.png'],
        ], static function (): \stored_file {
            throw new \moodle_exception('nofile', 'error');
        });
        $this->resetDebugging();

        $this->assertFalse($applied);
        $this->assertSame([], $this->cover_files((int) $course->id));
    }
}
