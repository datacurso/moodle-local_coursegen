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

defined('MOODLE_INTERNAL') || die();

// The shared fixture trait sits in tests/ root, outside the tests/classes
// autoload scope, so it must be required explicitly.
require_once(__DIR__ . '/sections_config_fixture_trait.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_coursegen\external\save_template;
use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;

/**
 * save_template only lets an activity be marked as a template when the activity has a placeholder.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\save_template
 * @covers     \local_coursegen\local\service\template_placeholder_guard
 *
 * @runTestsInSeparateProcesses
 */
final class save_template_placeholder_test extends \advanced_testcase {
    use sections_config_fixture_trait;

    /**
     * Save a template with one section that holds the given activity rows.
     *
     * @param \stdClass $course The base course.
     * @param array $activities The activity rows of the section.
     * @param int $templateid The template to replace, 0 for a new one.
     * @param string $name The template name.
     * @return array The result of save_template::execute().
     */
    private function save_rows(\stdClass $course, array $activities, int $templateid = 0, string $name = 'Mold'): array {
        $section = get_fast_modinfo($course)->get_section_info(1);
        $sections = [
            [
                'sectionid' => (int) $section->id,
                'sectionnum' => 1,
                'behavior' => 'aimodify',
                'activities' => $activities,
            ],
        ];
        return save_template::execute($templateid, $name, '', (int) $course->id, 0, false, '', 1, $sections);
    }

    /**
     * One activity row.
     *
     * @param \stdClass $page The activity.
     * @param string $action The action of the row.
     * @return array
     */
    private function row(\stdClass $page, string $action): array {
        return ['cmid' => (int) $page->cmid, 'action' => $action, 'useasreference' => true, 'prompt' => ''];
    }

    /**
     * An activity with a placeholder can be saved as a template.
     */
    public function test_activity_with_a_placeholder_can_be_saved_as_a_template(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $page] = $this->create_course_fixture();

        $saved = $this->save_rows($course, [$this->row($page, 'template')]);

        $record = template_activity::get_record(['templateid' => (int) $saved['id'], 'cmid' => (int) $page->cmid]);
        $this->assertNotFalse($record);
        $action = $record->get('action');
        $this->assertSame('template', $action);
    }

    /**
     * An activity with a valid marker in the angle bracket dialect can be saved as a template too.
     */
    public function test_activity_with_an_angle_marker_can_be_saved_as_a_template(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_fixture();
        $generator = $this->getDataGenerator();
        $page = $generator->create_module('page', [
            'course' => $course->id,
            'section' => 1,
            'content' => '<p>⟦coursegen:aiprompt: write the lesson⟧</p>',
        ]);

        $saved = $this->save_rows($course, [$this->row($page, 'template')]);

        $count = template_activity::count_records(['templateid' => (int) $saved['id']]);
        $this->assertSame(1, $count);
    }

    /**
     * An activity with no placeholder is refused, and the message names it and says what to add.
     */
    public function test_activity_without_a_placeholder_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_fixture();
        $plain = $this->create_page_without_placeholder($course);

        try {
            $this->save_rows($course, [$this->row($plain, 'template')]);
            $this->fail('An activity without a placeholder was saved as a template.');
        } catch (\moodle_exception $exception) {
            $message = $exception->getMessage();
            $this->assertSame('template_activity_placeholder_required', $exception->errorcode);
            $this->assertStringContainsString($plain->name, $message);
            $this->assertStringContainsString('coursegen:aiprompt', $message);
        }
    }

    /**
     * A refused save writes nothing: no template row and no activity row.
     */
    public function test_refused_new_template_leaves_nothing_saved(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_fixture();
        $plain = $this->create_page_without_placeholder($course);

        try {
            $this->save_rows($course, [$this->row($plain, 'template')]);
            $this->fail('An activity without a placeholder was saved as a template.');
        } catch (\moodle_exception $exception) {
            $templates = template::count_records();
            $activities = template_activity::count_records();
            $this->assertSame(0, $templates);
            $this->assertSame(0, $activities);
        }
    }

    /**
     * Changing a saved row to a template is refused when the activity has no placeholder, and the
     * template stays as it was saved.
     */
    public function test_changing_a_saved_row_to_a_template_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_fixture();
        $plain = $this->create_page_without_placeholder($course);
        $saved = $this->save_rows($course, [$this->row($plain, 'keep')], 0, 'Original name');
        $templateid = (int) $saved['id'];

        try {
            $this->save_rows($course, [$this->row($plain, 'template')], $templateid, 'Changed name');
            $this->fail('A saved row was changed to a template without a placeholder.');
        } catch (\moodle_exception $exception) {
            $record = template_activity::get_record(['templateid' => $templateid, 'cmid' => (int) $plain->cmid]);
            $this->assertNotFalse($record);
            $action = $record->get('action');
            $reloaded = new template($templateid);
            $name = $reloaded->get('name');
            $this->assertSame('keep', $action);
            $this->assertSame('Original name', $name);
        }
    }

    /**
     * Changing a saved row to a template is allowed once the activity has a placeholder.
     */
    public function test_changing_a_saved_row_to_a_template_is_allowed_with_a_placeholder(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $page] = $this->create_course_fixture();
        $saved = $this->save_rows($course, [$this->row($page, 'keep')]);
        $templateid = (int) $saved['id'];

        $this->save_rows($course, [$this->row($page, 'template')], $templateid);

        $record = template_activity::get_record(['templateid' => $templateid, 'cmid' => (int) $page->cmid]);
        $action = $record->get('action');
        $this->assertSame('template', $action);
    }

    /**
     * One activity without a placeholder refuses the whole save, even next to a valid one.
     */
    public function test_one_invalid_row_refuses_the_whole_save(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $page] = $this->create_course_fixture();
        $plain = $this->create_page_without_placeholder($course);

        $this->expectException(\moodle_exception::class);
        $this->save_rows($course, [$this->row($page, 'template'), $this->row($plain, 'template')]);
    }

    /**
     * Only the template action asks for a placeholder: every other action saves without one.
     */
    public function test_other_actions_do_not_need_a_placeholder(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_fixture();
        $actions = ['keep', 'modify', 'reference', 'exclude', 'space'];
        $rows = [];
        foreach ($actions as $action) {
            $plain = $this->create_page_without_placeholder($course);
            $rows[] = $this->row($plain, $action);
        }

        $saved = $this->save_rows($course, $rows);

        $count = template_activity::count_records(['templateid' => (int) $saved['id']]);
        $this->assertSame(count($actions), $count);
    }

    /**
     * A row whose activity no longer exists is not an activity of the course any more, so it is
     * not refused: the export leaves it out.
     */
    public function test_row_of_a_deleted_activity_is_not_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_fixture();
        $plain = $this->create_page_without_placeholder($course);
        $row = $this->row($plain, 'template');
        course_delete_module((int) $plain->cmid, false);

        $saved = $this->save_rows($course, [$row]);

        $count = template_activity::count_records(['templateid' => (int) $saved['id']]);
        $this->assertSame(1, $count);
    }
}
