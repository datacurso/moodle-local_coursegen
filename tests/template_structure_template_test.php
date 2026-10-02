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

/**
 * The structure view of the template screen: its space cards and the controls it no longer has.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class template_structure_template_test extends \advanced_testcase {
    /**
     * The structure view has no control to add an activity or a section, nor to remove an activity.
     */
    public function test_structure_view_offers_nothing_to_add(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('local_coursegen/template_structure', $this->structure_context());

        $this->assertStringNotContainsString('open-chooser', $html);
        $this->assertStringNotContainsString('add-section', $html);
        $this->assertStringNotContainsString('remove-activity', $html);
        $this->assertStringNotContainsString('cg-insert-zone', $html);
    }

    /**
     * A space without its file offers the Upload button and says the file is required.
     */
    public function test_an_empty_required_space_offers_upload_and_says_it_is_required(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('local_coursegen/template_structure', $this->structure_context());

        $this->assertStringContainsString('data-action="local_coursegen/template/pick-space-file"', $html);
        $this->assertStringContainsString('data-section-index="0" data-activity-index="1"', $html);
        $this->assertStringContainsString('Course guide in PDF', $html);
        $this->assertStringContainsString(get_string('courseai_template_space_upload', 'local_coursegen'), $html);
        $this->assertStringContainsString(get_string('courseai_template_space_missing', 'local_coursegen'), $html);
        $this->assertStringNotContainsString('remove-space-file', $html);
    }

    /**
     * The space is a card of its own: label, badge, the admin's instruction as the main line and the hint.
     */
    public function test_a_space_is_drawn_as_a_call_to_action_card(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('local_coursegen/template_structure', $this->structure_context());

        $this->assertSame(1, substr_count($html, 'class="cg-space-card '));
        $this->assertStringContainsString('cg-space-card--required', $html);
        $this->assertStringContainsString('cg-space-card--empty', $html);
        $this->assertStringContainsString(get_string('courseai_template_space_eyebrow', 'local_coursegen'), $html);
        $this->assertStringContainsString(get_string('courseai_template_space_required', 'local_coursegen'), $html);
        $this->assertStringContainsString(get_string('courseai_template_space_hint', 'local_coursegen'), $html);
        $this->assertStringContainsString(get_string('courseai_template_space_uploading', 'local_coursegen'), $html);
        $this->assertStringContainsString('<p class="cg-space-card__instruction">Course guide in PDF</p>', $html);
        $this->assertStringContainsString('<p class="cg-space-card__target">Weekly guide</p>', $html);
        $this->assertStringContainsString('role="status" aria-live="polite"', $html);
        $this->assertStringNotContainsString('cg-space-card--done', $html);
        $this->assertStringNotContainsString('activity-item', substr($html, strpos($html, 'cg-space-item')));
    }

    /**
     * A space without an instruction falls back to the activity name as its main line.
     */
    public function test_a_space_without_instruction_uses_the_activity_name(): void {
        global $OUTPUT;
        $this->resetAfterTest();
        $context = $this->structure_context();
        $space = &$context['sections'][0]['activities'][1];
        $space['spaceinstruction'] = '';
        $space['hasspaceinstruction'] = false;

        $html = $OUTPUT->render_from_template('local_coursegen/template_structure', $context);

        $this->assertStringContainsString('<p class="cg-space-card__instruction">Weekly guide</p>', $html);
        $this->assertStringNotContainsString('cg-space-card__target', $html);
    }

    /**
     * A space with its file shows the file name, a Change button and a control to remove it.
     */
    public function test_a_filled_space_shows_the_file_and_can_be_emptied(): void {
        global $OUTPUT;
        $this->resetAfterTest();
        $context = $this->structure_context();
        $space = &$context['sections'][0]['activities'][1];
        $space['hasspacefile'] = true;
        $space['spacefilename'] = 'guide.pdf';
        $space['spacemissing'] = false;

        $html = $OUTPUT->render_from_template('local_coursegen/template_structure', $context);

        $this->assertStringContainsString('guide.pdf', $html);
        $this->assertStringContainsString(get_string('courseai_template_space_change', 'local_coursegen'), $html);
        $this->assertStringContainsString('remove-space-file', $html);
        $this->assertStringContainsString('cg-space-card--done', $html);
        $this->assertStringNotContainsString('cg-space-card--empty', $html);
        $this->assertStringNotContainsString(get_string('courseai_template_space_hint', 'local_coursegen'), $html);
        $this->assertStringNotContainsString(get_string('courseai_template_space_missing', 'local_coursegen'), $html);
    }

    /**
     * An optional space says it is optional and never asks for the file.
     */
    public function test_an_optional_space_is_not_asked_for(): void {
        global $OUTPUT;
        $this->resetAfterTest();
        $context = $this->structure_context();
        $space = &$context['sections'][0]['activities'][1];
        $space['spacerequired'] = false;
        $space['spacemissing'] = false;

        $html = $OUTPUT->render_from_template('local_coursegen/template_structure', $context);

        $this->assertStringContainsString(get_string('courseai_template_space_optional', 'local_coursegen'), $html);
        $this->assertStringNotContainsString('cg-space-card--required', $html);
        $this->assertStringNotContainsString('cg-space-card__badge--required', $html);
        $this->assertStringContainsString(get_string('courseai_template_space_hint', 'local_coursegen'), $html);
        $this->assertStringNotContainsString(get_string('courseai_template_space_missing', 'local_coursegen'), $html);
    }

    /**
     * The space is not dimmed like the locked rows: its button has to be clickable.
     */
    public function test_a_space_is_not_rendered_as_a_locked_row(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('local_coursegen/template_structure', $this->structure_context());

        $this->assertSame(1, substr_count($html, 'is-locked'));
    }

    /**
     * A structure of one section: a locked page and a required space after it.
     *
     * @return array
     */
    private function structure_context(): array {
        $page = [
            'name' => 'Welcome', 'modname' => 'page', 'purpose' => 'content', 'iconhtml' => '', 'locked' => true,
            'isinstance' => false, 'aigenerated' => false, 'generationuid' => '', 'sectionindex' => 0, 'index' => 0,
            'typelabel' => 'Page', 'isspace' => false,
        ];
        $space = [
            'name' => 'Weekly guide', 'modname' => 'resource', 'purpose' => 'content', 'iconhtml' => '', 'locked' => true,
            'isinstance' => false, 'aigenerated' => false, 'generationuid' => '', 'sectionindex' => 0, 'index' => 1,
            'typelabel' => 'File', 'isspace' => true, 'spaceinstruction' => 'Course guide in PDF',
            'hasspaceinstruction' => true, 'spacerequired' => true, 'spacemissing' => true, 'hasspacefile' => false,
            'spacefilename' => '',
        ];
        return ['sections' => [[
            'index' => 0, 'name' => 'Welcome', 'locked' => false, 'collapsed' => false, 'activitiescount' => 2,
            'activities' => [$page, $space],
        ]]];
    }
}
