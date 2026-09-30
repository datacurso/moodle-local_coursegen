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

use local_coursegen\form\template_config_form;
use local_coursegen\form\template_name_form;

/**
 * The hooks the template editor script finds its elements by.
 *
 * The selectors live in one script module; every hook it names that belongs
 * to this plugin has to be written by a template or a form, or the script
 * would silently stop finding that element.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\form\template_config_form
 * @covers     \local_coursegen\form\template_name_form
 */
final class template_editor_hooks_test extends \advanced_testcase {
    /** @var string The hook namespace the plugin's own data-region and data-action values share. */
    private const NAMESPACE = 'local_coursegen/template/';

    /**
     * Every namespaced hook value found in the selectors module.
     *
     * @return string[]
     */
    private function hooks_in_selectors(): array {
        global $CFG;

        $path = $CFG->dirroot . '/local/coursegen/amd/src/local/template/selectors.js';
        $source = file_get_contents($path);
        $namespace = preg_quote(self::NAMESPACE, '~');
        $pattern = '~"(' . $namespace . '[a-z-]+)"~';
        preg_match_all($pattern, $source, $matches);
        $unique = array_unique($matches[1]);
        return array_values($unique);
    }

    /**
     * Everything that can write a hook: the templates and the form classes.
     *
     * @return string
     */
    private function markup_sources(): string {
        global $CFG;

        $root = $CFG->dirroot . '/local/coursegen/';
        $files = glob($root . 'templates/*.mustache');
        $files[] = $root . 'classes/form/template_config_form.php';
        $files[] = $root . 'classes/form/template_name_form.php';
        $sources = array_map('file_get_contents', $files);
        return implode("\n", $sources);
    }

    /**
     * The hooks that no template or form writes.
     *
     * @param string[] $hooks
     * @param string $sources
     * @return string[]
     */
    private function missing_from(array $hooks, string $sources): array {
        $missing = [];
        foreach ($hooks as $hook) {
            if (!str_contains($sources, $hook)) {
                $missing[] = $hook;
            }
        }
        return $missing;
    }

    /**
     * Every namespaced hook of the selectors module is written by some template or form.
     */
    public function test_every_namespaced_selector_hook_is_written_by_the_markup(): void {
        $hooks = $this->hooks_in_selectors();
        $sources = $this->markup_sources();

        $this->assertNotEmpty($hooks);
        $missing = $this->missing_from($hooks, $sources);
        $this->assertSame([], $missing);
    }

    /**
     * The naming preview shows a section name holding markup as plain text.
     */
    public function test_the_naming_preview_escapes_section_names(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $context = ['lines' => [['text' => 'Unit 1: <img src=x onerror=alert(1)>']]];
        $html = $OUTPUT->render_from_template('local_coursegen/template_naming_preview', $context);

        $this->assertStringContainsString('Unit 1: &lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    /**
     * The naming preview prints its label from the language pack, then one line per section.
     */
    public function test_the_naming_preview_has_a_label_and_one_line_per_section(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $context = ['lines' => [['text' => 'A'], ['text' => 'B']]];
        $html = $OUTPUT->render_from_template('local_coursegen/template_naming_preview', $context);

        $label = get_string('template_naming_preview', 'local_coursegen');
        $this->assertStringContainsString($label, $html);
        $count = substr_count($html, '<small');
        $this->assertSame(3, $count);
    }

    /**
     * The naming preview is empty but for its label when the course has no sections.
     */
    public function test_the_naming_preview_with_no_sections_only_has_the_label(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('local_coursegen/template_naming_preview', ['lines' => []]);

        $count = substr_count($html, '<small');
        $this->assertSame(1, $count);
    }

    /**
     * The preview box carries the hook the script renders the preview into.
     */
    public function test_the_preview_box_carries_its_region_hook(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('local_coursegen/template_naming_preview_box', []);

        $this->assertStringContainsString('data-region="local_coursegen/template/naming-preview"', $html);
    }

    /**
     * A new space row prints the icon markup it is given and carries its hooks.
     */
    public function test_a_space_row_prints_the_icon_and_carries_its_hooks(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $context = [
            'spaceid' => 'new-1',
            'name' => 'File',
            'typelabel' => 'File',
            'modname' => 'resource',
            'icon' => '<img src="x.svg" class="icon activityicon" alt="">',
            'requiredvalue' => 1,
            'badge' => 'Space',
            'instruction' => '',
            'hasinstruction' => false,
        ];
        $html = $OUTPUT->render_from_template('local_coursegen/template_space_row', $context);

        $this->assertStringContainsString('<img src="x.svg" class="icon activityicon" alt="">', $html);
        $this->assertStringContainsString('data-region="local_coursegen/template/space-row"', $html);
        $this->assertStringContainsString('data-action="local_coursegen/template/space-edit"', $html);
        $this->assertStringContainsString('data-action="local_coursegen/template/space-remove"', $html);
        $this->assertStringContainsString('data-region="local_coursegen/template/type-label"', $html);
    }

    /**
     * The add menu and the template picker menu carry the hooks their clicks are routed by.
     */
    public function test_the_menus_carry_their_action_hooks(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $add = $OUTPUT->render_from_template('local_coursegen/template_add_menu', []);
        $options = [[
            'sourcecmid' => 7, 'name' => 'Lesson', 'typelabel' => 'Lesson', 'modname' => 'lesson',
            'iconurl' => 'x.svg', 'disabled' => false, 'scopehint' => '', 'tooltip' => '',
        ]];
        $picker = $OUTPUT->render_from_template('local_coursegen/template_instance_menu', [
            'hasoptions' => true,
            'options' => $options,
        ]);

        $this->assertStringContainsString('data-action="local_coursegen/template/menu-from-template"', $add);
        $this->assertStringContainsString('data-action="local_coursegen/template/menu-add-space"', $add);
        $this->assertStringContainsString('data-action="local_coursegen/template/menu-back"', $picker);
        $this->assertStringContainsString('data-action="local_coursegen/template/pick-template-source"', $picker);
    }

    /**
     * A disabled picker option is not a pick: it carries no pick action.
     */
    public function test_a_disabled_picker_option_carries_no_pick_action(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $options = [[
            'sourcecmid' => 7, 'name' => 'Lesson', 'typelabel' => 'Lesson', 'modname' => 'lesson',
            'iconurl' => 'x.svg', 'disabled' => true, 'scopehint' => '', 'tooltip' => 'No',
        ]];
        $picker = $OUTPUT->render_from_template('local_coursegen/template_instance_menu', [
            'hasoptions' => true,
            'options' => $options,
        ]);

        $this->assertStringNotContainsString('pick-template-source', $picker);
    }

    /**
     * The hook names the forms write are the ones the script looks for.
     */
    public function test_the_form_hook_constants_match_the_selectors(): void {
        $hooks = $this->hooks_in_selectors();

        $this->assertContains(template_config_form::HOOK_NAMING_PATTERN, $hooks);
        $this->assertContains(template_config_form::HOOK_CUSTOM_PATTERN, $hooks);
        $this->assertContains(template_config_form::HOOK_NAMING_START, $hooks);
        $this->assertContains(template_config_form::HOOK_MAX_SECTIONS, $hooks);
        $this->assertContains(template_config_form::HOOK_ALLOW_ADD_SECTIONS, $hooks);
        $this->assertContains(template_name_form::HOOK_NAME, $hooks);
        $this->assertContains(template_name_form::HOOK_DESCRIPTION, $hooks);
    }
}
