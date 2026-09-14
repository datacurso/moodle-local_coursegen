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

use local_coursegen\output\sections_config;

/**
 * "Course sections" review: the per-row action select (including the
 * "Use as template" action, its badge and its scope select), and the single
 * global bulk action bar.
 *
 * "Use as template" marks an activity as a structural mold: it is gated the
 * same way "Allow AI modification" is (only offered for module types in
 * template_content_generator::AI_SUPPORTED_TYPES), renders a visible
 * "Template" badge, and reveals a scope select (course-wide or
 * section-only) that only matters for that action.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\output\sections_config
 * @covers     \local_coursegen\output\template_row_options
 */
final class course_sections_actions_test extends \advanced_testcase {
    use sections_config_fixture_trait;

    /**
     * Each activity row carries an action select preselected with the
     * server-side default: modify for an AI-supported type, keep otherwise —
     * an unsupported type must not offer the modify NOR the template option.
     */
    public function test_render_activity_select_offers_the_actions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page, , $lti] = $this->create_course_fixture();

        $html = sections_config::render(get_fast_modinfo($course));

        $this->assertStringNotContainsString('data-act-val=', $html);

        // AI-supported type (page): all five actions, modify preselected.
        $pageselect = $this->extract_action_select($html, (int) $page->cmid);
        $this->assertSame(5, substr_count($pageselect, '<option'));
        foreach (['modify', 'template', 'keep', 'reference', 'exclude'] as $action) {
            $this->assertStringContainsString('<option value="' . $action . '"', $pageselect);
        }
        $this->assertMatchesRegularExpression('/<option value="modify"[^>]*\sselected/', $pageselect);
        $this->assertStringContainsString(get_string('template_activity_modify', 'local_coursegen'), $pageselect);
        $this->assertStringContainsString(get_string('template_activity_template', 'local_coursegen'), $pageselect);
        $this->assertStringContainsString(get_string('template_activity_reference', 'local_coursegen'), $pageselect);
        $this->assertStringContainsString('Allow AI modification', $pageselect);

        // Unsupported type (lti): modify AND template omitted, keep preselected.
        $ltiselect = $this->extract_action_select($html, (int) $lti->cmid);
        $this->assertSame(3, substr_count($ltiselect, '<option'));
        $this->assertStringNotContainsString('<option value="modify"', $ltiselect);
        $this->assertStringNotContainsString('<option value="template"', $ltiselect);
        $this->assertMatchesRegularExpression('/<option value="keep"[^>]*\sselected/', $ltiselect);
    }

    /**
     * A row whose default (or saved) action is NOT "template" renders the
     * "Template" badge and the scope select, but both start hidden
     * (d-none) — sections_events.js is what reveals them on selection.
     */
    public function test_render_hides_template_badge_and_scope_by_default(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();

        $html = sections_config::render(get_fast_modinfo($course));

        $this->assertStringContainsString('data-region="template-badge" data-id="' . $page->cmid . '"', $html);
        $badgestart = strpos($html, 'data-region="template-badge" data-id="' . $page->cmid . '"');
        $badgetagstart = strrpos(substr($html, 0, $badgestart), '<span');
        $badgetag = substr($html, $badgetagstart, $badgestart - $badgetagstart);
        $this->assertStringContainsString('d-none', $badgetag);

        $scopeselect = $this->extract_scope_select($html, (int) $page->cmid);
        $selecttagend = strpos($scopeselect, '>');
        $this->assertStringContainsString('d-none', substr($scopeselect, 0, $selecttagend));
    }

    /**
     * The scope select always offers exactly "Whole course" and "This
     * section only", defaulting to "Whole course" when nothing is saved.
     */
    public function test_render_scope_select_offers_course_and_section(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();

        $html = sections_config::render(get_fast_modinfo($course));

        $scopeselect = $this->extract_scope_select($html, (int) $page->cmid);
        $this->assertSame(2, substr_count($scopeselect, '<option'));
        $this->assertStringContainsString('<option value="course"', $scopeselect);
        $this->assertStringContainsString('<option value="section"', $scopeselect);
        $this->assertMatchesRegularExpression('/<option value="course"[^>]*\sselected/', $scopeselect);
        $this->assertStringContainsString(get_string('template_activity_scope_course', 'local_coursegen'), $scopeselect);
        $this->assertStringContainsString(get_string('template_activity_scope_section', 'local_coursegen'), $scopeselect);
    }

    /**
     * ONE global bulk action bar renders below all the section cards, with
     * a label, a select born disabled, a choosedots placeholder plus the
     * five per-activity actions (including "Use as template").
     */
    public function test_render_offers_a_single_global_bulk_bar(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();

        $html = sections_config::render(get_fast_modinfo($course));

        $this->assertSame(1, substr_count($html, 'data-region="bulk-action"'));
        $this->assertStringNotContainsString('data-region="bulk-action" data-sid', $html);

        $this->assertStringContainsString(
            get_string('template_with_selected_activities', 'local_coursegen'),
            $html
        );

        $start = strpos($html, 'data-region="bulk-action"');
        $end = strpos($html, '</select>', $start);
        $bulkselect = substr($html, $start, $end - $start);
        $this->assertStringContainsString('disabled', $bulkselect);
        $this->assertSame(6, substr_count($bulkselect, '<option'));
        $this->assertMatchesRegularExpression('/<option value=""[^>]*\sselected/', $bulkselect);
        $this->assertStringContainsString(get_string('choosedots'), $bulkselect);
        foreach (['modify', 'template', 'keep', 'reference', 'exclude'] as $action) {
            $this->assertStringContainsString('<option value="' . $action . '"', $bulkselect);
        }
    }
}
