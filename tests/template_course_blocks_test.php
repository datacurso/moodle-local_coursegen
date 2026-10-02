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

/**
 * A course made from a template shows the blocks of the template's course,
 * set up the way the template sets them up, and the blocks the site gives a
 * new course are not added on top of them.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_course_service
 * @covers     \local_coursegen\local\service\template_course_blocks
 * @covers     \local_coursegen\local\service\template_course_custom_fields
 *
 * @runTestsInSeparateProcesses
 */
final class template_course_blocks_test extends \advanced_testcase {
    /**
     * A course whose only blocks are the ones the test gives it.
     *
     * @param array $record Course record.
     * @return \stdClass
     */
    private function create_course_without_blocks(array $record = []): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course($record);
        $context = \context_course::instance($course->id);
        foreach ($DB->get_records('block_instances', ['parentcontextid' => $context->id]) as $instance) {
            blocks_delete_instance($instance);
        }
        return $course;
    }

    /**
     * Put a block on a course.
     *
     * @param \stdClass $course
     * @param string $name Block name.
     * @param string $region
     * @param int $weight
     * @param array $config Block configuration.
     * @return \stdClass The block instance.
     */
    private function add_block(\stdClass $course, string $name, string $region, int $weight, array $config = []): \stdClass {
        return $this->getDataGenerator()->create_block($name, [
            'parentcontextid' => \context_course::instance($course->id)->id,
            'pagetypepattern' => 'course-view-*',
            'showinsubcontexts' => 0,
            'defaultregion' => $region,
            'defaultweight' => $weight,
            'configdata' => $config ? base64_encode(serialize((object) $config)) : '',
        ]);
    }

    /**
     * Change columns of a block instance.
     *
     * @param \stdClass $block
     * @param array $columns
     */
    private function update_block(\stdClass $block, array $columns): void {
        global $DB;
        $DB->update_record('block_instances', (object) ($columns + ['id' => $block->id]));
    }

    /**
     * Register a template over a course.
     *
     * @param int $courseid
     * @return template
     */
    private function create_template(int $courseid): template {
        $template = new template(0, (object) ['name' => 'Blocks template', 'courseid' => $courseid]);
        $template->create();
        return $template;
    }

    /**
     * A session started from a template, or from none.
     *
     * @param int $templateid 0 for a free session.
     * @return course_session
     */
    private function create_session(int $templateid): course_session {
        global $USER;
        $session = new course_session(0, (object) [
            'userid' => $USER->id,
            'session_id' => 'sess-blocks-' . $templateid,
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode(['templateid' => $templateid]),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $session->create();
        return $session;
    }

    /**
     * Create a course from a template and return it.
     *
     * @param int $sourcecourseid The template's course.
     * @return \stdClass
     */
    private function create_from(int $sourcecourseid): \stdClass {
        $templateid = $this->create_template($sourcecourseid)->get('id');
        $payload = template_export_service::build_init_payload($templateid);
        $configuration = $payload['course_configuration'];
        $configuration['fullname'] = 'Made from a template';
        $configuration['shortname'] = 'made-' . $sourcecourseid;
        $result = create_course_service::create_course(
            $this->create_session($templateid),
            ['course_configuration' => $configuration, 'sections_info' => $payload['sections_info']]
        );
        $this->assertTrue($result['success'], $result['message']);
        return get_course($result['courseid']);
    }

    /**
     * The blocks directly in a course, in the order they are shown.
     *
     * @param \stdClass $course
     * @return \stdClass[] Block instances.
     */
    private function course_blocks(\stdClass $course): array {
        global $DB;
        return array_values($DB->get_records(
            'block_instances',
            ['parentcontextid' => \context_course::instance($course->id)->id],
            'defaultregion, defaultweight, id'
        ));
    }

    /**
     * The names of the blocks directly in a course, in the order they are shown.
     *
     * @param \stdClass $course
     * @return string[]
     */
    private function block_names(\stdClass $course): array {
        $blocks = $this->course_blocks($course);
        return array_column($blocks, 'blockname');
    }

    /**
     * The site's default blocks for a new course are replaced by the template's.
     */
    public function test_new_course_has_the_blocks_of_the_template_and_not_the_site_defaults(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->defaultblocks_override = ':online_users';
        $source = $this->create_course_without_blocks();
        $this->add_block($source, 'search_forums', 'side-pre', 0);
        $this->add_block($source, 'calendar_upcoming', 'side-pre', 1);
        $this->add_block($source, 'recent_activity', 'side-pre', 2);

        $course = $this->create_from($source->id);

        $names = $this->block_names($course);
        $this->assertSame(['search_forums', 'calendar_upcoming', 'recent_activity'], $names);
    }

    /**
     * Where each block is shown, and how, is the template's.
     */
    public function test_blocks_keep_their_region_weight_and_page_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->create_course_without_blocks();
        $this->add_block($source, 'calendar_upcoming', 'side-pre', 4);
        $other = $this->add_block($source, 'recent_activity', 'content', 7);
        $this->update_block($other, [
            'showinsubcontexts' => 1,
            'pagetypepattern' => 'course-view-topics',
            'subpagepattern' => 'sub',
        ]);

        $course = $this->create_from($source->id);

        [$first, $second] = $this->course_blocks($course);
        $this->assertSame('recent_activity', $first->blockname);
        $this->assertSame('content', $first->defaultregion);
        $this->assertEquals(7, $first->defaultweight);
        $this->assertEquals(1, $first->showinsubcontexts);
        $this->assertSame('course-view-topics', $first->pagetypepattern);
        $this->assertSame('sub', $first->subpagepattern);
        $this->assertSame('calendar_upcoming', $second->blockname);
        $this->assertSame('side-pre', $second->defaultregion);
        $this->assertEquals(4, $second->defaultweight);
        $this->assertEquals(0, $second->showinsubcontexts);
    }

    /**
     * The configuration of a block travels with it.
     */
    public function test_block_configuration_is_copied(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->create_course_without_blocks();
        $this->add_block($source, 'html', 'side-pre', 0, ['title' => 'Welcome', 'text' => 'Read this first']);

        $course = $this->create_from($source->id);

        [$block] = $this->course_blocks($course);
        $config = unserialize(base64_decode($block->configdata));
        $this->assertSame('Welcome', $config->title);
        $this->assertSame('Read this first', $config->text);
    }

    /**
     * The files a block shows are copied under the new block.
     */
    public function test_block_files_are_copied_to_the_new_block(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->create_course_without_blocks();
        $sourceblock = $this->add_block($source, 'html', 'side-pre', 0, ['text' => '<img src="@@PLUGINFILE@@/logo.png">']);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_block::instance($sourceblock->id)->id,
            'component' => 'block_html',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'logo.png',
        ], 'logo-bytes');

        $course = $this->create_from($source->id);

        [$block] = $this->course_blocks($course);
        $file = get_file_storage()->get_file(\context_block::instance($block->id)->id, 'block_html', 'content', 0, '/', 'logo.png');
        $this->assertNotFalse($file);
        $this->assertSame('logo-bytes', $file->get_content());
    }

    /**
     * A block position of the page is moved over to the new course.
     */
    public function test_block_positions_are_copied_to_the_new_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->create_course_without_blocks();
        $block = $this->add_block($source, 'calendar_upcoming', 'side-pre', 0);
        $DB->insert_record('block_positions', (object) [
            'blockinstanceid' => $block->id,
            'contextid' => \context_course::instance($source->id)->id,
            'pagetype' => 'course-view-topics',
            'subpage' => '',
            'visible' => 0,
            'region' => 'content',
            'weight' => 3,
        ]);

        $course = $this->create_from($source->id);

        [$new] = $this->course_blocks($course);
        $position = $DB->get_record('block_positions', ['blockinstanceid' => $new->id], '*', MUST_EXIST);
        $this->assertEquals(\context_course::instance($course->id)->id, $position->contextid);
        $this->assertSame('course-view-topics', $position->pagetype);
        $this->assertEquals(0, $position->visible);
        $this->assertSame('content', $position->region);
        $this->assertEquals(3, $position->weight);
    }

    /**
     * The template's own blocks stay where they are.
     */
    public function test_the_templates_blocks_are_left_in_place(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->create_course_without_blocks();
        $block = $this->add_block($source, 'calendar_upcoming', 'side-pre', 0);

        $this->create_from($source->id);

        $blocks = $this->course_blocks($source);
        $this->assertCount(1, $blocks);
        $this->assertEquals($block->id, $blocks[0]->id);
    }

    /**
     * A template with no blocks leaves its course without them.
     */
    public function test_a_template_without_blocks_gives_a_course_without_blocks(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->defaultblocks_override = ':online_users';
        $source = $this->create_course_without_blocks();

        $course = $this->create_from($source->id);

        $this->assertSame([], $this->course_blocks($course));
    }

    /**
     * A free generation owns its blocks: the site's defaults stay.
     */
    public function test_free_course_keeps_the_site_default_blocks(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->defaultblocks_override = ':online_users';
        $source = $this->create_course_without_blocks();
        $this->add_block($source, 'calendar_upcoming', 'side-pre', 0);
        $template = $this->create_template($source->id);

        $course = $this->create_from_free($template->get('id'));

        $names = $this->block_names($course);
        $this->assertSame(['online_users'], $names);
    }

    /**
     * Create a course by the free flow, with a template's configuration.
     *
     * @param int $templateid Template whose payload is used.
     * @return \stdClass
     */
    private function create_from_free(int $templateid): \stdClass {
        $payload = template_export_service::build_init_payload($templateid);
        $configuration = $payload['course_configuration'];
        $configuration['fullname'] = 'Free course';
        $configuration['shortname'] = 'free-course';
        $result = create_course_service::create_course(
            $this->create_session(0),
            ['course_configuration' => $configuration, 'sections_info' => $payload['sections_info']]
        );
        $this->assertTrue($result['success'], $result['message']);
        return get_course($result['courseid']);
    }

    /**
     * The values of the custom fields of the template's course carry over.
     */
    public function test_custom_field_values_are_copied(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_custom_field_category(['component' => 'core_course', 'area' => 'course']);
        $this->getDataGenerator()->create_custom_field(['categoryid' => $category->get('id'), 'shortname' => 'area', 'type' => 'text']);
        $this->getDataGenerator()->create_custom_field(['categoryid' => $category->get('id'), 'shortname' => 'needed', 'type' => 'checkbox']);
        $source = $this->create_course_without_blocks(['customfield_area' => 'Operations', 'customfield_needed' => 1]);

        $course = $this->create_from($source->id);

        $values = [];
        foreach (\core_course\customfield\course_handler::create()->get_instance_data($course->id) as $data) {
            $values[$data->get_field()->get('shortname')] = $data->get_value();
        }
        $this->assertSame('Operations', $values['area']);
        $this->assertEquals(1, $values['needed']);
    }
}
