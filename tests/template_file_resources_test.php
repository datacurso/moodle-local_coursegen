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
 * The file resources of a result whose file the run replaced.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_file_resources
 */
final class template_file_resources_test extends \basic_testcase {
    /**
     * A resource entry of the result.
     *
     * @param array $files generated_files of the entry.
     * @param string $type Module name.
     * @param int $cmid Template course module id.
     * @return array The entry.
     */
    private function entry(array $files, string $type = 'resource', int $cmid = 11340): array {
        return ['cmid' => $cmid, 'uid' => (string) $cmid, 'resource_type' => $type, 'generated_files' => $files];
    }

    /**
     * A resource with a main file is selected with that file.
     */
    public function test_a_resource_with_a_main_file_is_selected(): void {
        $main = ['file_id' => 'f1', 'filename' => 'guide.pdf', 'role' => 'main'];

        $selected = template_file_resources::select([$this->entry([$main])]);

        $this->assertSame([['uid' => '11340', 'cmid' => 11340, 'file' => $main]], $selected);
        $this->assertSame([11340], template_file_resources::cmids($selected));
    }

    /**
     * The main file wins over an earlier one, and without a main file the first usable one is used.
     */
    public function test_the_main_file_wins_and_the_first_usable_is_the_fallback(): void {
        $other = ['file_id' => 'f0', 'filename' => 'cover.png', 'role' => 'cover'];
        $main = ['file_id' => 'f1', 'filename' => 'guide.pdf', 'role' => 'main'];

        $withmain = template_file_resources::select([$this->entry([$other, $main])]);
        $withoutmain = template_file_resources::select([$this->entry([$other])]);

        $this->assertSame($main, $withmain[0]['file']);
        $this->assertSame($other, $withoutmain[0]['file']);
    }

    /**
     * Activities that are not file resources, or have no usable file, are not selected.
     */
    public function test_other_activities_and_unusable_files_are_not_selected(): void {
        $good = ['file_id' => 'f1', 'filename' => 'guide.pdf'];
        $activities = [
            $this->entry([$good], 'page'),
            $this->entry([]),
            $this->entry([['file_id' => '', 'filename' => 'a.pdf']]),
            $this->entry([['file_id' => 'f2', 'filename' => '']]),
            $this->entry([['file_id' => 5, 'filename' => 'a.pdf']]),
            $this->entry(['junk', null]),
            $this->entry([$good], 'resource', 0),
            ['resource_type' => 'resource', 'generated_files' => 'x'],
            [],
        ];

        $this->assertSame([], template_file_resources::select($activities));
    }

    /**
     * Whether an entry is a file resource tells the same as selecting it.
     */
    public function test_is_file_resource_agrees_with_select(): void {
        $file = ['file_id' => 'f1', 'filename' => 'guide.pdf'];

        $this->assertTrue(template_file_resources::is_file_resource($this->entry([$file])));
        $this->assertFalse(template_file_resources::is_file_resource($this->entry([$file], 'page')));
        $this->assertFalse(template_file_resources::is_file_resource($this->entry([])));
    }

    /**
     * The uid falls back to the course module id and several resources keep their order.
     */
    public function test_the_uid_falls_back_to_the_cmid_and_the_order_is_kept(): void {
        $file = ['file_id' => 'f1', 'filename' => 'a.pdf'];
        $first = ['cmid' => 7, 'resource_type' => 'resource', 'generated_files' => [$file]];
        $second = $this->entry([$file], 'resource', 9);

        $selected = template_file_resources::select([$first, $second]);

        $this->assertSame(['7', '9'], array_column($selected, 'uid'));
        $this->assertSame([7, 9], template_file_resources::cmids($selected));
    }
}
