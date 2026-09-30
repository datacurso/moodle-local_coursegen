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

/**
 * Section-naming presets of the template configuration form.
 *
 * The visible words come from the language pack, while the substitution
 * tokens stay identical in every language.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\form\template_config_form
 */
final class template_config_form_naming_test extends \advanced_testcase {
    /**
     * Every preset keeps both substitution tokens untouched.
     */
    public function test_presets_keep_the_substitution_tokens(): void {
        $presets = template_config_form::naming_presets();

        $this->assertCount(4, $presets);
        foreach ($presets as $pattern => $label) {
            $this->assertStringContainsString('{N}', $pattern);
            $this->assertStringContainsString('{nombre}', $pattern);
            $this->assertSame($pattern, $label);
        }
    }

    /**
     * The presets are worded by the language pack, not by literals.
     */
    public function test_presets_come_from_the_language_pack(): void {
        $expected = [];
        foreach (['unit', 'module', 'topic', 'week'] as $kind) {
            $expected[] = get_string('template_naming_preset_' . $kind, 'local_coursegen');
        }

        $this->assertSame($expected, array_keys(template_config_form::naming_presets()));
    }

    /**
     * A fresh template starts with the first preset.
     */
    public function test_default_is_the_first_preset(): void {
        $presets = template_config_form::naming_presets();

        $this->assertSame(array_key_first($presets), template_config_form::default_naming_pattern());
    }

    /**
     * The English pack no longer carries Spanish wording.
     */
    public function test_english_presets_are_not_spanish(): void {
        foreach (array_keys(template_config_form::naming_presets()) as $pattern) {
            $this->assertStringNotContainsString('Unidad', $pattern);
            $this->assertStringNotContainsString('Módulo', $pattern);
            $this->assertStringNotContainsString('Semana', $pattern);
        }
    }
}
