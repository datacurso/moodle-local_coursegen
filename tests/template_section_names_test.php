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

use local_coursegen\local\models\course_session;
use local_coursegen\local\models\template;
use local_coursegen\local\service\create_course_service;
use local_coursegen\local\service\template_export_service;
use local_coursegen\local\service\template_section_names;

/**
 * The names the AI gives the sections reach the course made from a template, by section number.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_section_names
 * @covers     \local_coursegen\local\service\create_course_service
 *
 * @runTestsInSeparateProcesses
 */
final class template_section_names_test extends \advanced_testcase {
    /**
     * A flexsections template: 1 intro, 2 unit with subsections 3 and 4, 5 closing.
     *
     * @return \stdClass
     */
    private function create_flexsections_template_course(): \stdClass {
        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections', 'numsections' => 0]);
        $format = course_get_format($course);
        $tree = [1 => [0, 'Intro'], 2 => [0, 'Unit 1'], 3 => [2, 'Resources'], 4 => [2, 'Activities'], 5 => [0, 'Closing']];
        foreach ($tree as $number => [$parent, $name]) {
            $section = course_create_section($course, $number);
            $format->update_section_format_options(['id' => $section->id, 'parent' => $parent]);
            course_update_section($course, $section, ['name' => $name]);
        }
        rebuild_course_cache($course->id, true);
        return get_course($course->id);
    }

    /**
     * Make a course from a template the way the finish does, with the AI answering the given names.
     *
     * @param \stdClass $source The template's course.
     * @param callable $rename Receives the answered entries (section => entry) and returns them changed.
     * @param int|null $units Number of units to ask for.
     * @return array{0: array, 1: array} The result of create_course() and the sections_info sent.
     */
    private function create_from_template(\stdClass $source, callable $rename, ?int $units = null): array {
        global $USER;

        $template = new template(0, (object) ['name' => 'Names', 'courseid' => $source->id]);
        $template->create();
        $payload = template_export_service::build_init_payload($template->get('id'), '', '', false, $units);
        $session = new course_session(0, (object) [
            'userid' => $USER->id,
            'session_id' => 'sess-names',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode([
                'templateid' => $template->get('id'),
                'payload' => ['sections_info' => $payload['sections_info']],
            ]),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $session->create();

        $answered = [];
        foreach ($payload['sections_info'] as $entry) {
            $answered[(int) $entry['section']] = [
                'section' => $entry['section'],
                'name' => $entry['name'],
                'original_name' => $entry['name'],
                'cloned' => !empty($entry['cloned']),
                'summary' => $entry['summary'],
                'summaryformat' => $entry['summaryformat'],
                'format_options' => $entry['format_options'] ?? [],
            ];
        }
        $configuration = $payload['course_configuration'];
        $configuration['fullname'] = 'Made with names';
        $configuration['shortname'] = 'made-with-names';
        $result = create_course_service::create_course($session, [
            'course_configuration' => $configuration,
            'sections_info' => array_values($rename($answered)),
        ]);
        return [$result, $payload['sections_info']];
    }

    /**
     * The sections of a course: section number => [parent, name].
     *
     * @param int $courseid
     * @return array
     */
    private function tree_of(int $courseid): array {
        $course = get_course($courseid);
        $format = course_get_format($course);
        $tree = [];
        foreach (get_fast_modinfo($course)->get_section_info_all() as $section) {
            $tree[(int) $section->section] = [(int) $format->get_format_options($section)['parent'], (string) $section->name];
        }
        return $tree;
    }

    /**
     * The new names land on their section number and the hierarchy of the template is untouched.
     */
    public function test_the_ai_names_are_applied_by_section_number_and_the_hierarchy_is_kept(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->create_flexsections_template_course();

        [$result] = $this->create_from_template($source, function (array $sections): array {
            $sections[2]['name'] = 'Algebra basics';
            $sections[4]['name'] = 'Practice sets';
            return $sections;
        });

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame([
            0 => [0, 'General'],
            1 => [0, 'Intro'],
            2 => [0, 'Algebra basics'], 3 => [2, 'Resources'], 4 => [2, 'Practice sets'],
            5 => [0, 'Closing'],
        ], $this->tree_of($result['courseid']));
    }

    /**
     * An empty, blank or unchanged name does not replace what the section has; an overlong one is cut.
     */
    public function test_blank_names_are_ignored_and_long_ones_are_cut(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->create_flexsections_template_course();

        [$result, $sent] = $this->create_from_template($source, function (array $sections): array {
            $sections[1]['name'] = '   ';
            $sections[3]['name'] = str_repeat('x', 300);
            return $sections;
        });

        $this->assertTrue($result['success'], $result['message']);
        $tree = $this->tree_of($result['courseid']);
        $this->assertSame(255, \core_text::strlen($tree[3][1]));
        $this->assertNotSame('   ', $tree[1][1]);
        $this->assertSame(array_column($sent, 'parent_section', 'section')[3] ?? 2, $tree[3][0]);
    }

    /**
     * With more units than the template has, the cloned sections take the name the AI gave them.
     */
    public function test_cloned_units_take_the_name_the_ai_gave_them(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->create_flexsections_template_course();

        [$result, $sent] = $this->create_from_template($source, function (array $sections): array {
            foreach ($sections as $number => $entry) {
                if (!empty($entry['cloned'])) {
                    $sections[$number]['name'] = 'AI ' . $entry['name'];
                }
            }
            return $sections;
        }, 2);

        $this->assertTrue($result['success'], $result['message']);
        $cloned = array_filter($sent, static fn($entry) => !empty($entry['cloned']));
        $this->assertNotEmpty($cloned);
        $tree = $this->tree_of($result['courseid']);
        foreach ($cloned as $entry) {
            $this->assertSame('AI ' . $entry['name'], $tree[(int) $entry['section']][1]);
            $this->assertSame((int) $entry['parent_section'], $tree[(int) $entry['section']][0]);
        }
    }

    /**
     * Applying the names again changes only the sections whose name is different and not empty.
     */
    public function test_apply_changes_only_differing_non_empty_names(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        course_update_section($course, $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1]),
            ['name' => 'Keep me']);
        course_update_section($course, $DB->get_record('course_sections', ['course' => $course->id, 'section' => 2]),
            ['name' => 'Old']);

        template_section_names::apply((int) $course->id, [
            ['section' => 1, 'name' => 'Keep me', 'original_name' => 'Template one'],
            ['section' => 2, 'name' => 'New', 'original_name' => 'Old'],
            ['section' => 3, 'name' => ''],
            ['section' => 9, 'name' => 'No such section'],
        ]);

        $names = $DB->get_records_menu('course_sections', ['course' => $course->id], 'section', 'section, name');
        $this->assertSame('Keep me', $names[1]);
        $this->assertSame('New', $names[2]);
        $this->assertEmpty($names[3]);
        $this->assertArrayNotHasKey(9, $names);
    }

    /**
     * The same text, trimmed and cut, is what a name is stored as.
     */
    public function test_clean_name_trims_and_cuts(): void {
        $this->assertSame('', template_section_names::clean_name("  \t "));
        $this->assertSame('Unit', template_section_names::clean_name('  Unit '));
        $this->assertSame(255, \core_text::strlen(template_section_names::clean_name(str_repeat('é', 400))));
    }
}
