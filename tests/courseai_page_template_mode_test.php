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
            'freemodeactive' => !$templatemode,
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

        foreach ([
                'id="tplPromptInput"',
                'id="tplBtnSyllabus"',
                'id="tplWithImages"',
                'id="tplImgToggleTrack"',
                'id="tplLangSelect"',
                'id="tplModeGenerate"',
                'id="tplChipSyllabus"',
            ] as $needle) {
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

        // The stats line lives in the top bar, never in the input bar.
        $statspos = strpos($html, 'id="tplModeStats"');
        $this->assertNotFalse($statspos, 'Stats span missing');
        $this->assertSame(1, substr_count($html, 'id="tplModeStats"'), 'Stats span must render exactly once');
        $this->assertLessThan($inputbarpos, $statspos, 'Stats span must not render inside the input bar');
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

        // Structure container plus the pre-pick empty state live in the main column.
        foreach ([self::EMPTY_SENTINEL, 'id="tplModeStructure"'] as $needle) {
            $pos = strpos($html, $needle);
            $this->assertNotFalse($pos, "Main column content {$needle} missing");
            $this->assertGreaterThan($maincolpos, $pos, "{$needle} must render inside the main column");
        }

        // The splitter sits between the left panel and the main column.
        $splitterpos = strpos($html, 'id="cgSplitter"');
        $leftpanelpos = strpos($html, 'data-region="tpl-left-panel"');
        $this->assertGreaterThan($leftpanelpos, $splitterpos, 'Splitter must follow the left panel');
        $this->assertGreaterThan($splitterpos, $maincolpos, 'Main column must follow the splitter');
    }

    /**
     * The progress of the activities is a pill in the top bar, hidden until a run counts activities, with a closed
     * list the generation fills in one item per activity.
     */
    public function test_template_mode_renders_hidden_progress_pill_in_the_top_bar(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(true);

        $barpos = strpos($html, 'id="courseaiTopbar"');
        $barend = strpos($html, '</header>');
        $progresspos = strpos($html, 'id="courseaiChecklist"');
        $this->assertNotFalse($progresspos, 'Progress pill missing');
        $this->assertGreaterThan($barpos, $progresspos, 'Progress pill must render inside the top bar');
        $this->assertLessThan($barend, $progresspos, 'Progress pill must render inside the top bar');
        $this->assertSame(1, substr_count($html, 'id="courseaiChecklist"'), 'The progress pill must render once');
        $this->assertMatchesRegularExpression('/class="[^"]*\bhidden\b[^"]*"\s+id="courseaiChecklist"/', $html);
        $this->assertMatchesRegularExpression('/id="tplTopActivitiesToggle"[^>]*aria-expanded="false"/s', $html);
        $this->assertMatchesRegularExpression('/id="tplTopActivitiesPanel"[^>]*\shidden/s', $html);
        $this->assertStringContainsString('id="courseaiChecklistList"', $html);
        $this->assertStringContainsString('id="courseaiChecklistCount"', $html);
        $this->assertStringContainsString('id="tplTopActivitiesCount"', $html);
    }

    /**
     * The stats chip and the course preview link live in the top bar in template mode, and the preview link stays
     * hidden until a run exists.
     */
    public function test_template_mode_renders_stats_and_course_preview_in_the_top_bar(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(true);

        $barend = strpos($html, '</header>');
        foreach (['id="tplModeLimits"', 'id="tplModeStats"', 'id="tplPreviewCourse"'] as $needle) {
            $pos = strpos($html, $needle);
            $this->assertNotFalse($pos, "{$needle} missing");
            $this->assertLessThan($barend, $pos, "{$needle} must render inside the top bar");
            $this->assertSame(1, substr_count($html, $needle), "{$needle} must render once");
        }
        $this->assertMatchesRegularExpression('/id="tplPreviewCourse"[^>]*\shidden/s', $html);
    }

    /**
     * Free mode gets none of the top bar pieces of the template mode.
     */
    public function test_free_mode_renders_no_template_top_bar_pieces(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(false);

        $this->assertStringNotContainsString('tpl-topbar', $html);
        $this->assertStringNotContainsString('id="tplModeStats"', $html);
        $this->assertStringNotContainsString('id="tplPreviewCourse"', $html);
    }

    /**
     * The review card of template mode lists its actions as the screen shows them: the secondary one, then the
     * primary one, so the order of the keyboard is the order of the eyes. Free mode keeps its own order.
     */
    public function test_template_mode_review_lists_adjust_before_accept(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(true);

        $panel = substr($html, (int) strpos($html, 'data-region="tpl-left-panel"'));
        $adjustpos = strpos($panel, 'id="cgDecisionAdjust"');
        $acceptpos = strpos($panel, 'id="cgDecisionAccept"');
        $this->assertNotFalse($adjustpos, 'Adjust button missing');
        $this->assertNotFalse($acceptpos, 'Accept button missing');
        $this->assertLessThan($acceptpos, $adjustpos, 'Adjust must come before Accept in template mode');
    }

    /**
     * Free mode never receives the progress list.
     */
    public function test_free_mode_renders_no_template_progress_list(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(false);

        $this->assertStringNotContainsString('id="courseaiChecklist"', $html);
    }

    /**
     * One progress item shows the activity's name, type and section while it
     * is in progress, carrying the uid the stream events answer to.
     */
    public function test_generation_item_renders_name_type_and_section_in_progress(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('local_coursegen/template_generation_item', [
            'uid' => 'inst-7',
            'name' => 'Intro lesson',
            'typelabel' => 'Lesson',
            'sectionname' => 'Week 1',
        ]);

        $this->assertStringContainsString('data-progress-uid="inst-7"', $html);
        $this->assertStringContainsString('courseai-checklist-item is-loading', $html);
        $this->assertStringContainsString('Intro lesson', $html);
        $this->assertStringContainsString('Lesson', $html);
        $this->assertStringContainsString('Week 1', $html);
        $this->assertStringContainsString('spinner-icon', $html);
        $this->assertStringContainsString('check-icon', $html);
    }

    /**
     * An item without type or section leaves its detail slot empty, which the
     * stylesheet hides.
     */
    public function test_generation_item_without_type_or_section_has_an_empty_detail(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('local_coursegen/template_generation_item', [
            'uid' => 'inst-8',
            'name' => 'Quiz',
            'typelabel' => '',
            'sectionname' => '',
        ]);

        $this->assertMatchesRegularExpression('/<div class="courseai-checklist-detail">\s*<\/div>/', $html);
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

    /**
     * Template mode has no section for the files of the course and no picker of activities to add.
     */
    public function test_template_mode_holds_no_course_files_region_nor_activity_picker(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(true);

        $this->assertStringNotContainsString('template/reference-files', $html);
        $this->assertStringNotContainsString('tplActivityChooserModal', $html);
    }
}
