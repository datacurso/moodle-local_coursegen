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
use local_coursegen\local\models\template;

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
            $this->assertStringContainsString(template_config_form::NAMING_TOKEN_NUMBER, $pattern);
            $this->assertStringContainsString(template_config_form::NAMING_TOKEN_NAME, $pattern);
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

        $presets = template_config_form::naming_presets();
        $patterns = array_keys($presets);

        $this->assertSame($expected, $patterns);
    }

    /**
     * A fresh template starts with the first preset.
     */
    public function test_default_is_the_first_preset(): void {
        $presets = template_config_form::naming_presets();

        $first = array_key_first($presets);
        $default = template_config_form::default_naming_pattern();

        $this->assertSame($first, $default);
    }

    /**
     * The English pack no longer carries Spanish wording.
     */
    public function test_english_presets_are_not_spanish(): void {
        $presets = template_config_form::naming_presets();
        $patterns = array_keys($presets);
        foreach ($patterns as $pattern) {
            $this->assertStringNotContainsString('Unidad', $pattern);
            $this->assertStringNotContainsString('Módulo', $pattern);
            $this->assertStringNotContainsString('Semana', $pattern);
        }
    }

    /**
     * The naming select offers the presets, then the name alone, then the custom entry.
     */
    public function test_options_hold_the_presets_the_name_alone_and_the_custom_entry(): void {
        $presets = template_config_form::naming_presets();

        $options = template_config_form::naming_options();

        $keys = array_keys($options);
        $presetkeys = array_keys($presets);
        $tail = [template_config_form::NAMING_TOKEN_NAME, template_config_form::NAMING_CUSTOM];
        $expected = array_merge($presetkeys, $tail);
        $this->assertSame($expected, $keys);
    }

    /**
     * A fresh template selects the first preset and has no custom text.
     */
    public function test_a_fresh_template_selects_the_default_preset_with_no_custom_text(): void {
        $options = template_config_form::naming_options();

        [$selected, $custom] = template_config_form::naming_defaults(null, $options);

        $default = template_config_form::default_naming_pattern();
        $this->assertSame($default, $selected);
        $this->assertSame('', $custom);
    }

    /**
     * A saved pattern that is one of the presets selects that preset.
     */
    public function test_a_saved_preset_selects_that_preset(): void {
        $options = template_config_form::naming_options();
        $presets = template_config_form::naming_presets();
        $patterns = array_keys($presets);
        $saved = $patterns[1];
        $template = new template(0, (object) ['namingpattern' => $saved]);

        [$selected, $custom] = template_config_form::naming_defaults($template, $options);

        $this->assertSame($saved, $selected);
        $this->assertSame('', $custom);
    }

    /**
     * The name-only pattern is an option of its own, not a custom one.
     */
    public function test_a_saved_name_only_pattern_selects_the_name_only_option(): void {
        $options = template_config_form::naming_options();
        $nameonly = template_config_form::NAMING_TOKEN_NAME;
        $template = new template(0, (object) ['namingpattern' => $nameonly]);

        [$selected, $custom] = template_config_form::naming_defaults($template, $options);

        $this->assertSame($nameonly, $selected);
        $this->assertSame('', $custom);
    }

    /**
     * A saved pattern that is not an option goes through the custom entry,
     * with its text restored.
     */
    public function test_a_saved_pattern_that_is_not_an_option_goes_through_custom(): void {
        $options = template_config_form::naming_options();
        $typed = 'Chapter {N} - {name}';
        $template = new template(0, (object) ['namingpattern' => $typed]);

        [$selected, $custom] = template_config_form::naming_defaults($template, $options);

        $this->assertSame(template_config_form::NAMING_CUSTOM, $selected);
        $this->assertSame($typed, $custom);
    }

    /**
     * A template saved with an empty pattern behaves like a fresh one.
     */
    public function test_an_empty_saved_pattern_falls_back_to_the_default(): void {
        $options = template_config_form::naming_options();
        $template = new template(0, (object) ['namingpattern' => '']);

        [$selected, $custom] = template_config_form::naming_defaults($template, $options);

        $default = template_config_form::default_naming_pattern();
        $this->assertSame($default, $selected);
        $this->assertSame('', $custom);
    }

    /**
     * The client is told the tokens and the custom value, so it holds no copy of them.
     */
    public function test_the_naming_contract_exposes_the_tokens_and_the_custom_value(): void {
        $contract = template_config_form::naming_contract();

        $expected = [
            'customvalue' => template_config_form::NAMING_CUSTOM,
            'numbertoken' => template_config_form::NAMING_TOKEN_NUMBER,
            'nametoken' => template_config_form::NAMING_TOKEN_NAME,
        ];
        $this->assertSame($expected, $contract);
    }

    /**
     * A fresh template starts with one extra section.
     */
    public function test_a_fresh_template_starts_with_one_extra_section(): void {
        $extra = template_config_form::default_extra_sections(null);

        $this->assertSame(1, $extra);
    }

    /**
     * A saved allowance of zero falls back to one, a positive one is kept.
     *
     * @dataProvider saved_allowance_provider
     * @param int $saved The saved maxsections.
     * @param int $expected The default the form shows.
     */
    public function test_the_saved_extra_sections_allowance_is_kept_only_when_positive(int $saved, int $expected): void {
        $template = new template(0, (object) ['maxsections' => $saved]);

        $extra = template_config_form::default_extra_sections($template);

        $this->assertSame($expected, $extra);
    }

    /**
     * Saved allowances and the default each one gives.
     *
     * @return array
     */
    public static function saved_allowance_provider(): array {
        return [
            'zero falls back to one' => [0, 1],
            'one is kept' => [1, 1],
            'a larger allowance is kept' => [7, 7],
        ];
    }
}
