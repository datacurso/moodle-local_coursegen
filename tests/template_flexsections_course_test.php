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
use local_coursegen\local\service\template_course_order;
use local_coursegen\local\service\template_export_service;
use local_coursegen\local\service\template_keep_copier;
use local_coursegen\local\service\template_section_look;
use local_coursegen\local\service\template_section_map;
use local_coursegen\local\service\template_settings_copier;

/**
 * A course made from a flexsections template keeps its units and the subsections under them.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_course_service
 * @covers     \local_coursegen\local\service\template_section_look
 * @covers     \local_coursegen\local\service\template_unit_resizer
 *
 * @runTestsInSeparateProcesses
 */
final class template_flexsections_course_test extends \advanced_testcase {
    /** @var int The template made by create_from_template(). */
    private int $templateid = 0;

    /** @var array The sections_info that was sent for it. */
    private array $sent = [];

    /** @var array The whole payload that was built for it. */
    private array $payload = [];

    /** @var bool Whether the template ends with a closing section (section 8). */
    private bool $withclosing = true;

    /**
     * A template course: 1 is an introduction, 2 and 5 are units (children 3, 4 and 6, 7), 8 closes the course.
     *
     * @return \stdClass
     */
    private function create_template_course(): \stdClass {
        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections', 'numsections' => 0]);
        $format = course_get_format($course);
        $tree = [
            1 => [0, 'Intro'],
            2 => [0, 'Unit 1'], 3 => [2, 'Resources 1'], 4 => [2, 'Activities 1'],
            5 => [0, 'Unit 2'], 6 => [5, 'Resources 2'], 7 => [5, 'Activities 2'],
            8 => [0, 'Closing'],
        ];
        if (!$this->withclosing) {
            unset($tree[8]);
        }
        foreach ($tree as $number => [$parent, $name]) {
            $section = course_create_section($course, $number);
            $format->update_section_format_options(['id' => $section->id, 'parent' => $parent]);
            course_update_section($course, $section, ['name' => $name, 'summary' => 'About ' . $name]);
        }
        rebuild_course_cache($course->id, true);
        if (!$this->withclosing) {
            return get_course($course->id);
        }
        // The closing section shows a picture, which has to follow it wherever it ends up.
        $closing = course_get_format($course)->get_section(8);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_course::instance($course->id)->id,
            'component' => 'course',
            'filearea' => 'section',
            'itemid' => $closing->id,
            'filepath' => '/',
            'filename' => 'closing.png',
        ], 'closing-bytes');
        return get_course($course->id);
    }

    /**
     * Two kept pages in the closing section, one in the second unit's resources.
     *
     * @param \stdClass $course
     */
    private function create_kept_pages(\stdClass $course): void {
        $generator = $this->getDataGenerator();
        if ($this->withclosing) {
            $generator->create_module('page', ['course' => $course->id, 'section' => 8, 'name' => 'Closing one']);
            $generator->create_module('page', ['course' => $course->id, 'section' => 8, 'name' => 'Closing two']);
        } else {
            $generator->create_module('page', ['course' => $course->id, 'section' => 7, 'name' => 'Last activity']);
        }
        $generator->create_module('page', ['course' => $course->id, 'section' => 6, 'name' => 'Resource two']);
    }

    /**
     * The activities of a course: section number => names in order.
     *
     * @param int $courseid
     * @return array
     */
    private function activities_of(int $courseid): array {
        $modinfo = get_fast_modinfo($courseid);
        $bysection = [];
        foreach ($modinfo->get_cms() as $cm) {
            $bysection[$cm->sectionnum][] = $cm->name;
        }
        ksort($bysection);
        return $bysection;
    }

    /**
     * Build the course the AI service would have been asked for.
     *
     * @param int|null $units How many units the payload asks for.
     * @param bool $echoed Whether the session stores the payload and the service answers bare sections,
     *        as the real service does: the structure then has to come back from the stored payload.
     * @return array The result of create_course().
     */
    private function create_from_template(?int $units, bool $echoed = false): array {
        global $USER;
        $source = $this->create_template_course();
        $this->create_kept_pages($source);
        $template = new template(0, (object) ['name' => 'Flex template', 'courseid' => $source->id]);
        $template->create();
        $session = new course_session(0, (object) [
            'userid' => $USER->id,
            'session_id' => 'sess-flex',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode(['templateid' => $template->get('id')]),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $session->create();

        $this->templateid = (int) $template->get('id');
        $payload = template_export_service::build_init_payload($template->get('id'), '', '', false, $units);
        $this->payload = $payload;
        $this->sent = $payload['sections_info'];
        $configuration = $payload['course_configuration'];
        $configuration['fullname'] = 'Made from flex';
        $configuration['shortname'] = 'made-from-flex';
        $answered = $payload['sections_info'];
        if ($echoed) {
            $session->set('coursedata', json_encode([
                'templateid' => $template->get('id'),
                'payload' => ['sections_info' => $payload['sections_info']],
            ]));
            $session->update();
            $answered = array_map(static fn($entry) => [
                'section' => $entry['section'],
                'name' => $entry['name'],
                'summary' => $entry['summary'],
                'summaryformat' => $entry['summaryformat'],
                'format_options' => $entry['format_options'] ?? [],
            ], $payload['sections_info']);
        }
        return create_course_service::create_course($session, [
            'course_configuration' => $configuration,
            'sections_info' => $answered,
        ]);
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

    public function test_the_same_number_of_units_keeps_every_subsection_under_its_unit(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = $this->create_from_template(null);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame([
            0 => [0, 'General'],
            1 => [0, 'Intro'],
            2 => [0, 'Unit 1'], 3 => [2, 'Resources 1'], 4 => [2, 'Activities 1'],
            5 => [0, 'Unit 2'], 6 => [5, 'Resources 2'], 7 => [5, 'Activities 2'],
            8 => [0, 'Closing'],
        ], $this->tree_of($result['courseid']));
    }

    public function test_more_units_add_whole_units_before_the_closing_section(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = $this->create_from_template(3);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame([
            0 => [0, 'General'],
            1 => [0, 'Intro'],
            2 => [0, 'Unit 1'], 3 => [2, 'Resources 1'], 4 => [2, 'Activities 1'],
            5 => [0, 'Unit 2'], 6 => [5, 'Resources 2'], 7 => [5, 'Activities 2'],
            8 => [0, 'Unit 2'], 9 => [8, 'Resources 2'], 10 => [8, 'Activities 2'],
            11 => [0, 'Closing'],
        ], $this->tree_of($result['courseid']));
    }

    public function test_fewer_units_drop_whole_units_from_the_end(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = $this->create_from_template(1);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame([
            0 => [0, 'General'],
            1 => [0, 'Intro'],
            2 => [0, 'Unit 1'], 3 => [2, 'Resources 1'], 4 => [2, 'Activities 1'],
            5 => [0, 'Closing'],
        ], $this->tree_of($result['courseid']));
    }

    public function test_each_section_takes_the_summary_of_the_template_section_it_comes_from(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = $this->create_from_template(3);

        $summaries = $DB->get_records_menu('course_sections', ['course' => $result['courseid']], 'section', 'section, summary');
        $this->assertSame('About Unit 2', $summaries[5]);
        $this->assertSame('About Unit 2', $summaries[8]);
        $this->assertSame('About Resources 2', $summaries[9]);
        $this->assertSame('About Activities 2', $summaries[10]);
        $this->assertSame('About Closing', $summaries[11]);
    }

    public function test_fewer_units_keep_the_summary_of_the_section_that_stays(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = $this->create_from_template(1);

        $summaries = $DB->get_records_menu('course_sections', ['course' => $result['courseid']], 'section', 'section, summary');
        $this->assertSame('About Closing', $summaries[5]);
    }

    public function test_the_files_of_a_section_follow_it_to_its_new_number(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = $this->create_from_template(1);

        $closing = $DB->get_record('course_sections', ['course' => $result['courseid'], 'section' => 5], '*', MUST_EXIST);
        $contextid = \context_course::instance($result['courseid'])->id;
        $file = get_file_storage()->get_file($contextid, 'course', 'section', $closing->id, '/', 'closing.png');
        $this->assertNotFalse($file);
        $this->assertSame('closing-bytes', $file->get_content());
    }

    public function test_kept_activities_are_copied_into_the_section_they_moved_to_and_not_cloned(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $result = $this->create_from_template(3);
        $map = template_section_map::from_sections_info($this->sent);

        $kept = [];
        $failures = template_keep_copier::copy_into($this->templateid, $result['courseid'], $kept, $map);

        $this->assertSame([], $failures);
        $this->assertSame([
            6 => ['Resource two'],
            11 => ['Closing one', 'Closing two'],
        ], $this->activities_of($result['courseid']));
    }

    public function test_kept_activities_of_a_dropped_unit_are_not_copied_and_the_rest_land_in_place(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $result = $this->create_from_template(1);
        $map = template_section_map::from_sections_info($this->sent);

        $kept = [];
        $failures = template_keep_copier::copy_into($this->templateid, $result['courseid'], $kept, $map);

        $this->assertSame(['Resource two'], $failures);
        $this->assertSame([5 => ['Closing one', 'Closing two']], $this->activities_of($result['courseid']));
    }

    public function test_the_order_of_a_moved_section_follows_the_template(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $result = $this->create_from_template(3);
        $map = template_section_map::from_sections_info($this->sent);
        $kept = [];
        template_keep_copier::copy_into($this->templateid, $result['courseid'], $kept, $map);
        $sectionid = $DB->get_field('course_sections', 'id', ['course' => $result['courseid'], 'section' => 11]);
        $sequence = explode(',', $DB->get_field('course_sections', 'sequence', ['id' => $sectionid]));
        $DB->set_field('course_sections', 'sequence', implode(',', array_reverse($sequence)), ['id' => $sectionid]);

        template_course_order::apply($this->templateid, $result['courseid'], [], [], $kept, $map);

        $this->assertSame(['Closing one', 'Closing two'], $this->activities_of($result['courseid'])[11]);
    }

    public function test_the_visibility_of_a_moved_section_follows_the_template(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $result = $this->create_from_template(3);
        $map = template_section_map::from_sections_info($this->sent);
        $templatecourseid = (int) $DB->get_field('local_coursegen_template', 'courseid', ['id' => $this->templateid]);
        set_section_visible($templatecourseid, 8, 0);

        template_settings_copier::copy_into($this->templateid, $result['courseid'], [], [], [], $map);

        $visible = $DB->get_records_menu('course_sections', ['course' => $result['courseid']], 'section', 'section, visible');
        $this->assertEquals(0, $visible[11]);
        $this->assertEquals(1, $visible[8]);
    }

    public function test_a_template_without_a_closing_section_drops_the_activities_of_the_removed_units(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->withclosing = false;

        $result = $this->create_from_template(1);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame([
            0 => [0, 'General'],
            1 => [0, 'Intro'],
            2 => [0, 'Unit 1'], 3 => [2, 'Resources 1'], 4 => [2, 'Activities 1'],
        ], $this->tree_of($result['courseid']));
        $sections = array_column($this->payload['activities'], 'parameters');
        $this->assertSame([], array_filter($sections, static fn($parameters) => $parameters['section'] > 4));
        $map = template_section_map::from_sections_info($this->sent);
        $kept = [];
        $failures = template_keep_copier::copy_into($this->templateid, $result['courseid'], $kept, $map);
        $this->assertSame(['Resource two', 'Last activity'], $failures);
        $this->assertSame([], $this->activities_of($result['courseid']));
    }

    public function test_the_structure_the_service_does_not_echo_comes_back_from_the_stored_payload(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = $this->create_from_template(3, true);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame([
            0 => [0, 'General'],
            1 => [0, 'Intro'],
            2 => [0, 'Unit 1'], 3 => [2, 'Resources 1'], 4 => [2, 'Activities 1'],
            5 => [0, 'Unit 2'], 6 => [5, 'Resources 2'], 7 => [5, 'Activities 2'],
            8 => [0, 'Unit 2'], 9 => [8, 'Resources 2'], 10 => [8, 'Activities 2'],
            11 => [0, 'Closing'],
        ], $this->tree_of($result['courseid']));
        // The picture of the closing section reaches section 11 only through the restored source_section.
        global $DB;
        $closing = $DB->get_record('course_sections', ['course' => $result['courseid'], 'section' => 11], '*', MUST_EXIST);
        $contextid = \context_course::instance($result['courseid'])->id;
        $this->assertNotFalse(get_file_storage()->get_file($contextid, 'course', 'section', $closing->id, '/', 'closing.png'));
    }

    public function test_the_section_map_of_the_finish_is_read_from_the_stored_payload(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->create_from_template(3);
        $session = new course_session(0, (object) [
            'coursedata' => json_encode(['payload' => ['sections_info' => $this->sent]]),
        ]);

        $method = new \ReflectionMethod(\local_coursegen\external\finish_template_generation::class, 'section_map');
        $method->setAccessible(true);
        $map = $method->invoke(null, $session);

        $this->assertSame(8, $map->source_of(11));
        $this->assertTrue($map->is_cloned(9));
        $this->assertSame(11, $map->target_of_source(8));
    }

    public function test_a_section_whose_source_left_the_template_takes_the_look_of_the_same_numbered_one(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $result = $this->create_from_template(null);
        $course = get_course($result['courseid']);
        $closing = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 8], '*', MUST_EXIST);
        get_file_storage()->delete_area_files(\context_course::instance($course->id)->id, 'course', 'section', $closing->id);
        $info = ['section' => 8, 'source_section' => 99, 'summary' => 'Fresh', 'summaryformat' => FORMAT_HTML];

        template_section_look::apply($course, [$info], (int) $DB->get_field('local_coursegen_template', 'courseid', ['id' => $this->templateid]));

        $contextid = \context_course::instance($course->id)->id;
        $this->assertNotFalse(get_file_storage()->get_file($contextid, 'course', 'section', $closing->id, '/', 'closing.png'));
        $this->assertSame('Fresh', $DB->get_field('course_sections', 'summary', ['id' => $closing->id]));
        $this->assertDebuggingCalled();
    }

    public function test_a_section_with_no_counterpart_in_the_template_is_skipped_with_a_warning(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $result = $this->create_from_template(null);
        $course = get_course($result['courseid']);
        $bare = $this->getDataGenerator()->create_course(['format' => 'flexsections', 'numsections' => 0]);
        $before = $DB->get_field('course_sections', 'summary', ['course' => $course->id, 'section' => 8]);
        $info = ['section' => 8, 'source_section' => 99, 'summary' => 'Fresh'];

        template_section_look::apply($course, [$info], (int) $bare->id);

        $this->assertDebuggingCalled();
        $this->assertSame($before, $DB->get_field('course_sections', 'summary', ['course' => $course->id, 'section' => 8]));
    }
}
