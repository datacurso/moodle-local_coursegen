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

use core_reportbuilder\exception\report_access_exception;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\user_filter_manager;
use core_reportbuilder\system_report_factory;
use local_coursegen\local\template\template_service;
use local_coursegen\reportbuilder\local\entities\template as template_entity;
use local_coursegen\reportbuilder\local\systemreports\templates;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/template_test_helper.php');
require_once(__DIR__ . '/fixtures/templates_report_table.php');

/**
 * Tests for the report that lists the templates.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\reportbuilder\local\systemreports\templates
 * @covers     \local_coursegen\reportbuilder\local\entities\template
 */
final class template_report_test extends \advanced_testcase {
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
     * Open the report as the current user.
     *
     * @return \core_reportbuilder\system_report The report.
     */
    private function open_report(): \core_reportbuilder\system_report {
        $context = \context_system::instance();

        return system_report_factory::create(templates::class, $context);
    }

    /**
     * The table of the report, ready to hand its rows.
     *
     * @return templates_report_table
     */
    private function open_table(): templates_report_table {
        $reportid = $this->report_id();

        return templates_report_table::create($reportid, []);
    }

    /**
     * Id of the stored report that holds the list of templates.
     *
     * @return int
     */
    private function report_id(): int {
        $report = $this->open_report();
        $persistent = $report->get_report_persistent();

        return (int) $persistent->get('id');
    }

    /**
     * Every row the report shows.
     *
     * @return array Rows, each keyed by column name.
     */
    private function report_rows(): array {
        $table = $this->open_table();

        return $table->get_table_rows();
    }

    /**
     * Save a template of a course with the given choices.
     *
     * @param \stdClass $course Course of the template.
     * @param string $name Name of the template.
     * @param array $items Entries with cmid and action.
     * @return int Template id.
     */
    private function save_named(\stdClass $course, string $name, array $items): int {
        $service = new template_service();

        return $service->save(0, (int) $course->id, $name, null, $items, 2);
    }

    /**
     * Without templates the report has no rows.
     */
    public function test_report_without_templates_has_no_rows(): void {
        $rows = $this->report_rows();

        $this->assertSame([], $rows);
    }

    /**
     * A row shows the name, the course, how many activities there are and how many the AI modifies.
     */
    public function test_row_shows_name_course_and_counts(): void {
        [$course, $page, $quiz] = $this->make_course();
        $items = [['cmid' => $page, 'action' => 'ai'], ['cmid' => $quiz, 'action' => 'keep']];
        $this->save_named($course, 'Marketing base', $items);

        $rows = $this->report_rows();

        $this->assertCount(1, $rows);
        $this->assertSame('Marketing base', $rows[0]['name']);
        $coursename = format_string($course->fullname);
        $this->assertSame($coursename, $rows[0]['coursefullname']);
        $this->assertEquals(2, $rows[0]['activitycount']);
        $this->assertEquals(1, $rows[0]['aicount']);
        $this->assertNotSame('', $rows[0]['timemodified']);
    }

    /**
     * A template with no activity counts zero, not nothing.
     */
    public function test_template_without_activities_counts_zero(): void {
        [$course] = $this->make_course();
        $this->save_named($course, 'Empty template', []);

        $rows = $this->report_rows();

        $this->assertEquals(0, $rows[0]['activitycount']);
        $this->assertEquals(0, $rows[0]['aicount']);
    }

    /**
     * A template whose course was deleted says so instead of showing an empty cell.
     */
    public function test_row_of_a_deleted_course_says_the_course_is_gone(): void {
        [$course, $page] = $this->make_course();
        $this->save_named($course, 'Orphan template', [['cmid' => $page, 'action' => 'ai']]);
        delete_course($course, false);

        $rows = $this->report_rows();

        $missing = get_string('template_course_missing', 'local_coursegen');
        $this->assertSame($missing, $rows[0]['coursefullname']);
        $this->assertEquals(1, $rows[0]['activitycount']);
    }

    /**
     * The last saved template comes first.
     */
    public function test_last_modified_comes_first(): void {
        global $DB;
        [$course] = $this->make_course();
        $olderid = $this->save_named($course, 'Older template', []);
        $newerid = $this->save_named($course, 'Newer template', []);
        $DB->set_field('local_coursegen_template', 'timemodified', 1000, ['id' => $olderid]);
        $DB->set_field('local_coursegen_template', 'timemodified', 2000, ['id' => $newerid]);

        $rows = $this->report_rows();

        $this->assertSame('Newer template', $rows[0]['name']);
        $this->assertSame('Older template', $rows[1]['name']);
    }

    /**
     * Names with accents, other scripts and emoji come out as they were saved.
     */
    public function test_unicode_names_are_kept(): void {
        [$course] = $this->make_course();
        $name = 'Plantilla ñandú 日本語 😀';
        $this->save_named($course, $name, []);

        $rows = $this->report_rows();

        $this->assertSame($name, $rows[0]['name']);
    }

