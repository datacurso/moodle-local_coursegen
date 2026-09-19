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

    /**
     * Render the courseai_page template with a minimal context.
     *
     * @param array $overrides Context values replacing the defaults.
     * @return string Rendered HTML.
     */
    private function render_page(array $overrides = []): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template('local_coursegen/courseai_page', $overrides + [
            'startchooser' => true,
            'guidelines' => '[]',
            'coursetemplates' => [],
            'templatepickerformhtml' => self::PICKER_SENTINEL,
            'hascoursetemplates' => false,
            'languages' => '[]',
            'defaultlang' => 'en',
            'logourl' => '',
            'hassessions' => false,
            'sessions' => [],
            'allsessions' => [],
            'isresuming' => false,
            'showsessionsview' => false,
            'subsectionsenabled' => false,
            'closeurl' => (new \moodle_url('/my/courses.php'))->out(false),
        ]);
    }

    /**
     * The template column renders with the one-line picker first (the button
     * that opens the list, its × and the list itself), the native picker form
     * hidden right after it (its select is the value template_mode.js listens
     * to), and the input bar below. Nothing of the old card or Remove link is
     * left.
     */
    public function test_template_column_renders_picker_line_and_input_bar(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page();

        $leftpanelpos = strpos($html, 'data-region="tpl-left-panel"');
        $headpos = strpos($html, get_string('courseai_template_title', 'local_coursegen'));
        $pickerpos = strpos($html, 'id="tplPicker"');
        $popoverpos = strpos($html, 'id="templatesPopoverTpl"');
        $formpos = strpos($html, self::PICKER_SENTINEL);
        $inputbarpos = strpos($html, 'data-region="tpl-input-bar"');

        $this->assertNotFalse($leftpanelpos, 'Left panel region missing');
        $this->assertNotFalse($headpos, 'Column heading missing');
        $this->assertNotFalse($pickerpos, 'Picker line missing');
        $this->assertNotFalse($popoverpos, 'Templates popover missing');
        $this->assertNotFalse($formpos, 'Picker form slot missing');
        $this->assertNotFalse($inputbarpos, 'Input bar region missing');

        // Order inside the panel: heading, picker line, its list, the hidden
        // form, input bar pinned at the bottom.
        $this->assertGreaterThan($leftpanelpos, $headpos, 'Heading must render inside the left panel');
        $this->assertGreaterThan($headpos, $pickerpos, 'Picker line must render after the heading');
        $this->assertGreaterThan($pickerpos, $popoverpos, 'The list must render after the picker line');
        $this->assertGreaterThan($popoverpos, $formpos, 'The native form must render after the list');
        $this->assertGreaterThan($formpos, $inputbarpos, 'Input bar must render below the picker');

        // One button opens the list; its × is its own button, hidden until there is a value.
        $this->assertMatchesRegularExpression(
            '/<button class="tpl-picker" id="tplPicker" type="button"\s+aria-haspopup="listbox" aria-expanded="false"'
                . ' aria-controls="templatesPopoverTpl">/',
            $html
        );
        $this->assertSame(1, substr_count($html, 'id="tplPicker"'), 'One picker line');
        $this->assertStringContainsString(get_string('courseai_template_pick', 'local_coursegen'), $html);
        $this->assertMatchesRegularExpression('/<button class="tpl-picker-clear" id="tplPickerClear" type="button" hidden/', $html);
        $this->assertStringContainsString(
            'aria-label="' . get_string('courseai_chip_remove_template', 'local_coursegen') . '"',
            $html
        );
        foreach (['id="tplPickAnchor"', 'id="tplPickerName"', 'id="tplPickerCourse"', 'id="templateListTpl"',
            'id="templateSearchTpl"', 'id="tplPickerChevron"'] as $needle) {
            $this->assertStringContainsString($needle, $html, "$needle missing");
        }

        // The search line replaces the button while open: closed by default.
        $this->assertMatchesRegularExpression('/<input type="text" class="tpl-picker-search" id="templateSearchTpl" hidden/', $html);

        // The native picker is hidden: it is the value, not what the professor sees.
        $this->assertMatchesRegularExpression(
            '/<div class="hidden" id="templateModeCard">\s*' . preg_quote(self::PICKER_SENTINEL, '/') . '/',
            $html,
            'The native picker form must render hidden'
        );

        // The old button, the chosen-template card and its Remove link are gone.
        foreach (['tplPickBtn', 'tplCard', 'tplCardBtn', 'tplCardRemove', 'tplCardThumb', 'tplCardName'] as $id) {
            $this->assertStringNotContainsString('id="' . $id . '"', $html, "$id must be gone");
        }
    }

    /**
     * The input bar carries the prompt textarea plus exactly the reduced
     * control set: syllabus attach, generate-images toggle, language select,
     * and the Generate action. The stats line does NOT live here.
     */
    public function test_template_mode_input_bar_has_prompt_and_reduced_control_set(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page();

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
     * The shared splitter and a main column holding the structure containers
     * render alongside the free hero; the layout class is JS-driven, so the
     * server never emits it.
     */
    public function test_template_column_renders_splitter_and_main_column(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page();

        $this->assertDoesNotMatchRegularExpression(
            '/class="courseai-workspace[^"]*\bis-template\b/',
            $html,
            'The template layout class is added by JS when a template is attached'
        );

        // Shared resizable divider (same element/module as free mode).
        $this->assertStringContainsString('id="cgSplitter"', $html);

        $maincolpos = strpos($html, 'data-region="tpl-main-column"');
        $this->assertNotFalse($maincolpos, 'Main column region missing');

        // Structure containers live in the main column.
        foreach (
            [
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
     * Both columns render on every load, the free hero included, and the ids
     * the two threads share belong to the free thread until a template is
     * chosen: the template column carries them only as data-shared-id.
     */
    public function test_free_hero_and_template_column_share_thread_ids_without_duplicates(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page();

        $this->assertStringContainsString('id="contextView"', $html);
        $this->assertStringContainsString('data-region="tpl-left-panel"', $html);
        $this->assertStringNotContainsString('courseai-mode-seg', $html);
        $this->assertStringNotContainsString('courseai-sidebar-modes', $html);

        foreach (
            [
                'cgLog', 'cgLogAfter', 'courseaiChecklist', 'courseaiChecklistList', 'cgWorkingSlot',
                'cgDecisionOverlay', 'cgDecisionBody', 'cgDecisionAccept', 'cgDecisionAdjust',
            ] as $sharedid
        ) {
            // A leading space: the shared marker is data-shared-id="…", not an id.
            $this->assertSame(1, substr_count($html, ' id="' . $sharedid . '"'), "id {$sharedid} must render once");
            $this->assertSame(2, substr_count($html, 'data-shared-id="' . $sharedid . '"'),
                "both columns must declare {$sharedid} as shared");
        }
    }

    /**
     * A fresh visit opens on the chooser: the workspace carries is-choosing,
     * the two cards render, and the top bar holds the one path crumb, hidden
     * until a card is picked. Nothing names the path inside the columns. The
     * old ways of reaching a template from the free composer are gone.
     */
    public function test_fresh_visit_opens_on_the_start_chooser_with_the_path_crumb_in_the_top_bar(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page();

        $this->assertMatchesRegularExpression('/class="courseai-workspace[^"]*\bis-choosing\b[^"]*" id="courseaiWorkspace"/', $html);
        $this->assertStringContainsString('id="courseaiChooser"', $html);
        $this->assertStringContainsString('data-start-path="free"', $html);
        $this->assertStringContainsString('data-start-path="template"', $html);
        $this->assertSame(1, substr_count($html, 'data-start-modebar'), 'One path crumb, in the top bar');
        $this->assertMatchesRegularExpression(
            '/<button class="courseai-topbar-path" id="courseaiPathBack"[^>]*\bhidden\b[^>]*>/',
            $html
        );
        // The crumb's own data-start-path (which name it shows) is empty while
        // nothing is chosen yet - it must come from the server too, not sit
        // blank until start_path.js runs.
        $this->assertStringContainsString('data-start-path=""', $html);
        $this->assertStringNotContainsString('courseai-modebar', $html);
        $this->assertStringNotContainsString('id="tplPickHint"', $html);

        // The template is chosen on its own column's picker line, not from the free composer.
        $this->assertStringContainsString('id="tplPicker"', $html);
        $this->assertStringContainsString('id="templatesPopoverTpl"', $html);
        $this->assertStringNotContainsString('id="tplPickBtn"', $html);
        $this->assertStringNotContainsString('id="heroTemplateLink"', $html);
        $this->assertStringNotContainsString('id="btnTemplates"', $html);
        $this->assertStringNotContainsString('id="btnTemplatesCompact"', $html);
        $this->assertStringNotContainsString('id="templatesPopover"', $html);
        $this->assertStringNotContainsString('id="templatesPopoverCompact"', $html);
        $this->assertStringNotContainsString('id="chipTemplate"', $html);
    }

    /**
     * When the page is opened straight onto a template (a preselected
     * template, the template list, or a resumed session) the chooser is
     * skipped: the workspace renders without is-choosing.
     */
    public function test_direct_entry_points_skip_the_chooser(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(['startchooser' => false]);

        $this->assertDoesNotMatchRegularExpression(
            '/class="courseai-workspace[^"]*\bis-choosing\b[^"]*" id="courseaiWorkspace"/',
            $html
        );
        // The chooser markup still renders; JS brings it back on "Change starting point".
        $this->assertStringContainsString('id="courseaiChooser"', $html);
    }
}
