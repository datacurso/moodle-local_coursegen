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
use local_coursegen\local\models\template_activity;
use local_coursegen\output\sections_config;

/**
 * Saved-template hydration of the "Course sections" review: sections_config
 * preselects the saved action/behavior (including the new "template"
 * action), degrading it safely when a saved value is no longer valid for
 * that row's type. save_template's own persistence of templatescope is
 * covered separately in save_template_scope_test.php.
 *
 * get_course_preview autoloads classes/external/*, which requires each test
 * to run in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\output\sections_config
 * @covers     \local_coursegen\output\template_row_options
 * @covers     \local_coursegen\external\get_course_preview
 *
 * @runTestsInSeparateProcesses
 */
final class course_sections_saved_config_test extends \advanced_testcase {
    use sections_config_fixture_trait;

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

        $pageselect = $this->extract_action_select($html, (int) $page->cmid);
        $this->assertMatchesRegularExpression('/<option value="exclude"[^>]*\sselected/', $pageselect);
        $this->assertDoesNotMatchRegularExpression('/<option value="modify"[^>]*\sselected/', $pageselect);

        $forumselect = $this->extract_action_select($html, (int) $forum->cmid);
        $this->assertMatchesRegularExpression('/<option value="modify"[^>]*\sselected/', $forumselect);

        $section1select = $this->extract_behavior_select($html, (int) $section1->id);
        $this->assertMatchesRegularExpression('/<option value="keep"[^>]*\sselected/', $section1select);
        $this->assertStringNotContainsString('<option value="exclude"', $section1select);

        $section2select = $this->extract_behavior_select($html, (int) $section2->id);
        $this->assertSame(3, substr_count($section2select, '<option'));
        $this->assertMatchesRegularExpression('/<option value="exclude"[^>]*\sselected/', $section2select);

        $section0 = $modinfo->get_section_info(0);
        $generalselect = $this->extract_behavior_select($html, (int) $section0->id);
        $this->assertMatchesRegularExpression('/<option value="custom"[^>]*\sselected/', $generalselect);

        $result = get_course_preview::execute((int) $course->id, $templateid);
        $ajaxpageselect = $this->extract_action_select($result['html'], (int) $page->cmid);
        $this->assertMatchesRegularExpression('/<option value="exclude"[^>]*\sselected/', $ajaxpageselect);
    }

    /**
     * A saved "template" action on a type the generator cannot handle
     * (lti, not in AI_SUPPORTED_TYPES) degrades to "keep" when rendered —
     * the same rule "modify" already follows — instead of rendering an
     * option the row's own select does not even offer.
     */
    public function test_render_degrades_saved_template_action_on_unsupported_type(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, , , $lti] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);
        $section2 = $modinfo->get_section_info(2);

        // Bypass the external function (which never offers "template" for
        // an unsupported type in the first place) to simulate a stale/
        // corrupted saved row directly at the persistence layer.
        $templateid = 12345;
        $act = new template_activity(0);
        $act->set('templateid', $templateid);
        $act->set('sectionid', (int) $section2->id);
        $act->set('cmid', (int) $lti->cmid);
        $act->set('action', 'template');
        $act->set('useasreference', 1);
        $act->set('templatescope', 'course');
        $act->set('prompt', '');
        $act->create();

        $html = sections_config::render($modinfo, $templateid);

        $ltiselect = $this->extract_action_select($html, (int) $lti->cmid);
        $this->assertMatchesRegularExpression('/<option value="keep"[^>]*\sselected/', $ltiselect);
        $this->assertStringNotContainsString('<option value="template"', $ltiselect);
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