    /**
     * Markup in a name is not drawn as markup.
     */
    public function test_markup_in_a_name_is_not_drawn(): void {
        global $DB;
        [$course] = $this->make_course();
        $templateid = $this->save_named($course, 'Plain', []);
        $DB->set_field('local_coursegen_template', 'name', '<script>alert(1)</script>Marketing', ['id' => $templateid]);

        $rows = $this->report_rows();

        $this->assertStringNotContainsString('<script', $rows[0]['name']);
        $this->assertStringContainsString('Marketing', $rows[0]['name']);
    }

    /**
     * The name filter keeps the templates whose name contains the text.
     */
    public function test_name_filter_keeps_the_matching_templates(): void {
        [$course] = $this->make_course();
        $this->save_named($course, 'Marketing base', []);
        $this->save_named($course, 'Finance base', []);
        $reportid = $this->report_id();
        $values = ['template:name_operator' => text::CONTAINS, 'template:name_value' => 'Market'];
        user_filter_manager::set($reportid, $values);

        $rows = $this->report_rows();

        $this->assertCount(1, $rows);
        $this->assertSame('Marketing base', $rows[0]['name']);
    }

    /**
     * Text meant as SQL is only a text to look for.
     */
    public function test_filter_text_is_not_run_as_sql(): void {
        global $DB;
        [$course] = $this->make_course();
        $this->save_named($course, 'Marketing base', []);
        $reportid = $this->report_id();
        $attack = "x'; DROP TABLE {local_coursegen_template}; --";
        $values = ['template:name_operator' => text::CONTAINS, 'template:name_value' => $attack];
        user_filter_manager::set($reportid, $values);

        $rows = $this->report_rows();

        $this->assertSame([], $rows);
        $this->assertSame(1, $DB->count_records('local_coursegen_template'));
    }

    /**
     * Five hundred templates are paged: the first page is full and the total is known.
     */
    public function test_five_hundred_templates_are_paged(): void {
        global $DB;
        [$course] = $this->make_course();
        $records = [];
        for ($number = 1; $number <= 500; $number++) {
            $records[] = (object) [
                'courseid' => (int) $course->id,
                'name' => 'Template ' . $number,
                'description' => '',
                'timecreated' => 1000,
                'timemodified' => 1000 + $number,
                'usermodified' => 2,
            ];
        }
        $DB->insert_records('local_coursegen_template', $records);

        $pagetable = $this->open_table();
        $firstpage = $pagetable->get_table_rows(30);
        $totaltable = $this->open_table();
        $total = $totaltable->count_all_rows();

        $this->assertCount(30, $firstpage);
        $this->assertSame(500, $total);
        $this->assertSame('Template 500', $firstpage[0]['name']);
    }

    /**
     * Someone who cannot manage templates cannot open the report.
     */
    public function test_user_without_the_capability_cannot_open_the_report(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(report_access_exception::class);

        $this->open_report();
    }

    /**
     * Each row can be edited and deleted, and the delete action carries the template for the dialogue.
     */
    public function test_each_row_has_edit_and_delete_actions(): void {
        $report = $this->open_report();
        $actions = $report->get_actions();
        $row = (object) ['id' => 7, 'name' => 'Marketing base'];

        $this->assertCount(2, $actions);
        $edit = $actions[0]->get_action_link($row);
        $delete = $actions[1]->get_action_link($row);
        $editaddress = $edit->url->out(false);

        $this->assertStringContainsString('edit_template.php', $editaddress);
        $this->assertStringContainsString('id=7', $editaddress);
        $this->assertSame('local_coursegen/template/delete', $delete->attributes['data-action']);
        $this->assertSame('7', (string) $delete->attributes['data-templateid']);
        $this->assertSame('Marketing base', $delete->attributes['data-name']);
    }

    /**
     * A name with quotes reaches the delete dialogue as it is.
     */
    public function test_delete_action_keeps_quotes_of_the_name(): void {
        $report = $this->open_report();
        $actions = $report->get_actions();
        $row = (object) ['id' => 8, 'name' => 'Intro "advanced" & <more>'];

        $delete = $actions[1]->get_action_link($row);

        $this->assertSame('Intro "advanced" & <more>', $delete->attributes['data-name']);
    }

    /**
     * The entity offers the columns the report uses, and the name filter.
     */
    public function test_entity_defines_its_columns_and_filter(): void {
        $entity = new template_entity();
        $entity->initialise();
        $columnmap = $entity->get_columns();
        $filtermap = $entity->get_filters();

        $columns = array_keys($columnmap);
        $filters = array_keys($filtermap);

        $this->assertEqualsCanonicalizing(['name', 'coursefullname', 'activitycount', 'aicount', 'timemodified'], $columns);
        $this->assertSame(['name'], $filters);
    }
}
