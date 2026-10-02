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

use local_coursegen\local\language_options;

/**
 * Rendering tests for the course AI creation page template and its partials.
 *
 * The page is a layout of partials under local_coursegen/local/courseai/.
 * Rendering it with the context shape aicoursecreation.php builds checks the
 * DOM contract the JavaScript relies on: every element id the selectors
 * module resolves is rendered exactly once, the main and the compact composer
 * (both rendered from the shared chips/toolbar partials) carry their own ids,
 * and the chip remove buttons expose delegated data-action hooks instead of
 * inline handlers.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class courseai_page_templates_test extends \advanced_testcase {
    /**
     * Ids looked up with document.getElementById() that the page never renders.
     *
     * Each one is either created at runtime or guarded with a null check in
     * the module that looks it up:
     * - courseaiInitialPromptHistory, courseaiInitialPromptText: resolved by
     *   selectors.js, used behind null checks in local/courseai/actions.js
     * - courseaiCancelRow: null-checked in local/courseai/planning/review-actions.js
     * - btnWizardCancel: null-checked in local/courseai/actions.js
     * - cgFeedbackThinking: created at runtime by local/courseai/ui/feedback-progress.js
     * - planningEditIcon: created at runtime by courseai/bootstrap/create-course-callback.js
     * The last four are not resolved by selectors.js (their modules look them up
     * directly); they are listed so the allow-list documents every dynamic id.
     */
    private const UNRENDERED_IDS = [
        'courseaiInitialPromptHistory', 'courseaiInitialPromptText', 'courseaiCancelRow',
        'btnWizardCancel', 'cgFeedbackThinking', 'planningEditIcon',
    ];

    /**
     * Ids of the main composer chips and toolbar (compact=false).
     *
     * Source of truth: templates/local/courseai/composer_chips.mustache and
     * composer_toolbar.mustache render both instances from the `compact` flag.
     */
    private const MAIN_COMPOSER_IDS = [
        'chipsRow', 'chipSyllabus', 'chipSyllabusName', 'chipGuideline', 'chipGuidelineName',
        'chipGuidelineEyeBtn', 'btnPlusMenu', 'plusMenuPanel', 'btnSyllabus', 'btnDirectrices',
        'guidelineBadge', 'pmLangItem', 'pmLangValue', 'imgToggleTrack', 'btnWithImages',
        'subToggleTrack', 'btnWithSubsections', 'langPopover', 'langPopoverClose', 'langSearch',
        'langList', 'guidelinesPopover', 'guidelinesPopoverClose', 'guidelineSearch',
        'guidelineList', 'syllabusFilemanager', 'langSelect',
    ];

    /**
     * Ids of the compact composer chips and toolbar (compact=true).
     *
     * Source of truth: templates/local/courseai/composer_chips.mustache and
     * composer_toolbar.mustache render both instances from the `compact` flag.
     */
    private const COMPACT_COMPOSER_IDS = [
        'compactChipsRow', 'compactChipSyllabus', 'compactChipSyllabusName', 'compactChipGuideline',
        'compactChipGuidelineName', 'compactChipGuidelineEyeBtn', 'btnCompactPlusMenu',
        'compactPlusMenuPanel', 'btnCompactSyllabus', 'btnCompactDirectrices',
        'compactGuidelineBadge', 'pmCompactLangItem', 'pmCompactLangValue', 'compactImgToggleTrack',
        'btnCompactWithImages', 'compactSubToggleTrack', 'btnCompactWithSubsections',
        'langPopoverCompact', 'langPopoverCompactClose', 'langSearchCompact', 'langListCompact',
        'guidelinesPopoverCompact', 'guidelinesPopoverCompactClose', 'guidelineSearchCompact',
        'guidelineListCompact', 'compactLangSelect',
    ];

    /**
     * Element ids resolved by amd/src/local/courseai/selectors.js, read at test time.
     *
     * @return string[] Sorted unique ids, minus the ones the page never renders.
     */
    private function selector_ids(): array {
        $source = file_get_contents(__DIR__ . '/../amd/src/local/courseai/selectors.js');
        $this->assertNotFalse($source);
        preg_match_all("/getElementById\\('([A-Za-z0-9_-]+)'\\)/", $source, $matches);
        $ids = array_values(array_diff(array_unique($matches[1]), self::UNRENDERED_IDS));
        sort($ids);
        $this->assertGreaterThan(70, count($ids), 'selectors.js must still resolve elements by id.');

        return $ids;
    }

    /**
     * Render the page template.
     *
     * @param array $overrides Context keys to override.
     * @return string Rendered HTML.
     */
    private function render_page(array $overrides = []): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('local_coursegen/courseai_page', array_merge($this->page_context(), $overrides));
    }

    /**
     * A realistic page context, shaped like aicoursecreation.php builds it.
     *
     * @return array
     */
    private function page_context(): array {
        $session = ['id' => 7, 'title' => 'Sample & "session"', 'statuslabel' => 'Pending', 'timecreated' => '1 January 2026'];
        return [
            'guidelines' => json_encode([['id' => 'si_1', 'name' => 'Example', 'category' => 'General']]),
            'languages' => json_encode([['code' => 'es', 'name' => 'Español (es)'], ['code' => 'en', 'name' => 'English (en)']]),
            'defaultlang' => 'es',
            'logourl' => 'https://example.com/local/coursegen/pix/logo.png',
            'hassessions' => true,
            'sessions' => [$session],
            'allsessions' => [$session],
            'isresuming' => false,
            'subsectionsenabled' => true,
            'compactcomposer' => ['compact' => true],
            'guidelinelist' => [
                'listlabel' => 'Guidelines list',
                'emptytext' => 'No results',
                'hasitems' => false,
                'items' => [],
            ],
        ];
    }

    /**
     * Assert that an element id is rendered exactly once.
     *
     * @param string $html Rendered HTML.
     * @param string $id Element id.
     */
    private function assert_id_once(string $html, string $id): void {
        $this->assertSame(1, substr_count($html, 'id="' . $id . '"'), "Element id '{$id}' must be rendered exactly once.");
    }

    /**
     * Every id the selectors module resolves is rendered exactly once.
     */
    public function test_page_renders_every_selector_id_once(): void {
        $this->resetAfterTest();

        $html = $this->render_page();

        foreach ($this->selector_ids() as $id) {
            $this->assert_id_once($html, $id);
        }
    }

    /**
     * The allow-list of unrendered ids stays honest: none of them is in the page.
     */
    public function test_unrendered_ids_are_not_in_the_page(): void {
        $this->resetAfterTest();

        $html = $this->render_page();

        foreach (self::UNRENDERED_IDS as $id) {
            $this->assertStringNotContainsString('id="' . $id . '"', $html, "'{$id}' is listed as unrendered.");
        }
    }

    /**
     * The default language of the page is a supported code, like aicoursecreation.php
     * builds it: language_options::normalize(current_language()) for both the template
     * context and the AMD init config.
     */
    public function test_default_language_is_normalised(): void {
        global $SESSION;

        $this->resetAfterTest();
        $SESSION->lang = 'en_us';

        $defaultlang = language_options::normalize(current_language());
        $html = $this->render_page(['defaultlang' => $defaultlang]);

        $this->assertSame('en', $defaultlang);
        $this->assertStringContainsString('data-defaultlang="en"', $html);
        $this->assertArrayHasKey($defaultlang, language_options::supported());
    }

    /**
     * The main and the compact composer both render their own chips and toolbar ids.
     */
    public function test_page_renders_main_and_compact_composer_ids(): void {
        $this->resetAfterTest();

        $html = $this->render_page();

        foreach (array_merge(self::MAIN_COMPOSER_IDS, self::COMPACT_COMPOSER_IDS) as $id) {
            $this->assert_id_once($html, $id);
        }
        $this->assertSame(1, substr_count($html, 'data-region="guideline-list"'));
        $this->assertSame(1, substr_count($html, 'data-region="guideline-list-compact"'));
        $this->assertSame(1, substr_count($html, 'class="plus-menu-panel"'));
        $this->assertSame(1, substr_count($html, 'class="plus-menu-panel plus-menu-panel--up"'));
        $this->assertSame(2, substr_count($html, 'class="popover-panel popover-panel--down"'));
        $this->assertSame(2, substr_count($html, 'class="popover-panel"'));
        $this->assertStringContainsString('aria-controls="guidelinesPopover"', $html);
        $this->assertStringContainsString('aria-controls="guidelinesPopoverCompact"', $html);
        $this->assertStringContainsString('aria-controls="langPopover"', $html);
        $this->assertStringContainsString('aria-controls="langPopoverCompact"', $html);
    }

    /**
     * The chip remove buttons use delegated data-action hooks, never inline handlers.
     */
    public function test_chip_remove_buttons_use_data_actions(): void {
        $this->resetAfterTest();

        $html = $this->render_page();

        $this->assertSame(2, substr_count($html, 'data-action="local_coursegen/clear-syllabus"'));
        $this->assertSame(2, substr_count($html, 'data-action="local_coursegen/clear-guideline"'));
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('onerror=', $html);
    }

    /**
     * The subsections toggles only render when the feature is enabled.
     */
    public function test_subsections_toggles_render_only_when_enabled(): void {
        $this->resetAfterTest();

        $html = $this->render_page(['subsectionsenabled' => false]);

        $this->assertStringNotContainsString('id="btnWithSubsections"', $html);
        $this->assertStringNotContainsString('id="btnCompactWithSubsections"', $html);
        $this->assert_id_once($html, 'btnWithImages');
        $this->assert_id_once($html, 'btnCompactWithImages');
    }

    /**
     * Resuming a session renders the workspace in planning mode with the planning view shown.
     */
    public function test_resuming_renders_planning_mode(): void {
        $this->resetAfterTest();

        $html = $this->render_page(['isresuming' => true]);

        $this->assertStringContainsString('class="courseai-workspace is-planning" id="courseaiWorkspace"', $html);
        $this->assertStringContainsString('id="planningView" style="display:flex"', $html);
        $this->assertStringContainsString('id="cgCenterSkeleton" style="display:block"', $html);
        $this->assertStringContainsString('<div class="compact-chat-card" id="compactChatCard">', $html);

        $html = $this->render_page(['isresuming' => false]);

        $this->assertStringContainsString('class="courseai-workspace" id="courseaiWorkspace"', $html);
        $this->assertStringContainsString('id="planningView" style="display:none"', $html);
        $this->assertStringContainsString('<div class="compact-chat-card" id="compactChatCard" style="display:none">', $html);
    }

    /**
     * The sessions list renders the cards (escaped) or the empty state, and the inline data element.
     */
    public function test_sessions_list_and_inline_data(): void {
        $this->resetAfterTest();

        $html = $this->render_page();

        $this->assert_id_once($html, 'courseaiSessionsView');
        $this->assert_id_once($html, 'courseaiSessionsGrid');
        $this->assertStringContainsString('<div class="courseai-session-card" data-sessionid="7">', $html);
        $this->assertStringContainsString('Sample &amp; &quot;session&quot;', $html);
        $this->assertStringNotContainsString('Sample & "session"', $html);
        $this->assertStringContainsString('<div id="courseai-data" class="hidden"', $html);
        $this->assertStringContainsString('data-defaultlang="es"', $html);

        $html = $this->render_page(['hassessions' => false, 'sessions' => [], 'allsessions' => []]);

        $this->assertStringNotContainsString('courseaiSessionsGrid', $html);
        $this->assertStringContainsString('class="courseai-sessions-empty"', $html);
    }
}
