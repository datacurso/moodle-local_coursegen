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
 * Rendering tests for the guideline popover and preview Mustache templates.
 *
 * These templates are rendered from JavaScript (core/templates) on the course
 * AI creation page. Rendering them server-side with the same context shape
 * checks the markup contract the CSS and the delegated event handlers rely on,
 * and that user-supplied data is escaped by the template engine.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class guideline_templates_test extends \advanced_testcase {
    /**
     * Render one of the plugin's guideline templates.
     *
     * @param string $name Template name below local_coursegen/local/courseai/.
     * @param array $context Template context.
     * @return string Rendered HTML.
     */
    private function render(string $name, array $context): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('local_coursegen/local/courseai/' . $name, $context);
    }

    /**
     * Context for a representative guideline list.
     *
     * @return array
     */
    private function list_context(): array {
        return [
            'listlabel' => 'Guidelines list',
            'hasitems' => true,
            'items' => [
                ['id' => '7', 'name' => 'Quality policy', 'category' => 'General', 'selected' => true],
                ['id' => '9', 'name' => 'Tone & "voice"', 'category' => 'Style', 'selected' => false],
            ],
            'viewtitle' => 'View guideline',
            'emptytext' => 'No results',
        ];
    }

    /**
     * Context for a representative compact guideline list.
     *
     * @return array
     */
    private function compact_list_context(): array {
        return [
            'listlabel' => 'Guidelines list',
            'items' => [
                ['id' => '7', 'name' => 'Quality policy', 'selected' => true],
                ['id' => '9', 'name' => 'Tone & "voice"', 'selected' => false],
            ],
        ];
    }

    /**
     * Assert that the rendered HTML is a single, self-contained list element.
     *
     * The lists are rendered by JavaScript into a wrapper region and the whole
     * <ul> is replaced on every render, so the template must own the <ul>
     * (a bare <li> is not valid as a document fragment root).
     *
     * @param string $html Rendered HTML.
     * @param string $id Expected id of the list.
     * @param string $label Expected (already escaped) aria-label.
     */
    private function assert_listbox_wrapper(string $html, string $id, string $label): void {
        $open = '<ul class="pop-list" id="' . $id . '" role="listbox" aria-label="' . $label . '">';
        $this->assertStringStartsWith($open, trim($html));
        $this->assertStringEndsWith('</ul>', trim($html));
        $this->assertSame(1, substr_count($html, '<ul'));
        $this->assertSame(1, substr_count($html, '</ul>'));
    }

    /**
     * The preview body keeps the classes the CSS and Behat rely on.
     */
    public function test_preview_renders_category_subtitle_and_description(): void {
        $this->resetAfterTest();

        $html = $this->render('guideline_preview', [
            'category' => 'General',
            'fullcontexttext' => 'Complete content that will be sent to AI as context.',
            'description' => 'All courses must include a welcome forum.',
        ]);

        $this->assertStringContainsString('class="guideline-preview"', $html);
        $this->assertStringContainsString('class="preview-subtitle"', $html);
        $this->assertStringContainsString('<span class="preview-cat-badge">General</span>', $html);
        $this->assertStringContainsString(
            '<span class="preview-subtitle-text">Complete content that will be sent to AI as context.</span>',
            $html
        );
        $this->assertStringContainsString('class="preview-desc-box"', $html);
        $this->assertStringContainsString('<p>All courses must include a welcome forum.</p>', $html);
    }

    /**
     * HTML in the guideline data is escaped, not rendered.
     */
    public function test_preview_escapes_html_in_data(): void {
        $this->resetAfterTest();

        $html = $this->render('guideline_preview', [
            'category' => 'A & "B"',
            'fullcontexttext' => 'Full context',
            'description' => '<script>alert(1)</script>',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('A &amp; &quot;B&quot;', $html);
    }

    /**
     * The main list renders one item per guideline with the JS hook attributes.
     */
    public function test_list_renders_items_with_hooks_and_selected_class(): void {
        $this->resetAfterTest();

        $html = $this->render('guideline_list', $this->list_context());

        $this->assert_listbox_wrapper($html, 'guidelineList', 'Guidelines list');
        $this->assertSame(2, substr_count($html, '<li class="pop-item'));
        $this->assertStringContainsString('<li class="pop-item selected" data-id="7">', $html);
        $this->assertStringContainsString('<li class="pop-item" data-id="9">', $html);
        $this->assertStringContainsString('class="pop-select-btn" data-select="7" type="button"', $html);
        $this->assertStringContainsString('class="pop-eye-btn" data-preview="7" type="button" title="View guideline"', $html);
        $this->assertStringContainsString('<div class="pop-radio"><div class="pop-dot"></div></div>', $html);
        $this->assertStringContainsString('<span class="pop-item-name">Quality policy</span>', $html);
        $this->assertStringContainsString('<span class="pop-item-cat">Style</span>', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsString('pop-empty', $html);
    }

    /**
     * Names, categories and ids in the list are escaped.
     */
    public function test_list_escapes_html_in_data(): void {
        $this->resetAfterTest();

        $context = $this->list_context();
        $context['listlabel'] = 'A & "B"';
        $context['items'][0]['name'] = '<b>Bold</b>';
        $context['items'][0]['id'] = '1" onclick="x';

        $html = $this->render('guideline_list', $context);

        $this->assert_listbox_wrapper($html, 'guidelineList', 'A &amp; &quot;B&quot;');
        $this->assertStringNotContainsString('<b>Bold</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('data-id="1" onclick', $html);
        $this->assertStringContainsString('Tone &amp; &quot;voice&quot;', $html);
    }

    /**
     * With no matching guidelines the list shows only the empty message.
     */
    public function test_list_renders_empty_state_without_items(): void {
        $this->resetAfterTest();

        $html = $this->render('guideline_list', [
            'listlabel' => 'Guidelines list',
            'hasitems' => false,
            'items' => [],
            'viewtitle' => 'View guideline',
            'emptytext' => 'No <results>',
        ]);

        $this->assert_listbox_wrapper($html, 'guidelineList', 'Guidelines list');
        $this->assertStringContainsString('<li class="pop-empty" role="presentation">No &lt;results&gt;</li>', $html);
        $this->assertStringNotContainsString('pop-item', $html);
    }

    /**
     * The compact list marks the selected guideline and keeps the option hooks.
     */
    public function test_compact_list_renders_items_and_active_marker(): void {
        $this->resetAfterTest();

        $html = $this->render('guideline_list_compact', $this->compact_list_context());

        $this->assert_listbox_wrapper($html, 'guidelineListCompact', 'Guidelines list');
        $this->assertSame(2, substr_count($html, '<li class="pop-item'));
        $this->assertStringContainsString('<li class="pop-item active" role="option" data-id="7" tabindex="-1">', $html);
        $this->assertStringContainsString('<li class="pop-item" role="option" data-id="9" tabindex="-1">', $html);
        $this->assertSame(1, substr_count($html, '<span class="pop-item-check">'));
        $this->assertStringContainsString('<span class="pop-item-name">Tone &amp; &quot;voice&quot;</span>', $html);
        $this->assertSame(2, substr_count($html, '<svg'));
    }

    /**
     * The compact list escapes HTML in guideline names.
     */
    public function test_compact_list_escapes_html_in_data(): void {
        $this->resetAfterTest();

        $html = $this->render('guideline_list_compact', [
            'listlabel' => 'A & "B"',
            'items' => [
                ['id' => '7', 'name' => '<img src=x onerror=alert(1)>', 'selected' => false],
            ],
        ]);

        $this->assert_listbox_wrapper($html, 'guidelineListCompact', 'A &amp; &quot;B&quot;');
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    /**
     * With no items the compact list is an empty listbox, not a bare fragment.
     */
    public function test_compact_list_renders_empty_listbox_without_items(): void {
        $this->resetAfterTest();

        $html = $this->render('guideline_list_compact', [
            'listlabel' => 'Guidelines list',
            'items' => [],
        ]);

        $this->assert_listbox_wrapper($html, 'guidelineListCompact', 'Guidelines list');
        $this->assertStringNotContainsString('<li', $html);
        $this->assertMatchesRegularExpression('~<ul[^>]*>\s*</ul>~', $html);
    }
}
