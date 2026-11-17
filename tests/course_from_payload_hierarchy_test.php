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

use local_coursegen\local\preview\course_from_payload;

/**
 * The preview of a flexsections course shows the subsections under their unit.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\course_from_payload
 *
 * @runTestsInSeparateProcesses
 */
final class course_from_payload_hierarchy_test extends \advanced_testcase {
    /**
     * A payload: 1 is a unit with children 2 and 3, 4 closes the course.
     *
     * @param string $format
     * @return array
     */
    private function payload(string $format): array {
        $parents = [0 => 0, 1 => 0, 2 => 1, 3 => 1, 4 => 0];
        $sections = [];
        foreach ($parents as $number => $parent) {
            $sections[] = [
                'uid' => 'uid-' . $number,
                'section' => $number,
                'name' => 'S' . $number,
                'format_options' => ['parent' => $parent],
            ];
        }
        return [
            'course_configuration' => ['format' => $format],
            'sections_info' => $sections,
            'activities' => [],
        ];
    }

    public function test_flexsections_sections_keep_their_order_and_say_how_deep_they_are(): void {
        $this->resetAfterTest();

        $content = course_from_payload::content($this->payload('flexsections'), 1);

        $this->assertSame([1, 2, 3, 4], array_column($content['sections'], 'num'));
        $this->assertSame([0, 1, 1, 0], array_column($content['sections'], 'depth'));
    }

    public function test_other_formats_say_nothing_about_depth(): void {
        $this->resetAfterTest();

        $content = course_from_payload::content($this->payload('topics'), 1);

        $this->assertArrayNotHasKey('depth', $content['sections'][1]);
    }

    public function test_the_indent_is_a_rule_for_each_nested_section_only(): void {
        $css = course_from_payload::indent_css($this->payload('flexsections'));

        $this->assertStringContainsString('#section-2', $css);
        $this->assertStringContainsString('#section-3', $css);
        $this->assertStringNotContainsString('#section-1 ', $css);
        $this->assertStringNotContainsString('#section-4', $css);
        $this->assertStringContainsString('margin-left: 2rem', $css);
    }

    public function test_the_indent_is_empty_for_other_formats(): void {
        $this->assertSame('', course_from_payload::indent_css($this->payload('topics')));
    }
}
