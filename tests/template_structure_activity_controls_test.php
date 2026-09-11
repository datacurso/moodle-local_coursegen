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
 * Per-activity action controls of the template-mode structure view.
 *
 * Every UNLOCKED (professor-added) activity row rendered by
 * local_coursegen/template_structure must expose BOTH an edit (pencil) and a
 * delete control, wired through data-action attributes for
 * local/courseai/template/render.js, while locked reference rows expose
 * neither. The controls must carry core's "edit"/"delete" strings as their
 * accessible names.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen
 */
final class template_structure_activity_controls_test extends \advanced_testcase {
    /** @var string data-action attribute of the per-activity edit control. */
    private const EDIT_ACTION = 'data-action="local_coursegen/template/edit-activity"';

    /** @var string data-action attribute of the per-activity delete control. */
    private const REMOVE_ACTION = 'data-action="local_coursegen/template/remove-activity"';

    /**
     * Build one activity row context entry, mirroring render.js buildContext().
     *
     * @param int $id Activity id (negative for professor-added rows).
     * @param bool $locked Whether the row is a locked reference activity.
     * @param int $index Render index inside the section.
     * @return array
     */
    private function activity_context(int $id, bool $locked, int $index): array {
        return [
            'id' => $id,
            'name' => $locked ? 'Reference page' : 'New page',
            'modname' => 'page',
            'purpose' => 'content',
            'iconhtml' => '<img class="icon activityicon" src="#" alt="">',
            'locked' => $locked,
            'sectionid' => -1,
            'index' => $index,
            'typelabel' => 'Page',
            'showinsertzone' => true,
        ];
    }

    /**
     * Render the template_structure template for one unlocked section.
     *
     * @param array $activities Activity context entries.
     * @return string Rendered HTML.
     */
    private function render_structure(array $activities): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template('local_coursegen/template_structure', [
            'sections' => [
                [
                    'id' => -1,
                    'name' => 'Section 1',
                    'locked' => false,
                    'collapsed' => false,
                    'activitiescount' => count($activities),
                    'showaddactivity' => true,
                    'activities' => $activities,
                ],
            ],
            'showaddsection' => true,
            'addsectiondisabled' => false,
            'addsectionlabel' => 'Add section (3)',
        ]);
    }

    /**
     * An unlocked activity row carries both the edit and the delete control,
     * and a sibling locked row adds neither (each action appears exactly once).
     */
    public function test_unlocked_activity_row_renders_edit_and_remove_controls(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_structure([
            $this->activity_context(-1, false, 0),
            $this->activity_context(12, true, 1),
        ]);

        $this->assertSame(1, substr_count($html, self::EDIT_ACTION));
        $this->assertSame(1, substr_count($html, self::REMOVE_ACTION));
    }

    /**
     * A locked reference activity row exposes neither the edit nor the delete control.
     */
    public function test_locked_activity_row_renders_no_activity_controls(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_structure([
            $this->activity_context(12, true, 0),
        ]);

        $this->assertStringNotContainsString(self::EDIT_ACTION, $html);
        $this->assertStringNotContainsString(self::REMOVE_ACTION, $html);
    }

    /**
     * The controls use core's "edit"/"delete" strings as accessible names —
     * not the plugin's "Cancel" string the delete control used to reuse.
     */
    public function test_activity_controls_use_core_accessible_names(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_structure([
            $this->activity_context(-1, false, 0),
        ]);

        $this->assertStringContainsString('aria-label="' . get_string('edit') . '"', $html);
        $this->assertStringContainsString('aria-label="' . get_string('delete') . '"', $html);
        $this->assertStringNotContainsString(get_string('courseai_btn_cancel', 'local_coursegen'), $html);
    }
}
