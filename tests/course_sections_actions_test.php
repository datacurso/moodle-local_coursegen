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

defined('MOODLE_INTERNAL') || die();

// The shared fixture trait sits in tests/ root, outside the tests/classes
// autoload scope, so it must be required explicitly.
require_once(__DIR__ . '/sections_config_fixture_trait.php');

use local_coursegen\output\sections_config;

/**
 * "Course sections" review: the per-row action select (including the
 * "Use as template" action and its clickable "Template" tag), and the
 * single global bulk action bar.
 *
 * "Use as template" marks an activity as a structural mold: it is the only
 * action gated to module types in
 * ai_activity_types::MODNAMES, and renders a visible
 * "Template" tag next to the activity name. Its scope (course-wide or
 * section-only) is set through local/template/template_scope_modal.js, not
 * a select rendered in the row — the tag just carries both possible labels
 * as data attributes so that modal can update it without a server round trip.
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
     * server-side default, "keep" unconditionally — "instance" is never
     * offered any more, for either an AI-supported or an unsupported type.
     */
    public function test_render_activity_select_offers_the_actions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page, , $lti] = $this->create_course_fixture();

        $modinfo = get_fast_modinfo($course);
        $html = sections_config::render($modinfo);

        $this->assertStringNotContainsString('data-act-val=', $html);

        // Every type gets the same minimal pair; keep is the default.
        $pageselect = $this->extract_action_select($html, (int) $page->cmid);
        $pageoptions = substr_count($pageselect, '<option');
        $this->assertSame(2, $pageoptions);
        foreach (['template', 'keep'] as $action) {
            $this->assertStringContainsString('<option value="' . $action . '"', $pageselect);
        }
        $this->assertStringNotContainsString('<option value="space"', $pageselect);
        $this->assertStringNotContainsString('<option value="instance"', $pageselect);
        $this->assertMatchesRegularExpression('/<option value="keep"[^>]*\sselected/', $pageselect);
        $templatelabel = get_string('template_activity_template', 'local_coursegen');
        $this->assertStringContainsString($templatelabel, $pageselect);

        // Unsupported type (lti): it still gets the same two choices.
        $ltiselect = $this->extract_action_select($html, (int) $lti->cmid);
        $ltioptions = substr_count($ltiselect, '<option');
        $this->assertSame(2, $ltioptions);
        $this->assertStringNotContainsString('<option value="space"', $ltiselect);
        $this->assertStringNotContainsString('<option value="instance"', $ltiselect);
        $this->assertStringContainsString('<option value="template"', $ltiselect);
        $this->assertMatchesRegularExpression('/<option value="keep"[^>]*\sselected/', $ltiselect);
    }

    /**
     * Legacy space actions are not offered in the simplified editor.
     */
    public function test_render_offers_space_only_for_a_file_resource(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, , $forum] = $this->create_course_fixture();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id, 'section' => 1]);

        $modinfo = get_fast_modinfo($course);
        $html = sections_config::render($modinfo);

        $resourceselect = $this->extract_action_select($html, (int) $resource->cmid);
        $this->assertStringNotContainsString('<option value="space"', $resourceselect);
        $forumselect = $this->extract_action_select($html, (int) $forum->cmid);
        $this->assertStringNotContainsString('<option value="space"', $forumselect);
    }

    /**
     * A row whose default (or saved) action is NOT "template" renders the
     * "Template" tag, but it starts hidden (d-none) and showing the
     * "Whole course" label — sections_events.js is what reveals it (and
     * opens the scope modal) on selection.
     */
    public function test_render_hides_template_scope_badge_by_default(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();

        $modinfo = get_fast_modinfo($course);
        $html = sections_config::render($modinfo);

        $tag = $this->extract_template_tag($html, (int) $page->cmid);
        $this->assertStringContainsString('d-none', $tag);
    }

    /**
     * The tag always carries both possible scope labels as data attributes,
     * composed with the shared "Template · {label}" string, regardless of
     * which one is currently active — template_row_scope.js swaps between
     * them after a scope change without any further server round trip.
     */
    public function test_render_preserves_template_scope_labels(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();

        $modinfo = get_fast_modinfo($course);
        $html = sections_config::render($modinfo);

        $tag = $this->extract_template_tag($html, (int) $page->cmid);
        $this->assertStringContainsString('data-tag-course=', $tag);
        $this->assertStringContainsString('data-tag-section=', $tag);
    }

    /**
     * ONE global bulk action bar offers the two supported per-activity actions.
     */
    public function test_render_offers_a_single_global_bulk_bar(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();

        $modinfo = get_fast_modinfo($course);
        $html = sections_config::render($modinfo);

        $barcount = substr_count($html, 'data-region="bulk-action"');
        $this->assertSame(1, $barcount);
        $this->assertStringNotContainsString('data-region="bulk-action" data-sid', $html);

        $withselectedlabel = get_string('template_with_selected_activities', 'local_coursegen');
        $this->assertStringContainsString($withselectedlabel, $html);

        $start = strpos($html, 'data-region="bulk-action"');
        $end = strpos($html, '</select>', $start);
        $bulkselect = substr($html, $start, $end - $start);
        $this->assertStringContainsString('disabled', $bulkselect);
        $optioncount = substr_count($bulkselect, '<option');
        $this->assertSame(3, $optioncount);
        $this->assertMatchesRegularExpression('/<option value=""[^>]*\sselected/', $bulkselect);
        $choosedots = get_string('choosedots');
        $this->assertStringContainsString($choosedots, $bulkselect);
        $this->assertStringNotContainsString('<option value="instance"', $bulkselect);
        foreach (['template', 'keep'] as $action) {
            $this->assertStringContainsString('<option value="' . $action . '"', $bulkselect);
        }
    }
}
