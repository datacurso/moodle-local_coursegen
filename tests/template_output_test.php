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
use local_coursegen\output\sections_config;
use local_coursegen\output\template_row_options;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/template_test_helper.php');

/**
 * Tests for the "Course sections" review the template editor draws.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\output\sections_config
 * @covers     \local_coursegen\output\template_row_options
 */
final class template_output_test extends \advanced_testcase {
    use template_test_helper;

    /** @var template_service Service that saves and loads the templates. */
    private template_service $service;

    /**
     * Start with a clean site and an admin.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->service = new template_service();
    }

    /**
     * The sections of a course as the editor loads them for a saved template.
     *
     * @param \stdClass $course Course of the template.
     * @param array $items Entries with cmid, action and instruction.
     * @return array Sections with the saved action and instruction of each activity.
     */
    private function saved_sections(\stdClass $course, array $items): array {
        $templateid = $this->save_items($course, $items);
        $loaded = $this->service->load_for_edit($templateid, 0);

        return $loaded['sections'];
    }

    /**
     * An activity offers exactly two choices and keeps intact by default.
     */
    public function test_an_activity_offers_two_choices_and_keeps_by_default(): void {
        $options = template_row_options::activity_actions(7);

        $this->assertSame(['keep', 'ai'], array_column($options, 'value'));
        $this->assertSame(['keep'], [template_row_options::active_action($options)]);
        $this->assertSame([true, false], array_column($options, 'active'));
        $this->assertSame([7, 7], array_column($options, 'cmid'));
    }

    /**
     * Every choice has a label and a tip.
     */
    public function test_every_choice_has_a_label_and_a_tip(): void {
        $options = template_row_options::activity_actions(7);

        foreach ($options as $option) {
            $this->assertNotSame('', $option['label']);
            $this->assertNotSame('', $option['tip']);
        }
    }

    /**
     * The saved action preselects its choice.
     */
    public function test_the_saved_action_is_preselected(): void {
        $options = template_row_options::activity_actions(7, 'ai');

        $this->assertSame('ai', template_row_options::active_action($options));
        $this->assertSame([false, true], array_column($options, 'active'));
    }

    /**
     * Anything that is not one of the two actions falls back to keep.
     *
     * @dataProvider unknown_actions
     * @param string|null $saved What was saved.
     */
    public function test_unknown_saved_actions_fall_back_to_keep(?string $saved): void {
        $options = template_row_options::activity_actions(7, $saved);

        $this->assertSame('keep', template_row_options::active_action($options));
    }

    /**
     * Values that are not an action.
     *
     * @return array
     */
    public static function unknown_actions(): array {
        return [
            'nothing saved' => [null],
            'empty' => [''],
            'an old action' => ['template'],
            'another old action' => ['space'],
            'wrong case' => ['AI'],
        ];
    }

    /**
     * With no active option the resolved action is keep.
     */
    public function test_no_active_option_resolves_to_keep(): void {
        $this->assertSame('keep', template_row_options::active_action([]));
        $this->assertSame('keep', template_row_options::active_action([['value' => 'ai', 'active' => false]]));
    }

    /**
     * A saved template draws each activity with its action and instruction.
     */
    public function test_a_saved_template_draws_each_choice(): void {
        [$course, $page, $quiz] = $this->make_course();
        $items = [
            ['cmid' => $page, 'action' => 'ai', 'instruction' => 'Shorter'],
            ['cmid' => $quiz, 'action' => 'keep'],
        ];
        $sections = $this->saved_sections($course, $items);

        $context = sections_config::export_for_template($sections, (int) $course->id);

        $this->assertTrue($context['hassections']);
        $this->assertSame((int) $course->id, $context['courseid']);
        $rows = $context['sections'][1]['rows'];
        $this->assertSame($page, $rows[0]['cmid']);
        $this->assertTrue($rows[0]['isai']);
        $this->assertSame('Shorter', $rows[0]['instruction']);
        $this->assertSame('page', $rows[0]['modname']);
        $this->assertNotEmpty($rows[0]['viewurl']);
        $this->assertFalse($rows[1]['isai']);
        $this->assertSame('', $rows[1]['instruction']);
    }

