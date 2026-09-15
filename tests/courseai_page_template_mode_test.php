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
 * Template-mode layout contract of the AI course creation page.
 *
 * Template mode mirrors the free-mode planning workspace: a resizable LEFT
 * panel (template picker on top, a reduced input bar pinned at the bottom
 * with syllabus attach + images toggle + language select + Generate) and a
 * MAIN column holding the template structure, separated by the shared
 * splitter. Before a template is picked the main column shows the empty
 * state, so the page still looks complete.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen
 */
final class courseai_page_template_mode_test extends \advanced_testcase {
    /** @var string Sentinel markup standing in for the server-rendered picker form. */
    private const PICKER_SENTINEL = '<div class="tpl-picker-sentinel"></div>';

    /** @var string Sentinel markup standing in for the server-rendered empty state. */
    private const EMPTY_SENTINEL = '<div class="tpl-empty-sentinel"></div>';

    /**
     * Render the courseai_page template with a minimal context.
     *
     * @param bool $templatemode Whether template mode is active.
     * @return string Rendered HTML.
     */
    private function render_page(bool $templatemode): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template('local_coursegen/courseai_page', [
            'guidelines' => '[]',
            'coursetemplates' => [],
            'templatepickerformhtml' => self::PICKER_SENTINEL,
            'templateemptystatehtml' => self::EMPTY_SENTINEL,
            'hascoursetemplates' => false,
            'languages' => '[]',
            'defaultlang' => 'en',
            'logourl' => '',
            'hassessions' => false,
            'sessions' => [],
            'allsessions' => [],
            'isresuming' => false,
            'showsessionsview' => false,
            'templatemodeactive' => $templatemode,
            'subsectionsenabled' => false,
            'closeurl' => (new \moodle_url('/my/courses.php'))->out(false),
        ]);
    }

    /**
     * Template mode renders the left panel: picker form first, input bar below.
     */
    public function test_template_mode_renders_left_panel_with_picker_and_input_bar(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(true);

        $leftpanelpos = strpos($html, 'data-region="tpl-left-panel"');
        $pickerpos = strpos($html, self::PICKER_SENTINEL);
        $inputbarpos = strpos($html, 'data-region="tpl-input-bar"');

        $this->assertNotFalse($leftpanelpos, 'Left panel region missing');
        $this->assertNotFalse($pickerpos, 'Picker form slot missing');
        $this->assertNotFalse($inputbarpos, 'Input bar region missing');

        // Order inside the panel: picker first, input bar pinned at the bottom.
        $this->assertGreaterThan($leftpanelpos, $pickerpos, 'Picker must render inside/after the left panel');
        $this->assertGreaterThan($pickerpos, $inputbarpos, 'Input bar must render below the picker');

        // Template description block sits under the picker, above the input bar.
        $descriptionpos = strpos($html, 'data-region="tpl-description"');
        $this->assertNotFalse($descriptionpos, 'Template description region missing');
        $this->assertGreaterThan($pickerpos, $descriptionpos, 'Description must render below the picker');
        $this->assertGreaterThan($descriptionpos, $inputbarpos, 'Description must render above the input bar');
    }

    /**
     * The chooser confirm button carries both mode labels (add / save changes)
     * so JS can flip them when the modal reopens in edit mode.
     */
    public function test_chooser_confirm_button_carries_add_and_save_labels(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(true);

        $confirmpos = strpos($html, 'data-region="local_coursegen/template/chooser-confirm"');
        $this->assertNotFalse($confirmpos, 'Chooser confirm button missing');

        $addlabelpos = strpos($html, 'data-region="local_coursegen/template/chooser-confirm-add"');
        $savelabelpos = strpos($html, 'data-region="local_coursegen/template/chooser-confirm-save"');
        $this->assertNotFalse($addlabelpos, 'Add-mode label span missing');
        $this->assertNotFalse($savelabelpos, 'Edit-mode label span missing');
        $this->assertGreaterThan($confirmpos, $addlabelpos, 'Add label must render inside the confirm button');
        $this->assertGreaterThan($confirmpos, $savelabelpos, 'Save label must render inside the confirm button');
    }

    /**
     * The input bar carries the prompt textarea plus exactly the reduced
     * control set: syllabus attach, generate-images toggle, language select,
     * and the Generate action. The stats line does NOT live here.
     */
    public function test_template_mode_input_bar_has_prompt_and_reduced_control_set(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(true);

        $inputbarpos = strpos($html, 'data-region="tpl-input-bar"');
        $this->assertNotFalse($inputbarpos, 'Input bar region missing');

        foreach (
            [
                'id="tplPromptInput"',
                'id="tplBtnSyllabus"',
                'id="tplWithImages"',
                'id="tplImgToggleTrack"',
                'id="tplLangSelect"',
                'id="tplModeGenerate"',
                'id="tplChipSyllabus"',
            ] as $needle
        ) {
            $pos = strpos($html, $needle);
            $this->assertNotFalse($pos, "Control {$needle} missing");
            $this->assertGreaterThan($inputbarpos, $pos, "Control {$needle} must render inside the input bar");
        }

        // Prompt textarea sits above the toolbar controls, composer-style.
        $this->assertGreaterThan(
            strpos($html, 'id="tplPromptInput"'),
            strpos($html, 'id="tplBtnSyllabus"'),
            'Prompt textarea must render above the toolbar controls'
        );

        // The stats line moved out of the input bar into the main column's
        // badge area (asserted in the main-column test below).
        $statspos = strpos($html, 'id="tplModeStats"');
        $this->assertNotFalse($statspos, 'Stats span missing');
        $this->assertSame(1, substr_count($html, 'id="tplModeStats"'), 'Stats span must render exactly once');
        $this->assertGreaterThan(
            strpos($html, 'data-region="tpl-main-column"'),
            $statspos,
            'Stats span must not render inside the input bar'
        );
    }

    /**
     * Template mode renders the shared splitter and a main column holding the
     * structure containers, with the empty state shown before a pick.
     */
    public function test_template_mode_renders_splitter_and_main_column(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(true);

        // The workspace opts into the two-column template grid server-side.
        $this->assertMatchesRegularExpression(
            '/class="courseai-workspace[^"]*\bis-template\b/',
            $html,
            'Workspace must carry the is-template layout class'
        );

        // Shared resizable divider (same element/module as free mode).
        $this->assertStringContainsString('id="cgSplitter"', $html);

        $maincolpos = strpos($html, 'data-region="tpl-main-column"');
        $this->assertNotFalse($maincolpos, 'Main column region missing');

        // Structure containers plus the pre-pick empty state live in the main column.
        foreach (
            [
                self::EMPTY_SENTINEL,
                'id="tplModeLimits"',
                'id="tplModeLimitsBadge"',
                'id="tplModeStats"',
                'id="tplModeStructure"',
            ] as $needle
        ) {
            $pos = strpos($html, $needle);
            $this->assertNotFalse($pos, "Main column content {$needle} missing");
            $this->assertGreaterThan($maincolpos, $pos, "{$needle} must render inside the main column");
        }

        // The stats span lives in the limits badge area, above the structure.
        $this->assertGreaterThan(
            strpos($html, 'id="tplModeLimits"'),
            strpos($html, 'id="tplModeStats"'),
            'Stats span must render inside the limits badge area'
        );
        $this->assertGreaterThan(
            strpos($html, 'id="tplModeStats"'),
            strpos($html, 'id="tplModeStructure"'),
            'Stats span must render above the structure container'
        );

        // The splitter sits between the left panel and the main column.
        $splitterpos = strpos($html, 'id="cgSplitter"');
        $leftpanelpos = strpos($html, 'data-region="tpl-left-panel"');
        $this->assertGreaterThan($leftpanelpos, $splitterpos, 'Splitter must follow the left panel');
        $this->assertGreaterThan($splitterpos, $maincolpos, 'Main column must follow the splitter');
    }

    /**
     * Free mode renders none of the template-mode layout regions.
     */
    public function test_free_mode_renders_no_template_layout_regions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(false);

        $this->assertStringNotContainsString('data-region="tpl-left-panel"', $html);
        $this->assertStringNotContainsString('data-region="tpl-input-bar"', $html);
        $this->assertStringNotContainsString('data-region="tpl-main-column"', $html);
        $this->assertStringNotContainsString('is-template', $html);
    }
}
