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
 * Start screen contract of the AI course creation page.
 *
 * A fresh visit opens on two cards, free creation or from a template. Each
 * card is a link that opens its own path; the top bar then names the path and
 * leads back to the cards, until planning starts and the choice is fixed. A
 * user who can create only one way never sees the cards nor the crumb.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen
 */
final class courseai_page_start_chooser_test extends \advanced_testcase {
    /** @var string Pattern of the crumb button in the top bar. */
    private const CRUMB = '/<button class="courseai-topbar-path[^"]*"[^>]*data-region="local_coursegen\/start\/path-back"/';

    /**
     * Render the courseai_page template as the page script does for one state.
     *
     * @param string $state One of: chooser, free, template.
     * @param array $overrides Context values replacing the state's defaults.
     * @return string Rendered HTML.
     */
    private function render_page(string $state, array $overrides = []): string {
        global $OUTPUT;

        $closeurl = new \moodle_url('/my/courses.php');
        $closeurltext = $closeurl->out(false);
        $context = [
            'guidelines' => '[]',
            'coursetemplates' => [],
            'templatepickerformhtml' => '<div class="tpl-picker-sentinel"></div>',
            'templateemptystatehtml' => '<div class="tpl-empty-sentinel"></div>',
            'hascoursetemplates' => false,
            'languages' => '[]',
            'defaultlang' => 'en',
            'logourl' => '',
            'hassessions' => false,
            'sessions' => [],
            'allsessions' => [],
            'isresuming' => false,
            'showsessionsview' => false,
            'templatemodeactive' => $state === 'template',
            'startchooser' => $state === 'chooser',
            'freemodeactive' => $state === 'free',
            'showstartcrumb' => $state !== 'chooser',
            'startcrumblocked' => false,
            'subsectionsenabled' => false,
            'closeurl' => $closeurltext,
        ];
        $merged = $overrides + $context;

        return $OUTPUT->render_from_template('local_coursegen/courseai_page', $merged);
    }

    /**
     * A fresh visit shows the two cards and nothing of either path.
     */
    public function test_fresh_visit_renders_only_the_two_cards(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page('chooser');

        $this->assertMatchesRegularExpression('/class="courseai-workspace[^"]*\bis-choosing\b/', $html);
        $this->assertStringContainsString('href="aicoursecreation.php?mode=free"', $html);
        $this->assertStringContainsString('href="aicoursecreation.php?mode=template"', $html);
        $this->assertSame(2, substr_count($html, 'class="courseai-start-card '), 'Exactly two cards');
        $this->assertStringNotContainsString('id="promptInput"', $html);
        $this->assertStringNotContainsString('data-region="tpl-left-panel"', $html);
        $this->assertStringNotContainsString('data-region="tpl-main-column"', $html);
        $this->assertStringNotContainsString('courseai-mode-seg', $html);
    }

    /**
     * The cards carry the title and the description of each path.
     */
    public function test_cards_are_named_and_described(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page('chooser');

        $strings = [
            'courseai_start_title',
            'courseai_start_subtitle',
            'courseai_mode_free',
            'courseai_mode_template',
            'courseai_start_free_desc',
            'courseai_start_template_desc',
            'courseai_start_new',
            'courseai_start_note',
        ];
        foreach ($strings as $identifier) {
            $text = get_string($identifier, 'local_coursegen');
            $this->assertStringContainsString($text, $html, "String {$identifier} missing");
        }
    }

    /**
     * The crumb is not rendered on the cards themselves: there is no path to name yet.
     */
    public function test_cards_have_no_path_crumb(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page('chooser');

        $this->assertDoesNotMatchRegularExpression(self::CRUMB, $html);
    }

    /**
     * The free path renders the composer, names itself in the top bar and
     * offers the way back to the cards.
     */
    public function test_free_path_renders_composer_and_enabled_crumb(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page('free');

        $this->assertDoesNotMatchRegularExpression('/class="courseai-workspace[^"]*\bis-choosing\b/', $html);
        $this->assertStringNotContainsString('courseai-start-card', $html);
        $this->assertStringContainsString('id="promptInput"', $html);
        $this->assertMatchesRegularExpression(self::CRUMB, $html);
        $this->assertStringContainsString('data-url="aicoursecreation.php"', $html);
        $this->assertDoesNotMatchRegularExpression('/<button class="courseai-topbar-path[^>]*\bdisabled\b/', $html);
        $name = get_string('courseai_mode_free', 'local_coursegen');
        $quotedname = preg_quote($name, '/');
        $namepattern = '/courseai-topbar-path-name">' . $quotedname . '</';
        $this->assertMatchesRegularExpression($namepattern, $html);
        $this->assertStringNotContainsString('courseai-mode-seg', $html);
    }

    /**
     * The template path renders the template column and names itself in the top bar.
     */
    public function test_template_path_renders_column_and_names_itself(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page('template');

        $this->assertMatchesRegularExpression('/class="courseai-workspace[^"]*\bis-template\b/', $html);
        $this->assertStringContainsString('data-region="tpl-left-panel"', $html);
        $this->assertStringNotContainsString('id="promptInput"', $html);
        $this->assertMatchesRegularExpression(self::CRUMB, $html);
        $name = get_string('courseai_mode_template', 'local_coursegen');
        $quotedname = preg_quote($name, '/');
        $namepattern = '/courseai-topbar-path-name">' . $quotedname . '</';
        $this->assertMatchesRegularExpression($namepattern, $html);
    }

    /**
     * Once planning has started the crumb is disabled and only names the path.
     */
    public function test_locked_crumb_is_disabled_with_its_own_tooltip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page('free', ['startcrumblocked' => true, 'isresuming' => true]);

        $this->assertMatchesRegularExpression('/<button class="courseai-topbar-path is-locked"[^>]*\bdisabled\b/', $html);
        $tooltip = get_string('courseai_start_locked', 'local_coursegen');
        $quotedtooltip = preg_quote($tooltip, '/');
        $tooltippattern = '/\btitle="' . $quotedtooltip . '"/';
        $this->assertMatchesRegularExpression($tooltippattern, $html);
    }

    /**
     * A user who can create only one way gets neither the cards nor the crumb.
     */
    public function test_single_mode_user_sees_no_cards_and_no_crumb(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page('free', ['showstartcrumb' => false]);

        $this->assertStringNotContainsString('courseai-start-card', $html);
        $this->assertDoesNotMatchRegularExpression(self::CRUMB, $html);
        $this->assertStringContainsString('id="promptInput"', $html);
    }
}
