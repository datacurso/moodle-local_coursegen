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

namespace local_coursegen\output;

/**
 * Unit tests for template_row_options — no course/modinfo involved, just the
 * option-array builders in isolation.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\output\template_row_options
 */
final class template_row_options_test extends \advanced_testcase {
    /**
     * An unrecognised scope value (never persisted by save_template, but
     * this class must still be defensive on its own) falls back to
     * "course" rather than being echoed back as the active option.
     */
    public function test_template_scope_options_falls_back_to_course_on_invalid_value(): void {
        $options = template_row_options::template_scope_options(42, 'nonsense');

        $this->assertCount(2, $options);
        $active = $this->active_options($options);
        $this->assertCount(1, $active);
        $this->assertSame('course', $active[0]['value']);
    }

    /**
     * An empty-string scope value also falls back to "course".
     */
    public function test_template_scope_options_falls_back_to_course_on_empty_value(): void {
        $options = template_row_options::template_scope_options(42, '');

        $active = $this->active_options($options);
        $this->assertSame('course', $active[0]['value']);
    }

    /**
     * "section" is preserved as the active option when it is a recognised
     * value.
     */
    public function test_template_scope_options_preserves_section_scope(): void {
        $options = template_row_options::template_scope_options(42, 'section');

        $active = $this->active_options($options);
        $this->assertSame('section', $active[0]['value']);
    }

    /**
     * active_action() returns the value flagged active, defaulting to
     * "keep" if somehow none of the options carry that flag.
     */
    public function test_active_action_returns_the_flagged_value(): void {
        $flagged = template_row_options::active_action([
            ['value' => 'keep', 'active' => false],
            ['value' => 'template', 'active' => true],
        ]);
        $this->assertSame('template', $flagged);

        $none = template_row_options::active_action([
            ['value' => 'keep', 'active' => false],
            ['value' => 'template', 'active' => false],
        ]);
        $this->assertSame('keep', $none);

        $empty = template_row_options::active_action([]);
        $this->assertSame('keep', $empty);
    }

    /**
     * activity_actions() never offers "instance", for either an
     * AI-supported type or an unsupported one — "template" is the only
     * action still gated to ai_activity_types::MODNAMES, and "space" is offered for
     * every type.
     */
    public function test_activity_actions_never_offers_instance(): void {
        $supported = template_row_options::activity_actions(1, 'page');
        $supportedvalues = array_column($supported, 'value');
        $this->assertSame(['template', 'keep', 'reference', 'exclude', 'space'], $supportedvalues);

        $unsupported = template_row_options::activity_actions(1, 'lti');
        $unsupportedvalues = array_column($unsupported, 'value');
        $this->assertSame(['keep', 'reference', 'exclude', 'space'], $unsupportedvalues);
    }

    /**
     * A brand-new row (no saved action) always defaults to "keep", whether
     * or not the module type supports "template".
     */
    public function test_activity_actions_defaults_to_keep_regardless_of_ai_support(): void {
        $supported = template_row_options::activity_actions(1, 'page');
        $supportedaction = template_row_options::active_action($supported);
        $this->assertSame('keep', $supportedaction);

        $unsupported = template_row_options::activity_actions(1, 'lti');
        $unsupportedaction = template_row_options::active_action($unsupported);
        $this->assertSame('keep', $unsupportedaction);
    }

    /**
     * A saved "template" action on an unsupported type degrades to "keep".
     */
    public function test_activity_actions_degrades_saved_template_on_unsupported_type(): void {
        $options = template_row_options::activity_actions(1, 'lti', 'template');
        $action = template_row_options::active_action($options);
        $this->assertSame('keep', $action);
    }

    /**
     * A saved action of "instance" (never offered for a real activity)
     * degrades to "keep" on re-render, for both an AI-supported and an
     * unsupported module type — the hydration guard simply finds "instance"
     * is not in the offered keys and falls through to the default.
     */
    public function test_activity_actions_degrades_saved_instance_to_keep(): void {
        $supported = template_row_options::activity_actions(1, 'page', 'instance');
        $supportedaction = template_row_options::active_action($supported);
        $this->assertSame('keep', $supportedaction);

        $unsupported = template_row_options::activity_actions(1, 'lti', 'instance');
        $unsupportedaction = template_row_options::active_action($unsupported);
        $this->assertSame('keep', $unsupportedaction);
    }

    /**
     * active_scope_label() returns the label of whichever option is
     * flagged active.
     */
    public function test_active_scope_label_returns_the_flagged_options_label(): void {
        $options = template_row_options::template_scope_options(1, 'section');
        $expected = get_string('template_activity_scope_section', 'local_coursegen');
        $label = template_row_options::active_scope_label($options);
        $this->assertSame($expected, $label);
    }

    /**
     * With no option flagged active (an empty array, defensively), the
     * label falls back to "Whole course" rather than throwing or returning
     * nothing.
     */
    public function test_active_scope_label_falls_back_to_course_when_none_flagged(): void {
        $expected = get_string('template_activity_scope_course', 'local_coursegen');
        $label = template_row_options::active_scope_label([]);
        $this->assertSame($expected, $label);
    }

    /**
     * The options flagged active, in their original order.
     *
     * @param array $options Select option contexts.
     * @return array
     */
    private function active_options(array $options): array {
        $active = [];
        foreach ($options as $option) {
            if ($option['active']) {
                $active[] = $option;
            }
        }
        return $active;
    }
}
