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
use local_coursegen\local\service\template_section_look;

/**
 * The sections the AI service appends after a template's own ones hang under their parent.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_section_look
 * @covers     \local_coursegen\local\service\template_unit_resizer
 *
 * @runTestsInSeparateProcesses
 */
final class template_added_sections_test extends \advanced_testcase {
    /**
     * A flexsections template with an introduction (1) and a closing section (2).
     *
     * @return \stdClass
     */
    private function create_template_course(): \stdClass {
        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections', 'numsections' => 0]);
        $format = course_get_format($course);
        foreach ([1 => 'Intro', 2 => 'Closing'] as $number => $name) {
            $section = course_create_section($course, $number);
            $format->update_section_format_options(['id' => $section->id, 'parent' => 0]);
            course_update_section($course, $section, ['name' => $name, 'summary' => 'About ' . $name]);
        }
        rebuild_course_cache($course->id, true);
        return get_course($course->id);
    }

    /**
     * The entries the service appends: two units, the first with two subsections, the second with one.
     *
     * @param int $first The number of the first added section.
     * @return array
     */
    private function added_entries(int $first): array {
        $layout = [
            [0, 'Unidad 1', 0, 0], [1, 'Recursos Educativos', 1, 0], [2, 'Actividades de Aprendizaje y Evaluación', 1, 0],
            [3, 'Unidad 2', 0, 0], [4, 'Recursos Educativos', 1, 3],
        ];
        $entries = [];
        foreach ($layout as [$offset, $name, $depth, $parentoffset]) {
            $parent = 0;
            if ($depth > 0) {
                $parent = $first + $parentoffset;
            }
            $entries[] = [
                'uid' => 'added-' . $offset,
                'section' => $first + $offset,
                'name' => $name,
                'summary' => '',
                'summaryformat' => FORMAT_HTML,
                'format_options' => ['parent' => $parent],
                'image' => null,
                'template_behavior' => ['behavior' => ($depth > 0) ? 'custom' : 'aimodify'],
                'parent_section' => $parent,
                'depth' => $depth,
            ];
        }
        return $entries;
    }

    /**
     * Create a course the way the service answers: the sent sections bare, then the appended ones.
     *
     * @return array [result of create_course(), the template course]
     */
    private function create_with_added_sections(): array {
        global $USER;
        $source = $this->create_template_course();
        $template = new template(0, (object) ['name' => 'Flex template', 'courseid' => $source->id]);
        $template->create();
        $payload = template_export_service::build_init_payload($template->get('id'), '', '', false, null);
        $session = new course_session(0, (object) [
            'userid' => $USER->id,
            'session_id' => 'sess-added',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode([
                'templateid' => $template->get('id'),
                'payload' => ['sections_info' => $payload['sections_info']],
            ]),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $session->create();

        $answered = array_map(static fn($entry) => [
            'section' => $entry['section'],
            'name' => $entry['name'],
            'summary' => $entry['summary'],
            'summaryformat' => $entry['summaryformat'],
            'format_options' => $entry['format_options'] ?? [],
        ], $payload['sections_info']);
        $answered = array_merge($answered, $this->added_entries(count($answered)));
        $configuration = $payload['course_configuration'];
        $configuration['fullname'] = 'Made with added sections';
        $configuration['shortname'] = 'made-added';
        $result = create_course_service::create_course($session, [
            'course_configuration' => $configuration,
            'sections_info' => $answered,
        ]);
        return [$result, $source];
    }

    public function test_added_sections_are_created_under_their_parents(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$result] = $this->create_with_added_sections();

        $this->assertTrue($result['success'], $result['message']);
        $course = get_course($result['courseid']);
        $format = course_get_format($course);
        $tree = [];
        foreach (get_fast_modinfo($course)->get_section_info_all() as $section) {
            $tree[(int) $section->section] = [(int) $format->get_format_options($section)['parent'], (string) $section->name];
        }
        $this->assertSame([0, 'Unidad 1'], $tree[3]);
        $this->assertSame([3, 'Recursos Educativos'], $tree[4]);
        $this->assertSame([3, 'Actividades de Aprendizaje y Evaluación'], $tree[5]);
        $this->assertSame([0, 'Unidad 2'], $tree[6]);
        $this->assertSame([6, 'Recursos Educativos'], $tree[7]);
        $this->assertSame([0, 'Closing'], $tree[2]);
    }

    public function test_template_sections_keep_their_look_and_added_ones_have_no_template_summary(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$result] = $this->create_with_added_sections();

        $summaries = $DB->get_records_menu('course_sections', ['course' => $result['courseid']], 'section', 'section, summary');
        $this->assertSame('About Intro', $summaries[1]);
        $this->assertSame('About Closing', $summaries[2]);
        $this->assertSame('', (string) $summaries[3]);
    }

    public function test_an_added_section_never_takes_the_look_of_a_template_section_with_its_number(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$result, $source] = $this->create_with_added_sections();
        $course = get_course($result['courseid']);
        $info = [
            'section' => 2, 'name' => 'Added', 'summary' => 'Fresh', 'added' => true,
            'format_options' => ['parent' => 1], 'parent_section' => 1,
        ];

        template_section_look::apply($course, [$info], (int) $source->id);

        $format = course_get_format($course);
        $section = $format->get_section(2);
        $this->assertSame(1, (int) $format->get_format_options($section)['parent']);
        $this->assertSame('About Closing', $DB->get_field('course_sections', 'summary', ['id' => $section->id]));
        $this->assertDebuggingNotCalled();
    }

    public function test_a_section_with_no_template_counterpart_still_gets_its_parent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$result, $source] = $this->create_with_added_sections();
        $course = get_course($result['courseid']);
        $info = ['section' => 4, 'source_section' => 99, 'format_options' => ['parent' => 6], 'parent_section' => 6];

        template_section_look::apply($course, [$info], (int) $source->id);

        $format = course_get_format($course);
        $this->assertSame(6, (int) $format->get_format_options($format->get_section(4))['parent']);
        $this->assertDebuggingCalled();
    }
}
