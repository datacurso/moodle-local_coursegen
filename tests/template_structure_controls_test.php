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
 * Controls contract of the template-mode structure view.
 *
 * The professor-facing structure (local_coursegen/template_structure) exposes
 * mutation controls only where they can act: the add-section button is omitted
 * entirely when the template allows no more sections, and the edit pencil
 * appears only on professor-added (unlocked) activity rows — locked reference
 * rows expose neither edit nor delete.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen
 */
final class template_structure_controls_test extends \advanced_testcase {
    /**
     * Render the template_structure template.
     *
     * @param array $overrides Context overrides.
     * @return string Rendered HTML.
     */
    private function render_structure(array $overrides = []): string {
        global $OUTPUT;

        $context = array_merge([
            'sections' => [
                [
                    'id' => 5,
                    'name' => 'Introduction',
                    'locked' => false,
                    'collapsed' => false,
                    'activitiescount' => 2,
                    'showaddactivity' => true,
                    'activities' => [
                        [
                            'id' => 12,
                            'name' => 'Reference page',
                            'modname' => 'page',
                            'purpose' => 'content',
                            'iconhtml' => '',
                            'locked' => true,
                            'isinstance' => false,
                            'aigenerated' => false,
                            'sectionid' => 5,
                            'index' => 0,
                            'typelabel' => 'Page',
                            'showinsertzone' => true,
                        ],
                        [
                            'id' => -1,
                            'name' => 'Professor-added quiz',
                            'modname' => 'quiz',
                            'purpose' => 'assessment',
                            'iconhtml' => '',
                            'locked' => false,
                            'isinstance' => false,
                            'aigenerated' => false,
                            'sectionid' => 5,
                            'index' => 1,
                            'typelabel' => 'Quiz',
                            'showinsertzone' => true,
                        ],
                    ],
                ],
            ],
            'showaddsection' => true,
            'addsectiondisabled' => false,
            'addsectionlabel' => 'Add section (3)',
        ], $overrides);

        return $OUTPUT->render_from_template('local_coursegen/template_structure', $context);
    }

    /**
     * When no more sections are allowed the add-section control is omitted
     * entirely — not rendered disabled.
     */
    public function test_add_section_control_omitted_when_not_allowed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_structure(['showaddsection' => false]);

        $this->assertStringNotContainsString('data-action="local_coursegen/template/add-section"', $html);
    }

    /**
     * When sections may still be added the add-section control renders.
     */
    public function test_add_section_control_renders_when_allowed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_structure();

        $this->assertStringContainsString('data-action="local_coursegen/template/add-section"', $html);
    }

    /**
     * Professor-added (unlocked) rows expose an edit control next to delete;
     * locked reference rows expose neither.
     */
    public function test_edit_control_renders_only_on_unlocked_rows(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_structure();

        // Exactly one unlocked row → exactly one edit and one delete control.
        $this->assertSame(
            1,
            substr_count($html, 'data-action="local_coursegen/template/edit-activity"'),
            'Edit control must render exactly once (only on the unlocked row)'
        );
        $this->assertSame(
            1,
            substr_count($html, 'data-action="local_coursegen/template/remove-activity"'),
            'Delete control must render exactly once (only on the unlocked row)'
        );

        // The edit control targets the unlocked row's section/index.
        $editpos = strpos($html, 'data-action="local_coursegen/template/edit-activity"');
        $chunk = substr($html, $editpos, 300);
        $this->assertStringContainsString('data-activity-index="1"', $chunk);
    }
}
