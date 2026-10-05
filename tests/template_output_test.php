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

use local_coursegen\local\template\template_service;
use local_coursegen\output\edit_template_page;
use local_coursegen\output\manage_templates_page;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/template_test_helper.php');

/**
 * Tests for the data the template pages draw.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\output\manage_templates_page
 * @covers     \local_coursegen\output\edit_template_page
 */
final class template_output_test extends \advanced_testcase {
    use template_test_helper;

    /**
     * Start with a clean site and an admin.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Without templates the list is empty and offers to create one.
     */
    public function test_empty_list(): void {
        global $OUTPUT;
        $page = new manage_templates_page();

        $data = $page->export_for_template($OUTPUT);

        $this->assertFalse($data['hastemplates']);
        $this->assertSame([], $data['templates']);
        $this->assertStringContainsString('edit_template.php', $data['createurl']);
    }

    /**
     * The list shows each template with its counts, and marks a template whose course was deleted.
     */
    public function test_list_shows_counts_and_a_missing_course(): void {
        global $OUTPUT;
        [$course, $page, $quiz] = $this->make_course();
        [$gone, $gonepage] = $this->make_course();
        $service = new template_service();
        $items = [['cmid' => $page, 'action' => 'ai'], ['cmid' => $quiz, 'action' => 'keep']];
        $service->save(0, (int) $course->id, 'A template', null, $items, 2);
        $service->save(0, (int) $gone->id, 'B template', null, [['cmid' => $gonepage, 'action' => 'ai']], 2);
        delete_course($gone, false);
        $listpage = new manage_templates_page();

        $data = $listpage->export_for_template($OUTPUT);

        $this->assertTrue($data['hastemplates']);
        $this->assertSame('A template', $data['templates'][0]['name']);
        $this->assertSame(2, $data['templates'][0]['activitycount']);
        $this->assertSame(1, $data['templates'][0]['aicount']);
        $this->assertFalse($data['templates'][0]['coursemissing']);
        $this->assertTrue($data['templates'][1]['coursemissing']);
    }

    /**
     * A new template without a course shows the filter and no structure.
     */
    public function test_new_template_without_a_course(): void {
        global $OUTPUT;
        $page = new edit_template_page(0, 0);

        $data = $page->export_for_template($OUTPUT);

        $this->assertFalse($data['courseusable']);
        $this->assertFalse($data['hascourse']);
        $this->assertSame([], $data['sections']);
        $this->assertSame(0, $data['categories'][0]['id']);
        $this->assertTrue($data['categories'][0]['selected']);
    }

    /**
     * A saved template draws each activity with its choice, and the chosen course selects its category.
     */
    public function test_saved_template_draws_each_choice(): void {
        global $OUTPUT;
        [$course, $page, $quiz] = $this->make_course();
        $service = new template_service();
        $items = [
            ['cmid' => $page, 'action' => 'ai', 'instruction' => 'Shorter'],
            ['cmid' => $quiz, 'action' => 'keep'],
        ];
        $templateid = $service->save(0, (int) $course->id, 'Saved', 'About it', $items, 2);
        $editor = new edit_template_page($templateid, 0);

        $data = $editor->export_for_template($OUTPUT);

        $this->assertSame('Saved', $data['name']);
        $this->assertSame('About it', $data['description']);
        $this->assertTrue($data['courseusable']);
        $this->assertTrue($data['hascourse']);
        $activities = $data['sections'][1]['activities'];
        $this->assertTrue($activities[0]['isai']);
        $this->assertFalse($activities[0]['iskeep']);
        $this->assertSame('Shorter', $activities[0]['instruction']);
        $this->assertTrue($activities[1]['iskeep']);
        $this->assertFalse($data['hasmissing']);
        $selected = array_column($data['categories'], 'selected', 'id');
        $this->assertTrue($selected[(int) $course->category]);
    }

    /**
     * An activity deleted after the save is listed so the admin knows it will be dropped.
     */
    public function test_deleted_activity_is_listed(): void {
        global $OUTPUT;
        [$course, $page] = $this->make_course();
        $service = new template_service();
        $templateid = $service->save(0, (int) $course->id, 'Saved', null, [['cmid' => $page, 'action' => 'ai']], 2);
        course_delete_module($page);
        $editor = new edit_template_page($templateid, 0);

        $data = $editor->export_for_template($OUTPUT);

        $this->assertTrue($data['hasmissing']);
        $this->assertSame($page, $data['missing'][0]['cmid']);
    }

    /**
     * The names of activities are escaped for drawing, so markup in a name is not run.
     */
    public function test_activity_names_are_formatted(): void {
        global $OUTPUT;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $generator->create_module('page', ['course' => $course->id, 'name' => 'Fish & <b>chips</b>']);
        $editor = new edit_template_page(0, (int) $course->id);

        $data = $editor->export_for_template($OUTPUT);

        $name = $data['sections'][1]['activities'][0]['name'];
        $this->assertStringNotContainsString('<b>', $name);
    }
}
