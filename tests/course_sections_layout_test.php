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
 * "Course sections" review layout: collapsible section cards, the activity
 * table's columns/links, and selection checkboxes.
 *
 * sections_config builds the review from modinfo alone (no course-format DOM
 * surgery): one collapsible card per section holding a report-style table
 * (checkbox/Name/Type/Actions columns) with one row per activity, every
 * section a working Bootstrap 4 collapse pair (deterministic data-target/id,
 * since the ids must be valid CSS identifiers — html_writer::random_id
 * output can start with a digit, which #id selectors cannot).
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\output\sections_config
 */
final class course_sections_layout_test extends \advanced_testcase {
    use sections_config_fixture_trait;

    /**
     * Each section renders as a working Bootstrap 4 collapsible: matching
     * data-target/id pair built from course id + section id, expanded by
     * default, with a visible behavior select (custom preselected, no
     * exclude option offered).
     */
    public function test_render_produces_a_collapsible_per_section(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);

        $html = sections_config::render($modinfo);

        foreach ($modinfo->get_section_info_all() as $section) {
            $this->assertStringContainsString(
                'data-for="section" data-id="' . $section->id . '"',
                $html
            );
            $collapseid = 'local-coursegen-tpl-section-' . $course->id . '-' . $section->id;
            $this->assertStringContainsString('data-target="#' . $collapseid . '"', $html);
            $this->assertStringContainsString('aria-controls="' . $collapseid . '"', $html);
            $this->assertStringContainsString('id="' . $collapseid . '"', $html);
        }
        $this->assertStringContainsString('data-toggle="collapse"', $html);
        $this->assertStringContainsString('class="collapse show"', $html);

        $this->assertStringNotContainsString('data-sec-action=', $html);
        foreach ($modinfo->get_section_info_all() as $section) {
            $behaviorselect = $this->extract_behavior_select($html, (int) $section->id);
            $this->assertSame(2, substr_count($behaviorselect, '<option'));
            foreach (['aimodify', 'keep'] as $behavior) {
                $this->assertStringContainsString('<option value="' . $behavior . '"', $behaviorselect);
            }
            $this->assertStringNotContainsString('<option value="exclude"', $behaviorselect);
            $this->assertMatchesRegularExpression('/<option value="aimodify"[^>]*\sselected/', $behaviorselect);
            $this->assertStringContainsString('Allow AI modification', $behaviorselect);
        }
    }

    /**
     * Activities render as table rows with Name and Type columns.
     */
    public function test_render_produces_activity_table_rows(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page, $forum] = $this->create_course_fixture();

        $html = sections_config::render(get_fast_modinfo($course));

        $this->assertStringContainsString('generaltable', $html);
        $this->assertStringContainsString(get_string('name'), $html);
        $this->assertStringContainsString(get_string('template_table_type', 'local_coursegen'), $html);

        $this->assertStringContainsString('tpl-activity-table', $html);
        $this->assertStringContainsString('tpl-col-type', $html);
        $this->assertStringContainsString('tpl-col-actions', $html);

        $this->assertStringContainsString(
            'data-for="cmitem" data-id="' . $page->cmid . '" data-modname="page"',
            $html
        );
        $this->assertStringContainsString(
            'data-for="cmitem" data-id="' . $forum->cmid . '" data-modname="forum"',
            $html
        );
        $this->assertStringContainsString(get_string('modulename', 'page'), $html);
        $this->assertStringContainsString(get_string('modulename', 'forum'), $html);
    }

    /**
     * Activity names link to the real activity in the base course (new tab),
     * except modules without a view URL of their own (label), whose names
     * stay plain text — never a dead link.
     */
    public function test_render_links_activity_names_to_the_base_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page, , , $label] = $this->create_course_fixture();

        $html = sections_config::render(get_fast_modinfo($course));

        $this->assertMatchesRegularExpression(
            '~<a href="[^"]*/mod/page/view\.php\?id=' . $page->cmid . '"[^>]*target="_blank"[^>]*rel="noopener"~',
            $html
        );
        $this->assertStringContainsString(
            'data-for="cmitem" data-id="' . $label->cmid . '" data-modname="label"',
            $html
        );
        $this->assertStringNotContainsString('/mod/label/view.php', $html);
    }

    /**
     * Each activity row carries a selection checkbox (labelled with the
     * activity name), each section table a select-all checkbox in its
     * header row, and each section CARD HEADER its own select-all checkbox
     * (labelled with the section name, usable while collapsed) — sections
     * without activities render no header checkbox.
     */
    public function test_render_offers_selection_checkboxes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);

        $html = sections_config::render($modinfo);

        $this->assertStringContainsString(
            'data-region="activity-select" data-id="' . $page->cmid . '"',
            $html
        );
        $this->assertStringContainsString('aria-label="' . $page->name . '"', $html);
        $this->assertStringContainsString('data-region="select-all"', $html);

        foreach ($modinfo->get_section_info_all() as $section) {
            $marker = 'data-region="section-select-all" data-sid="' . $section->id . '"';
            if (empty($modinfo->sections[$section->section])) {
                $this->assertStringNotContainsString($marker, $html);
                continue;
            }
            $this->assertStringContainsString($marker, $html);
            $this->assertStringContainsString(
                'aria-label="' . get_section_name($course, $section) . '"',
                $html
            );
        }
    }

    /**
     * No prompt textareas and no native course-editing markup survive.
     */
    public function test_render_carries_no_prompt_or_editing_markup(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();

        $html = sections_config::render(get_fast_modinfo($course));

        $this->assertStringNotContainsString('data-tpl-prompt', $html);
        $this->assertStringNotContainsString('<textarea', $html);
        $this->assertStringNotContainsString('data-inplaceeditable', $html);
        $this->assertStringNotContainsString('data-region="actionmenu"', $html);
        // The template docblock must never leak into the page: any `}}`
        // inside the {{! }} comment closes it early and dumps the rest of
        // the docblock as visible content.
        $this->assertStringNotContainsString('Context variables required', $html);
    }
}