    /**
     * A section counts its activities and tells whether it has any.
     */
    public function test_a_section_counts_its_activities(): void {
        [$course] = $this->make_course();
        $sections = $this->saved_sections($course, []);

        $context = sections_config::export_for_template($sections, (int) $course->id);

        $this->assertSame(2, $context['sections'][1]['activitycount']);
        $this->assertTrue($context['sections'][1]['hasactivities']);
        $this->assertSame(1, $context['sections'][2]['activitycount']);
        $this->assertFalse($context['sections'][0]['hasactivities']);
    }

    /**
     * The names of activities are formatted, so markup in a name is not run.
     */
    public function test_activity_names_are_formatted(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $generator->create_module('page', ['course' => $course->id, 'name' => 'Fish & <b>chips</b>', 'section' => 1]);
        $sections = $this->service->load_for_edit(0, (int) $course->id)['sections'];

        $context = sections_config::export_for_template($sections, (int) $course->id);

        $name = $context['sections'][1]['rows'][0]['name'];
        $this->assertStringNotContainsString('<b>', $name);
    }

    /**
     * An activity deleted from the course is not drawn, even when the template still has a row for it.
     */
    public function test_a_deleted_activity_is_not_drawn(): void {
        [$course, $page, $quiz] = $this->make_course();
        $templateid = $this->save_items($course, [['cmid' => $page, 'action' => 'ai', 'instruction' => 'Gone']]);
        course_delete_module($page);
        $sections = $this->service->load_for_edit($templateid, 0)['sections'];

        $context = sections_config::export_for_template($sections, (int) $course->id);

        $cmids = array_column($context['sections'][1]['rows'], 'cmid');
        $this->assertNotContains($page, $cmids);
        $this->assertContains($quiz, $cmids);
    }

    /**
     * The rendered review keeps what the scripts read: one row, one select and one instruction box per activity.
     */
    public function test_the_render_holds_the_hooks_of_the_scripts(): void {
        [$course, $page, $quiz] = $this->make_course();
        $items = [['cmid' => $page, 'action' => 'ai', 'instruction' => 'Shorter']];
        $sections = $this->saved_sections($course, $items);

        $html = sections_config::render($sections, (int) $course->id);

        $this->assertStringContainsString('data-region="course-sections"', $html);
        $this->assertStringContainsString('data-for="cmitem" data-id="' . $page . '"', $html);
        $this->assertStringContainsString('data-region="activity-action" data-id="' . $quiz . '"', $html);
        $this->assertStringContainsString('data-region="activity-instruction" data-id="' . $page . '"', $html);
        $this->assertStringContainsString('data-region="bulk-action"', $html);
        $this->assertSame(1, preg_match_all('/<option value="ai"[^>]* selected/', $html));
    }

    /**
     * The instruction box is shown only for an activity the AI modifies.
     */
    public function test_the_instruction_box_is_hidden_for_a_kept_activity(): void {
        [$course, $page, $quiz] = $this->make_course();
        $items = [['cmid' => $page, 'action' => 'ai', 'instruction' => 'Shorter']];
        $sections = $this->saved_sections($course, $items);

        $html = sections_config::render($sections, (int) $course->id);

        $shown = '/<tr class="tpl-instruction-row" data-for="instruction" data-id="' . $page . '">/';
        $hidden = '/<tr class="tpl-instruction-row d-none" data-for="instruction" data-id="' . $quiz . '">/';
        $this->assertSame(1, preg_match($shown, $html));
        $this->assertSame(1, preg_match($hidden, $html));
    }

    /**
     * An instruction with markup is escaped, so it cannot close the box and run.
     */
    public function test_a_hostile_instruction_is_escaped(): void {
        [$course, $page] = $this->make_course();
        $hostile = '</textarea><script>alert(1)</script>';
        $sections = $this->saved_sections($course, [['cmid' => $page, 'action' => 'ai', 'instruction' => $hostile]]);

        $html = sections_config::render($sections, (int) $course->id);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;/textarea&gt;', $html);
    }

    /**
     * A course without sections draws no bulk bar and no cards.
     */
    public function test_a_course_without_sections_draws_nothing(): void {
        $context = sections_config::export_for_template([], 42);

        $this->assertFalse($context['hassections']);
        $this->assertSame([], $context['sections']);
    }
}
