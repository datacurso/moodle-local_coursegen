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

use local_coursegen\external\get_template_structure;
use local_coursegen\local\models\template;
use local_coursegen\output\sections_config;

/**
 * The screens that list a template's sections show the subsections of a flexsections course under their unit.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\output\sections_config
 * @covers     \local_coursegen\external\get_template_structure
 *
 * @runTestsInSeparateProcesses
 */
final class template_section_hierarchy_ui_test extends \advanced_testcase {
    /**
     * A flexsections course: 1 is a unit with children 2 and 3 (3 has a child, 4), 5 is a top section.
     *
     * @return \stdClass
     */
    private function create_flexsections_course(): \stdClass {
        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections', 'numsections' => 0]);
        $format = course_get_format($course);
        foreach ([1 => 0, 2 => 1, 3 => 1, 4 => 3, 5 => 0] as $number => $parent) {
            $section = course_create_section($course, $number);
            $format->update_section_format_options(['id' => $section->id, 'parent' => $parent]);
        }
        rebuild_course_cache($course->id, true);
        return get_course($course->id);
    }

    /**
     * The opening tag of one section card of the review.
     *
     * @param string $html
     * @param \stdClass $course
     * @param int $number
     * @return string
     */
    private function card_of(string $html, \stdClass $course, int $number): string {
        global $DB;
        $id = $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => $number], MUST_EXIST);
        $start = strpos($html, 'data-for="section" data-id="' . $id . '"');
        $this->assertNotFalse($start);
        $tagstart = strrpos(substr($html, 0, $start), '<div');
        return substr($html, $tagstart, strpos($html, '>', $start) - $tagstart + 1);
    }

    public function test_the_config_screen_marks_each_subsection_with_its_parent_and_indents_it(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_flexsections_course();

        $html = sections_config::render(get_fast_modinfo($course));

        $this->assertStringContainsString('data-parent="1"', $this->card_of($html, $course, 2));
        $this->assertStringContainsString('data-parent="3"', $this->card_of($html, $course, 4));
        $this->assertStringContainsString('margin-left: 3rem', $this->card_of($html, $course, 4));
        $this->assertStringNotContainsString('data-parent', $this->card_of($html, $course, 1));
        $this->assertStringNotContainsString('margin-left', $this->card_of($html, $course, 5));
    }

    public function test_the_config_screen_of_another_format_is_not_marked(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);

        $html = sections_config::render(get_fast_modinfo($course));

        $this->assertStringNotContainsString('data-parent', $html);
        $this->assertStringNotContainsString('margin-left', $html);
    }

    public function test_the_professor_structure_gives_the_parent_and_depth_of_a_flexsections_section(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_flexsections_course();
        $template = new template(0, (object) ['name' => 'Flex', 'courseid' => $course->id]);
        $template->create();

        $structure = get_template_structure::execute($template->get('id'));

        $sections = array_column($structure['sections'], null, 'num');
        $this->assertSame(0, $sections[1]['depth']);
        $this->assertSame(2, $sections[4]['depth']);
        $this->assertSame(3, $sections[4]['parent']);
    }

    public function test_the_professor_structure_of_another_format_has_no_parent_or_depth(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);
        $template = new template(0, (object) ['name' => 'Topics', 'courseid' => $course->id]);
        $template->create();

        $structure = get_template_structure::execute($template->get('id'));

        $this->assertArrayNotHasKey('depth', $structure['sections'][1]);
        $this->assertArrayNotHasKey('parent', $structure['sections'][1]);
    }
}
