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

use local_coursegen\external\get_course_preview;
use local_coursegen\external\save_template;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_section;
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
     * Saved Modify with AI instructions are rendered in the optional row editor.
     */
    public function test_saved_activity_instruction_round_trips_in_editor(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $page] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);
        $section = $modinfo->get_section_info(1);
        $instruction = 'Adapt the activity for a beginner audience.';
        $saved = save_template::execute(0, 'Prompt test', '', (int) $course->id, 0, false, '', 1, [[
            'sectionid' => (int) $section->id,
            'sectionnum' => 1,
            'behavior' => 'aimodify',
            'activities' => [[
                'cmid' => (int) $page->cmid,
                'action' => 'template',
                'prompt' => $instruction,
            ]],
        ]]);

        $html = sections_config::render($modinfo, (int) $saved['id']);

        $this->assertStringContainsString('data-region="activity-instruction"', $html);
        $this->assertStringContainsString($instruction, $html);
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

        $saved = save_template::execute(0, 'Edit me', '', (int) $course->id, 0, false, '', 1, [
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
        $this->assertMatchesRegularExpression('/<option value="keep"[^>]*\sselected/', $pageselect);
        $this->assertStringNotContainsString('<option value="instance"', $pageselect);

        // Forum has no saved row, so it falls back to the unconditional "keep"
        // default — never "instance", which is not offered to the admin.
        $forumselect = $this->extract_action_select($html, (int) $forum->cmid);
        $this->assertMatchesRegularExpression('/<option value="keep"[^>]*\sselected/', $forumselect);

        $section1select = $this->extract_behavior_select($html, (int) $section1->id);
        $this->assertMatchesRegularExpression('/<option value="keep"[^>]*\sselected/', $section1select);
        $section2select = $this->extract_behavior_select($html, (int) $section2->id);
        $this->assertMatchesRegularExpression('/<option value="exclude"[^>]*\sselected/', $section2select);
        $savedsection = template_section::get_record(['templateid' => $templateid, 'sectionid' => (int) $section2->id]);
        $this->assertSame('exclude', $savedsection->get('behavior'));

        $result = get_course_preview::execute((int) $course->id, $templateid);
        $ajaxpageselect = $this->extract_action_select($result['html'], (int) $page->cmid);
        $this->assertMatchesRegularExpression('/<option value="keep"[^>]*\sselected/', $ajaxpageselect);
    }

    /**
     * A saved "instance" action (never offered for a real activity) degrades
     * to "keep" when rendered, for an AI-supported module type — the
     * hydration guard finds "instance" is not in the offered keys and falls
     * through to the default,
     * instead of rendering an option the row's own select does not offer.
     */
    public function test_render_degrades_saved_instance_action_to_keep(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);
        $section1 = $modinfo->get_section_info(1);

        // Bypass the external function to simulate a row saved with an
        // action the admin is never offered.
        $templateid = 54321;
        $act = new template_activity(0);
        $act->set('templateid', $templateid);
        $act->set('sectionid', (int) $section1->id);
        $act->set('cmid', (int) $page->cmid);
        $act->set('action', 'instance');
        $act->set('useasreference', 1);
        $act->set('templatescope', 'course');
        $act->set('prompt', '');
        $act->create();

        $html = sections_config::render($modinfo, $templateid);

        $pageselect = $this->extract_action_select($html, (int) $page->cmid);
        $this->assertMatchesRegularExpression('/<option value="keep"[^>]*\sselected/', $pageselect);
        $this->assertStringNotContainsString('<option value="instance"', $pageselect);
    }

    /**
     * A saved "template" action on a type the generator cannot handle
     * (lti, not in ai_activity_types::MODNAMES) degrades to "keep" when rendered —
     * instead of rendering an option the row's own select does not even
     * offer.
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
