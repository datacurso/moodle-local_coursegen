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

        $this->assertNotContains('local_coursegen/template/naming-pattern', $hooks);
        $this->assertNotContains('local_coursegen/template/custom-pattern', $hooks);
        $this->assertNotContains('local_coursegen/template/naming-start', $hooks);
        $this->assertNotContains('local_coursegen/template/max-sections', $hooks);
        $this->assertNotContains('local_coursegen/template/allow-add-sections', $hooks);
        $this->assertContains(template_name_form::HOOK_NAME, $hooks);
        $this->assertContains(template_name_form::HOOK_DESCRIPTION, $hooks);
    }

    /**
     * The template wizard no longer renders limits or section-name controls.
     */
    public function test_limits_and_section_naming_controls_are_not_in_the_wizard(): void {
        global $CFG;

        $wizard = file_get_contents($CFG->dirroot . '/local/coursegen/templates/template_wizard.mustache');
        $selectors = file_get_contents($CFG->dirroot . '/local/coursegen/amd/src/local/template/selectors.js');

        $form = file_get_contents($CFG->dirroot . '/local/coursegen/classes/form/template_config_form.php');
        $this->assertStringNotContainsString('maxsections', $form);
        $this->assertStringNotContainsString('allowaddsections', $form);
        $this->assertStringNotContainsString('namingpattern', $form);
        $this->assertStringNotContainsString('namingstart', $form);
        $this->assertStringNotContainsString('custompattern', $form);
        $this->assertStringNotContainsString('naming-pattern', $selectors);
        $this->assertStringNotContainsString('naming-start', $selectors);
        $this->assertStringNotContainsString('max-sections', $selectors);
        $this->assertStringNotContainsString('allow-add-sections', $selectors);
    }
}
