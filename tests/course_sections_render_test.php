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

use local_coursegen\external\get_course_preview;
use local_coursegen\external\save_template;
use local_coursegen\output\sections_config;

/**
 * "Course sections" review markup contract on the create-template screen.
 *
 * sections_config builds the review from modinfo alone (no course-format DOM
 * surgery): one collapsible card per section holding a report-style table
 * (checkbox/Name/Type/Actions columns) with one row per activity. Each row
 * carries a selection checkbox and a compact action <select> preselected with
 * the type's server-side default (modify for AI-supported types, keep
 * otherwise — with the modify option omitted entirely for unsupported
 * types), each section table a select-all checkbox in its header and a bulk
 * "apply to selected" select at the bottom, and every section a working
 * Bootstrap 4 collapse pair (deterministic data-target/id, since the ids
 * must be valid CSS identifiers — html_writer::random_id output can start
 * with a digit, which #id selectors cannot).
 *
 * get_course_preview autoloads classes/external/*, which requires each test
 * to run in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\output\sections_config
 * @covers     \local_coursegen\external\get_course_preview
 *
 * @runTestsInSeparateProcesses
 */
final class course_sections_render_test extends \advanced_testcase {
    /**
     * Create a 2-section course with a page in section 1 and a forum plus an
     * LTI external tool in section 2.
     *
     * Page and forum are both in template_content_generator::AI_SUPPORTED_TYPES;
     * lti is NOT — it is the "unsupported type" fixture for the rows that
     * must not offer (nor default to) the modify action.
     *
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass,3:\stdClass} Course, page, forum and lti records.
     */
    private function create_course_fixture(): array {
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'section' => 2]);
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id, 'section' => 2]);
        // A label has no view URL of its own — the "name renders as plain
        // text, never a dead link" fixture.
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id, 'section' => 2]);
        return [$course, $page, $forum, $lti, $label];
    }

    /**
     * Extract one activity row's action <select> markup (opening tag up to
     * its closing tag) so per-row option assertions cannot accidentally
     * match a different row's options.
     *
     * @param string $html Full rendered review.
     * @param int $cmid Course module id the select belongs to.
     * @return string The select markup, without the closing tag.
     */
    private function extract_action_select(string $html, int $cmid): string {
        $marker = 'data-region="activity-action" data-id="' . $cmid . '"';
        $start = strpos($html, $marker);
        $this->assertNotFalse($start, 'No action select rendered for cmid ' . $cmid);
        $end = strpos($html, '</select>', $start);
        $this->assertNotFalse($end, 'Unterminated action select for cmid ' . $cmid);
        return substr($html, $start, $end - $start);
    }

    /**
     * Extract one section's behavior <select> markup, same scoping idea as
     * extract_action_select().
     *
     * @param string $html Full rendered review.
     * @param int $sectionid Base course section id the select belongs to.
     * @return string The select markup, without the closing tag.
     */
    private function extract_behavior_select(string $html, int $sectionid): string {
        $marker = 'data-region="section-behavior" data-sid="' . $sectionid . '"';
        $start = strpos($html, $marker);
        $this->assertNotFalse($start, 'No behavior select rendered for section ' . $sectionid);
        $end = strpos($html, '</select>', $start);
        $this->assertNotFalse($end, 'Unterminated behavior select for section ' . $sectionid);
        return substr($html, $start, $end - $start);
    }

    /**
     * Each section renders as a working Bootstrap 4 collapsible: matching
     * data-target/id pair built from course id + section id (a deterministic
     * id that is a valid CSS identifier — never html_writer::random_id
     * output, which can start with a digit and then silently breaks
     * Bootstrap's #id selector), expanded by default.
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
            // Deterministic collapse pair per section.
            $collapseid = 'local-coursegen-tpl-section-' . $course->id . '-' . $section->id;
            $this->assertStringContainsString('data-target="#' . $collapseid . '"', $html);
            $this->assertStringContainsString('aria-controls="' . $collapseid . '"', $html);
            $this->assertStringContainsString('id="' . $collapseid . '"', $html);
        }
        // Bootstrap collapse toggles, expanded by default.
        $this->assertStringContainsString('data-toggle="collapse"', $html);
        $this->assertStringContainsString('class="collapse show"', $html);

        // The old 3-dot section menu is gone: each header carries a visible
        // behavior select instead — custom (permission-framed label)
        // preselected by default, and no exclude option offered any more.
        $this->assertStringNotContainsString('data-sec-action=', $html);
        foreach ($modinfo->get_section_info_all() as $section) {
            $behaviorselect = $this->extract_behavior_select($html, (int) $section->id);
            $this->assertSame(2, substr_count($behaviorselect, '<option'));
            foreach (['custom', 'keep'] as $behavior) {
                $this->assertStringContainsString('<option value="' . $behavior . '"', $behaviorselect);
            }
            $this->assertStringNotContainsString('<option value="exclude"', $behaviorselect);
            $this->assertMatchesRegularExpression('/<option value="custom"[^>]*\sselected/', $behaviorselect);
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

        // Shared column geometry: every section renders its own table, so
        // the columns only line up across sections when all tables use the
        // same fixed layout classes (see styles/templates.css).
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
        // Human module type labels.
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
        // The label row renders, but with no anchor at all.
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
     * Each activity row carries an action select preselected with the
     * server-side default: modify for an AI-supported type, keep otherwise —
     * and an unsupported type must not offer the modify option at all.
     */
    public function test_render_activity_select_offers_the_actions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page, , $lti] = $this->create_course_fixture();

        $html = sections_config::render(get_fast_modinfo($course));

        // The old inert 3-dot activity menu is gone.
        $this->assertStringNotContainsString('data-act-val=', $html);

        // AI-supported type (page): all four actions, modify preselected.
        $pageselect = $this->extract_action_select($html, (int) $page->cmid);
        $this->assertSame(4, substr_count($pageselect, '<option'));
        foreach (['modify', 'keep', 'reference', 'exclude'] as $action) {
            $this->assertStringContainsString('<option value="' . $action . '"', $pageselect);
        }
        $this->assertMatchesRegularExpression('/<option value="modify"[^>]*\sselected/', $pageselect);
        $this->assertStringContainsString(get_string('template_activity_modify', 'local_coursegen'), $pageselect);
        $this->assertStringContainsString(get_string('template_activity_reference', 'local_coursegen'), $pageselect);
        // Permission framing: the modify option reads as what the teacher is
        // allowed to do, not as an instruction to the AI.
        $this->assertStringContainsString('Allow AI modification', $pageselect);

        // Unsupported type (lti): modify omitted, keep preselected.
        $ltiselect = $this->extract_action_select($html, (int) $lti->cmid);
        $this->assertSame(3, substr_count($ltiselect, '<option'));
        $this->assertStringNotContainsString('<option value="modify"', $ltiselect);
        $this->assertMatchesRegularExpression('/<option value="keep"[^>]*\sselected/', $ltiselect);
    }

    /**
     * ONE global bulk action bar renders below all the section cards
     * (mirroring the users-table "With selected users…" pattern): a label,
     * then a select born disabled — sections_events.js enables it once any
     * activity checkbox is checked — with a choosedots placeholder plus the
     * four per-activity actions. No per-section bulk selects remain.
     */
    public function test_render_offers_a_single_global_bulk_bar(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();

        $html = sections_config::render(get_fast_modinfo($course));

        // Exactly one, and never the old per-section flavour.
        $this->assertSame(1, substr_count($html, 'data-region="bulk-action"'));
        $this->assertStringNotContainsString('data-region="bulk-action" data-sid', $html);

        $this->assertStringContainsString(
            get_string('template_with_selected_activities', 'local_coursegen'),
            $html
        );

        $start = strpos($html, 'data-region="bulk-action"');
        $end = strpos($html, '</select>', $start);
        $bulkselect = substr($html, $start, $end - $start);
        // Born disabled: nothing is checked on a fresh render.
        $this->assertStringContainsString('disabled', $bulkselect);
        // Placeholder first, then the four actions.
        $this->assertSame(5, substr_count($bulkselect, '<option'));
        $this->assertMatchesRegularExpression('/<option value=""[^>]*\sselected/', $bulkselect);
        $this->assertStringContainsString(get_string('choosedots'), $bulkselect);
        foreach (['modify', 'keep', 'reference', 'exclude'] as $action) {
            $this->assertStringContainsString('<option value="' . $action . '"', $bulkselect);
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

    /**
     * Rendering for an existing template preselects the SAVED per-activity
     * action and section behavior instead of the type defaults — while
     * activities added to the base course after the template was saved fall
     * back to their type default, and saved rows whose cmid no longer
     * exists are ignored gracefully. The AJAX course-switch path takes the
     * same optional templateid.
     */
    public function test_render_preselects_saved_template_configuration(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page, $forum] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);
        $section1 = $modinfo->get_section_info(1);
        $section2 = $modinfo->get_section_info(2);

        // Save a template overriding the page's default action ("modify")
        // and section 1's default behavior ("custom"), a section saved with
        // the no-longer-offered "exclude" behavior, plus one saved row whose
        // cmid no longer exists in the base course.
        $saved = save_template::execute(0, 'Edit me', '', (int) $course->id, 0, false, '[]', '', 1, [
            [
                'sectionid' => (int) $section1->id,
                'sectionnum' => 1,
                'behavior' => 'keep',
                'activities' => [
                    ['cmid' => (int) $page->cmid, 'action' => 'exclude', 'useasreference' => false, 'prompt' => ''],
                    ['cmid' => 999999, 'action' => 'keep', 'useasreference' => true, 'prompt' => ''],
                ],
            ],
            [
                'sectionid' => (int) $section2->id,
                'sectionnum' => 2,
                'behavior' => 'exclude',
                'activities' => [],
            ],
        ]);
        $templateid = (int) $saved['id'];

        $html = sections_config::render($modinfo, $templateid);

        // Page: the SAVED action is preselected, not the type default.
        $pageselect = $this->extract_action_select($html, (int) $page->cmid);
        $this->assertMatchesRegularExpression('/<option value="exclude"[^>]*\sselected/', $pageselect);
        $this->assertDoesNotMatchRegularExpression('/<option value="modify"[^>]*\sselected/', $pageselect);

        // Forum: added semantics — no saved row, so the type default stays.
        $forumselect = $this->extract_action_select($html, (int) $forum->cmid);
        $this->assertMatchesRegularExpression('/<option value="modify"[^>]*\sselected/', $forumselect);

        // Section 1's saved behavior is preselected in its behavior select —
        // still only two options, since it isn't "exclude".
        $section1select = $this->extract_behavior_select($html, (int) $section1->id);
        $this->assertMatchesRegularExpression('/<option value="keep"[^>]*\sselected/', $section1select);
        $this->assertStringNotContainsString('<option value="exclude"', $section1select);

        // Grace case: a section SAVED as "exclude" still renders (and
        // preselects) that option so hydration never lies about the state.
        $section2select = $this->extract_behavior_select($html, (int) $section2->id);
        $this->assertSame(3, substr_count($section2select, '<option'));
        $this->assertMatchesRegularExpression('/<option value="exclude"[^>]*\sselected/', $section2select);

        // No saved row (the general section) keeps the default.
        $section0 = $modinfo->get_section_info(0);
        $generalselect = $this->extract_behavior_select($html, (int) $section0->id);
        $this->assertMatchesRegularExpression('/<option value="custom"[^>]*\sselected/', $generalselect);

        // The AJAX path hydrates the same way via its optional templateid.
        $result = get_course_preview::execute((int) $course->id, $templateid);
        $ajaxpageselect = $this->extract_action_select($result['html'], (int) $page->cmid);
        $this->assertMatchesRegularExpression('/<option value="exclude"[^>]*\sselected/', $ajaxpageselect);
    }

    /**
     * The AJAX course-switch path serves the same clean markup.
     */
    public function test_get_course_preview_serves_the_sections_review(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();

        $result = get_course_preview::execute((int) $course->id);

        $this->assertStringContainsString('data-region="course-sections"', $result['html']);
        $this->assertStringContainsString(
            'data-for="cmitem" data-id="' . $page->cmid . '" data-modname="page"',
            $result['html']
        );
        $this->assertStringContainsString('data-region="activity-action"', $result['html']);
        $this->assertStringNotContainsString('data-tpl-prompt', $result['html']);
        $this->assertSame(2, $result['numsections']);
        $this->assertSame(4, $result['numactivities']);
    }
}
